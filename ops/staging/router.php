<?php

use Illuminate\Http\Request;

$repo = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (! preg_match('#^/(api(?:/|$)|sanctum(?:/|$)|up$|login$|logout$|forgot-password$|reset-password$|password$)#', $path) || (in_array($path, ['/login', '/forgot-password', '/reset-password']) && in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD']))) {
    $root = realpath($repo.'/frontend/dist');
    $file = realpath($root.'/'.ltrim(rawurldecode($path), '/'));
    if ($file && str_starts_with($file, $root.DIRECTORY_SEPARATOR) && is_file($file)) {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        header('Content-Type: '.(['js' => 'text/javascript', 'css' => 'text/css', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'ico' => 'image/x-icon', 'woff2' => 'font/woff2'][$ext] ?? 'application/octet-stream'));
        readfile($file);

        return;
    }
    header('Content-Type: text/html');
    header('Cache-Control: no-store');
    readfile($root.'/index.html');

    return;
}
$app = require __DIR__.'/bootstrap.php';
$app->handleRequest(Request::capture());
