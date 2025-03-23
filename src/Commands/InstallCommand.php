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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use ZipArchive;

/**
 * pvm install php82
 * pvm install php82 --ts --arch=x64 --vc=17
 * pvm install php82-redis5.3.7
 *
 * Key Features:
 * 1) Multiple variants for the same major.minor => stored as config keys like "php82-nts-x64-vc16".
 *    So you can have "php82-nts-x64-vc16" and "php82-ts-x64-vc17" at the same time.
 * 2) Interactive menu if user doesn't specify --ts/--nts, --arch, --vc, letting them pick from discovered builds.
 * 3) In-place upgrade if a newer patch is available for that variant key.
 * 4) Extension installs:
 *    - e.g. "pvm install php82-redis5.3.7"
 *    - If multiple variants exist for "php82", we ask which one to attach to.
 *    - We pick the correct .dll from PECL by matching TS vs. NTS, x64 vs. x86, same VC.
 */
class InstallCommand extends Command
{
    public function __construct()
    {
        parent::__construct('install');
    }
    
    protected function configure(): void
    {
        $this
            ->setDescription('Install a base PHP version or a PECL extension (supporting multiple side-by-side variants).')
            ->addArgument('package', InputArgument::REQUIRED, 'Package name (e.g. php82, or php82-redis5.3.7)')
            ->addOption('ts', null, InputOption::VALUE_NONE, 'Install Thread-Safe build')
            ->addOption('nts', null, InputOption::VALUE_NONE, 'Install Non-Thread-Safe build (default if omitted)')
            ->addOption('arch', null, InputOption::VALUE_REQUIRED, 'Architecture: x64 or x86 (default x64)')
            ->addOption('vc', null, InputOption::VALUE_REQUIRED, 'Compiler version: 15, 16, 17, etc.')
            ->setHelp(<<<EOT
Examples:

  # Base install with interactive selection of variant if multiple are found
  pvm install php82

  # Force TS, x64, VC=17:
  pvm install php82 --ts --arch=x64 --vc=17

  # If you do so, you'll end up with a config key like "php82-ts-x64-vc17"

  # Installing a PECL extension for that variant:
  pvm install php82-redis5.3.7

  # If multiple "php82-..." variants exist, you'll be prompted which one to attach the extension to.
EOT
            );
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $packageName = $input->getArgument('package');
        
        // extension scenario => "php82-xxx"
        if (preg_match('/^(php\d{2})-(.+)$/', $packageName, $m)) {
            $baseName  = $m[1]; // e.g. "php82"
            $extString = $m[2]; // e.g. "redis5.3.7"
            return $this->installExtension($baseName, $extString, $io, $input);
        } else {
            // base scenario => "php82"
            return $this->installBasePackage($packageName, $io, $input);
        }
    }
    
    /**
     * BASE PACKAGE INSTALL (supports multiple variants).
     */
    private function installBasePackage(string $packageName, SymfonyStyle $io, InputInterface $input): int
    {
        // parse major.minor from php(\d)(\d)
        if (!preg_match('/^php(\d)(\d)$/', $packageName, $mm)) {
            $io->error("Invalid base package name '$packageName'. Must be like php82, php81, etc.");
            return Command::FAILURE;
        }
        $majorMinor = $mm[1].'.'.$mm[2]; // e.g. "8.2"
        
        $io->title("Installing $packageName (PHP $majorMinor) with side-by-side variant support...");
        
        // gather user flags
        $flagTs  = (bool)$input->getOption('ts');
        $flagNts = (bool)$input->getOption('nts');
        if ($flagTs && $flagNts) {
            $io->error("Cannot specify both --ts and --nts.");
            return Command::FAILURE;
        }
        
        // If neither is specified => default to NTS
        $isTs = $flagTs ? true : false;
        if (!$flagTs && !$flagNts) {
            // user didn't specify => default NTS
            $isTs = false;
        }
        
        $arch = $input->getOption('arch') ?: '';
        if ($arch && !in_array($arch,['x64','x86'], true)) {
            $io->error("Invalid --arch value '$arch'. Must be 'x64' or 'x86'.");
            return Command::FAILURE;
        }
        // default to x64
        if (!$arch) {
            $arch = 'x64';
        }
        
        $vcOption = $input->getOption('vc');
        $vcWanted = $vcOption ? (int)$vcOption : null;
        
        // gather all builds from RemoteVersionService
        $baseDir = $input->getOption('base-dir');
        $cache     = new CacheService($baseDir);
        $remoteSvc = new RemoteVersionService($cache);
        $allBuilds = $remoteSvc->getAllBuilds(false);
        
        // filter to major.minor
        $matchingMm = array_filter($allBuilds, fn(PhpBuildInfo $b) => $b->majorMinor===$majorMinor);
        if (empty($matchingMm)) {
            $io->error("No remote builds found for PHP $majorMinor.");
            return Command::FAILURE;
        }
        
        // group by (isNts, arch, vc)
        $byVariant = [];
        foreach ($matchingMm as $b) {
            $variantKey = ($b->isNts?'NTS':'TS').'|'.($b->isX64?'x64':'x86').'|'.$b->vcVersion;
            $byVariant[$variantKey][] = $b;
        }
        
        // sort each subarray by patch desc
        foreach ($byVariant as $k => $list) {
            usort($list, fn($a,$b)=>version_compare($b->fullVersion, $a->fullVersion));
            $byVariant[$k] = $list;
        }
        
        // If user provided flags => skip the prompt, pick a matching variant
        if ($flagTs || $flagNts || $arch!=='x64' || $vcWanted!==null) {
            // filter by those flags
            $chosenBuild = $this->pickVariantDirect($byVariant, $isTs, $arch, $vcWanted, $io);
            if (!$chosenBuild) {
                return Command::FAILURE; // error was printed inside pickVariantDirect
            }
            // we have a single build => do the final install/upgrade
            return $this->installOrUpgradeVariant($majorMinor, $isTs, ($arch==='x64'), $chosenBuild->vcVersion, $chosenBuild, $io);
        } else {
            // no flags => interactive approach
            return $this->pickVariantInteractive($majorMinor, $byVariant, $io);
        }
    }
    
    /**
     * Filter variants by user-chosen TS vs. NTS, arch, vcWanted if any,
     * then pick the highest patch among them.
     */
    private function pickVariantDirect(array $byVariant, bool $isTs, string $arch, ?int $vcWanted, SymfonyStyle $io): ?PhpBuildInfo
    {
        $matches = [];
        foreach ($byVariant as $k => $list) {
            [$tsLabel, $archLabel, $vcString] = explode('|', $k);
            $vcNum = (int)$vcString;
            // compare TS
            if ($isTs && $tsLabel!=='TS') continue;
            if (!$isTs && $tsLabel!=='NTS') continue;
            // compare arch
            if ($archLabel!==$arch) continue;
            // compare vc
            if ($vcWanted!==null && $vcNum!==$vcWanted) continue;
            
            // pick the newest patch from $list[0]
            $matches[] = $list[0];
        }
        
        if (empty($matches)) {
            $io->error("No build matches your flags: TS=".($isTs?'true':'false').", arch=$arch, vc=".($vcWanted ?? 'any'));
            return null;
        }
        // pick the highest patch among matches
        usort($matches, fn($a,$b)=>version_compare($b->fullVersion,$a->fullVersion));
        return $matches[0];
    }
    
    /**
     * If the user didn't specify any flags, we show them a choice of distinct variants,
     * defaulting to "NTS / x64 / highest VC" if present. Once they pick, we install or upgrade.
     */
    private function pickVariantInteractive(string $majorMinor, array $byVariant, SymfonyStyle $io): int
    {
        // build a menu
        $menuItems = [];
        $defaultIndex = 0;
        $bestVc = 0;
        $i=0;
        foreach ($byVariant as $k => $list) {
            [$tsLabel, $archLabel, $vcString] = explode('|', $k);
            $vcNum = (int)$vcString;
            // newest patch for that group => $list[0]
            $build = $list[0];
            $menuItems[] = [
                'tsLabel' => $tsLabel,
                'arch'    => $archLabel,
                'vcNum'   => $vcNum,
                'build'   => $build,
            ];
            // default pick => NTS + x64 + highest VC
            if ($tsLabel==='NTS' && $archLabel==='x64' && $vcNum>=$bestVc) {
                $bestVc = $vcNum;
                $defaultIndex = $i;
            }
            $i++;
        }
        
        // if there's only 1 variant total, skip the prompt
        if (count($menuItems)===1) {
            $only = $menuItems[0];
            $io->text("Only one variant found: {$only['tsLabel']} / {$only['arch']} / VC={$only['vcNum']} => patch=".$only['build']->fullVersion);
            return $this->installOrUpgradeVariant(
                $majorMinor,
                ($only['tsLabel']==='TS'),
                ($only['arch']==='x64'),
                $only['vcNum'],
                $only['build'],
                $io
            );
        }
        
        // build choices
        $choices = [];
        foreach ($menuItems as $idx => $mi) {
            $choices[] = "#$idx => {$mi['tsLabel']} / {$mi['arch']} / VC={$mi['vcNum']} (patch=".$mi['build']->fullVersion.")";
        }
        $defaultChoice = $choices[$defaultIndex];
        $selected = $io->choice(
            "Multiple variants found for PHP $majorMinor. Pick one:",
            $choices,
            $defaultChoice
        );
        // parse index
        if (!preg_match('/^#(\d+)/', $selected, $m)) {
            $idx = $defaultIndex;
        } else {
            $idx = (int)$m[1];
        }
        if (!isset($menuItems[$idx])) {
            $io->error("Invalid choice index $idx");
            return Command::FAILURE;
        }
        
        $pick = $menuItems[$idx];
        return $this->installOrUpgradeVariant(
            $majorMinor,
            ($pick['tsLabel']==='TS'),
            ($pick['arch']==='x64'),
            $pick['vcNum'],
            $pick['build'],
            $io
        );
    }
    
    /**
     * After deciding on a single build (patch version), we generate a unique config key,
     * e.g. "php82-ts-x64-vc17", and either do a fresh install or an in-place upgrade
     * if that config key is already installed with an older patch.
     */
    private function installOrUpgradeVariant(
        string $majorMinor,
        bool $isTs,
        bool $isX64,
        int $vcVer,
        PhpBuildInfo $build,
        SymfonyStyle $io,
        InputInterface $input,
    ): int {
        $fullVersion = $build->fullVersion;
        
        // create a config key => "php82-ts-x64-vc17" etc.
        $variantKey = $this->makeVariantKey($majorMinor, $isTs, $isX64, $vcVer);
        $io->section("Chosen variant key: $variantKey (patch=$fullVersion)");
        
        $baseDir = $input->getOption('base-dir');
        $configSvc = new ConfigService($baseDir);
        $config    = $configSvc->getConfig();
        
        // check if it's already installed
        if (isset($config['packages'][$variantKey])) {
            // see if we need to upgrade
            $installedPatch = $config['packages'][$variantKey]['current_patch_version'] ?? '(unknown)';
            $installPath    = $config['packages'][$variantKey]['install_path'] ?? '(missing)';
            
            if (preg_match('/^\d+\.\d+\.\d+$/', $installedPatch) && version_compare($installedPatch, $fullVersion, '<')) {
                $io->section("Variant '$variantKey' is already installed (patch=$installedPatch).");
                $upgrade = $io->confirm("A newer patch ($fullVersion) is available. Upgrade in-place?", false);
                if ($upgrade) {
                    try {
                        $this->doInPlaceUpgrade($build, $installPath, $io);
                        $config['packages'][$variantKey]['current_patch_version'] = $fullVersion;
                        $configSvc->setConfig($config);
                        $configSvc->saveConfig();
                        
                        $io->success("Upgraded $variantKey from $installedPatch to $fullVersion!");
                        return Command::SUCCESS;
                    } catch (RuntimeException $ex) {
                        $io->error("Upgrade failed: ".$ex->getMessage());
                        return Command::FAILURE;
                    }
                } else {
                    $io->text("User declined upgrade. Nothing done.");
                    return Command::SUCCESS;
                }
            } else {
                $io->section("Variant '$variantKey' (patch=$installedPatch) is already installed.");
                $io->text("No newer patch found (remote=$fullVersion).");
                return Command::SUCCESS;
            }
        }
        
        // fresh install
        $io->section("Fresh install of $variantKey (PHP $fullVersion)...");
        try {
            $installPath = $this->doFreshInstall($variantKey, $build, $io, $input);
        } catch (\Exception $e) {
            $io->error("Installation failed: ".$e->getMessage());
            return Command::FAILURE;
        }
        
        // store in config
        $io->section("Updating config.json...");
        $config['packages'][$variantKey] = [
            'current_patch_version' => $fullVersion,
            'install_path'          => $installPath,
            'extensions'            => [],
            'is_ts'                 => $isTs,
            'is_x64'                => $isX64,
            'compiler_version'      => $vcVer
        ];
        $configSvc->setConfig($config);
        $configSvc->saveConfig();
        
        $io->success("Installed variant '$variantKey' (PHP $fullVersion) successfully!");
        return Command::SUCCESS;
    }
    
    /**
     * Creates a config key like "php82-ts-x64-vc17".
     */
    private function makeVariantKey(string $majorMinor, bool $isTs, bool $isX64, int $vcVer): string
    {
        // "8.2" => "82"
        $parts = explode('.', $majorMinor);
        $mmTag = $parts[0].$parts[1]; // "82"
        $tsTag = $isTs ? 'ts' : 'nts';
        $archTag = $isX64 ? 'x64' : 'x86';
        return "php{$mmTag}-{$tsTag}-{$archTag}-vc{$vcVer}";
    }
    
    /**
     * Actually download + extract the new build into packages/<variantKey>.
     */
    private function doFreshInstall(string $variantKey, PhpBuildInfo $build, SymfonyStyle $io, InputInterface $input): string
    {
        $downloadUrl = $build->downloadUrl;
        $fullVersion= $build->fullVersion;
        
        $io->section("Downloading $downloadUrl ...");
        $tempZip = sys_get_temp_dir().DIRECTORY_SEPARATOR."php-{$fullVersion}-".uniqid().".zip";
        
        $client = new Client();
        try {
            $head = $client->head($downloadUrl);
            if ($head->getStatusCode()!==200) {
                throw new RuntimeException("Server returned code {$head->getStatusCode()} for $downloadUrl");
            }
        } catch (\Exception $e) {
            throw new RuntimeException("HEAD request failed: ".$e->getMessage());
        }
        
        $io->text("Saving to $tempZip");
        try {
            $client->request('GET', $downloadUrl, ['sink'=>$tempZip]);
        } catch (\Exception $e) {
            throw new RuntimeException("Download failed: ".$e->getMessage());
        }
        if (!file_exists($tempZip) || filesize($tempZip)<10000) {
            throw new RuntimeException("Downloaded file is too small or invalid.");
        }
        
        $baseDir = $input->getOption('base-dir');
        $installPath = $baseDir . DIRECTORY_SEPARATOR . '.pvm' . DIRECTORY_SEPARATOR . 'packages' . DIRECTORY_SEPARATOR . $variantKey;
        if (!is_dir($installPath)) {
            mkdir($installPath, 0777, true);
        }
        
        $io->section("Extracting to $installPath...");
        $zip = new ZipArchive();
        if ($zip->open($tempZip)!==true) {
            throw new RuntimeException("Failed to open zip archive.");
        }
        $zip->extractTo($installPath);
        $zip->close();
        
        // rename php.ini-production => php.ini
        $iniProduction = $installPath.DIRECTORY_SEPARATOR.'php.ini-production';
        $iniFile       = $installPath.DIRECTORY_SEPARATOR.'php.ini';
        if (file_exists($iniProduction) && !file_exists($iniFile)) {
            rename($iniProduction, $iniFile);
        }
        
        return $installPath;
    }
    
    /**
     * Overwrites .exe/.dll, skipping php.ini
     */
    private function doInPlaceUpgrade(PhpBuildInfo $build, string $installPath, SymfonyStyle $io): void
    {
        if (!is_dir($installPath)) {
            throw new RuntimeException("Install path does not exist: $installPath");
        }
        
        $io->text("Downloading patch from {$build->downloadUrl}...");
        $tempZip = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pvm-upgrade-'.uniqid().'.zip';
        
        $client = new Client();
        try {
            $head = $client->head($build->downloadUrl);
            if ($head->getStatusCode()!==200) {
                throw new RuntimeException("Server returned code {$head->getStatusCode()}");
            }
        } catch (\Exception $e) {
            throw new RuntimeException("HEAD request failed: ".$e->getMessage());
        }
        
        try {
            $client->request('GET', $build->downloadUrl, ['sink'=>$tempZip]);
        } catch (\Exception $e) {
            throw new RuntimeException("Download failed: ".$e->getMessage());
        }
        
        if (!file_exists($tempZip) || filesize($tempZip)<10000) {
            throw new RuntimeException("Downloaded file is too small or invalid.");
        }
        
        $io->text("Extracting to $installPath (overwriting, skipping php.ini)...");
        $zip = new ZipArchive();
        if ($zip->open($tempZip)!==true) {
            throw new RuntimeException("Failed to open zip archive.");
        }
        
        for($i=0; $i<$zip->numFiles; $i++){
            $stat = $zip->statIndex($i);
            if (!$stat) continue;
            $relativePath = $stat['name'];
            if (strtolower($relativePath)==='php.ini') {
                continue;
            }
            $dest = rtrim($installPath,'/\\').DIRECTORY_SEPARATOR.$relativePath;
            if (substr($relativePath, -1)==='/') {
                @mkdir($dest, 0777, true);
                continue;
            }
            $parent = dirname($dest);
            if (!is_dir($parent)) mkdir($parent, 0777, true);
            $contents = $zip->getFromIndex($i);
            file_put_contents($dest, $contents);
        }
        $zip->close();
        $io->text("In-place upgrade complete. Existing php.ini preserved.");
    }
    
    /**
     * EXTENSION INSTALL => e.g. "php82-redis5.3.7"
     * We find all config keys that match "php82-(nts|ts)-(x64|x86)-vc(\d+)", if multiple, ask user.
     * Then parse is_ts, is_x64, compiler_version, do a pecl install matching those.
     */
    private function installExtension(string $baseName, string $extIdentifier, SymfonyStyle $io, InputInterface $input): int
    {
        $io->title("Installing extension '$extIdentifier' for $baseName (multiple variants supported).");
        
        // find all packages that start with e.g. "php82-nts-x64-vc", "php82-ts-x64-vc", etc.
        // We do a pattern like: ^(php82)-(nts|ts)-(x64|x86)-vc(\d+)$
        $baseDir = $input->getOption('base-dir');
        $configSvc = new ConfigService($baseDir);
        $config = $configSvc->getConfig();
        $packages = $config['packages'] ?? [];
        
        // e.g. "php82" => we want to find config keys that match ^php82-(nts|ts)-(x64|x86)-vc\d+$
        $regex = '/^'.$baseName.'-(nts|ts)-(x64|x86)-vc(\d+)$/';
        
        $foundKeys = [];
        foreach ($packages as $key => $info) {
            if (preg_match($regex, $key)) {
                $foundKeys[] = $key;
            }
        }
        
        if (empty($foundKeys)) {
            $io->error("No installed variants found for $baseName. Install one first (e.g. pvm install $baseName).");
            return Command::FAILURE;
        }
        $variantKey = '';
        if (count($foundKeys)===1) {
            // only one
            $variantKey = $foundKeys[0];
            $io->text("Found exactly one variant: $variantKey");
        } else {
            // ask user
            $variantKey = $io->choice(
                "Multiple variants found for $baseName. Which one do you want to attach '$extIdentifier' to?",
                $foundKeys,
                $foundKeys[0]
            );
        }
        
        if (!isset($packages[$variantKey])) {
            $io->error("Config mismatch: selected $variantKey not found?!");
            return Command::FAILURE;
        }
        
        // parse that variant's info
        $varInfo   = $packages[$variantKey];
        $baseIsTs  = !empty($varInfo['is_ts']);
        $baseIsX64 = !empty($varInfo['is_x64']);
        $baseVcVer = $varInfo['compiler_version'] ?? 0;
        $installPath= $varInfo['install_path'] ?? null;
        if (!$installPath || !is_dir($installPath)) {
            $io->error("Install path for '$variantKey' is missing or invalid: $installPath");
            return Command::FAILURE;
        }
        
        // parse extension name & version => e.g. "redis5.3.7"
        $extName = '';
        $extVer  = '';
        if (preg_match('/^(?<name>[a-zA-Z_]+)(?<ver>\d+\.\d+\.\d+(?:\.\d+)?)$/', $extIdentifier, $m2)) {
            $extName = strtolower($m2['name']);
            $extVer  = $m2['ver'];
        } else {
            $io->error("Could not parse extension name/version from '$extIdentifier' (e.g. 'redis5.3.7').");
            return Command::FAILURE;
        }
        
        // use PeclService to find extension builds
        $baseDir = $input->getOption('base-dir');
        $peclSvc   = new PeclService(new CacheService($baseDir));
        $allExtBuilds = $peclSvc->getExtensionBuilds($extName, null, false);
        
        // derive major.minor => from e.g. "php82" => "8.2"
        if (!preg_match('/^php(\d)(\d)$/', $baseName, $mm)) {
            $io->error("Cannot parse major.minor from $baseName");
            return Command::FAILURE;
        }
        $phpMajorMinor = $mm[1].'.'.$mm[2];
        
        // filter for matching TS vs NTS, x64 vs x86, vcVersion, same phpMajorMinor
        // if baseIsTs => we need $b->isNts==false
        $filtered = array_filter($allExtBuilds, function (PeclBuildInfo $b) use ($baseIsTs, $baseIsX64, $baseVcVer, $phpMajorMinor, $extVer) {
            // if baseIsTs => $b->isNts == false
            if ($baseIsTs && $b->isNts) return false;
            if (!$baseIsTs && !$b->isNts) return false;
            
            if ($b->phpMajorMinor !== $phpMajorMinor) return false;
            if ($b->isX64 !== $baseIsX64) return false;
            if ($b->vcVersion !== (int)$baseVcVer) return false;
            
            // extension version partial match
            if (!str_starts_with($b->extensionVer, $extVer)) return false;
            return true;
        });
        
        if (empty($filtered)) {
            $io->error("No matching PECL build found for '$extIdentifier' with variant '$variantKey'.");
            return Command::FAILURE;
        }
        // pick highest extension version
        usort($filtered, fn($a,$b)=>version_compare($b->extensionVer,$a->extensionVer));
        $chosen = $filtered[0];
        
        $io->section("Chosen extension build => version={$chosen->extensionVer}, file={$chosen->dllFileName}");
        // download => ext dir
        $extDir = $installPath.DIRECTORY_SEPARATOR.'ext';
        if (!is_dir($extDir)) mkdir($extDir, 0777, true);
        
        $io->text("Downloading from {$chosen->downloadUrl}");
        $tempFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.basename($chosen->dllFileName);
        
        $client = new Client();
        try {
            $client->request('GET', $chosen->downloadUrl, ['sink'=>$tempFile]);
        } catch (\Exception $e) {
            $io->error("Download failed: ".$e->getMessage());
            return Command::FAILURE;
        }
        
        // if it's a zip, we must extract a .dll
        $dllName = '';
        if (preg_match('/\.zip$/i', $chosen->dllFileName)) {
            $zip = new ZipArchive();
            if ($zip->open($tempFile)!==true) {
                $io->error("Failed to open zip: $tempFile");
                return Command::FAILURE;
            }
            $foundDll = false;
            for($i=0; $i<$zip->numFiles; $i++){
                $st = $zip->statIndex($i);
                if ($st && preg_match('/\.dll$/i', $st['name'])) {
                    $dllContents = $zip->getFromIndex($i);
                    $targetName  = basename($st['name']);
                    file_put_contents($extDir.DIRECTORY_SEPARATOR.$targetName, $dllContents);
                    $foundDll = $targetName;
                }
            }
            $zip->close();
            if (!$foundDll) {
                $io->error("No .dll found inside the zip!");
                return Command::FAILURE;
            }
            $dllName = $foundDll;
        } else {
            $dllName = basename($chosen->dllFileName);
            rename($tempFile, $extDir.DIRECTORY_SEPARATOR.$dllName);
        }
        
        // add extension= to php.ini
        $iniPath = $installPath.DIRECTORY_SEPARATOR.'php.ini';
        if (!file_exists($iniPath)) {
            $io->warning("php.ini not found in $installPath. Please enable extension manually.");
        } else {
            $extLine = 'extension="'.$dllName.'"';
            file_put_contents($iniPath, PHP_EOL.$extLine.PHP_EOL, FILE_APPEND);
            $io->text("Appended $extLine to php.ini");
        }
        
        // store in config
        $extKey = $variantKey.'-'.$extIdentifier;
        $config['packages'][$variantKey]['extensions'][$extKey] = [
            'pecl_name'         => $extName,
            'requested_version' => $extVer,
            'installed_version' => $chosen->extensionVer,
            'dll_file'          => $dllName,
            'ini_entry'         => 'extension="'.$dllName.'"',
            'date_installed'    => date('Y-m-d H:i:s')
        ];
        $configSvc->setConfig($config);
        $configSvc->saveConfig();
        
        $io->success("Installed extension '$extIdentifier' (v{$chosen->extensionVer}) for variant '$variantKey' successfully!");
        return Command::SUCCESS;
    }
}
