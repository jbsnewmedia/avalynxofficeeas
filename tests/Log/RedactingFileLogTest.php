<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Tests\Log;

use AvalynxOfficeEas\Log\RedactingFileLog;
use PHPUnit\Framework\TestCase;

final class RedactingFileLogTest extends TestCase
{
    /**
     * The redaction logic is the security-relevant core: every WBXML base64
     * dump must lose its payload, everything else must pass through
     * unchanged.
     */
    public function testRedactStripsWbxmlPayloadsButKeepsOtherMessages(): void
    {
        $redact = new \ReflectionMethod(RedactingFileLog::class, 'redact');

        $body = base64_encode('sensitive message content with subjects and bodies');
        $wbxmlFatal = 'WBXML 10K debug data: '.$body;
        self::assertSame(
            'WBXML 10K debug data: [redacted WBXML payload, '.(strlen($body) + 1).' characters]',
            $redact->invoke(null, $wbxmlFatal),
        );

        $in = str_repeat('QUJD', 64);
        self::assertSame(
            'WBXML-IN : [redacted WBXML payload, '.(strlen($in) + 1).' characters]',
            $redact->invoke(null, 'WBXML-IN : '.$in),
        );

        // Short payloads and non-base64 content are not WBXML dumps.
        self::assertSame('WBXML something failed', $redact->invoke(null, 'WBXML something failed'));
        self::assertSame('WBXML-IN : too short', $redact->invoke(null, 'WBXML-IN : too short'));
        self::assertSame('Ordinary log line', $redact->invoke(null, 'Ordinary log line'));
        self::assertSame('RequestProcessor failed', $redact->invoke(null, 'RequestProcessor failed'));
    }

    /**
     * The error log receives FATAL messages regardless of the configured
     * log level (FileLog::afterLog): the WBXML exception dump takes exactly
     * that path and must arrive redacted.
     */
    public function testAfterLogWritesRedactedMessageToErrorFile(): void
    {
        $log = new RedactingFileLog();
        $afterLog = new \ReflectionMethod(RedactingFileLog::class, 'afterLog');
        $errorFile = \constant('LOGERRORFILE');
        $errorFile = \is_string($errorFile) ? $errorFile : '';
        $created = !file_exists($errorFile);
        $before = is_string(@file_get_contents($errorFile)) ? (string) file_get_contents($errorFile) : '';

        try {
            $payload = base64_encode('confidential body with subject, recipients and a lot of message content that surely exceeds the minimum length');
            $afterLog->invoke($log, \LOGLEVEL_FATAL, 'WBXML 10K debug data: '.$payload);

            $content = (string) file_get_contents($errorFile);
            self::assertStringNotContainsString($payload, $content);
            self::assertStringContainsString('WBXML 10K debug data: [redacted WBXML payload,', $content);
        } finally {
            if ($created) {
                @unlink($errorFile);
            } else {
                @file_put_contents($errorFile, $before);
            }
        }
    }
}
