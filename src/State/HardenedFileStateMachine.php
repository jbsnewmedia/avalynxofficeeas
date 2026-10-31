<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\State;

use AvalynxOfficeEas\Db\Database;
use FileStateMachine;
use StateInvalidException;

/**
 * Hardened replacement for Z-Push's FileStateMachine.
 *
 * Z-Push stores its sync state as raw PHP-serialized objects in the state
 * directory and unserialifies them on the next read. Whoever can write to
 * the state volume (host compromise, a container sharing the volume, a
 * malicious backup restore) therefore gets PHP object injection inside the
 * EAS process. ZPush::GetStateMachine() constructs the plain FileStateMachine
 * itself, so this class is injected via EndpointService after
 * Request::Initialize().
 *
 * Two defenses, both fail closed:
 *
 *  - state files (arbitrary object graphs) carry an HMAC-SHA256 signature
 *    (appended behind a marker line). GetState() rejects files without a
 *    valid signature with a StateInvalidException - Z-Push's regular
 *    recovery for broken states is a full device resync;
 *  - the shared "users" and "settings" files only ever contain plain arrays
 *    of strings and integers: they are signed as well, but unsigned legacy
 *    content is accepted after a structural check that rejects any object
 *    token (the injection payload) before the content is unserialized. The
 *    files are re-signed on the next write.
 *
 * The signing key is EAS_STATE_KEY (base64/hex/plain, any content) or - if
 * unset - derived deterministically from DATABASE_URL: it never lives in or
 * next to the state directory, so write access to the volume alone is not
 * enough to forge state.
 *
 * The private path helpers of the parent cannot be reused; buildStatePath(),
 * directoryForDevice() and the overridden methods mirror the behaviour of
 * z-push/z-push-dev 2.7.x (pinned via composer.lock) - keep them in sync on
 * updates.
 */
final class HardenedFileStateMachine extends \FileStateMachine
{
    private const string SIGNATURE_MARKER = "\n--avalynx-state-signature-v1--:";

    /**
     * @param string          $devid
     * @param string          $type
     * @param string|bool     $key
     * @param int|string|bool $counter
     * @param bool|string     $cleanstates
     *
     * @throws \StateNotFoundException
     * @throws \StateInvalidException
     */
    public function GetState($devid, $type, $key = false, $counter = false, $cleanstates = true)
    {
        if ($counter && $cleanstates && \is_string($key)) {
            $this->CleanStates($devid, $type, $key, (string) $counter);
        }

        $filename = $this->buildStatePath($devid, $type, $key, $counter);

        if (file_exists($filename)) {
            $raw = \Utils::SafeGetContents($filename, __FUNCTION__, false, false);

            if (!is_string($raw) || '' === $raw) {
                throw new \StateInvalidException(sprintf("%s->GetState(): Could not read state '%s'", self::class, $filename));
            }

            return unserialize(self::verifySigned($raw, $filename));
        }

        if (\IStateMachine::FAILSAVE !== $type) {
            throw new \StateNotFoundException(sprintf("%s->GetState(): Could not locate state '%s'", self::class, $filename));
        }

        return null;
    }

    /**
     * @param string      $devid
     * @param string      $type
     * @param string|bool $key
     * @param int|bool    $counter
     *
     * @return int|bool
     */
    public function SetState($state, $devid, $type, $key = false, $counter = false)
    {
        $signed = self::sign(serialize($state));
        $filename = $this->buildStatePath($devid, $type, $key, $counter);

        $bytes = \Utils::SafePutContents($filename, $signed);
        if (false === $bytes) {
            throw new \FatalMisconfigurationException(sprintf("%s->SetState(): Could not write state '%s'", self::class, $filename));
        }

        \ZLog::Write(LOGLEVEL_DEBUG, sprintf('%s->SetState() written %d bytes on file: \'%s\'', self::class, $bytes, $filename));

        return $bytes;
    }

    /**
     * @param string $username
     * @param string $devid
     *
     * @return bool
     */
    public function LinkUserDevice($username, $devid)
    {
        $mutex = new \SimpleMutex();
        $changed = false;

        if ($mutex->Block()) {
            /** @var array<string, array<string, int>> $users */
            $users = $this->readSharedArrayFile($this->usersFilePath());

            if (!isset($users[$username])) {
                $users[$username] = [];
                $changed = true;
            }
            if (!isset($users[$username][$devid])) {
                $users[$username][$devid] = 1;
                $changed = true;
            }

            if ($changed) {
                $this->writeSharedArrayFile($this->usersFilePath(), $users, 'LinkUserDevice()');
            } else {
                \ZLog::Write(LOGLEVEL_DEBUG, self::class.'->LinkUserDevice(): nothing changed');
            }

            $mutex->Release();
        }

        return $changed;
    }

    /**
     * @param string $username
     * @param string $devid
     *
     * @return bool
     */
    public function UnLinkUserDevice($username, $devid)
    {
        $mutex = new \SimpleMutex();
        $changed = false;

        if ($mutex->Block()) {
            /** @var array<string, array<string, int>> $users */
            $users = $this->readSharedArrayFile($this->usersFilePath());

            if (isset($users[$username][$devid])) {
                unset($users[$username][$devid]);
                $changed = true;
            }

            if (isset($users[$username]) && empty($users[$username])) {
                unset($users[$username]);
                $changed = true;
            }

            if ($changed) {
                $this->writeSharedArrayFile($this->usersFilePath(), $users, 'UnLinkUserDevice()');
            } else {
                \ZLog::Write(LOGLEVEL_DEBUG, self::class.'->UnLinkUserDevice(): nothing changed');
            }

            $mutex->Release();
        }

        return $changed;
    }

    /**
     * @param string|bool $username
     *
     * @return list<string>
     */
    public function GetAllDevices($username = false)
    {
        if (false === $username) {
            $out = [];

            foreach ($this->getDeviceDataFiles() as $devdata) {
                if (\is_string($devdata) && preg_match('/\/([A-Za-z0-9]+)-'.\IStateMachine::DEVICEDATA.'$/', $devdata, $matches)) {
                    $out[] = $matches[1];
                }
            }

            return $out;
        }

        /** @var array<string, array<string, int>> $users */
        $users = $this->readSharedArrayFile($this->usersFilePath());

        return isset($users[$username]) && is_array($users[$username]) ? array_keys($users[$username]) : [];
    }

    /**
     * @return int
     */
    public function GetStateVersion()
    {
        $settings = $this->readSharedArrayFile($this->settingsFilePath());
        $value = $settings[self::VERSION] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        $version = (int) self::SUPPORTED_STATE_VERSION;
        $this->SetStateVersion($version);

        return $version;
    }

    /**
     * @param int $version
     *
     * @return int|bool
     */
    public function SetStateVersion($version)
    {
        $settings = $this->readSharedArrayFile($this->settingsFilePath());
        $settings[self::VERSION] = (int) $version;

        \ZLog::Write(LOGLEVEL_INFO, sprintf("%s->SetStateVersion() saving supported state version, value '%d'", self::class, $version));

        return $this->writeSharedArrayFile($this->settingsFilePath(), $settings, '');
    }

    /**
     * Signs a serialized payload with the deployment's state key.
     *
     * @param string $serialized the serialize() output to protect
     *
     * @return string the payload with the appended signature marker
     */
    public static function sign(string $serialized): string
    {
        return $serialized.self::SIGNATURE_MARKER.hash_hmac('sha256', $serialized, self::stateKey());
    }

    /**
     * Verifies a signed payload and returns the serialized original.
     *
     * @param string $raw      the raw file content
     * @param string $filename the source file (for the exception message)
     *
     * @return string the verified serialize() output
     *
     * @throws \StateInvalidException when the signature is missing or invalid
     */
    public static function verifySigned(string $raw, string $filename): string
    {
        $markerPosition = strrpos($raw, self::SIGNATURE_MARKER);

        if (false === $markerPosition) {
            throw new \StateInvalidException(sprintf("%s: state file '%s' is not signed - rejecting it", self::class, $filename));
        }

        $payload = substr($raw, 0, $markerPosition);
        $signature = substr($raw, $markerPosition + strlen(self::SIGNATURE_MARKER));
        $expected = hash_hmac('sha256', $payload, self::stateKey());

        if (!hash_equals($expected, $signature)) {
            throw new \StateInvalidException(sprintf("%s: state file '%s' has an invalid signature - rejecting it", self::class, $filename));
        }

        return $payload;
    }

    /**
     * The state key: EAS_STATE_KEY when configured, otherwise derived from
     * DATABASE_URL. Both variants stay outside the state directory.
     */
    private static function stateKey(): string
    {
        $configured = trim((string) (Database::env('EAS_STATE_KEY', '') ?? ''));

        if ('' !== $configured) {
            return hash('sha256', 'avalynxofficeeas-state-key|'.$configured, true);
        }

        return hash('sha256', 'avalynxofficeeas-state-key|'.(string) (Database::env('DATABASE_URL', '') ?? ''), true);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function readSharedArrayFile(string $filename): array
    {
        if (!file_exists($filename)) {
            return [];
        }

        $raw = (string) \Utils::SafeGetContents($filename, __FUNCTION__, false, false);
        $markerPosition = strrpos($raw, self::SIGNATURE_MARKER);

        // Signed content: the signature must match. Unsigned legacy content
        // stays readable - the structural object-token check below is the
        // actual guard for these scalar-only files - and is re-signed with
        // the next write.
        $payload = false !== $markerPosition ? self::verifySigned($raw, $filename) : $raw;

        // Defense in depth: these files only ever contain arrays of strings
        // and integers written by this class; object tokens cannot be valid
        // here and would be the injection payload.
        if (preg_match('/(^|[;{\[rR])([OCR]):\d+[:;]/', $payload)) {
            throw new \StateInvalidException(sprintf("%s: shared state file '%s' contains object tokens - rejecting it", self::class, $filename));
        }

        $data = unserialize($payload);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<array-key, mixed> $data
     * @param string                  $debugContext short method context for the debug log
     *
     * @return int|bool
     */
    private function writeSharedArrayFile(string $filename, array $data, string $debugContext)
    {
        $bytes = \Utils::SafePutContents($filename, self::sign(serialize($data)), true);

        if (false !== $bytes) {
            \ZLog::Write(LOGLEVEL_DEBUG, sprintf("%s->%s wrote %d bytes to shared state file '%s'", self::class, $debugContext, $bytes, $filename));
        }

        return $bytes;
    }

    private function usersFilePath(): string
    {
        return self::stateDir().'users';
    }

    private function settingsFilePath(): string
    {
        return self::stateDir().'settings';
    }

    /**
     * STATE_DIR is defined at runtime by the Z-Push configuration (dynamic
     * define()), so it is not visible to static analysis - resolve it through
     * a typed accessor instead of a bare constant lookup.
     */
    private static function stateDir(): string
    {
        $dir = \constant('STATE_DIR');

        return \is_string($dir) ? $dir : '';
    }

    /**
     * Mirrors FileStateMachine::getFullFilePath() (private in the vendor
     * class, z-push 2.7.x): validates the parts and builds the state file
     * path inside the device's directory.
     *
     * @param string          $devid
     * @param string          $type
     * @param string|bool     $key
     * @param int|bool|string $counter
     *
     * @return string
     */
    private function buildStatePath($devid, $type, $key = false, $counter = false)
    {
        $testkey = $devid.(false !== $key ? '-'.$key : '').('' !== $type ? '-'.$type : '');
        if (preg_match('/^[a-zA-Z0-9-]+$/', $testkey) || ('' === $type && false === $key)) {
            $internkey = $testkey.($counter && is_int($counter) ? '-'.$counter : '');
        } else {
            throw new \StateInvalidException(sprintf('%s->buildStatePath(): Invalid state deviceid, type, key or in any combination', self::class));
        }

        return $this->directoryForDevice($devid).'/'.basename($internkey);
    }

    /**
     * Mirrors FileStateMachine::getDirectoryForDevice() (private in the
     * vendor class, z-push 2.7.x): two-level sharding by the last characters
     * of the device id.
     *
     * @param string $devid
     *
     * @return string
     */
    private function directoryForDevice($devid)
    {
        $cleandevid = basename((string) $devid);
        $firstLevel = substr(strtolower($cleandevid), -1, 1);
        $secondLevel = substr(strtolower($cleandevid), -2, 1);

        $dir = self::stateDir().$firstLevel.'/'.$secondLevel;
        if (is_dir($dir)) {
            return $dir;
        }

        foreach ([self::stateDir().$firstLevel, $dir] as $level) {
            if (!is_dir($level) && !mkdir($level) && !is_dir($level)) {
                throw new \FatalMisconfigurationException(sprintf('%s->directoryForDevice(): Not possible to create state sub-directory: %s', self::class, $level));
            }
        }

        return $dir;
    }
}
