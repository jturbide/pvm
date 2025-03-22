<?php
namespace PVM;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

class PvmApplication extends Application
{
    protected function getDefaultInputDefinition(): InputDefinition
    {
        $definition = parent::getDefaultInputDefinition();
        
        // Add a global --base-dir option
        $definition->addOption(
            new InputOption('base-dir', null, InputOption::VALUE_REQUIRED, 'Base directory for config and packages', getcwd())
        );
        
        return $definition;
    }
}
