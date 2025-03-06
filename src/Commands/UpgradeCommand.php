<?php

namespace PVM\Commands;

use PVM\Services\ConfigService;
use PVM\Services\RemoteVersionService;
use PVM\Services\CacheService;
use PVM\Services\PhpBuildInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use GuzzleHttp\Client;
use ZipArchive;
use RuntimeException;

/**
 * pvm upgrade php84
 * or
 * pvm upgrade --all
 */
class UpgradeCommand extends Command
{
    protected static $defaultName = 'upgrade';
    
    protected function configure()
    {
        $this
            ->setDescription('Upgrade one or all installed base packages (e.g. php84)')
            ->setHelp('Upgrade an existing PHP installation to the newest patch version without overwriting php.ini.')
            ->addArgument('package', InputArgument::OPTIONAL, 'Package to upgrade (e.g. php84)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Upgrade all installed base packages');
    }
    
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $configService = new ConfigService();
        $config = $configService->getConfig();
        $packages = $config['packages'] ?? [];
        
        // Determine if user wants to upgrade a single package or all
        $packageName = $input->getArgument('package');
        $upgradeAll  = (bool)$input->getOption('all');
        
        if (!$upgradeAll && !$packageName) {
            $io->error('You must specify either a package name or use --all');
            return Command::FAILURE;
        }
        
        // Collect target packages
        $targets = [];
        if ($upgradeAll) {
            // Filter only base packages (e.g. phpXX)
            foreach ($packages as $pName => $info) {
                if (preg_match('/^php(\d)(\d)$/', $pName)) {
                    $targets[] = $pName;
                }
            }
            if (empty($targets)) {
                $io->text('No base packages installed to upgrade.');
                return Command::SUCCESS;
            }
        } else {
            // Single package
            if (!isset($packages[$packageName])) {
                $io->error("Package '$packageName' is not installed.");
                return Command::FAILURE;
            }
            $targets[] = $packageName;
        }
        
        // We'll fetch remote versions once
        $remoteService = new RemoteVersionService(new CacheService());
        $bestBuilds = $remoteService->getLatestVersions(false);
        
        // Now upgrade each target
        foreach ($targets as $pkg) {
            $this->upgradePackage($pkg, $config, $bestBuilds, $io);
        }
        
        // Save any changes
        $configService->setConfig($config);
        $configService->saveConfig();
        
        $io->success('Upgrade process complete.');
        return Command::SUCCESS;
    }
    
    /**
     * Upgrade a single base package in-place, skipping .ini overwrites.
     */
    private function upgradePackage(string $packageName, array &$config, array $bestBuilds, SymfonyStyle $io)
    {
        // For example "php84" => major.minor => "8.4"
        if (!preg_match('/^php(\d)(\d)$/', $packageName, $m)) {
            $io->warning("Skipping $packageName (not a base package).");
            return;
        }
        $majorMinor = $m[1] . '.' . $m[2];
        
        $packageInfo = $config['packages'][$packageName] ?? null;
        if (!$packageInfo) {
            $io->warning("Skipping $packageName, not found in config.");
            return;
        }
        
        $currentPatch = $packageInfo['current_patch_version'] ?? '(unknown)';
        $installPath  = $packageInfo['install_path'] ?? '';
        
        $io->section("Upgrading $packageName (current patch: $currentPatch)...");
        
        if (!isset($bestBuilds[$majorMinor])) {
            $io->text("No remote build found for $majorMinor. Cannot upgrade.");
            return;
        }
        
        $remoteBuild = $bestBuilds[$majorMinor];
        $newPatch    = $remoteBuild->fullVersion;
        
        // Check if an upgrade is actually needed
        if (!preg_match('/^\d+\.\d+\.\d+$/', $currentPatch)) {
            $io->text("Current patch '$currentPatch' is not valid. Upgrading anyway...");
        } else {
            if (version_compare($currentPatch, $newPatch, '>=')) {
                $io->text("Already at newest patch ($currentPatch).");
                return;
            }
        }
        
        $io->text("New patch available: $newPatch");
        // Download & extract
        try {
            $this->inPlaceOverwrite($remoteBuild, $installPath, $io);
        } catch (RuntimeException $ex) {
            $io->error("Failed to upgrade $packageName: " . $ex->getMessage());
            return;
        }
        
        // Update config
        $config['packages'][$packageName]['current_patch_version'] = $newPatch;
        $io->success("Upgraded $packageName from $currentPatch to $newPatch successfully!");
    }
    
    /**
     * Downloads the new build and overwrites the existing folder, skipping php.ini.
     */
    private function inPlaceOverwrite(PhpBuildInfo $buildInfo, string $installPath, SymfonyStyle $io): void
    {
        if (!is_dir($installPath)) {
            throw new RuntimeException("Install path does not exist: $installPath");
        }
        
        $io->text("Downloading patch: {$buildInfo->downloadUrl}");
        $client = new Client();
        // Check HEAD first
        try {
            $head = $client->head($buildInfo->downloadUrl);
            if ($head->getStatusCode() !== 200) {
                throw new RuntimeException("Server returned status code {$head->getStatusCode()}");
            }
        } catch (\Exception $e) {
            throw new RuntimeException("HEAD request failed: " . $e->getMessage());
        }
        
        $tempZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'pvm-upgrade-' . uniqid() . '.zip';
        
        try {
            $client->request('GET', $buildInfo->downloadUrl, ['sink' => $tempZip]);
        } catch (\Exception $e) {
            throw new RuntimeException("Download failed: " . $e->getMessage());
        }
        
        if (!file_exists($tempZip) || filesize($tempZip) < 10000) {
            throw new RuntimeException("Downloaded file is too small or invalid.");
        }
        
        $io->text("Extracting to $installPath (overwriting files, skipping php.ini)...");
        $zip = new ZipArchive();
        if ($zip->open($tempZip) !== true) {
            throw new RuntimeException("Failed to open zip archive at $tempZip");
        }
        
        // We'll extract each file, skipping existing php.ini
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!$stat) {
                continue;
            }
            $relativePath = $stat['name'];
            // If the file is "php.ini-production" or "php.ini-development", we can let them be overwritten
            // But if the file is "php.ini", skip. Actually, the official zip doesn't typically have a php.ini
            // but let's skip anyway to be safe:
            // (In official builds, we have php.ini-production, php.ini-development, no plain php.ini)
            if (strtolower($relativePath) === 'php.ini') {
                // skip
                continue;
            }
            
            $dest = rtrim($installPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relativePath;
            
            // If the item is a directory, create it
            if (substr($relativePath, -1) === '/') {
                @mkdir($dest, 0777, true);
                continue;
            }
            
            // Ensure parent directory exists
            $parentDir = dirname($dest);
            if (!is_dir($parentDir)) {
                mkdir($parentDir, 0777, true);
            }
            
            // Extract the file
            $contents = $zip->getFromIndex($i);
            file_put_contents($dest, $contents);
        }
        $zip->close();
        
        $io->text("Upgrade extraction complete. Keeping existing php.ini (if any).");
    }
}
