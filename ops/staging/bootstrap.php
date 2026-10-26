<?php

// Windows fallback only: shadow .env and runtime paths before Laravel bootstrap.
$repo = dirname(__DIR__, 2);
$root = realpath(getenv('STAGE8_ROOT') ?: '');
if (! $root || dirname($root) !== realpath($repo.'/.tools') || ! str_starts_with(basename($root), 'stage8-local-') || ! is_file($root.'/.synthetic-stage8')) {
    throw new RuntimeException('An explicitly initialized isolated Stage 8 root is required.');
}
// Override inherited shell configuration too: an accidental DB/Redis environment must not redirect the drill.
$forced = ['APP_ENV' => 'staging', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $root.'/database.sqlite', 'DB_URL' => '', 'LOCAL_PRIVATE_ROOT' => $root.'/private', 'LARAVEL_STORAGE_PATH' => $root.'/runtime', 'QUEUE_CONNECTION' => 'database', 'CACHE_STORE' => 'database', 'SESSION_DRIVER' => 'database', 'MAIL_MAILER' => 'log', 'STAGE8_DISABLE_EXPORT_CLEANUP' => 'true'];
foreach (['APP_CONFIG_CACHE' => 'config.php', 'APP_ROUTES_CACHE' => 'routes.php', 'APP_SERVICES_CACHE' => 'services.php', 'APP_PACKAGES_CACHE' => 'packages.php', 'APP_EVENTS_CACHE' => 'events.php'] as $key => $name) {
    $forced[$key] = '../.tools/'.basename($root).'/cache/'.$name;
}
foreach ($forced as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
require $repo.'/backend/vendor/autoload.php';
$app = require $repo.'/backend/bootstrap/app.php';
$app->useEnvironmentPath($root);
$app->useStoragePath($root.'/runtime');

return $app;
