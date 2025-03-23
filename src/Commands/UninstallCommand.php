<?php

namespace PVM\Commands;

use PVM\Services\ConfigService;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

/**
 * pvm uninstall php82-nts-x64-vc16
 * pvm uninstall php82-nts-x64-vc16-redis5.3.7
 *
 * If it's a base package, remove the entire folder + config entry.
 * If it's an extension, remove the .dll from ext/, remove extension= line from php.ini, remove from config.
 *
 * If the user only typed "php82" or "php82-redis5.3.7" and multiple variants exist,
 * we prompt them which variant key to remove.
 */
class UninstallCommand extends BaseCommand
{
    public function __construct()
    {
        parent::__construct('uninstall');
    }
    
    protected function configure(): void
    {
        $this
            ->setDescription('Uninstall a base package (php82-nts-x64-vc16) or extension (php82-nts-x64-vc16-redis5.3.7).')
            ->setHelp('Usage: pvm uninstall php82-nts-x64-vc16 or pvm uninstall php82-nts-x64-vc16-redis5.3.7')
            ->addArgument('package', InputArgument::REQUIRED, 'Package or extension to uninstall')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $packageName = $input->getArgument('package');
        $force       = (bool) $input->getOption('force');
        
        // We check if it looks like "phpXX-..." with a trailing extension name, or just a base variant
        // For example:
        //   "php82-nts-x64-vc16-redis5.3.7"
        //   "php82-redis5.3.7"  (partial base)
        //   "php82-nts-x64-vc16" (full base variant)
        //   "php82" (partial base)
        // We'll do the same approach as InstallCommand for disambiguation.
        
        // 1) Attempt a full parse for base variant + extension
        //    ^(php\d+-(?:nts|ts)-(?:x64|x86)-vc\d+)-(.*)$
        if (preg_match('/^(php\d+-(?:nts|ts)-(?:x64|x86)-vc\d+)-(.+)$/', $packageName, $m)) {
            // extension scenario with full base key
            $baseVariantKey = $m[1];     // e.g. "php82-nts-x64-vc16"
            $extIdentifier  = $m[2];     // e.g. "redis5.3.7"
            return $this->uninstallExtensionByVariant($baseVariantKey, $extIdentifier, $force, $io, $input);
        }
        
        // 2) Attempt a partial parse for "php\d{2}-(.+)$ => extension, but user didn't specify the full variant
        //    e.g. "php82-redis5.3.7"
        if (preg_match('/^(php\d+)-(.+)$/', $packageName, $m2)) {
            // extension scenario but partial base
            $baseName = $m2[1];    // e.g. "php82"
            $extIdent = $m2[2];    // e.g. "redis5.3.7"
            return $this->uninstallExtensionPartial($baseName, $extIdent, $force, $io, $input);
        }
        
        // 3) Attempt a full parse for base variant only: ^php\d+-(?:nts|ts)-(x64|x86)-vc\d+$
        if (preg_match('/^php\d+-(?:nts|ts)-(?:x64|x86)-vc\d+$/', $packageName)) {
            // base variant scenario
            return $this->uninstallBaseVariant($packageName, $force, $io, $input);
        }
        
        // 4) Possibly user typed just "php82"
        if (preg_match('/^php(\d+)$/' , $packageName)) {
            // e.g. "php82" => we see if there's multiple variants that match that prefix
            return $this->uninstallBasePartial($packageName, $force, $io, $input);
        }
        
        // otherwise, we say invalid
        $io->error("Unrecognized package name format '$packageName'.");
        return Command::FAILURE;
    }
    
    /**
     * Uninstall a fully specified base variant, e.g. "php82-nts-x64-vc16".
     */
    private function uninstallBaseVariant(string $variantKey, bool $force, SymfonyStyle $io, InputInterface $input): int
    {
        $baseDir = $input->getOption('base-dir');
        $configSvc = new ConfigService($baseDir);
        $config    = $configSvc->getConfig();
        
        if (!isset($config['packages'][$variantKey])) {
            $io->error("Variant '$variantKey' is not installed.");
            return Command::FAILURE;
        }
        
        if (!$force) {
            $confirm = $io->confirm("Are you sure you want to uninstall base package '$variantKey'?", false);
            if (!$confirm) {
                $io->text('Aborting uninstall.');
                return Command::SUCCESS;
            }
        }
        
        $installPath = $config['packages'][$variantKey]['install_path'] ?? null;
        if ($installPath && is_dir($installPath)) {
            $io->section("Removing directory: $installPath");
            $this->removeDirectory($installPath);
        } else {
            $io->warning("Install path '$installPath' not found. Skipping directory removal.");
        }
        
        // remove from config
        unset($config['packages'][$variantKey]);
        $configSvc->setConfig($config);
        $configSvc->saveConfig();
        
        $io->success("Uninstalled base package '$variantKey' successfully.");
        return Command::SUCCESS;
    }
    
    /**
     * If user typed "php82" => we see which variants exist, ask them which to remove, etc.
     */
    private function uninstallBasePartial(string $baseName, bool $force, SymfonyStyle $io, InputInterface $input): int
    {
        // e.g. "php82" => find all config keys matching ^php82-(nts|ts)-(x64|x86)-vc\d+$
        $baseDir = $input->getOption('base-dir');
        $configSvc = new ConfigService($baseDir);
        $config    = $configSvc->getConfig();
        $packages  = $config['packages'] ?? [];
        
        $pattern = '/^'.$baseName.'-(?:nts|ts)-(?:x64|x86)-vc\d+$/';
        $matches = [];
        foreach ($packages as $key => $info) {
            if (preg_match($pattern, $key)) {
                $matches[] = $key;
            }
        }
        if (empty($matches)) {
            $io->error("No installed variants found for '$baseName'.");
            return Command::FAILURE;
        }
        if (count($matches)===1) {
            $only = $matches[0];
            $io->text("Found exactly one variant: $only");
            return $this->uninstallBaseVariant($only, $force, $io);
        }
        
        // multiple => ask user
        $pick = $io->choice(
            "Multiple variants found for '$baseName'. Which do you want to uninstall?",
            $matches,
            $matches[0]
        );
        return $this->uninstallBaseVariant($pick, $force, $io);
    }
    
    /**
     * Uninstall an extension if user typed a fully qualified base variant + extension,
     * e.g. "php82-nts-x64-vc16-redis5.3.7".
     */
    private function uninstallExtensionByVariant(string $baseVariantKey, string $extIdent, bool $force, SymfonyStyle $io, InputInterface $input): int
    {
        $baseDir = $input->getOption('base-dir');
        $configSvc = new ConfigService($baseDir);
        $config    = $configSvc->getConfig();
        
        if (!isset($config['packages'][$baseVariantKey])) {
            $io->error("Base variant '$baseVariantKey' is not installed.");
            return Command::FAILURE;
        }
        $pkgExtensions = $config['packages'][$baseVariantKey]['extensions'] ?? [];
        // we assume the ext key is baseVariantKey-<extIdent>
        $extKey = $baseVariantKey.'-'.$extIdent;
        if (!isset($pkgExtensions[$extKey])) {
            $io->error("Extension '$extIdent' is not installed under $baseVariantKey.");
            return Command::FAILURE;
        }
        
        if (!$force) {
            $confirm = $io->confirm("Are you sure you want to uninstall extension '$extIdent' from $baseVariantKey?", false);
            if (!$confirm) {
                $io->text('Aborting uninstall.');
                return Command::SUCCESS;
            }
        }
        
        $extensionInfo = $pkgExtensions[$extKey];
        $dllFile       = $extensionInfo['dll_file']  ?? '';
        $iniEntry      = $extensionInfo['ini_entry'] ?? '';
        $installPath   = $config['packages'][$baseVariantKey]['install_path'] ?? null;
        
        // remove the DLL
        if ($installPath && is_dir($installPath)) {
            $extDir  = $installPath.DIRECTORY_SEPARATOR.'ext';
            $dllPath = $extDir.DIRECTORY_SEPARATOR.$dllFile;
            if (file_exists($dllPath)) {
                $io->section("Removing DLL: $dllPath");
                @unlink($dllPath);
            } else {
                $io->warning("DLL not found at $dllPath, skipping.");
            }
            
            // remove from php.ini
            $iniFile = $installPath.DIRECTORY_SEPARATOR.'php.ini';
            if (file_exists($iniFile) && $iniEntry) {
                $io->section("Removing '$iniEntry' from php.ini...");
                $iniContents = file_get_contents($iniFile);
                $pattern = '/^.*'.preg_quote($iniEntry, '/').'.*$/m';
                $newContents = preg_replace($pattern, '', $iniContents);
                if ($newContents!==$iniContents) {
                    file_put_contents($iniFile, $newContents);
                    $io->text("Removed extension line from php.ini");
                } else {
                    $io->warning("Could not find '$iniEntry' in php.ini. Possibly removed manually.");
                }
            }
        } else {
            $io->warning("Install path not found for $baseVariantKey, skipping file removal.");
        }
        
        // remove from config
        unset($config['packages'][$baseVariantKey]['extensions'][$extKey]);
        $configSvc->setConfig($config);
        $configSvc->saveConfig();
        
        $io->success("Uninstalled extension '$extIdent' from $baseVariantKey successfully.");
        return Command::SUCCESS;
    }
    
    /**
     * Uninstall extension if user typed e.g. "php82-redis5.3.7" => partial base, then we see
     * which base variants exist for "php82-(nts|ts)-(x64|x86)-vc\d+", see if extension is installed, etc.
     */
    private function uninstallExtensionPartial(string $baseName, string $extIdent, bool $force, SymfonyStyle $io, InputInterface $input): int
    {
        // e.g. baseName="php82", extIdent="redis5.3.7"
        // find all installed keys that match ^php82-(nts|ts)-(x64|x86)-vc\d+$
        $baseDir = $input->getOption('base-dir');
        $configSvc = new ConfigService($baseDir);
        $config    = $configSvc->getConfig();
        $packages  = $config['packages'] ?? [];
        
        $pattern = '/^'.$baseName.'-(nts|ts)-(x64|x86)-vc(\d+)$/';
        $foundKeys = [];
        foreach ($packages as $pkgKey => $info) {
            if (preg_match($pattern, $pkgKey)) {
                $foundKeys[] = $pkgKey;
            }
        }
        if (empty($foundKeys)) {
            $io->error("No base variants found for '$baseName'.");
            return Command::FAILURE;
        }
        
        // each base might or might not have the extension
        // we search for extKey => baseVariantKey-extIdent
        $candidates = [];
        foreach ($foundKeys as $bk) {
            $extKey = $bk.'-'.$extIdent;
            if (!empty($packages[$bk]['extensions'][$extKey])) {
                $candidates[] = $extKey; // fully qualified
            }
        }
        if (empty($candidates)) {
            $io->error("Extension '$extIdent' not found under any '$baseName' variant. Possibly not installed.");
            return Command::FAILURE;
        }
        if (count($candidates)===1) {
            $only = $candidates[0];
            // parse out baseVariant from extKey => e.g. "php82-nts-x64-vc16-redis5.3.7"
            if (preg_match('/^(php\d+-(?:nts|ts)-(?:x64|x86)-vc\d+)-(.*)$/', $only, $mm)) {
                $baseVariant = $mm[1];
                $extension   = $mm[2];
                return $this->uninstallExtensionByVariant($baseVariant, $extension, $force, $io);
            }
            // fallback
            $io->error("Error parsing extension key: $only");
            return Command::FAILURE;
        }
        
        // multiple => ask user
        $choice = $io->choice(
            "Multiple variants have extension '$extIdent'. Which do you want to uninstall?",
            $candidates,
            $candidates[0]
        );
        if (preg_match('/^(php\d+-(?:nts|ts)-(?:x64|x86)-vc\d+)-(.*)$/', $choice, $mm2)) {
            $bv = $mm2[1];
            $ex= $mm2[2];
            return $this->uninstallExtensionByVariant($bv, $ex, $force, $io);
        }
        
        $io->error("Error parsing chosen extension key.");
        return Command::FAILURE;
    }
    
    /**
     * Recursively remove a directory.
     */
    private function removeDirectory(string $dirPath): void
    {
        if (!is_dir($dirPath)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dirPath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            if ($file->isDir()) {
                @rmdir($file->getRealPath());
            } else {
                @unlink($file->getRealPath());
            }
        }
        @rmdir($dirPath);
    }
}
