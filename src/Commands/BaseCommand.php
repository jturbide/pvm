<?php
namespace PVM\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

abstract class BaseCommand extends Command
{
    public string $baseDir;
    public string $pvmDir;
    
    protected function configure()
    {
        parent::configure();
        
        // We add the --base-dir option so *this command* (and all subclasses) accept it.
        $this->addOption(
            'base-dir',
            null,
            InputOption::VALUE_REQUIRED,
            'Base directory for config and packages',
            getcwd()
        );
    }
    
    /**
     * The "initialize()" method is called before "execute()",
     * and AFTER Symfony parses/validates all input options.
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);
        
        $io = new SymfonyStyle($input, $output);
        
        $baseDir = rtrim($input->getOption('base-dir'), DIRECTORY_SEPARATOR);
        
        // For example, if you want a .pvm folder:
        $pvmDir = $baseDir . DIRECTORY_SEPARATOR . '.pvm';
        
        if (!is_dir($pvmDir)) {
            $io->section("Checking directory: $pvmDir");
            $confirm = $io->confirm("Folder does not exist. Create it?", true);
            if ($confirm) {
                if (!@mkdir($pvmDir, 0777, true) && !is_dir($pvmDir)) {
                    $io->error("Failed to create $pvmDir. Aborting...");
                    // Throwing an exception will abort this command gracefully
                    throw new \RuntimeException("Cannot proceed without $pvmDir.");
                }
                $io->text("Created folder $pvmDir");
            } else {
                // or throw an exception
                $io->error("Cannot proceed without $pvmDir. Aborting...");
                throw new \RuntimeException("User declined to create $pvmDir.");
            }
        }
        
        // Now commands can rely on $baseDir or $pvmDir
        // You could store them in a protected property:
        $this->baseDir = $baseDir;
        $this->pvmDir  = $pvmDir;
    }
}
