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
 * pvm uninstall php84
 * pvm uninstall php84-phalcon5
 *
 * If it's a base package, remove the entire folder + config entry.
 * If it's an extension, remove the .dll from ext/, remove the extension= line in php.ini, remove from config.
 */
class UninstallCommand extends Command
{
    protected static $defaultName = 'uninstall';
    
    protected function configure()
    {
        $this
            ->setDescription('Uninstall a base package (php84) or extension (php84-phalcon5).')
            ->setHelp('Usage: pvm uninstall php84 or pvm uninstall php84-phalcon5')
            ->addArgument('package', InputArgument::REQUIRED, 'Package or extension to uninstall')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }
    
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        
        $packageName = $input->getArgument('package');
        $force       = (bool) $input->getOption('force');
        
        // Detect extension or base package
        if (preg_match('/^(php\d{2})-(.+)$/', $packageName, $m)) {
            // extension
            $basePackage = $m[1];   // e.g. "php84"
            $extensionId = $m[2];   // e.g. "phalcon5"
            return $this->uninstallExtension($basePackage, $extensionId, $force, $io);
        } else {
            // base package
            return $this->uninstallBasePackage($packageName, $force, $io);
        }
    }
    
    /**
     * Uninstall a base package, e.g. 'php84': remove folder + config entry.
     */
    private function uninstallBasePackage(string $packageName, bool $force, SymfonyStyle $io): int
    {
        $configSvc = new ConfigService();
        $config    = $configSvc->getConfig();
        
        if (!isset($config['packages'][$packageName])) {
            $io->error("Package '$packageName' is not installed.");
            return Command::FAILURE;
        }
        
        $installPath = $config['packages'][$packageName]['install_path'] ?? null;
        if (!$force) {
            $confirm = $io->confirm("Are you sure you want to uninstall $packageName?", false);
            if (!$confirm) {
                $io->text('Aborting uninstall.');
                return Command::SUCCESS;
            }
        }
        
        // Remove directory
        if ($installPath && is_dir($installPath)) {
            $io->section("Removing directory: $installPath");
            $this->removeDirectory($installPath);
        } else {
            $io->warning("Install path '$installPath' not found. Skipping directory removal.");
        }
        
        // Remove from config
        unset($config['packages'][$packageName]);
        $configSvc->setConfig($config);
        $configSvc->saveConfig();
        
        $io->success("Uninstalled $packageName successfully.");
        return Command::SUCCESS;
    }
    
    /**
     * Uninstall an extension, e.g. 'phalcon5' for base 'php84': remove from php.ini, remove dll, remove config.
     */
    private function uninstallExtension(string $basePackageName, string $extensionId, bool $force, SymfonyStyle $io): int
    {
        $packageKey = $basePackageName . '-' . $extensionId;
        
        $configSvc = new ConfigService();
        $config    = $configSvc->getConfig();
        
        if (!isset($config['packages'][$basePackageName])) {
            $io->error("Base package '$basePackageName' is not installed.");
            return Command::FAILURE;
        }
        if (empty($config['packages'][$basePackageName]['extensions'][$packageKey])) {
            $io->error("Extension '$extensionId' is not installed under $basePackageName.");
            return Command::FAILURE;
        }
        
        $extensionInfo = $config['packages'][$basePackageName]['extensions'][$packageKey];
        $dllFile       = $extensionInfo['dll_file'] ?? '(unknown)';
        $iniEntry      = $extensionInfo['ini_entry'] ?? '';
        $installPath   = $config['packages'][$basePackageName]['install_path'] ?? null;
        
        if (!$force) {
            $confirm = $io->confirm("Are you sure you want to uninstall extension '$extensionId' from $basePackageName?", false);
            if (!$confirm) {
                $io->text('Aborting uninstall.');
                return Command::SUCCESS;
            }
        }
        
        // 1) Remove DLL from ext/
        if ($installPath && is_dir($installPath)) {
            $extPath = $installPath . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . $dllFile;
            if (file_exists($extPath)) {
                $io->section("Removing DLL: $extPath");
                @unlink($extPath);
            } else {
                $io->warning("DLL not found at $extPath, skipping.");
            }
            
            // 2) Remove extension= line from php.ini (very naive approach)
            $iniFile = $installPath . DIRECTORY_SEPARATOR . 'php.ini';
            if (file_exists($iniFile)) {
                $io->section("Removing '$iniEntry' from php.ini...");
                $iniContents = file_get_contents($iniFile);
                // We'll remove the line containing that text
                $pattern = '/^.*' . preg_quote($iniEntry, '/') . '.*$/m';
                $newContents = preg_replace($pattern, '', $iniContents);
                if ($newContents !== $iniContents) {
                    file_put_contents($iniFile, $newContents);
                    $io->text("Removed extension line from php.ini");
                } else {
                    $io->warning("Could not find '$iniEntry' in php.ini. Possibly user removed it manually.");
                }
            } else {
                $io->warning("No php.ini found at $iniFile, cannot remove extension line automatically.");
            }
        } else {
            $io->warning("Install path not found for $basePackageName, skipping file removal.");
        }
        
        // 3) Remove from config
        unset($config['packages'][$basePackageName]['extensions'][$packageKey]);
        $configSvc->setConfig($config);
        $configSvc->saveConfig();
        
        $io->success("Uninstalled extension '$extensionId' from $basePackageName successfully.");
        return Command::SUCCESS;
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
