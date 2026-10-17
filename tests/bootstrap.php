<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

require dirname(__DIR__).'/vendor/autoload.php';

/*
 * Boot Z-Push once per process, like the production front controller does:
 * the config defines are not idempotent and Z-Push registers its error
 * handler during ZLog::Initialize.
 */
if (!defined('ZPUSH_CONFIG')) {
    // Relative paths are required: Z-Push prefixes them with BASE_PATH and
    // enforces ownership of the state/log directories (FixFileOwner).
    foreach (['EAS_STATE_DIR' => 'var/eas-state', 'EAS_LOG_DIR' => 'var/log/eas'] as $name => $default) {
        $configured = $_SERVER[$name] ?? null;
        $relative = is_string($configured) && '' !== $configured ? $configured : $default;

        if (!str_starts_with($relative, '/')) {
            $dir = dirname(__DIR__).'/'.$relative;

            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
        }

        $_SERVER[$name] = $relative;
    }

    define('ZPUSH_CONFIG', dirname(__DIR__).'/config/zpush-config.php');
    include_once ZPUSH_CONFIG;
    ZPush::CheckConfig();
    ZLog::Initialize();
}
