<?php

namespace App\Services;

/** Stable OS execution context without copying request headers or application secrets. */
class ProcessEnvironment
{
    public static function local(): array
    {
        $temporary = sys_get_temp_dir();
        $environment = ['TMP' => $temporary, 'TEMP' => $temporary, 'TMPDIR' => $temporary];
        foreach (['PATH', 'HOME', 'USERPROFILE', 'SystemRoot', 'WINDIR'] as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') {
                $environment[$name] = $value;
            }
        }

        return $environment;
    }
}
