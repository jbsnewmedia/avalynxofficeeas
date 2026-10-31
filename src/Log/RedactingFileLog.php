<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Log;

/**
 * Drop-in replacement for Z-Push's default file log backend.
 *
 * Z-Push writes the raw (base64 encoded) WBXML request body into the log on
 * every WBXML exception - with LOGLEVEL_FATAL, so the payload ends up in the
 * error log regardless of the configured log level ("WBXML 10K debug data",
 * RequestProcessor::HandleRequest()). The payload contains the full message
 * content of the failed request (subjects, bodies, attachments).
 *
 * This backend keeps the file log behaviour but redacts the base64 payload of
 * the WBXML debug messages in every output path (main log, user log, error
 * log), leaving a length notice instead.
 */
final class RedactingFileLog extends \FileLog
{
    /**
     * Redacts the base64 payload of WBXML debug messages.
     *
     * Only messages that actually look like a WBXML dump (prefix "WBXML",
     * base64-only payload of at least 64 characters) are touched; everything
     * else is passed through unchanged.
     *
     * @param string $message the log message as built by Z-Push
     *
     * @return string the message with the payload replaced by a length notice
     */
    private static function redact(string $message): string
    {
        if (!str_starts_with($message, 'WBXML')) {
            return $message;
        }

        $separator = strpos($message, ':');
        if (false === $separator) {
            return $message;
        }

        $prefix = substr($message, 0, $separator + 1);
        $payload = substr($message, $separator + 1);

        if (strlen($payload) < 64 || !preg_match('/^[A-Za-z0-9+\/=\s]+$/', $payload)) {
            return $message;
        }

        return sprintf('%s [redacted WBXML payload, %d characters]', rtrim($prefix), strlen($payload));
    }

    /**
     * @param int    $loglevel
     * @param string $message
     *
     * @return void
     */
    protected function Write($loglevel, $message)
    {
        parent::Write($loglevel, self::redact($message));
    }

    /**
     * @param int    $loglevel
     * @param string $message
     *
     * @return void
     */
    public function WriteForUser($loglevel, $message)
    {
        parent::WriteForUser($loglevel, self::redact($message));
    }

    /**
     * FATAL/ERROR/WARN reach the error log independently of the configured
     * log level - this is the path the WBXML exception dump takes.
     *
     * @param int    $loglevel
     * @param string $message
     *
     * @return void
     */
    protected function afterLog($loglevel, $message)
    {
        parent::afterLog($loglevel, self::redact($message));
    }
}
