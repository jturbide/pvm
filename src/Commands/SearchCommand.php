<?php

namespace PVM\Commands;

use PVM\Services\CacheService;
use PVM\Services\PeclService;
use PVM\Services\PeclBuildInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class SearchCommand extends BaseCommand
{
    public function __construct()
    {
        parent::__construct('search');
    }
    
    protected function configure(): void
    {
        $this
            ->setDescription('Search PECL packages by keyword, optionally filtering by partial ext version, partial PHP version, etc.')
            ->setHelp(
                <<<EOT
Examples:
  pvm search redis
  pvm search imagick --ext-version=3.7     (partial match for extension version)
  pvm search redis --ext-version=5.3 --php-version=8  (match "8.1", "8.2"...)
  pvm search xdebug --arch=x64 --no-cache
  pvm search gd --php-version=7

If you specify --ext-version=<partial> or --php-version=<partial>, the command
matches any build whose extensionVer/phpMajorMinor contains that substring.
E.g. --php-version=8 matches 8.0, 8.1, 8.2, etc.
EOT
            )
            ->addArgument(
                'keyword',
                InputArgument::REQUIRED,
                'Keyword to search for in extension name (e.g. "redis", "imagick")'
            )
            ->addOption(
                'no-cache',
                null,
                InputOption::VALUE_NONE,
                'Force re-scrape of remote source (ignore cache)'
            )
            ->addOption(
                'ext-version',
                null,
                InputOption::VALUE_REQUIRED,
                'Partial match for extension version substring (e.g. "3.7" matches 3.7.0, 3.7.0RC1, etc.)'
            )
            ->addOption(
                'php-version',
                null,
                InputOption::VALUE_REQUIRED,
                'Partial match for PHP version substring (e.g. "8" matches 8.0, 8.1, 8.2, etc.)'
            )
            ->addOption(
                'nts-only',
                null,
                InputOption::VALUE_NONE,
                'Show only NTS builds'
            )
            ->addOption(
                'ts-only',
                null,
                InputOption::VALUE_NONE,
                'Show only TS builds'
            )
            ->addOption(
                'arch',
                null,
                InputOption::VALUE_REQUIRED,
                'Filter by architecture (x86 or x64)'
            );
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io            = new SymfonyStyle($input, $output);
        $keyword       = strtolower($input->getArgument('keyword'));
        $forceFresh    = (bool)$input->getOption('no-cache');
        $partialExtVer = $input->getOption('ext-version');  // e.g. "5.3", "3.7", "3.7.0RC"
        $phpPartial    = $input->getOption('php-version');  // e.g. "8", "7", "8.1"
        $ntsOnly       = (bool)$input->getOption('nts-only');
        $tsOnly        = (bool)$input->getOption('ts-only');
        $archFilter    = $input->getOption('arch');         // "x64" or "x86"
        
        if (empty($keyword)) {
            $io->error('You must provide a search keyword, e.g. "pvm search redis"');
            return Command::FAILURE;
        }
        if ($ntsOnly && $tsOnly) {
            $io->warning("You used both --nts-only and --ts-only. That means no builds will match. Remove one.");
            return Command::SUCCESS;
        }
        if ($archFilter && !in_array($archFilter, ['x86', 'x64'], true)) {
            $io->error("Invalid --arch value '$archFilter'. Must be 'x86' or 'x64'.");
            return Command::FAILURE;
        }
        
        // 1) PeclService for scraping
        $baseDir = $input->getOption('base-dir');
        $cacheService  = new CacheService($baseDir);
        $peclService   = new PeclService($cacheService);
        
        // 2) Find extension folders that match the keyword
        $allExtensions = $peclService->listAllExtensions($forceFresh);
        $matchedExtensions = [];
        foreach ($allExtensions as $extName) {
            if (strpos(strtolower($extName), $keyword) !== false) {
                $matchedExtensions[] = $extName;
            }
        }
        
        $io->title("Search Results for '{$keyword}'");
        
        if (empty($matchedExtensions)) {
            $io->warning("No matching extensions found for '{$keyword}'.");
            return Command::SUCCESS;
        }
        
        sort($matchedExtensions, SORT_NATURAL | SORT_FLAG_CASE);
        
        // 3) For each matching extension, retrieve *all* builds
        foreach ($matchedExtensions as $extName) {
            $io->section("Extension: {$extName}");
            
            $allBuilds = $peclService->getExtensionBuilds($extName, null, $forceFresh);
            if (empty($allBuilds)) {
                $io->text("  No builds found for '{$extName}'. Possibly no stable or no archived releases.");
                continue;
            }
            
            // 4) Filter by partial extension version
            if ($partialExtVer) {
                $allBuilds = array_filter($allBuilds, function (PeclBuildInfo $b) use ($partialExtVer) {
                    return stripos($b->extensionVer, $partialExtVer) !== false;
                });
            }
            
            // 5) Filter by partial php-version, nts/ts, arch
            $allBuilds = array_filter($allBuilds, function (PeclBuildInfo $b) use ($phpPartial, $ntsOnly, $tsOnly, $archFilter) {
                // partial php version
                if ($phpPartial && stripos($b->phpMajorMinor, $phpPartial) === false) {
                    return false;
                }
                if ($ntsOnly && !$b->isNts) {
                    return false;
                }
                if ($tsOnly && $b->isNts) {
                    return false;
                }
                if ($archFilter) {
                    $actualArch = $b->isX64 ? 'x64' : 'x86';
                    if ($actualArch !== $archFilter) {
                        return false;
                    }
                }
                return true;
            });
            
            if (empty($allBuilds)) {
                $io->comment("No builds match your filters (ext-version, php-version, nts-only/ts-only, arch).");
                continue;
            }
            
            // 6) Sort them into a single table:
            //    First by extensionVer (desc), then by phpMajorMinor (desc), etc.
            usort($allBuilds, function (PeclBuildInfo $a, PeclBuildInfo $b) {
                // Compare extension version descending
                $cmpVersion = version_compare($b->extensionVer, $a->extensionVer);
                if ($cmpVersion !== 0) {
                    return $cmpVersion;
                }
                // Then compare phpMajorMinor descending
                $cmpPhp = version_compare($b->phpMajorMinor, $a->phpMajorMinor);
                if ($cmpPhp !== 0) {
                    return $cmpPhp;
                }
                // Then put NTS first
                if ($a->isNts !== $b->isNts) {
                    return $b->isNts <=> $a->isNts;
                }
                // Then x64 first
                if ($a->isX64 !== $b->isX64) {
                    return $b->isX64 <=> $a->isX64;
                }
                // Then highest vcVersion first
                return $b->vcVersion <=> $a->vcVersion;
            });
            
            // 7) Single table for all builds
            $rows = [];
            foreach ($allBuilds as $build) {
                $rows[] = [
                    $build->extensionVer,
                    $build->phpMajorMinor,
                    $build->isNts ? 'NTS' : 'TS',
                    $build->isX64 ? 'x64' : 'x86',
                    'VS' . $build->vcVersion,
                    $build->dllFileName,
                    $build->downloadUrl,
                ];
            }
            
            $io->table(
                ['Ext. Version', 'PHP Ver', 'TS/NTS', 'Arch', 'Compiler', 'DLL', 'Download URL'],
                $rows
            );
        }
        
        return Command::SUCCESS;
    }
}
