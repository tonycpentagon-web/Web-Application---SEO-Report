<?php

namespace App\Services;

class ProductionConfiguration
{
    /** Return names/reasons only, never configuration values or credentials. */
    public function errors(bool $production = false): array
    {
        if (! $production && config('app.env') !== 'production') {
            return [];
        }
        $errors = [];
        $check = function (bool $ok, string $message) use (&$errors) {
            if (! $ok) {
                $errors[] = $message;
            }
        };
        $check(config('app.env') === 'production', 'APP_ENV must be production.');
        $check(config('app.debug') === false, 'APP_DEBUG must be false.');
        $key = (string) config('app.key');
        $decoded = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        $check(is_string($decoded) && strlen($decoded) === 32, 'APP_KEY must be a valid, privately generated 32-byte key.');
        foreach (['app.url' => 'APP_URL', 'insight.frontend_url' => 'FRONTEND_URL'] as $setting => $name) {
            $url = parse_url((string) config($setting));
            $host = strtolower($url['host'] ?? '');
            $check(($url['scheme'] ?? '') === 'https' && $host && ! in_array($host, ['localhost', '127.0.0.1', '::1']) && ! str_ends_with($host, '.example') && ! str_ends_with($host, '.invalid') && ! isset($url['user']) && ! isset($url['query']) && ! isset($url['fragment']) && in_array($url['path'] ?? '', ['', '/']), $name.' must be a real HTTPS origin without credentials, paths or placeholders.');
        }
        $check(config('session.secure') === true, 'SESSION_SECURE_COOKIE must be true.');
        $check(config('session.http_only') === true, 'SESSION_HTTP_ONLY must be true.');
        $check(config('session.encrypt') === true, 'SESSION_ENCRYPT must be true.');
        $check(in_array(config('session.same_site'), ['lax', 'strict']), 'SESSION_SAME_SITE must be lax or strict.');
        $check(in_array(config('session.driver'), ['database', 'redis']), 'SESSION_DRIVER must use durable database or redis storage.');
        $check(in_array(config('cache.default'), ['database', 'redis']), 'CACHE_STORE must support shared worker/limiter state.');
        $origins = config('cors.allowed_origins', []);
        $frontend = rtrim((string) config('insight.frontend_url'), '/');
        $check($origins === [$frontend] && config('cors.allowed_origins_patterns', []) === [] && config('cors.supports_credentials') === true, 'CORS must allow exactly FRONTEND_URL with credentials and no wildcard/pattern origins.');
        $host = parse_url($frontend, PHP_URL_HOST);
        $port = parse_url($frontend, PHP_URL_PORT);
        $stateful = $host.($port ? ':'.$port : '');
        $domains = config('sanctum.stateful', []);
        $check(in_array($stateful, $domains, true) && ! collect($domains)->contains(fn ($d) => str_contains($d, '*') || str_contains($d, 'localhost') || str_contains($d, '127.0.0.1')), 'SANCTUM_STATEFUL_DOMAINS must include the frontend host and exclude development/wildcard hosts.');
        $check(config('database.default') === 'mysql', 'Production requires the verified MySQL 8 deployment target.');
        $db = config('database.connections.mysql', []);
        $check(! empty($db['database']) && ! empty($db['username']) && ! in_array($db['username'] ?? '', ['root', 'REPLACE_ME']) && ! empty($db['password']) && empty($db['url']), 'Use explicit private MySQL database credentials and a non-root application account.');
        $check(($db['strict'] ?? false) === true && ($db['charset'] ?? '') === 'utf8mb4' && ($db['engine'] ?? '') === 'InnoDB', 'MySQL must use strict mode, utf8mb4 and InnoDB.');
        $check(in_array(config('queue.default'), ['database', 'redis']), 'QUEUE_CONNECTION must be database or redis, never sync/null.');
        $retry = (int) config('queue.connections.'.config('queue.default').'.retry_after');
        $check($retry > (int) config('documents.timeout') + 60 && $retry > 240, 'Queue retry_after must exceed document and PDF job timeouts.');
        $check((int) config('delivery.timeout') > 0 && (int) config('delivery.timeout') < 240, 'PDF_TIMEOUT must be between 1 and 239 seconds.');
        $root = realpath(config('filesystems.disks.local.root', '')) ?: config('filesystems.disks.local.root', '');
        $normalize = fn ($path) => rtrim(str_replace('\\', '/', (string) $path), '/');
        $root = $normalize($root);
        $check(is_dir($root), 'Private storage root must already exist before startup.');
        foreach ([public_path(), base_path('../frontend/dist')] as $public) {
            $public = $normalize(realpath($public) ?: $public);
            $check($root !== $public && ! str_starts_with($root.'/', $public.'/'), 'Private storage must be outside all web document roots.');
        }
        $check(config('filesystems.default') === 'local' && config('filesystems.disks.local.serve') === false, 'Private local storage must not expose signed public file serving.');
        $check(in_array(config('mail.default'), ['smtp', 'ses', 'postmark', 'resend']), 'Production password email requires a configured delivery transport, not log/array.');
        $check(filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false && ! str_ends_with((string) config('mail.from.address'), '@example.com'), 'MAIL_FROM_ADDRESS must be an explicit sender address.');
        if (config('mail.default') === 'smtp') {
            $check(! empty(config('mail.mailers.smtp.host')) && ! str_ends_with((string) config('mail.mailers.smtp.host'), '.invalid') && (int) config('mail.mailers.smtp.port') > 0, 'SMTP host and port must be configured.');
        }
        $check(! empty(config('documents.python')) && is_file(config('documents.script')), 'Python executable and processor script must be configured.');
        $check(! empty(config('delivery.node')) && is_file(base_path('pdf-renderer/render.mjs')), 'Node and the PDF renderer must be configured.');
        $check((int) config('documents.timeout') > 0 && (int) config('documents.max_batch') > 0 && (int) config('documents.max_total_mb') > 0, 'Document limits/timeouts must be positive.');

        return array_values(array_unique($errors));
    }
}
