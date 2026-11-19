<?php
declare(strict_types=1);
// Router exclusivo del servidor de desarrollo. Apache usa public/ como DocumentRoot.
$public = realpath(__DIR__ . '/public');
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if ($path === '/') return false;
$target = realpath($public . $path);
if ($target && str_starts_with($target, $public . DIRECTORY_SEPARATOR) && is_file($target)
    && !str_starts_with(basename($target), '.')) return false;
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Página no encontrada.';
