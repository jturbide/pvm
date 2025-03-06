<?php

namespace PVM\Services;

class PhpBuildInfo
{
    public string $fullVersion; // e.g. "8.4.4"
    public string $majorMinor;  // e.g. "8.4"
    public bool   $isNts;       // true => NTS, false => TS
    public bool   $isX64;       // true => x64, false => x86
    public int    $vcVersion;   // e.g. 15, 16, 17, ...
    public string $downloadUrl; // full URL to the .zip
}
