<?php

namespace PVM\Commands;

use GuzzleHttp\Client;
use PVM\Services\CacheService;
use PVM\Services\ConfigService;
use PVM\Services\RemoteVersionService;
use PVM\Services\PhpBuildInfo;
use PVM\Services\PeclService;
use PVM\Services\PeclBuildInfo;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use ZipArchive;

/**
 * pvm install php84
 * pvm install php84-phalcon5
 *
 * If installing a base package (php84), it prompts to upgrade if it's already installed
 * and a newer patch is available.
 *
 * If installing an extension (php84-phalcon5), it fetches from windows.php.net/pecl
 * (via PeclService), places the DLL in ext/, and updates php.ini and config.json.
 */
class InstallCommand extends Command
{
    protected static $defaultName = 'install';
    
    protected function configure()
    {
        $this
            ->setDescription('Install a base PHP package (e.g. php84) or a PECL extension (e.g. php84-phalcon5).')
            ->addArgument('package', InputArgument::REQUIRED, 'Package name to install')
            ->setHelp(
                <<<EOT
Install a base PHP version, e.g.:
  pvm install php84

Or install a PECL extension for that version, e.g.:
  pvm install php84-phalcon5
EOT
            );
    }
    
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io          = new SymfonyStyle($input, $output);
        $packageName = $input->getArgument('package');
        
        // Detect whether user wants a base package (like php84) or an extension (like php84-phalcon5).
        if (preg_match('/^(php\d{2})-(.+)$/', $packageName, $m)) {
            // e.g. "php84-phalcon5": extension scenario
            $basePackage = $m[1];   // "php84"
            $extNameId   = $m[2];   // "phalcon5"
            return $this->installExtension($basePackage, $extNameId, $io);
        } else {
            // base package scenario: e.g. "php84"
            return $this->installBasePackage($packageName, $io);
        }
    }
    
    /**
     * Install (or upgrade if available) a base package: e.g. php84
     */
    private function installBasePackage(string $packageName, SymfonyStyle $io): int
    {
        if (!preg_match('/^php(\d)(\d)$/', $packageName, $mmMatch)) {
            $io->error("Invalid base package name '$packageName'. Must be like php84, php82, etc.");
            return Command::FAILURE;
        }
        $majorMinor = $mmMatch[1] . '.' . $mmMatch[2]; // e.g. "8.4"
        
        $io->title("Installing $packageName (PHP $majorMinor)...");
        
        // 1) Retrieve the "best build" from RemoteVersionService
        $cache      = new CacheService();
        $remoteSvc  = new RemoteVersionService($cache);
        $bestBuilds = $remoteSvc->getLatestVersions(false);
        
        if (!isset($bestBuilds[$majorMinor])) {
            $io->error("No remote build found for PHP $majorMinor. Possibly not released.");
            return Command::FAILURE;
        }
        /** @var PhpBuildInfo $build */
        $build       = $bestBuilds[$majorMinor];
        $fullVersion = $build->fullVersion; // e.g. "8.4.4"
        
        $configSvc = new ConfigService();
        $config    = $configSvc->getConfig();
        
        // 2) Check if package is already installed
        if (isset($config['packages'][$packageName])) {
            // Already installed
            $installedPatch = $config['packages'][$packageName]['current_patch_version'] ?? '(unknown)';
            $installPath    = $config['packages'][$packageName]['install_path'] ?? '(missing)';
            
            // Compare versions
            if (preg_match('/^\d+\.\d+\.\d+$/', $installedPatch)
                && version_compare($installedPatch, $fullVersion, '<')
            ) {
                // A newer patch is available
                $io->section("Package '$packageName' is already installed (patch: $installedPatch).");
                $upgrade = $io->confirm("A newer patch ($fullVersion) is available. Upgrade in-place now?", false);
                
                if ($upgrade) {
                    try {
                        $this->doInPlaceUpgrade($build, $installPath, $io);
                        // Update config
                        $config['packages'][$packageName]['current_patch_version'] = $fullVersion;
                        $configSvc->setConfig($config);
                        $configSvc->saveConfig();
                        
                        $io->success("Upgraded $packageName from $installedPatch to $fullVersion successfully!");
                        return Command::SUCCESS;
                    } catch (RuntimeException $ex) {
                        $io->error("Upgrade failed: " . $ex->getMessage());
                        return Command::FAILURE;
                    }
                } else {
                    $io->text('User declined upgrade. Nothing done.');
                    return Command::SUCCESS;
                }
            } else {
                // installedPatch >= fullVersion => no upgrade
                $io->section("Package '$packageName' (patch: $installedPatch) is already installed.");
                $io->text("No newer patch found (remote patch = $fullVersion), so nothing to do.");
                return Command::SUCCESS;
            }
        }
        
        // 3) If not installed, do a fresh install
        $io->section("Fresh install of $packageName (PHP $fullVersion)...");
        try {
            $installPath = $this->doFreshInstall($packageName, $build, $io);
        } catch (RuntimeException $e) {
            $io->error("Installation failed: " . $e->getMessage());
            return Command::FAILURE;
        }
        
        // 4) Update config.json
        $io->section("Updating config.json...");
        $config['packages'][$packageName] = [
            'current_patch_version' => $fullVersion,
            'install_path'          => $installPath,
            'extensions'            => []
        ];
        $configSvc->setConfig($config);
        $configSvc->saveConfig();
        
        $io->success("$packageName (PHP $fullVersion) installed successfully!");
        return Command::SUCCESS;
    }
    
    /**
     * Fresh install logic for base packages: download + extract to packages/phpXX
     */
    private function doFreshInstall(string $packageName, PhpBuildInfo $build, SymfonyStyle $io): string
    {
        $fullVersion = $build->fullVersion;
        $downloadUrl = $build->downloadUrl;
        
        $io->section("Downloading $downloadUrl ...");
        
        $tempZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . "php-{$fullVersion}-" . uniqid() . ".zip";
        
        $client = new Client();
        // HEAD check
        try {
            $head = $client->head($downloadUrl);
            if ($head->getStatusCode() !== 200) {
                throw new RuntimeException("Server returned status code {$head->getStatusCode()} for $downloadUrl");
            }
        } catch (\Exception $e) {
            throw new RuntimeException("HEAD request failed: " . $e->getMessage());
        }
        
        $io->text("Saving to $tempZip");
        try {
            $client->request('GET', $downloadUrl, ['sink' => $tempZip]);
        } catch (\Exception $e) {
            throw new RuntimeException("Download failed: " . $e->getMessage());
        }
        
        if (!file_exists($tempZip) || filesize($tempZip) < 10000) {
            throw new RuntimeException("Downloaded file is too small or invalid. Possibly 404 or a bad build?");
        }
        
        $rootPath    = realpath(__DIR__ . '/../../');
        $installPath = $rootPath . DIRECTORY_SEPARATOR . 'packages' . DIRECTORY_SEPARATOR . $packageName;
        if (!is_dir($installPath)) {
            mkdir($installPath, 0777, true);
        }
        
        $io->section("Extracting to $installPath...");
        
        $zip = new ZipArchive();
        if ($zip->open($tempZip) !== true) {
            throw new RuntimeException("Failed to open zip archive at $tempZip");
        }
        $zip->extractTo($installPath);
        $zip->close();
        
        // rename php.ini-production => php.ini
        $iniProduction = $installPath . DIRECTORY_SEPARATOR . 'php.ini-production';
        $iniFile       = $installPath . DIRECTORY_SEPARATOR . 'php.ini';
        if (file_exists($iniProduction) && !file_exists($iniFile)) {
            rename($iniProduction, $iniFile);
        }
        
        return $installPath;
    }
    
    /**
     * In-place upgrade for a base package. Overwrites .exe/.dll, skipping php.ini
     */
    private function doInPlaceUpgrade(PhpBuildInfo $build, string $installPath, SymfonyStyle $io): void
    {
        if (!is_dir($installPath)) {
            throw new RuntimeException("Install path does not exist: $installPath");
        }
        
        $io->text("Downloading patch from {$build->downloadUrl}...");
        $tempZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'pvm-upgrade-' . uniqid() . '.zip';
        
        $client = new Client();
        try {
            $head = $client->head($build->downloadUrl);
            if ($head->getStatusCode() !== 200) {
                throw new RuntimeException("Server returned status code {$head->getStatusCode()}");
            }
        } catch (\Exception $e) {
            throw new RuntimeException("HEAD request failed: " . $e->getMessage());
        }
        
        try {
            $client->request('GET', $build->downloadUrl, ['sink' => $tempZip]);
        } catch (\Exception $e) {
            throw new RuntimeException("Download failed: " . $e->getMessage());
        }
        
        if (!file_exists($tempZip) || filesize($tempZip) < 10000) {
            throw new RuntimeException("Downloaded file is too small or invalid.");
        }
        
        $io->text("Extracting to $installPath (overwriting existing, skipping php.ini)...");
        $zip = new ZipArchive();
        if ($zip->open($tempZip) !== true) {
            throw new RuntimeException("Failed to open zip archive at $tempZip");
        }
        
        // Overwrite everything except an existing php.ini
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!$stat) {
                continue;
            }
            $relativePath = $stat['name'];
            if (strtolower($relativePath) === 'php.ini') {
                // skip overwriting user’s php.ini
                continue;
            }
            $dest = rtrim($installPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relativePath;
            if (substr($relativePath, -1) === '/') {
                @mkdir($dest, 0777, true);
                continue;
            }
            $parent = dirname($dest);
            if (!is_dir($parent)) {
                mkdir($parent, 0777, true);
            }
            $contents = $zip->getFromIndex($i);
            file_put_contents($dest, $contents);
        }
        $zip->close();
        
        $io->text("In-place upgrade complete. Existing php.ini preserved.");
    }
    
    /**
     * Extension-install logic if user typed something like "php84-phalcon5".
     */
    private function installExtension(string $basePackageName, string $extIdentifier, SymfonyStyle $io): int
    {
        $io->title("Installing extension '$extIdentifier' for $basePackageName...");
        
        // 1) Load config, ensure base package is installed
        $configSvc = new ConfigService();
        $config    = $configSvc->getConfig();
        if (!isset($config['packages'][$basePackageName])) {
            $io->error("Base package '$basePackageName' is not installed. Install it first.");
            return Command::FAILURE;
        }
        
        // 2) Derive major.minor from "php82"
        if (!preg_match('/^php(\d)(\d)$/', $basePackageName, $mm)) {
            $io->error("Cannot parse major.minor from $basePackageName");
            return Command::FAILURE;
        }
        $phpMajorMinor = $mm[1] . '.' . $mm[2]; // e.g. "8.2"
        
        // 3) Parse the extension name and version out of something like "phalcon5.8.0".
        // Instead of naive 'preg_replace("/\d+$/", "", ...)', do something robust:
        $extensionName = '';
        $extVerReq     = '';
        
        // e.g. match: ^(phalcon)(5.8.0)$ capturing the name vs. version
        if (preg_match('/^(?<name>[a-zA-Z_]+)(?<ver>\d+\.\d+\.\d+(?:\.\d+)?)$/', $extIdentifier, $m2)) {
            $extensionName = strtolower($m2['name']); // "phalcon"
            $extVerReq     = $m2['ver'];             // "5.8.0"
        } else {
            // fallback: maybe user typed "phalcon5.8" or "phalconX"
            // you can adapt or error out if not recognized
            $io->error("Could not parse extension name/version from '$extIdentifier'. Expected something like 'phalcon5.8.0'.");
            return Command::FAILURE;
        }
        
        // 4) We'll assume the base package is NTS, x64, vs17.
        //    In a real scenario, parse from config or from the original build.
        //    We'll do naive approach for demonstration:
        $baseIsNts  = true;
        $baseIsX64  = true;
        $baseVcVer  = 17;   // Or 16, etc.
        
        // 5) Use PeclService to find possible builds
        $peclSvc   = new PeclService(new CacheService());
        $allBuilds = $peclSvc->getExtensionBuilds($extensionName, null, false);
        
        // Filter for builds that match:
        // - extensionVer starts with extVerReq (if provided),
        // - phpMajorMinor,
        // - isNts, isX64, vcVersion, etc.
        $filtered = array_filter($allBuilds, function (PeclBuildInfo $b) use ($phpMajorMinor, $baseIsNts, $baseIsX64, $baseVcVer, $extVerReq) {
            
            if ($b->phpMajorMinor !== $phpMajorMinor) {
                return false;
            }
            if ($b->isNts !== $baseIsNts) {
                return false;
            }
            if ($b->isX64 !== $baseIsX64) {
                return false;
            }
//            if ($b->vcVersion !== $baseVcVer) { // @todo
//                return false;
//            }
            if ($extVerReq !== '' && !str_starts_with($b->extensionVer, $extVerReq)) {
                return false;
            }
            return true;
        });
        
        if (empty($filtered)) {
            $io->error("No matching PECL build found for extension '$extensionName', version '$extVerReq' on PHP $phpMajorMinor (NTS x64 vs$baseVcVer).");
            return Command::FAILURE;
        }
        
        // pick the highest extension version
        usort($filtered, fn(PeclBuildInfo $a, PeclBuildInfo $b) => version_compare($b->extensionVer, $a->extensionVer));
        $chosen = $filtered[0];
        $io->section("Chosen build: extension version {$chosen->extensionVer}, file: {$chosen->dllFileName}");
        
        // 6) Download => \ext\
        $installPath = $config['packages'][$basePackageName]['install_path'];
        $extDir      = $installPath . DIRECTORY_SEPARATOR . 'ext';
        if (!is_dir($extDir)) {
            mkdir($extDir, 0777, true);
        }
        
        $io->text("Downloading from {$chosen->downloadUrl}");
        $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . basename($chosen->dllFileName);
        
        $client = new Client();
        try {
            $client->request('GET', $chosen->downloadUrl, ['sink' => $tempFile]);
        } catch (\Exception $e) {
            $io->error("Download failed: " . $e->getMessage());
            return Command::FAILURE;
        }
        
        // If it's a ZIP, we must extract the .dll. Otherwise, rename the .dll directly:
        if (preg_match('/\.zip$/i', $chosen->dllFileName)) {
            $io->text("Extracting .dll from zip...");
            $zip = new ZipArchive();
            if ($zip->open($tempFile) !== true) {
                $io->error("Failed to open zip: $tempFile");
                return Command::FAILURE;
            }
            $foundDll = false;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $st = $zip->statIndex($i);
                if ($st && preg_match('/\.dll$/i', $st['name'])) {
                    $dllContents = $zip->getFromIndex($i);
                    $targetName  = basename($st['name']);
                    file_put_contents($extDir . DIRECTORY_SEPARATOR . $targetName, $dllContents);
                    $foundDll = true;
                }
            }
            $zip->close();
            if (!$foundDll) {
                $io->error("No .dll found inside zip file!");
                return Command::FAILURE;
            }
        } else {
            // direct .dll
            rename($tempFile, $extDir . DIRECTORY_SEPARATOR . basename($chosen->dllFileName));
        }
        
        // 7) Add "extension=xxx.dll" to php.ini
        $iniPath = $installPath . DIRECTORY_SEPARATOR . 'php.ini';
        if (!file_exists($iniPath)) {
            $io->warning("php.ini not found at $iniPath. Please enable the extension manually.");
        } else {
            $extLine = 'extension="' . basename($chosen->dllFileName) . '"';
            file_put_contents($iniPath, PHP_EOL . $extLine . PHP_EOL, FILE_APPEND);
            $io->text("Appended $extLine to php.ini");
        }
        
        // 8) Add to config
        $extKey = $basePackageName . '-' . $extIdentifier;
        $config['packages'][$basePackageName]['extensions'][$extKey] = [
            'pecl_name'         => $extensionName,
            'requested_version' => $extVerReq,
            'installed_version' => $chosen->extensionVer,
            'dll_file'          => basename($chosen->dllFileName),
            'ini_entry'         => 'extension="' . basename($chosen->dllFileName) . '"',
            'date_installed'    => date('Y-m-d H:i:s'),
        ];
        
        $configSvc->setConfig($config);
        $configSvc->saveConfig();
        
        $io->success("Installed extension '$extIdentifier' (v{$chosen->extensionVer}) for $basePackageName successfully!");
        return Command::SUCCESS;
    }
    
    
    /**
     * Installs ionCube Loader zend extension.
     *
     * @param string $phpMajorMinor The PHP version in major.minor format (e.g., "8.0").
     * @param bool $baseIsX64 Whether the system is x64.
     * @param string $installPath Path to the PHP installation.
     * @param object $io Input/Output interface for messaging.
     * @param object $configSvc Configuration service managing the config.
     * @param object $client HTTP Client for downloading files.
     *
     * @return int Command::SUCCESS or Command::FAILURE.
     */
    private function installIoncubeLoader(string $phpMajorMinor, bool $baseIsX64, string $installPath, $io, $configSvc, $client): int
    {
        $io->section("Installing ionCube Loader...");
        
        // Define the expected parameters for downloading the ionCube Loader
        $ionCubePhpVersion = str_replace('.', '', $phpMajorMinor);
        $ionCubePlatform = $baseIsX64 ? 'x86-64' : 'x86'; // Assume 64-bit if $baseIsX64
        $ionCubeFileName = "ioncube_loader_win_{$ionCubePhpVersion}.dll";
        $ionCubeBaseUrl = "https://downloads.ioncube.com/loader_downloads/";
        
        $ionCubeDownloadUrl = $ionCubeBaseUrl . $ionCubeFileName;
        $tempIonCubeFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $ionCubeFileName;
        
        $io->text("Downloading ionCube Loader from {$ionCubeDownloadUrl}...");
        try {
            $client->request('GET', $ionCubeDownloadUrl, ['sink' => $tempIonCubeFile]);
        }
        catch (\Exception $e) {
            $io->error("Failed to download ionCube Loader: " . $e->getMessage());
            return Command::FAILURE;
        }
        
        $extDir = $installPath . DIRECTORY_SEPARATOR . 'ext';
        $targetIonCubePath = $extDir . DIRECTORY_SEPARATOR . $ionCubeFileName;
        
        if (!is_dir($extDir)) {
            mkdir($extDir, 0777, true);
        }
        
        if (!rename($tempIonCubeFile, $targetIonCubePath)) {
            $io->error("Failed to move ionCube Loader to target directory: $targetIonCubePath");
            return Command::FAILURE;
        }
        
        $io->text("Successfully downloaded ionCube Loader to $targetIonCubePath.");
        
        // Add the ionCube Loader entry to php.ini
        $iniPath = $installPath . DIRECTORY_SEPARATOR . 'php.ini';
        if (!file_exists($iniPath)) {
            $io->warning("php.ini not found at $iniPath. Please enable the ionCube Loader manually.");
        }
        else {
            $ionCubeIniEntry = 'zend_extension="' . $ionCubeFileName . '"';
            file_put_contents($iniPath, PHP_EOL . $ionCubeIniEntry . PHP_EOL, FILE_APPEND);
            $io->text("Appended $ionCubeIniEntry to php.ini");
        }
        
        // Log the installation in the configuration
        $config = $configSvc->getConfig();
        $config['packages']['ioncube_loader'] = [
            'zend_extension' => $ionCubeFileName,
            'dll_file' => $ionCubeFileName,
            'ini_entry' => $ionCubeIniEntry,
            'date_installed' => date('Y-m-d H:i:s'),
        ];
        
        $configSvc->setConfig($config);
        $configSvc->saveConfig();
        
        $io->success("ionCube Loader successfully installed for PHP $phpMajorMinor!");
        
        return Command::SUCCESS;
    }
}
