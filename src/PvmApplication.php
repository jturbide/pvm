<?php

namespace PVM;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class PvmApplication extends Application
{
    protected function getDefaultInputDefinition(): InputDefinition
    {
        $definition = parent::getDefaultInputDefinition();
        
        // Add a global --base-dir option (defaults to getcwd())
        $definition->addOption(
            new InputOption('base-dir', null, InputOption::VALUE_REQUIRED, 'Base directory for config/packages', getcwd())
        );
        
        return $definition;
    }
    
    /**
     * We override doRun so that after Symfony parses --base-dir,
     * we can check (and possibly create) the .pvm folder
     * before any command actually runs.
     */
    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        // Convert the base-dir option to an absolute path if needed
        $baseDir = $input->getOption('base-dir') ?: getcwd();
        $baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR);
        
        // We'll keep everything in $baseDir/.pvm
        $pvmDir = $baseDir . DIRECTORY_SEPARATOR . '.pvm';
        
        // We can prompt the user with SymfonyStyle
        $io = new SymfonyStyle($input, $output);
        
        // Check if .pvm folder exists
        if (!is_dir($pvmDir)) {
            // Ask if they want to create it
            $io->section("Checking directory: $pvmDir");
            $confirm = $io->confirm("The directory $pvmDir does not exist. Create it now?", true);
            
            if ($confirm) {
                // Attempt creation
                if (!@mkdir($pvmDir, 0777, true) && !is_dir($pvmDir)) {
                    $io->error("Failed to create directory $pvmDir. Aborting...");
                    return 1; // non-zero exit
                }
                $io->text("Created folder $pvmDir");
            } else {
                $io->error("Cannot proceed without $pvmDir existing. Aborting...");
                return 1;
            }
        }
        
        // If we get here, $pvmDir exists.
        // Now commands can rely on it (e.g. for config, cache, etc.)
        
        // Optionally, we can define a constant or store it in the container
        // so the commands can retrieve it:
        define('PVM_DIR', $pvmDir);
        
        // Or commands can read it from $input->getOption('base-dir') + '/.pvm'
        
        // Now proceed with normal command execution
        return parent::doRun($input, $output);
    }
}
