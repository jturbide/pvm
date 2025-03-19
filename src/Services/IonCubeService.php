<?php

namespace PVM\Services;

use PVM\ConfigService;
use PVM\PhpBuildInfo;
use GuzzleHttp\Client;
use ZipArchive;

class IonCubeService
{
    protected ConfigService $configService;
    protected Client $httpClient;
    
    public function __construct(ConfigService $configService)
    {
        $this->configService = $configService;
        $this->httpClient = new Client();
    }
    
    public function installIonCubeLoader(PhpBuildInfo $buildInfo, string $requestedVersion = 'latest'): void
    {
        // 1) Determine IonCube download URL for this PHP version
        //    For example, from https://downloads.ioncube.com/loader_downloads/ioncube_loaders_win.zip
        //    Or from a versioned location you store in your own config/cached data.
        $downloadUrl = 'https://downloads.ioncube.com/loader_downloads/ioncube_loaders_win.zip';
        
        // 2) Download & save to temp file
        $tempZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ioncube_temp.zip';
        $this->downloadFile($downloadUrl, $tempZip);
        
        // 3) Extract the correct DLL from the .zip
        //    For instance: ioncube_loader_win_8.2.dll or the NTS x64 variant
        $extractedFile = $this->extractIoncubeDll($tempZip, $buildInfo);
        
        // 4) Copy .dll to the ext folder
        $extPath = $buildInfo->getInstallPath() . DIRECTORY_SEPARATOR . 'ext';
        if (!is_dir($extPath)) {
            mkdir($extPath, 0777, true);
        }
        
        $targetFile = $extPath . DIRECTORY_SEPARATOR . basename($extractedFile);
        copy($extractedFile, $targetFile);
        
        // 5) Add zend_extension= line to php.ini
        $iniPath = $buildInfo->getInstallPath() . DIRECTORY_SEPARATOR . 'php.ini';
        $this->addIniEntry($iniPath, 'zend_extension="' . basename($extractedFile) . '"');
        
        // 6) Store extension info in config.json
        $this->configService->addExtension(
            $buildInfo->getPackageName(),
            'ioncube',
            'latest', // or parse real version from the downloaded .zip
            basename($extractedFile),
            'zend_extension="' . basename($extractedFile) . '"'
        );
    }
    
    protected function downloadFile(string $url, string $destination): void
    {
        $response = $this->httpClient->get($url, ['sink' => $destination]);
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException("Error downloading IonCube loader from $url");
        }
    }
    
    protected function extractIoncubeDll(string $zipFile, PhpBuildInfo $buildInfo): string
    {
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new \RuntimeException("Failed to open IonCube zip archive.");
        }
        
        // Figure out correct DLL name. Suppose you want:
        //   - php version major.minor => e.g. 8.2 -> "ioncube_loader_win_8.2.dll"
        //   - if NTS => "ioncube_loader_win_8.2_ts.dll" vs "ioncube_loader_win_8.2_nts.dll"
        //   - or if x64 => might be _x86-64, etc.
        $phpMajorMinor = $buildInfo->getMajorMinor(); // e.g. "8.2"
        $isNts = $buildInfo->isNts();
        $isX64 = $buildInfo->isX64();
        
        // As a quick hack:
        $searchName = "ioncube_loader_win_" . $phpMajorMinor;
        if ($isNts) {
            $searchName .= "_nts";
        }
        if ($isX64) {
            $searchName .= "_x86-64"; // or some variant if that's what IonCube uses
        }
        $searchName .= ".dll";
        
        // Now find that file in the zip
        $extractedPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $searchName;
        $found = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (str_ends_with($stat['name'], $searchName)) {
                // Extract it
                $zip->extractTo(sys_get_temp_dir(), $stat['name']);
                rename(sys_get_temp_dir() . DIRECTORY_SEPARATOR . $stat['name'], $extractedPath);
                $found = true;
                break;
            }
        }
        $zip->close();
        
        if (!$found) {
            throw new \RuntimeException("Could not find the IonCube loader '$searchName' in the ZIP");
        }
        
        return $extractedPath;
    }
    
    protected function addIniEntry(string $iniPath, string $extensionLine): void
    {
        if (!file_exists($iniPath)) {
            throw new \RuntimeException("php.ini not found at $iniPath");
        }
        
        $contents = file_get_contents($iniPath);
        // If not already present, add to end of file or somewhere appropriate
        if (!str_contains($contents, $extensionLine)) {
            $contents .= PHP_EOL . $extensionLine . PHP_EOL;
            file_put_contents($iniPath, $contents);
        }
    }
}
