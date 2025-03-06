<?php

namespace PVM\Commands;

use PVM\Services\CacheService;
use PVM\Services\ConfigService;
use PVM\Services\RemoteVersionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ListCommand extends Command
{
    protected static $defaultName = 'list';
    
    protected function configure()
    {
        $this
            ->setDescription('List available (remote) or installed packages')
            ->setHelp('Displays the newest PHP base packages from windows.php.net and any installed packages.')
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'Force re-scrape of remote sources');
    }
    
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        
        $forceRefresh = (bool)$input->getOption('no-cache');
        
        $cache = new CacheService();
        $remoteService = new RemoteVersionService($cache);
        $bestBuilds = $remoteService->getLatestVersions($forceRefresh);
        // e.g. [ "8.4" => PhpBuildInfo(8.4.4, NTS, x64, vs17, url=...), "8.3" => ... ]
        
        $configService = new ConfigService();
        $config = $configService->getConfig();
        $installedPackages = $config['packages'] ?? [];
        
        $io->title('PVM Packages');
        
        $rows = [];
        
        // installedByMajorMinor
        $installedByMajorMinor = [];
        foreach ($installedPackages as $pkgName => $info) {
            if (preg_match('/^php(\d)(\d)$/', $pkgName, $m)) {
                $mm = $m[1] . '.' . $m[2];
                $installedByMajorMinor[$mm] = $info['current_patch_version'] ?? '(unknown)';
            }
        }
        
        // Merge all known major.minor
        $allMM = array_unique(
            array_merge(array_keys($bestBuilds), array_keys($installedByMajorMinor))
        );
        sort($allMM);
        
        foreach ($allMM as $mm) {
            $remoteStr    = '(none)';
            $installedStr = '-';
            $updateNeeded = false;
            
            if (isset($bestBuilds[$mm])) {
                $b = $bestBuilds[$mm];
                $arch    = $b->isX64 ? 'x64' : 'x86';
                $ntsOrTs = $b->isNts ? 'NTS' : 'TS';
                $vc      = "VC/VS{$b->vcVersion}";
                $remoteStr = "{$b->fullVersion} ($ntsOrTs, $arch, {$vc})";
            }
            
            $isInstalled = isset($installedByMajorMinor[$mm]);
            if ($isInstalled) {
                $installedStr = $installedByMajorMinor[$mm];
            }
            
            if (preg_match('/^\d+\.\d+\.\d+$/', $installedStr) && isset($bestBuilds[$mm])) {
                $updateNeeded = version_compare($installedStr, $bestBuilds[$mm]->fullVersion, '<');
            }
            
            $rows[] = [
                $mm,
                $remoteStr,
                $installedStr,
                !$isInstalled? '-' : (
                    $updateNeeded ? '<fg=red>Update available</>' : '<fg=green>Up to date</>'
                )
            ];
        }
        
        $io->table(
            ['Major.Minor', 'Latest Remote Build', 'Installed Patch', 'Update?'],
            $rows
        );
        
        return Command::SUCCESS;
    }
}
