<?php

namespace PVM\Services;

class CacheService
{
    private string $cachePath;
    private array $cacheData = [];
    
    public function __construct(string $cachePath = __DIR__ . '/../../config/cache.json')
    {
        $this->cachePath = $cachePath;
        $this->loadCache();
    }
    
    private function loadCache(): void
    {
        if (file_exists($this->cachePath)) {
            $json = file_get_contents($this->cachePath);
            $this->cacheData = json_decode($json, true) ?: [];
        } else {
            $this->cacheData = [];
        }
    }
    
    public function getCache(): array
    {
        return $this->cacheData;
    }
    
    public function setCache(array $newData): void
    {
        $this->cacheData = $newData;
    }
    
    public function saveCache(): void
    {
        file_put_contents(
            $this->cachePath,
            json_encode($this->cacheData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }
}
