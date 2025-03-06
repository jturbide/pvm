<?php

namespace PVM\Services;

use GuzzleHttp\Client;
use RuntimeException;

/**
 * A service to scrape https://downloads.php.net/~windows/pecl/releases/
 * to find extension builds for Windows (NTS/TS, x64/x86, vs16/vs17, etc.).
 *
 * It stores data in cache.json similarly to your RemoteVersionService.
 */
class PeclService
{
    private CacheService $cache;
    private const CACHE_TTL = 86400; // 1 day
    
    // The new base URL for Windows PECL builds:
    private const PECL_BASE_URL = 'https://downloads.php.net/~windows/pecl/releases/';
    
    public function __construct(CacheService $cache)
    {
        $this->cache = $cache;
    }
    
    /**
     * Returns an array of PeclBuildInfo objects for the given extension name.
     * If $specificVersion is provided, it filters down to that version only.
     * If $forceRefresh is true, ignores the cache and re-scrapes.
     *
     * @param string      $extensionName   e.g. "phalcon", "redis", "imagick"
     * @param string|null $specificVersion e.g. "5.0.0" or "3.1.5"
     * @param bool        $forceRefresh
     *
     * @return PeclBuildInfo[] array of discovered builds
     */
    public function getExtensionBuilds(string $extensionName, ?string $specificVersion = null, bool $forceRefresh = false): array
    {
        $extensionName = strtolower($extensionName);
        $cacheData     = $this->cache->getCache();
        $now           = time();
        
        // Check cache
        if (
            !$forceRefresh
            && isset($cacheData['pecl_data'], $cacheData['pecl_data'][$extensionName])
            && isset($cacheData['pecl_data'][$extensionName]['timestamp'])
            && ($now - $cacheData['pecl_data'][$extensionName]['timestamp'] < self::CACHE_TTL)
        ) {
            // We have fresh cache for this extension
            $allBuildsAsArrays = $cacheData['pecl_data'][$extensionName]['builds'] ?? [];
            // Re-hydrate them back into PeclBuildInfo objects
            $allBuilds = $this->hydrateBuilds($allBuildsAsArrays);
            
            if ($specificVersion) {
                return array_filter($allBuilds, fn($b) => $b->extensionVer === $specificVersion);
            }
            return $allBuilds;
        }
        
        // Otherwise, do a fresh scrape
        $allBuilds = $this->scrapeAllVersions($extensionName);
        
        // Store in cache
        $cacheData['pecl_data'][$extensionName] = [
            'timestamp' => $now,
            'builds'    => $this->dehydrateBuilds($allBuilds),
        ];
        $this->cache->setCache($cacheData);
        $this->cache->saveCache();
        
        if ($specificVersion) {
            return array_filter($allBuilds, fn($b) => $b->extensionVer === $specificVersion);
        }
        return $allBuilds;
    }
    
    /**
     * Scrape the extension’s base directory: e.g. https://downloads.php.net/~windows/pecl/releases/phalcon/
     * to find all sub-versions (4.0.5/, 5.0.0/, etc.), then parse each subfolder’s files.
     */
    private function scrapeAllVersions(string $extensionName): array
    {
        // 1) list subfolders from e.g. https://downloads.php.net/~windows/pecl/releases/phalcon/
        $baseUrl   = self::PECL_BASE_URL . $extensionName . '/';
        $versions  = $this->listDirectory($baseUrl);
        
        $allBuilds = [];
        
        foreach ($versions as $verDir) {
            // e.g. "5.0.0/"
            $ver = rtrim($verDir, '/'); // "5.0.0"
            // skip if not a normal version
            if (!preg_match('/^\d+(\.\d+){1,3}$/', $ver)) {
                // could also skip alpha/beta tags if you want
                continue;
            }
            
            $verUrl = $baseUrl . $verDir;  // e.g. ".../phalcon/5.0.0/"
            $files  = $this->listDirectory($verUrl);
            
            // parse each file that ends in .zip or .dll
            foreach ($files as $f) {
                if (!preg_match('/\.(zip|dll)$/i', $f)) {
                    continue;
                }
                
                // Example: php_phalcon-5.8.0-8.4-nts-vs17-x64.zip
                // We'll do a more robust regex that might catch alpha/beta too
                if (preg_match(
                    '/php_([a-z0-9_]+)-(\d+\.\d+\.\d+(?:\.\d+)?)-(\d+\.\d+)-(nts|ts)-(vc\d+|vs\d+)-(x64|x86)\.(zip|dll)$/i',
                    $f,
                    $m
                )) {
                    // m[1] => extension short name
                    // m[2] => extension version, e.g. "5.8.0"
                    // m[3] => "8.4" => php major.minor
                    // m[4] => "nts" or "ts"
                    // m[5] => "vc15" or "vs16" etc
                    // m[6] => "x64" or "x86"
                    // m[7] => "zip" or "dll"
                    
                    $extShortName = strtolower($m[1]);
                    $extVersion   = $m[2];
                    $phpVer       = $m[3];
                    $ntsOrTs      = $m[4];
                    $vcStr        = strtolower($m[5]); // vs17, vc15, etc
                    $arch         = $m[6];
                    // $fileType     = $m[7]; // "zip" or "dll"
                    
                    // parse digits from vs17 or vc15
                    $vcNum = (int) preg_replace('/\D/', '', $vcStr);
                    
                    $build = new PeclBuildInfo();
                    $build->extensionName  = $extensionName;
                    $build->extensionVer   = $extVersion;
                    $build->phpMajorMinor  = $phpVer;
                    $build->isNts          = ($ntsOrTs === 'nts');
                    $build->isX64          = ($arch === 'x64');
                    $build->vcVersion      = $vcNum;
                    $build->dllFileName    = $f;
                    $build->downloadUrl    = $verUrl . $f;
                    
                    $allBuilds[] = $build;
                }
            }
        }
        
        return $allBuilds;
    }
    
    /**
     * Parse the HTML directory listing at $url, returning a list of "href" items
     * (subfolders or filenames), ignoring parent directories, etc.
     */
    private function listDirectory(string $url): array
    {
        $client = new Client();
        $res = $client->get($url);
        if ($res->getStatusCode() !== 200) {
            throw new RuntimeException("Failed to retrieve $url");
        }
        
        $html = (string) $res->getBody();
        // We'll parse all href="xxx"
        preg_match_all('/href="([^"]+)"/i', $html, $matches);
        
        $items = [];
        foreach ($matches[1] as $candidate) {
            // skip ../ and ?
            if ($candidate === '../' || $candidate === './' || str_starts_with($candidate, '?')) {
                continue;
            }
            $items[] = $candidate;
        }
        
        return $items;
    }
    
    /**
     * Convert array of PeclBuildInfo objects => plain arrays for JSON cache
     */
    private function dehydrateBuilds(array $builds): array
    {
        $out = [];
        foreach ($builds as $b) {
            $out[] = [
                'extensionName' => $b->extensionName,
                'extensionVer'  => $b->extensionVer,
                'phpMajorMinor' => $b->phpMajorMinor,
                'isNts'         => $b->isNts,
                'isX64'         => $b->isX64,
                'vcVersion'     => $b->vcVersion,
                'dllFileName'   => $b->dllFileName,
                'downloadUrl'   => $b->downloadUrl,
            ];
        }
        return $out;
    }
    
    /**
     * Re-hydrate arrays => PeclBuildInfo objects
     */
    private function hydrateBuilds(array $arr): array
    {
        $out = [];
        foreach ($arr as $item) {
            $b = new PeclBuildInfo();
            $b->extensionName  = $item['extensionName'];
            $b->extensionVer   = $item['extensionVer'];
            $b->phpMajorMinor  = $item['phpMajorMinor'];
            $b->isNts          = $item['isNts'];
            $b->isX64          = $item['isX64'];
            $b->vcVersion      = $item['vcVersion'];
            $b->dllFileName    = $item['dllFileName'];
            $b->downloadUrl    = $item['downloadUrl'];
            $out[] = $b;
        }
        return $out;
    }
}
