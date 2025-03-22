<?php

namespace PVM\Services;

class ConfigService
{
    private string $configPath;
    private array $config = [];
    
    public function __construct(?string $baseDir = null)
    {
        $this->configPath = $baseDir . '/config/config.json';
        $this->loadConfig();
    }
    
    private function loadConfig(): void
    {
        if (file_exists($this->configPath)) {
            $content = file_get_contents($this->configPath);
            $this->config = json_decode($content, true) ?: [];
        } else {
            $this->config = [];
        }
        
        if (!isset($this->config['packages'])) {
            $this->config['packages'] = [];
        }
    }
    
    public function saveConfig(): void
    {
        file_put_contents(
            $this->configPath,
            json_encode($this->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }
    
    public function getConfig(): array
    {
        return $this->config;
    }
    
    public function setConfig(array $data): void
    {
        $this->config = $data;
    }
}
