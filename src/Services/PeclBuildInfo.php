<?php

namespace PVM\Services;

/**
 * Similar to your PhpBuildInfo, but for PECL extension builds.
 */
class PeclBuildInfo
{
    public string $extensionName;   // e.g. "phalcon"
    public string $extensionVer;    // e.g. "5.8.0"
    public string $phpMajorMinor;   // e.g. "8.4"
    public bool   $isNts;           // true => NTS, false => TS
    public bool   $isX64;           // true => x64, false => x86
    public int    $vcVersion;       // e.g. 15, 16, 17
    public string $dllFileName;     // e.g. php_phalcon-5.8.0-8.4-nts-vs17-x64.zip
    public string $downloadUrl;     // full URL
}
