<?php
namespace UKModules\ADIF\Cache;

/**
 * Where admin (system) and project choices come from.
 * In REDCap this is backed by the EM's getSystemSetting / getProjectSetting;
 * in tests it's an array.
 */
interface SettingsSource
{
    public function system(string $providerId, string $key, mixed $default = null): mixed;

    public function project(int $projectId, string $providerId, string $key, mixed $default = null): mixed;
}
