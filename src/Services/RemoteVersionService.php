<?php

namespace PVM\Services;

use GuzzleHttp\Client;
use RuntimeException;

/**
 * RemoteVersionService handles scraping windows.php.net (releases + archives) to find:
 *   - ALL discovered builds (via getAllBuilds()), returning many PhpBuildInfo objects
 *   - The "best" single build per major.minor (via getLatestVersions())
 *
 * We cache both sets of data in cache.json so we don't scrape repeatedly.
 */
class RemoteVersionService
{
    private CacheService $cache;
    private const CACHE_TTL = 86400; // 24 hours
    
    private const RELEASES_URL = 'https://windows.php.net/downloads/releases/';
    private const ARCHIVES_URL = 'https://windows.php.net/downloads/releases/archives/';
    
    public function __construct(CacheService $cache)
    {
        $this->cache = $cache;
    }
    
    /**
     * Returns an array of all discovered builds from windows.php.net,
     * not just the single "best" build per major.minor. Each element is a PhpBuildInfo:
     *   ->fullVersion e.g. "8.2.10"
     *   ->majorMinor  e.g. "8.2"
     *   ->isNts       bool
     *   ->isX64       bool
     *   ->vcVersion   int
     *   ->downloadUrl string
     */
    public function getAllBuilds(bool $forceRefresh = false): array
    {
        $cacheData = $this->cache->getCache();
        $now       = time();
        
        // Check if we have cached "all_builds" data
        if (
            !$forceRefresh
            && isset($cacheData['all_builds'], $cacheData['all_builds']['timestamp'])
            && ($now - $cacheData['all_builds']['timestamp'] < self::CACHE_TTL)
        ) {
            // Return from cache
            $buildsAsArray = $cacheData['all_builds']['data'] ?? [];
            return $this->rehydrateBuilds($buildsAsArray);
        }
        
        // Otherwise, scrape from both releases and archives
        $allBuilds = array_merge(
            $this->scrapeAllBuildsFrom(self::RELEASES_URL),
            $this->scrapeAllBuildsFrom(self::ARCHIVES_URL)
        );
        
        // Save to cache
        $cacheData['all_builds'] = [
            'timestamp' => $now,
            'data'      => $this->dehydrateBuilds($allBuilds),
        ];
        $this->cache->setCache($cacheData);
        $this->cache->saveCache();
        
        return $allBuilds;
    }
    
    /**
     * Scrape the given URL (either releases/ or archives/) for all .zip files matching:
     *   php-<fullVersion>-(nts|ts)-Win32-(vsXX|vcXX)-(x64|x86).zip
     * Return an array of PhpBuildInfo for each match.
     */
    private function scrapeAllBuildsFrom(string $baseUrl): array
    {
        $client = new Client();
        $res    = $client->get($baseUrl);
        if ($res->getStatusCode() !== 200) {
            throw new RuntimeException("Failed to retrieve $baseUrl");
        }
        $html = (string) $res->getBody();
        
        // This pattern makes "-nts" optional.
        $pattern = '/php-(\d+\.\d+\.\d+)(-nts)?-Win32-(vc\d+|vs\d+)-(x64|x86)\.zip/i';
        
        $builds = [];
        if (preg_match_all($pattern, $html, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $fullVersion = $m[1];          // e.g. "8.2.6"
                $ntsCapture  = $m[2] ?? '';    // e.g. "-nts" or ""
                $vcString    = strtolower($m[3]); // e.g. "vs16" or "vc15"
                $archString  = strtolower($m[4]); // "x64" or "x86"
                
                // major.minor => "8.2" from "8.2.6"
                $parts = explode('.', $fullVersion);
                if (count($parts) !== 3) {
                    // skip weird version
                    continue;
                }
                $majorMinor = $parts[0] . '.' . $parts[1];
                
                // parse digits from vs16 or vc15 => 16 or 15
                $vcNum = (int) preg_replace('/\D/', '', $vcString);
                
                // If $ntsCapture == "-nts", then isNts=true; otherwise TS
                $isNts = !empty($ntsCapture); // if we captured "-nts", this is NTS
                
                // Build the download URL accordingly
                // If NTS => has "-nts" in the filename, else not
                $ntsPart = $isNts ? '-nts' : '';
                $downloadUrl = $baseUrl
                    . "php-{$fullVersion}{$ntsPart}-Win32-{$vcString}-{$archString}.zip";
                
                $info = new PhpBuildInfo();
                $info->fullVersion = $fullVersion;
                $info->majorMinor  = $majorMinor;
                $info->isNts       = $isNts;
                $info->isX64       = ($archString === 'x64');
                $info->vcVersion   = $vcNum;
                $info->downloadUrl = $downloadUrl;
                
                $builds[] = $info;
            }
        }
        
        return $builds;
    }
    
    /**
     * Convert an array of PhpBuildInfo objects to a simple array for JSON storage in cache.
     */
    private function dehydrateBuilds(array $builds): array
    {
        $out = [];
        foreach ($builds as $b) {
            $out[] = [
                'fullVersion' => $b->fullVersion,
                'majorMinor'  => $b->majorMinor,
                'isNts'       => $b->isNts,
                'isX64'       => $b->isX64,
                'vcVersion'   => $b->vcVersion,
                'downloadUrl' => $b->downloadUrl,
            ];
        }
        return $out;
    }
    
    /**
     * Convert the array => array of PhpBuildInfo objects.
     */
    private function rehydrateBuilds(array $arr): array
    {
        $out = [];
        foreach ($arr as $item) {
            $b = new PhpBuildInfo();
            $b->fullVersion = $item['fullVersion'];
            $b->majorMinor  = $item['majorMinor'];
            $b->isNts       = $item['isNts'];
            $b->isX64       = $item['isX64'];
            $b->vcVersion   = $item['vcVersion'];
            $b->downloadUrl = $item['downloadUrl'];
            $out[] = $b;
        }
        return $out;
    }
    
    /**
     * Returns a single "best" build per major.minor, typically used for the old "pvm list" style.
     * This logic picks the highest patch, preferring NTS, x64, highest vc version, etc.
     */
    public function getLatestVersions(bool $forceRefresh = false): array
    {
        // check cache quickly
        $cacheData = $this->cache->getCache();
        $now       = time();
        
        if (
            !$forceRefresh
            && isset($cacheData['timestamp'], $cacheData['best_builds'])
            && ($now - $cacheData['timestamp'] < self::CACHE_TTL)
        ) {
            // Re-hydrate
            return $this->hydrateBestBuilds($cacheData['best_builds']);
        }
        
        // Otherwise, build from getAllBuilds
        $all = $this->getAllBuilds($forceRefresh);
        
        // Group by major.minor
        $grouped = [];
        foreach ($all as $b) {
            $grouped[$b->majorMinor][] = $b;
        }
        
        // For each group, pick the "best"
        // 1) highest patch
        // 2) prefer NTS over TS
        // 3) prefer x64 over x86
        // 4) highest vc version
        $best = [];
        foreach ($grouped as $mm => $list) {
            usort($list, function (PhpBuildInfo $a, PhpBuildInfo $b) {
                // 1) patch
                $cmp = version_compare($b->fullVersion, $a->fullVersion);
                if ($cmp !== 0) {
                    return $cmp;
                }
                // 2) NTS over TS
                if ($a->isNts !== $b->isNts) {
                    return $b->isNts <=> $a->isNts;
                }
                // 3) x64 over x86
                if ($a->isX64 !== $b->isX64) {
                    return $b->isX64 <=> $a->isX64;
                }
                // 4) highest vcVersion
                return $b->vcVersion <=> $a->vcVersion;
            });
            
            $best[$mm] = $list[0]; // first is the "best"
        }
        
        // store in cache
        $bestAsArr = [];
        foreach ($best as $mm => $obj) {
            $bestAsArr[$mm] = [
                'fullVersion' => $obj->fullVersion,
                'majorMinor'  => $obj->majorMinor,
                'isNts'       => $obj->isNts,
                'isX64'       => $obj->isX64,
                'vcVersion'   => $obj->vcVersion,
                'downloadUrl' => $obj->downloadUrl
            ];
        }
        
        $cacheData['timestamp']   = $now;
        $cacheData['best_builds'] = $bestAsArr;
        
        $this->cache->setCache($cacheData);
        $this->cache->saveCache();
        
        return $best;
    }
    
    private function hydrateBestBuilds(array $bestArr): array
    {
        $out = [];
        foreach ($bestArr as $mm => $a) {
            $b = new PhpBuildInfo();
            $b->fullVersion = $a['fullVersion'];
            $b->majorMinor  = $a['majorMinor'];
            $b->isNts       = $a['isNts'];
            $b->isX64       = $a['isX64'];
            $b->vcVersion   = $a['vcVersion'];
            $b->downloadUrl = $a['downloadUrl'];
            $out[$mm] = $b;
        }
        return $out;
    }
}
