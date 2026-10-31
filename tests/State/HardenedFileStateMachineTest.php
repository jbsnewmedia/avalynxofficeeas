<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Tests\State;

use AvalynxOfficeEas\State\HardenedFileStateMachine;
use PHPUnit\Framework\TestCase;

final class HardenedFileStateMachineTest extends TestCase
{
    private string $deviceId;

    protected function setUp(): void
    {
        // Unique device per run: the machine shards by device id, so the
        // test never touches states of other tests or real devices.
        $this->deviceId = 'test'.bin2hex(random_bytes(6));

        $_GET['DeviceId'] = $this->deviceId;
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    protected function tearDown(): void
    {
        unset($_GET['DeviceId'], $_SERVER['REQUEST_METHOD']);

        $deviceDir = $this->deviceDir();
        if (is_dir($deviceDir)) {
            foreach ((array) glob($deviceDir.'/*') ?: [] as $file) {
                @unlink((string) $file);
            }
            @rmdir($deviceDir);
        }
    }

    public function testSignAndVerifyRoundTrip(): void
    {
        $payload = 'a:1:{s:3:"foo";s:3:"bar";}';

        self::assertSame($payload, HardenedFileStateMachine::verifySigned(HardenedFileStateMachine::sign($payload), 'test'));
    }

    public function testVerifyRejectsUnsignedContent(): void
    {
        $this->expectException(\StateInvalidException::class);
        $this->expectExceptionMessage('not signed');

        HardenedFileStateMachine::verifySigned('O:8:"Gadget":0:{}', 'attack');
    }

    public function testVerifyRejectsTamperedContent(): void
    {
        $signed = HardenedFileStateMachine::sign('a:1:{s:3:"foo";s:3:"bar";}');
        $tampered = str_replace('bar', 'baz', $signed);

        $this->expectException(\StateInvalidException::class);
        $this->expectExceptionMessage('invalid signature');

        HardenedFileStateMachine::verifySigned($tampered, 'attack');
    }

    public function testSetAndGetStateRoundTrip(): void
    {
        $machine = new HardenedFileStateMachine();
        $state = ['step' => 42, 'ids' => ['a', 'b']];

        $bytes = $machine->SetState($state, $this->deviceId, 'FOLDERDATA', 'sync-1', 7);
        self::assertNotFalse($bytes);

        // The stored file really carries a signature.
        $raw = (string) file_get_contents($this->stateFile('FOLDERDATA', 'sync-1', 7));
        $markerPosition = strpos($raw, '--avalynx-state-signature-v1--:');
        self::assertNotFalse($markerPosition);
        self::assertStringNotContainsString('O:', substr($raw, 0, (int) $markerPosition));

        self::assertSame($state, $machine->GetState($this->deviceId, 'FOLDERDATA', 'sync-1', 7, false));
    }

    public function testGetStateRejectsForgedUnsignedFile(): void
    {
        $machine = new HardenedFileStateMachine();
        $file = $this->stateFile('FOLDERDATA', 'forged', 1);
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, 'a:1:{s:3:"foo";s:3:"bar";}');

        try {
            $this->expectException(\StateInvalidException::class);
            $this->expectExceptionMessage('not signed');

            $machine->GetState($this->deviceId, 'FOLDERDATA', 'forged', 1, false);
        } finally {
            @unlink($file);
        }
    }

    public function testGetStateThrowsStateNotFoundForMissingFile(): void
    {
        $machine = new HardenedFileStateMachine();

        $this->expectException(\StateNotFoundException::class);

        $machine->GetState($this->deviceId, 'FOLDERDATA', 'missing', 1, false);
    }

    public function testUsersFileRejectsObjectTokensEvenWithValidSignature(): void
    {
        $machine = new HardenedFileStateMachine();

        // Simulates a leaked signing key: the payload is signed correctly
        // but carries an object - the structural check must still reject it.
        $forged = HardenedFileStateMachine::sign('a:1:{i:0;O:8:"Gadget":0:{}}');
        $usersFile = $this->usersFile();
        $backup = is_string(@file_get_contents($usersFile)) ? (string) file_get_contents($usersFile) : null;

        try {
            file_put_contents($usersFile, $forged);

            $this->expectException(\StateInvalidException::class);
            $this->expectExceptionMessage('object tokens');

            $machine->GetAllDevices('admin@example.com');
        } finally {
            if (null !== $backup) {
                @file_put_contents($usersFile, $backup);
            } else {
                @unlink($usersFile);
            }
        }
    }

    public function testLinkUserDeviceWritesSignedFileAndReadsItBack(): void
    {
        $machine = new HardenedFileStateMachine();
        $usersFile = $this->usersFile();
        $backup = is_string(@file_get_contents($usersFile)) ? (string) file_get_contents($usersFile) : null;

        try {
            // Unsigned legacy content (e.g. left from an earlier version) is
            // still readable and gets re-signed by the next write.
            file_put_contents($usersFile, 'a:1:{s:5:"admin";a:1:{s:5:"dev01";i:1;}}');

            self::assertSame(['dev01'], $machine->GetAllDevices('admin'));
            self::assertTrue($machine->LinkUserDevice('admin@example.com', $this->deviceId));

            $raw = (string) file_get_contents($usersFile);
            self::assertStringContainsString('--avalynx-state-signature-v1--:', $raw);
            self::assertSame([$this->deviceId], $machine->GetAllDevices('admin@example.com'));
        } finally {
            if (null !== $backup) {
                @file_put_contents($usersFile, $backup);
            } else {
                @unlink($usersFile);
            }
        }
    }

    private function usersFile(): string
    {
        $stateDir = \constant('STATE_DIR');

        return (\is_string($stateDir) ? $stateDir : '').'users';
    }

    private function deviceDir(): string
    {
        $lower = strtolower($this->deviceId);
        $stateDir = \constant('STATE_DIR');

        return (\is_string($stateDir) ? $stateDir : '').substr($lower, -1, 1).'/'.substr($lower, -2, 1);
    }

    private function stateFile(string $type, string $key, int $counter): string
    {
        return $this->deviceDir().'/'.$this->deviceId.'-'.$key.'-'.$type.'-'.$counter;
    }
}
