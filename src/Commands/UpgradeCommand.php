<?php

namespace PVM\Commands;

use PVM\Services\ConfigService;
use PVM\Services\CacheService;
use PVM\Services\RemoteVersionService;
use PVM\Services\PhpBuildInfo;
use RuntimeException;
use GuzzleHttp\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use ZipArchive;

/**
 * pvm upgrade php82-nts-x64-vc16
 * pvm upgrade php82
 * pvm upgrade --all
 *
 * Logic:
 *  1) If user typed a fully-specified variant (e.g. "php82-nts-x64-vc16"), attempt to upgrade that one only.
 *  2) If user typed a partial base (e.g. "php82") but multiple variants exist, prompt user which to upgrade.
 *  3) If --all is used, upgrade all installed variants.
 *
 * For each variant, we find the newest patch from getAllBuilds() that matches
 * (majorMinor, isNts, isX64, vcVersion). If it's higher than the installed patch, we do an in-place upgrade.
 */
class UpgradeCommand extends BaseCommand
{
    public function __construct()
    {
        parent::__construct('upgrade');
    }
    
    protected function configure(): void
    {
        parent::configure();
        
        $this
            ->setDescription('Upgrade one or all installed base variants.')
            ->setHelp(
                <<<EOT
Examples:
  pvm upgrade php82-nts-x64-vc16
  pvm upgrade php82            (if multiple variants exist, user is prompted)
  pvm upgrade --all            (upgrade all installed variants)
EOT
            )
            ->addArgument(
                'package',
                InputArgument::OPTIONAL,
                'Package to upgrade (e.g. php82-nts-x64-vc16, or just php82 if you want to pick from multiple variants).'
            )
            ->addOption('all', null, InputOption::VALUE_NONE, 'Upgrade all installed variants');
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $packageArg = $input->getArgument('package');
        $upgradeAll = (bool)$input->getOption('all');
        
        if (!$upgradeAll && !$packageArg) {
            $io->error('You must specify either a package name or use --all.');
            return Command::FAILURE;
        }
        
        $baseDir = $input->getOption('base-dir');
        $configSvc = new ConfigService($baseDir);
        $config    = $configSvc->getConfig();
        $packages  = $config['packages'] ?? [];
        
        // We'll gather a list of "variant keys" we plan to upgrade
        // e.g. "php82-nts-x64-vc16"
        $targets = [];
        
        if ($upgradeAll) {
            // Filter all installed variants => ^php(\d+)-(nts|ts)-(x64|x86)-vc(\d+)$
            foreach ($packages as $key => $info) {
                if (preg_match('/^php(\d+)-(nts|ts)-(x64|x86)-vc(\d+)$/', $key)) {
                    $targets[] = $key;
                }
            }
            if (empty($targets)) {
                $io->text('No installed variants found to upgrade.');
                return Command::SUCCESS;
            }
        } else {
            // single
            // check if user typed a fully-specified variant or a partial
            if (preg_match('/^php(\d+)-(nts|ts)-(x64|x86)-vc(\d+)$/', $packageArg)) {
                // fully-specified
                if (!isset($packages[$packageArg])) {
                    $io->error("Variant '$packageArg' is not installed.");
                    return Command::FAILURE;
                }
                $targets[] = $packageArg;
            }
            else if (preg_match('/^php(\d+)$/', $packageArg, $m)) {
                // partial base => e.g. "php82"
                $baseName = $packageArg; // e.g. "php82"
                // find all variants that match ^php82-(nts|ts)-(x64|x86)-vc\d+$
                $pattern = '/^'.$baseName.'-(nts|ts)-(x64|x86)-vc(\d+)$/';
                $found   = [];
                foreach ($packages as $k => $info) {
                    if (preg_match($pattern, $k)) {
                        $found[] = $k;
                    }
                }
                if (empty($found)) {
                    $io->error("No installed variants found for '$baseName'.");
                    return Command::FAILURE;
                }
                if (count($found)===1) {
                    // just pick that one
                    $targets[] = $found[0];
                } else {
                    // ask user
                    $chosen = $io->choice(
                        "Multiple variants found for '$baseName'. Which do you want to upgrade?",
                        $found,
                        $found[0]
                    );
                    $targets[] = $chosen;
                }
            }
            else {
                $io->error("Unrecognized package name '$packageArg'. Must be a variant 'phpXX-nts-x64-vc16' or base 'phpXX'.");
                return Command::FAILURE;
            }
        }
        
        if (empty($targets)) {
            $io->text('No valid targets found to upgrade.');
            return Command::SUCCESS;
        }
        
        // Now let's retrieve all remote builds, so we can find the newest patch for each variant
        $baseDir = $input->getOption('base-dir');
        $remoteSvc   = new RemoteVersionService(new CacheService($baseDir));
        $allBuilds   = $remoteSvc->getAllBuilds(false); // an array of PhpBuildInfo
        
        // We'll do the actual upgrade logic
        foreach ($targets as $variantKey) {
            $this->upgradeVariant($variantKey, $config, $allBuilds, $io);
        }
        
        // Save changes
        $configSvc->setConfig($config);
        $configSvc->saveConfig();
        
        $io->success('Upgrade process complete.');
        return Command::SUCCESS;
    }
    
    /**
     * Actually upgrade a single variant key, e.g. "php82-nts-x64-vc16".
     * We parse out majorMinor => "8.2", isNts => true, isX64 => true, vcVersion => 16,
     * find the newest patch in $allBuilds matching that variant, and if it's higher than
     * the installedPatch, do the in-place upgrade.
     */
    private function upgradeVariant(string $variantKey, array &$config, array $allBuilds, SymfonyStyle $io)
    {
        if (!isset($config['packages'][$variantKey])) {
            $io->warning("Skipping $variantKey (not found in config).");
            return;
        }
        
        // parse the variantKey => e.g. "php82-nts-x64-vc16"
        // => majorMinor=8.2, isNts=true, isX64=true, vc=16
        if (!preg_match('/^php(\d+)-(nts|ts)-(x64|x86)-vc(\d+)$/', $variantKey, $m)) {
            $io->warning("Skipping $variantKey (does not match variant pattern).");
            return;
        }
        $mmTag   = $m[1]; // e.g. "82"
        $tsPart  = $m[2]; // "nts" or "ts"
        $arch    = $m[3]; // "x64" or "x86"
        $vcNum   = (int)$m[4];
        
        // reconstruct major.minor => "8.2"
        $maj = substr($mmTag, 0, 1);
        $min = substr($mmTag, 1);
        $majorMinor = $maj.'.'.$min;
        
        $isNts = ($tsPart==='nts');
        $isX64= ($arch==='x64');
        
        $io->section("Upgrading $variantKey...");
        
        $installedPatch = $config['packages'][$variantKey]['current_patch_version'] ?? '(unknown)';
        $installPath    = $config['packages'][$variantKey]['install_path'] ?? '';
        
        // find the newest remote patch that matches these variant attributes
        $matching = array_filter($allBuilds, function(PhpBuildInfo $b) use ($majorMinor,$isNts,$isX64,$vcNum){
            if ($b->majorMinor!==$majorMinor) return false;
            if ($b->isNts!==$isNts) return false;
            if ($b->isX64!==$isX64) return false;
            if ($b->vcVersion!==$vcNum) return false;
            return true;
        });
        if (empty($matching)) {
            $io->text("No remote build found for $variantKey. Cannot upgrade.");
            return;
        }
        // pick the highest patch
        usort($matching, fn($a,$b)=>version_compare($b->fullVersion,$a->fullVersion));
        $newest = $matching[0];
        $newPatch = $newest->fullVersion;
        
        // compare with installed
        if (!preg_match('/^\d+\.\d+\.\d+$/', $installedPatch)) {
            $io->text("Current patch '$installedPatch' is not valid. Upgrading anyway to $newPatch...");
        } else {
            if (version_compare($installedPatch, $newPatch, '>=')) {
                $io->text("Already at newest patch ($installedPatch).");
                return;
            }
        }
        
        $io->text("A newer patch is available: $newPatch");
        // do in-place upgrade
        try {
            $this->inPlaceOverwrite($newest, $installPath, $io);
        } catch (RuntimeException $ex) {
            $io->error("Failed to upgrade $variantKey: ".$ex->getMessage());
            return;
        }
        
        // update config
        $config['packages'][$variantKey]['current_patch_version'] = $newPatch;
        $io->success("Upgraded $variantKey from $installedPatch to $newPatch successfully!");
    }
    
    /**
     * In-place overwrite logic, skipping php.ini
     */
    private function inPlaceOverwrite(PhpBuildInfo $buildInfo, string $installPath, SymfonyStyle $io): void
    {
        if (!is_dir($installPath)) {
            throw new RuntimeException("Install path does not exist: $installPath");
        }
        
        $io->text("Downloading patch from {$buildInfo->downloadUrl}...");
        $tempZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pvm-upgrade-' . uniqid() . '.zip';
        
        $client = new Client();
        try {
            $head = $client->head($buildInfo->downloadUrl);
            if ($head->getStatusCode()!==200) {
                throw new RuntimeException("Server returned code {$head->getStatusCode()}");
            }
        } catch (\Exception $e) {
            throw new RuntimeException("HEAD request failed: ".$e->getMessage());
        }
        
        try {
            $client->request('GET', $buildInfo->downloadUrl, ['sink'=>$tempZip]);
        } catch (\Exception $e) {
            throw new RuntimeException("Download failed: ".$e->getMessage());
        }
        
        if (!file_exists($tempZip) || filesize($tempZip)<10000) {
            throw new RuntimeException("Downloaded file is too small or invalid.");
        }
        
        $io->text("Extracting to $installPath (overwriting, skipping php.ini)...");
        $zip = new ZipArchive();
        if ($zip->open($tempZip)!==true) {
            throw new RuntimeException("Failed to open zip archive at $tempZip");
        }
        
        // Overwrite everything except existing php.ini
        for($i=0; $i<$zip->numFiles; $i++){
            $stat = $zip->statIndex($i);
            if (!$stat) continue;
            $relativePath = $stat['name'];
            if (strtolower($relativePath)==='php.ini') {
                continue;
            }
            $dest = rtrim($installPath,'/\\').DIRECTORY_SEPARATOR.$relativePath;
            if (substr($relativePath,-1)==='/') {
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
}
