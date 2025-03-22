<?php

namespace PVM\Commands;

use PVM\Services\CacheService;
use PVM\Services\ConfigService;
use PVM\Services\RemoteVersionService;
use PVM\Services\PhpBuildInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * pvm list
 * pvm list php
 * pvm list extensions
 *
 * Now supports:
 *   --no-cache      => force re-scrape
 *   --available     => show all remote variants
 *   --ts-only       => only show TS variants
 *   --nts-only      => only show NTS variants
 *   --arch=x64|x86  => only show that architecture
 *   --vc=17         => only show that compiler version
 */
class ListCommand extends Command
{
    public function __construct()
    {
        parent::__construct('list');
    }
    
    protected function configure(): void
    {
        $this
            ->setDescription('List installed PHP versions or extensions (or both), plus remote variants if needed.')
            ->setHelp(
                <<<EOT
Usage:
  pvm list                     -> Show installed PHP variants, plus installed extensions
  pvm list php                 -> Show only PHP variants
  pvm list extensions          -> Show only installed extensions
  pvm list php --available     -> Show all remote variants, not just installed
  pvm list php --ts-only       -> Filter only TS variants
  pvm list php --arch=x86      -> Filter only x86
  pvm list php --vc=17         -> Filter only VC17
EOT
            )
            ->addArgument(
                'type',
                InputArgument::OPTIONAL,
                'What to list: "php", "extensions", or omit for both.',
                '' // default => show both
            )
            ->addOption(
                'no-cache',
                null,
                InputOption::VALUE_NONE,
                'Force re-scrape of remote sources (affects "php" listing).'
            )
            ->addOption(
                'available',
                null,
                InputOption::VALUE_NONE,
                'Show all remote variants, not just installed.'
            )
            ->addOption(
                'ts-only',
                null,
                InputOption::VALUE_NONE,
                'Show only Thread-Safe variants.'
            )
            ->addOption(
                'nts-only',
                null,
                InputOption::VALUE_NONE,
                'Show only Non-Thread-Safe variants.'
            )
            ->addOption(
                'arch',
                null,
                InputOption::VALUE_REQUIRED,
                'Filter by architecture: "x64" or "x86".'
            )
            ->addOption(
                'vc',
                null,
                InputOption::VALUE_REQUIRED,
                'Filter by compiler version: e.g. 15, 16, 17.'
            );
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $type = strtolower($input->getArgument('type') ?? '');
        
        // Decide which tables to show
        if (!in_array($type, ['', 'php', 'extensions'], true)) {
            $io->warning('Invalid argument. Use "php", "extensions", or omit.');
            return Command::SUCCESS;
        }
        $listPhp        = ($type === '' || $type === 'php');
        $listExtensions = ($type === '' || $type === 'extensions');
        
        $forceRefresh = (bool)$input->getOption('no-cache');
        $showAll      = (bool)$input->getOption('available'); // if true, show all remote variants
        $tsOnly       = (bool)$input->getOption('ts-only');
        $ntsOnly      = (bool)$input->getOption('nts-only');
        $archFilter   = $input->getOption('arch'); // "x64" or "x86"
        $vcFilter     = $input->getOption('vc');   // e.g. "17"
        
        // If user gave both --ts-only and --nts-only, that's contradictory
        if ($tsOnly && $ntsOnly) {
            $io->error("Cannot specify both --ts-only and --nts-only!");
            return Command::FAILURE;
        }
        if ($archFilter && !in_array($archFilter, ['x64','x86'], true)) {
            $io->error("Invalid --arch value '$archFilter'. Must be x64 or x86.");
            return Command::FAILURE;
        }
        
        // If user wants the php listing, do it
        if ($listPhp) {
            $this->listPhpVariants($io, $forceRefresh, $showAll, $tsOnly, $ntsOnly, $archFilter, $vcFilter, $input);
        }
        
        // If user wants the extension listing, do that
        if ($listExtensions) {
            $this->listExtensionsTable($io);
        }
        
        return Command::SUCCESS;
    }
    
    /**
     * Lists either only installed variants (by default), or if --available is passed,
     * merges installed with all remote variants. Then applies filters:
     *   --ts-only, --nts-only, --arch, --vc
     */
    private function listPhpVariants(
        SymfonyStyle $io,
        bool $forceRefresh,
        bool $showAll,
        bool $tsOnly,
        bool $ntsOnly,
        ?string $archFilter,
        ?string $vcFilter,
        InputInterface $input,
    ) {
        $baseDir = $input->getOption('base-dir');
        $cacheService   = new CacheService($baseDir);
        $remoteService  = new RemoteVersionService($cacheService);
        $allRemoteBuilds= $remoteService->getAllBuilds($forceRefresh);
        
        // Group remote builds by variant key => pick newest patch
        $byVariant = [];
        foreach ($allRemoteBuilds as $b) {
            $tsPart = $b->isNts ? 'nts' : 'ts';
            $arch   = $b->isX64 ? 'x64' : 'x86';
            $key = "php{$this->tagMajorMinor($b->majorMinor)}-{$tsPart}-{$arch}-vc{$b->vcVersion}";
            $byVariant[$key][] = $b;
        }
        // pick the newest patch for each group
        $latestByVariant = [];
        foreach ($byVariant as $k => $list) {
            usort($list, fn($a,$b)=>version_compare($b->fullVersion, $a->fullVersion));
            $latestByVariant[$k] = $list[0]; // newest
        }
        
        // gather installed
        $baseDir = $input->getOption('base-dir');
        $configService = new ConfigService($baseDir);
        $config        = $configService->getConfig();
        $packages      = $config['packages'] ?? [];
        
        // build installed info
        $installedInfo = [];
        foreach ($packages as $pkgKey => $pkgData) {
            if (preg_match('/^php(\d+)-(nts|ts)-(x64|x86)-vc(\d+)$/', $pkgKey)) {
                $installedInfo[$pkgKey] = [
                    'patch' => $pkgData['current_patch_version'] ?? '-'
                ];
            }
        }
        
        // final list of variantKeys
        $finalKeys = [];
        if ($showAll) {
            // union of installed + remote
            $remoteKeys   = array_keys($latestByVariant);
            $installedK   = array_keys($installedInfo);
            $finalKeys    = array_unique(array_merge($remoteKeys, $installedK));
        } else {
            // only installed
            $finalKeys = array_keys($installedInfo);
        }
        
        if (empty($finalKeys)) {
            $io->section($showAll ? 'All Remote + Installed PHP Variants' : 'Installed PHP Variants');
            $io->text('No variants found (depending on your --available flag).');
            return;
        }
        
        // build row data
        $rows = [];
        foreach ($finalKeys as $variantKey) {
            // parse from variantKey => "php82-nts-x64-vc16"
            if (!preg_match('/^php(\d+)-(nts|ts)-(x64|x86)-vc(\d+)$/', $variantKey, $mm)) {
                continue; // skip unexpected keys
            }
            $mmTag   = $mm[1]; // e.g. "82"
            $tsPart  = $mm[2]; // "nts" or "ts"
            $arch    = $mm[3]; // "x64" or "x86"
            $vc      = (int)$mm[4];
            
            // find remote patch if any
            $remotePatch = '-';
            if (isset($latestByVariant[$variantKey])) {
                $remotePatch = $latestByVariant[$variantKey]->fullVersion;
            }
            
            // find installed patch if any
            $installedPatch = '-';
            if (isset($installedInfo[$variantKey])) {
                $installedPatch = $installedInfo[$variantKey]['patch'];
            }
            
            // figure out status
            $status = '';
            if ($remotePatch==='-' && $installedPatch==='-') {
                $status = 'No remote found';
            } elseif ($installedPatch==='-') {
                $status = 'Not installed';
            } else {
                // installed
                if ($remotePatch==='-') {
                    $status = '(no remote data)';
                } else {
                    // compare
                    if (preg_match('/^\d+\.\d+\.\d+$/', $installedPatch)
                        && version_compare($installedPatch, $remotePatch, '<')
                    ) {
                        $status = '<fg=red>Update available</>';
                    } else {
                        $status = '<fg=green>Up to date</>';
                    }
                }
            }
            
            $majorMinor = $this->untagMajorMinor($mmTag); // e.g. "8.2"
            // convert "nts" => "NTS", "ts" => "TS"
            $tsLabel = strtoupper($tsPart);
            
            $rows[] = [
                'variantKey' => $variantKey,
                'majorMinor' => $majorMinor,
                'thread'     => $tsLabel,  // "NTS" or "TS"
                'arch'       => $arch,     // "x64" or "x86"
                'vc'         => $vc,
                'remote'     => $remotePatch,
                'installed'  => $installedPatch,
                'status'     => $status
            ];
        }
        
        // apply filter flags: --ts-only, --nts-only, --arch, --vc
        $rows = array_filter($rows, function($r) use($tsOnly, $ntsOnly, $archFilter, $vcFilter){
            // thread safety
            if ($tsOnly && $r['thread']!=='TS') return false;
            if ($ntsOnly && $r['thread']!=='NTS') return false;
            // arch
            if ($archFilter && $r['arch']!==$archFilter) return false;
            // vc
            if ($vcFilter) {
                $needVc = (int)$vcFilter;
                if ($r['vc']!==$needVc) return false;
            }
            return true;
        });
        
        if (empty($rows)) {
            $io->section($showAll ? 'All Remote + Installed PHP Variants' : 'Installed PHP Variants');
            $io->text('No variants match your filter criteria.');
            return;
        }
        
        // sort them
        usort($rows, function($a, $b){
            // 1) majorMinor asc
            $cmp = version_compare($a['majorMinor'], $b['majorMinor']);
            if ($cmp!==0) return $cmp;
            
            // 2) NTS < TS
            if ($a['thread']!==$b['thread']) {
                return strcmp($a['thread'], $b['thread']);
            }
            // 3) x64 < x86 alpha
            if ($a['arch']!==$b['arch']) {
                return strcmp($a['arch'], $b['arch']);
            }
            // 4) vc desc
            return $b['vc'] <=> $a['vc'];
        });
        
        // build final table
        $title = $showAll
            ? 'All Remote + Installed PHP Variants (filtered)'
            : 'Installed PHP Variants (filtered)';
        $io->section($title);
        
        $tableRows = [];
        foreach ($rows as $r) {
            $tableRows[] = [
                $r['majorMinor'],
                $r['thread'],
                $r['arch'],
                'VS'.$r['vc'],
                $r['remote'],
                $r['installed'],
                $r['status']
            ];
        }
        
        $io->table(
            ['Major.Minor','Thread Safety','Arch','Compiler','Remote Patch','Installed Patch','Status'],
            $tableRows
        );
    }
    
    /**
     * Lists installed extensions.
     * Now we consider config keys matching ^php(\d+)-(nts|ts)-(x64|x86)-vc(\d+)$
     */
    private function listExtensionsTable(SymfonyStyle $io, InputInterface $input)
    {
        $baseDir = $input->getOption('base-dir');
        $configService = new ConfigService($baseDir);
        $config        = $configService->getConfig();
        $packages      = $config['packages'] ?? [];
        
        $rows = [];
        foreach ($packages as $pkgKey => $pkgData) {
            if (!preg_match('/^php(\d+)-(nts|ts)-(x64|x86)-vc(\d+)$/', $pkgKey)) {
                continue; // skip
            }
            $extArr = $pkgData['extensions'] ?? [];
            if (!empty($extArr) && is_array($extArr)) {
                foreach ($extArr as $extKey => $extInfo) {
                    $ver     = $extInfo['installed_version'] ?? '(unknown)';
                    $dllFile = $extInfo['dll_file']          ?? '(none)';
                    $rows[] = [
                        $extKey,
                        $pkgKey,
                        $ver,
                        $dllFile
                    ];
                }
            }
        }
        
        $io->section('Installed Extensions');
        if (empty($rows)) {
            $io->text('No extension packages found.');
            return;
        }
        
        usort($rows, fn($a, $b) => strcmp($a[0], $b[0]));
        
        $io->table(
            ['Package Key', 'Base Variant', 'Version', 'DLL File'],
            $rows
        );
    }
    
    /**
     * Convert e.g. "8.2" => "82"
     */
    private function tagMajorMinor(string $mm): string
    {
        $parts = explode('.', $mm);
        return $parts[0].$parts[1];
    }
    
    /**
     * Convert e.g. "82" => "8.2"
     */
    private function untagMajorMinor(string $tag): string
    {
        // e.g. "82" => "8.2"
        if (strlen($tag)===2) {
            return $tag[0].'.'.$tag[1];
        }
        // in case we ever get "810" => "8.10"
        if (strlen($tag)>=2) {
            return $tag[0].'.'.substr($tag,1);
        }
        return $tag;
    }
}
