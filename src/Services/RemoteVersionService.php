<?php

namespace PVM\Services;

use GuzzleHttp\Client;
use RuntimeException;

/**
 * Scrapes "releases" + "archives" pages, looks for
 *   php-8.4.4-nts-Win32-vs17-x64.zip
 * and picks best build (highest patch, prefer NTS, prefer x64, highest vc/vs).
 *
 * Caches results in cache.json. We store them as arrays but
 * re-hydrate to PhpBuildInfo objects before returning.
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
     * Returns an array of "best" PhpBuildInfo objects keyed by major.minor.
     * e.g. [
     *   "8.4" => (PhpBuildInfo object with fullVersion=8.4.4, ...),
     *   "8.3" => ...
     * ]
     */
    public function getLatestVersions(bool $forceRefresh = false): array
    {
        $cachedData = $this->cache->getCache();
        $now = time();
        
        // If we have cached data and it's not stale (and not forcing refresh)...
        if (
            !$forceRefresh
            && isset($cachedData['timestamp'], $cachedData['best_builds'])
            && ($now - $cachedData['timestamp'] < self::CACHE_TTL)
        ) {
            // Re-hydrate from arrays to objects
            return $this->hydrateBestBuilds($cachedData['best_builds']);
        }
        
        // Otherwise, scrape fresh
        $allBuilds = array_merge(
            $this->scrapePage(self::RELEASES_URL),
            $this->scrapePage(self::ARCHIVES_URL)
        );
        $bestByMajorMinor = $this->pickBestBuilds($allBuilds);
        
        // Convert best builds from objects to arrays for caching
        $bestAsArrays = [];
        foreach ($bestByMajorMinor as $mm => $buildObj) {
            $bestAsArrays[$mm] = $this->objectToArray($buildObj);
        }
        
        // Store in cache
        $this->cache->setCache([
            'timestamp'    => $now,
            'best_builds'  => $bestAsArrays,
            // optionally store all_builds if you want (converted to array),
            // but it's not strictly necessary
        ]);
        $this->cache->saveCache();
        
        return $bestByMajorMinor; // returning actual objects
    }
    
    /**
     * Scrape a single page (releases or archives) and return an array of PhpBuildInfo objects.
     */
    private function scrapePage(string $baseUrl): array
    {
        $client = new Client();
        $res = $client->get($baseUrl);
        
        if ($res->getStatusCode() !== 200) {
            throw new RuntimeException("Failed to retrieve $baseUrl");
        }
        $html = (string)$res->getBody();
        
        // Regex for links like: php-8.4.4-nts-Win32-vs17-x64.zip
        // group1 => entire filename
        // group2 => "8.4.4"
        // group3 => "nts"|"ts"
        // group4 => "vc15"|"vs16"|"vs17"
        // group5 => "x64"|"x86"
        $pattern = '/>(php-(\d+\.\d+\.\d+)-(nts|ts)-Win32-(vc\d+|vs\d+)-(x64|x86)\.zip)<\/A>/i';
        
        preg_match_all($pattern, $html, $matches, \PREG_SET_ORDER);
        
        $builds = [];
        
        foreach ($matches as $m) {
            $fileName   = $m[1];
            $fullVer    = $m[2];
            $ntsOrTs    = strtolower($m[3]) === 'nts';
            $vcString   = strtolower($m[4]); // "vs17", "vc15", etc.
            $arch       = strtolower($m[5]);  // "x64" or "x86"
            
            $parts = explode('.', $fullVer);
            if (count($parts) !== 3) {
                continue;
            }
            $majorMinor = $parts[0] . '.' . $parts[1];
            
            // parse digits from "vs17" or "vc15"
            $vcNum = (int) preg_replace('/\D/', '', $vcString);
            
            $info = new PhpBuildInfo();
            $info->fullVersion = $fullVer;
            $info->majorMinor  = $majorMinor;
            $info->isNts       = $ntsOrTs;
            $info->isX64       = ($arch === 'x64');
            $info->vcVersion   = $vcNum;
            $info->downloadUrl = $baseUrl . $fileName;
            
            $builds[] = $info;
        }
        
        return $builds;
    }
    
    /**
     * Among all discovered builds, pick the "best" per major.minor:
     *  - highest patch (version_compare)
     *  - prefer NTS over TS
     *  - prefer x64 over x86
     *  - highest vc/vs number
     */
    private function pickBestBuilds(array $allBuilds): array
    {
        // Group them
        $grouped = [];
        foreach ($allBuilds as $b) {
            $grouped[$b->majorMinor][] = $b;
        }
        
        $best = [];
        
        foreach ($grouped as $mm => $buildList) {
            // Sort so "best" is first
            usort($buildList, function (PhpBuildInfo $a, PhpBuildInfo $b) {
                // 1) compare patch
                $cmpVer = version_compare($b->fullVersion, $a->fullVersion);
                if ($cmpVer !== 0) {
                    return $cmpVer;
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
            
            // The first in the sorted list is the best
            $best[$mm] = $buildList[0];
        }
        
        return $best;
    }
    
    /**
     * Convert a single PhpBuildInfo object -> array
     */
    private function objectToArray(PhpBuildInfo $obj): array
    {
        return [
            'fullVersion' => $obj->fullVersion,
            'majorMinor'  => $obj->majorMinor,
            'isNts'       => $obj->isNts,
            'isX64'       => $obj->isX64,
            'vcVersion'   => $obj->vcVersion,
            'downloadUrl' => $obj->downloadUrl,
        ];
    }
    
    /**
     * Convert an array -> PhpBuildInfo object
     */
    private function arrayToObject(array $a): PhpBuildInfo
    {
        $obj = new PhpBuildInfo();
        $obj->fullVersion = $a['fullVersion'];
        $obj->majorMinor  = $a['majorMinor'];
        $obj->isNts       = $a['isNts'];
        $obj->isX64       = $a['isX64'];
        $obj->vcVersion   = $a['vcVersion'];
        $obj->downloadUrl = $a['downloadUrl'];
        return $obj;
    }
    
    /**
     * Convert the "best_builds" array from the cache back into objects.
     * e.g. [ "8.4" => [ "fullVersion"=>"8.4.4", ... ], "8.3"=>[ ... ] ]
     */
    private function hydrateBestBuilds(array $bestAsArrays): array
    {
        $result = [];
        foreach ($bestAsArrays as $mm => $buildArr) {
            $result[$mm] = $this->arrayToObject($buildArr);
        }
        return $result;
    }
}
