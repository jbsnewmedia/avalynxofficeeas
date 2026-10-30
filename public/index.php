<?php

declare(strict_types=1);

/*
 * avalynxofficeeas - ActiveSync endpoint for avalynxoffice.
 *
 * Separate process, shared database, no core code dependencies (AGPL
 * aggregation boundary). Bootstraps the plugin services and runs the
 * Z-Push request processor.
 */

require dirname(__DIR__).'/vendor/autoload.php';

use AvalynxOfficeEas\Db\Database;
use AvalynxOfficeEas\Service\EndpointService;
use AvalynxOfficeEas\Service\ServiceHolder;

// Load a local .env when present (Docker/compose injects real env vars).
// Values are mirrored into $_ENV, $_SERVER and putenv() so that code reading
// $_SERVER (Z-Push config, EndpointService) sees the same values as
// Database::env(); existing environment variables are never overwritten.
$envFile = dirname(__DIR__).'/.env';

if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);

        if ('' === $line || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value, " \t\"'");

        if ('' === $name) {
            continue;
        }

        if (!array_key_exists($name, $_ENV) && !array_key_exists($name, $_SERVER) && false === getenv($name)) {
            $_ENV[$name] = $value;
            putenv("$name=$value");
        }

        if (!array_key_exists($name, $_SERVER)) {
            $_SERVER[$name] = $_ENV[$name] ?? (false !== getenv($name) ? getenv($name) : $value);
        }
    }
}

$projectDir = dirname(__DIR__);

// Some Apache proxy_fcgi setups (and nginx+fastcgi) do not populate
// PHP_AUTH_USER. Decode Basic authentication from the Authorization header
// so Z-Push's Request::Initialize() sees the credentials in all setups.
if (!isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authorization = trim((string) $_SERVER['HTTP_AUTHORIZATION']);

    if (preg_match('/^Basic\s+(.+)$/i', $authorization, $matches)) {
        $decoded = base64_decode($matches[1], true);

        if (false !== $decoded && str_contains($decoded, ':')) {
            [$_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']] = explode(':', $decoded, 2);
        }
    }
}

// Z-Push state/log directories must exist before the first request.
foreach ([Database::env('EAS_STATE_DIR', 'var/eas-state') ?? 'var/eas-state', Database::env('EAS_LOG_DIR', 'var/log/eas') ?? 'var/log/eas'] as $directory) {
    if (!is_dir($projectDir.'/'.$directory)) {
        @mkdir($projectDir.'/'.$directory, 0770, true);
    }
}

try {
    ServiceHolder::bootstrap($projectDir);
} catch (\Throwable $throwable) {
    error_log(sprintf('avalynxofficeeas bootstrap failed: %s (%s:%d)', $throwable->getMessage(), $throwable->getFile(), $throwable->getLine()));

    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Retry-After: 60');
    echo 'Service Unavailable';

    exit;
}

$endpoint = new EndpointService($projectDir);
$response = $endpoint->handle();

http_response_code($response['status']);
foreach ($response['headers'] as $name => $value) {
    header($name.': '.$value);
}
echo $response['body'];
