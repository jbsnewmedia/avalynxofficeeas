<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Tests\Backend;

use AvalynxOfficeEas\Backend\AvalynxBackend;
use AvalynxOfficeEas\Db\Model\Email;
use AvalynxOfficeEas\Db\Model\LicenseState;
use AvalynxOfficeEas\Service\ServiceHolder;
use AvalynxOfficeEas\Tests\Support\SyncProps;
use AvalynxOfficeEas\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * Backend integration tests against the shared core database schema.
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
final class AvalynxBackendTest extends TestCase
{
    use TestEnvironment;

    private AvalynxBackend $backend;

    /** @var array{user: \AvalynxOfficeEas\Db\Model\User, account: \AvalynxOfficeEas\Db\Model\Account, folder: \AvalynxOfficeEas\Db\Model\Folder, email: Email, contact: \AvalynxOfficeEas\Db\Model\Address, event: \AvalynxOfficeEas\Db\Model\CalendarEvent} */
    private array $fixture;

    protected function setUp(): void
    {
        self::bootEnvironment();
        $this->fixture = self::seed();
        $this->backend = new AvalynxBackend();
        self::assertTrue($this->backend->Logon('admin@example.com', '', 'admin'));
    }

    protected function tearDown(): void
    {
        self::tearDownEnvironment();
    }

    public function testLogonRejectsWrongCredentials(): void
    {
        self::assertFalse($this->backend->Logon('admin@example.com', '', 'wrong'));
        self::assertFalse($this->backend->Logon('nobody@example.com', '', 'admin'));
    }

    public function testLogonRejectsWebPasswordAndAcceptsOnlyAppPasswords(): void
    {
        // The web login password must never work on a device.
        self::assertFalse($this->backend->Logon('admin@example.com', '', self::SEED_WEB_PASSWORD));

        // App passwords are case-insensitive and may be typed with or
        // without the dashes/spaces of the "xxxxx-xxxxx-..." display form.
        self::assertTrue(new AvalynxBackend()->Logon('admin@example.com', '', 'AD-MIN'));
        self::assertTrue(new AvalynxBackend()->Logon('admin@example.com', '', 'ad min'));
    }

    public function testLogonRejectsRevokedAppPasswordAndRecordsUsage(): void
    {
        self::em()->clear();
        $rows = self::em()->getRepository(\AvalynxOfficeEas\Db\Model\UserAppPassword::class)->findAll();
        self::assertCount(1, $rows);
        $row = $rows[0];

        // setUp() logged on successfully -> last use recorded.
        self::assertInstanceOf(\DateTimeImmutable::class, $row->getLastUsedAt());

        $row->setRevokedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        self::em()->flush();
        self::em()->clear();

        self::assertFalse(new AvalynxBackend()->Logon('admin@example.com', '', self::SEED_APP_PASSWORD));

        // A fresh, second app password works independently of the revoked one.
        $user = self::em()->find(\AvalynxOfficeEas\Db\Model\User::class, $this->fixture['user']->getId());
        self::assertNotNull($user);
        $second = new \AvalynxOfficeEas\Db\Model\UserAppPassword();
        $second->setUser($user)->setLabel('Second')->setPasswordHash(password_hash('second', PASSWORD_BCRYPT));
        self::em()->persist($second);
        self::em()->flush();
        self::em()->clear();

        self::assertTrue(new AvalynxBackend()->Logon('admin@example.com', '', 'SECOND'));
        self::assertFalse(new AvalynxBackend()->Logon('admin@example.com', '', self::SEED_APP_PASSWORD));
    }

    public function testLogonRejectsAccountAddressAsUsername(): void
    {
        // The account (mailbox) address is not a username: devices log in
        // with the application user only.
        self::assertFalse($this->backend->Logon('info@example.com', '', 'admin'));
    }

    public function testLogonRecordsSeatActivity(): void
    {
        // The Logon() in setUp() already recorded the authentication; clear
        // the identity map because the backend loaded the user before the
        // recorder's raw update.
        self::em()->clear();
        $user = self::em()->find(\AvalynxOfficeEas\Db\Model\User::class, $this->fixture['user']->getId());

        self::assertNotNull($user);
        self::assertInstanceOf(\DateTimeImmutable::class, $user->getLastAuthAt());
        self::assertLessThanOrEqual(5, time() - $this->storedAsUtc($user->getLastAuthAt())->getTimestamp());
    }

    public function testLogonRefreshesStaleSeatActivity(): void
    {
        self::em()->clear();
        $userId = $this->fixture['user']->getId();
        $user = self::em()->find(\AvalynxOfficeEas\Db\Model\User::class, $userId);
        self::assertNotNull($user);
        $user->setLastAuthAt(new \DateTimeImmutable('-2 days', new \DateTimeZone('UTC')));
        self::em()->flush();
        self::em()->clear();

        $backend = new AvalynxBackend();
        self::assertTrue($backend->Logon('admin@example.com', '', 'admin'));

        self::em()->clear();
        $reloaded = self::em()->find(\AvalynxOfficeEas\Db\Model\User::class, $userId);
        self::assertNotNull($reloaded);
        self::assertNotNull($reloaded->getLastAuthAt());
        self::assertGreaterThan(new \DateTimeImmutable('-1 hour')->getTimestamp(), $this->storedAsUtc($reloaded->getLastAuthAt())->getTimestamp());
    }

    /**
     * last_auth_at is a timezone-less column written in UTC (the core runs
     * in UTC); Doctrine hydrates it in the Z-Push default timezone, so the
     * wall-clock value is re-interpreted as UTC for comparisons.
     */
    private function storedAsUtc(\DateTimeImmutable $stored): \DateTimeImmutable
    {
        return new \DateTimeImmutable($stored->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'));
    }

    public function testLogonKeepsRecentSeatActivityUnchanged(): void
    {
        self::em()->clear();
        $userId = $this->fixture['user']->getId();
        $recent = new \DateTimeImmutable('-30 minutes', new \DateTimeZone('UTC'));
        $user = self::em()->find(\AvalynxOfficeEas\Db\Model\User::class, $userId);
        self::assertNotNull($user);
        $user->setLastAuthAt($recent);
        self::em()->flush();
        self::em()->clear();

        $backend = new AvalynxBackend();
        self::assertTrue($backend->Logon('admin@example.com', '', 'admin'));

        $reloaded = self::em()->find(\AvalynxOfficeEas\Db\Model\User::class, $userId);
        self::assertNotNull($reloaded);
        self::assertNotNull($reloaded->getLastAuthAt());
        self::assertSame(
            $recent->format('Y-m-d H:i:s'),
            $reloaded->getLastAuthAt()->format('Y-m-d H:i:s'),
        );
    }

    public function testFailedLogonDoesNotRecordSeatActivity(): void
    {
        // Fresh read: the successful Logon() in setUp() already recorded a
        // value.
        self::em()->clear();
        $userId = $this->fixture['user']->getId();
        $user = self::em()->find(\AvalynxOfficeEas\Db\Model\User::class, $userId);
        self::assertNotNull($user);
        $user->setLastAuthAt(null);
        self::em()->flush();
        self::em()->clear();

        $backend = new AvalynxBackend();
        self::assertFalse($backend->Logon('admin@example.com', '', 'wrong'));

        $reloaded = self::em()->find(\AvalynxOfficeEas\Db\Model\User::class, $userId);
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->getLastAuthAt());
    }

    public function testGetFolderListContainsMailFoldersContactsAndCalendar(): void
    {
        $folders = $this->backend->GetFolderList();

        self::assertIsArray($folders);
        self::assertCount(3, $folders); // inbox folder + contacts + calendar
    }

    public function testLogonFailsForDeactivatedUser(): void
    {
        $user = self::em()->find(\AvalynxOfficeEas\Db\Model\User::class, $this->fixture['user']->getId());
        self::assertNotNull($user);
        $user->setActive(false);
        self::em()->flush();

        self::assertFalse($this->backend->Logon('admin@example.com', '', 'admin'));
    }

    public function testGetFolderReturnsInboxType(): void
    {
        $serverId = $this->inboxServerId();
        $folder = $this->backend->GetFolder($serverId);

        self::assertInstanceOf(\SyncFolder::class, $folder);
        self::assertSame('Posteingang', $folder->displayname);
        self::assertSame(SYNC_FOLDER_TYPE_INBOX, $folder->type);
    }

    public function testGetMessageListAnnouncesUnreadMessage(): void
    {
        $messages = $this->backend->GetMessageList($this->inboxServerId(), 0);

        self::assertIsArray($messages);
        self::assertCount(1, $messages);
        self::assertSame(0, $messages[0]['flags']);
        self::assertSame($this->fixture['email']->getId()?->toRfc4122(), $messages[0]['id']);
    }

    public function testGetMessageReturnsBodyAndSender(): void
    {
        // The mapper branches on the negotiated AS version; emulate a device
        // header since the backend is called without the request processor.
        $version = new \ReflectionProperty(\Request::class, 'asProtocolVersion');
        $version->setValue(null, '14.1');

        $message = $this->backend->GetMessage($this->inboxServerId(), $this->emailId(), $this->contentParameters());

        self::assertInstanceOf(\SyncMail::class, $message);
        self::assertSame('Incoming message', $message->subject);

        $asbody = $message->asbody;
        self::assertNotNull($asbody);
        self::assertInstanceOf(\SyncBaseBody::class, $asbody);
        self::assertSame(SYNC_BODYPREFERENCE_PLAIN, $asbody->type);
        self::assertIsResource($asbody->data);
        self::assertSame('Body', (string) stream_get_contents($asbody->data));
    }

    public function testSetReadFlagPersistsReadState(): void
    {
        $result = $this->backend->SetReadFlag($this->inboxServerId(), $this->emailId(), 1, $this->contentParameters());

        self::assertIsArray($result);
        self::assertSame(1, $result['flags']);

        self::em()->clear();
        $email = self::em()->find(Email::class, $this->fixture['email']->getId());
        self::assertNotNull($email);
        self::assertTrue($email->isRead());
    }

    public function testChangeMessageSetsFavorite(): void
    {
        $message = new \SyncMail();
        $message->flag = new \SyncMailFlags();
        $message->flag->flagstatus = SYNC_FLAGSTATUS_ACTIVE;

        $result = $this->backend->ChangeMessage($this->inboxServerId(), $this->emailId(), $message, $this->contentParameters());

        self::assertIsArray($result);
        self::assertSame(1, $result['star']);

        self::em()->clear();
        $email = self::em()->find(Email::class, $this->fixture['email']->getId());
        self::assertNotNull($email);
        self::assertTrue($email->isFavorite());
    }

    public function testChangeFolderCreatesFolderAndChangeMessageCreatesContact(): void
    {
        $newFolderId = $this->backend->ChangeFolder('0', false, 'Archiv', SYNC_FOLDER_TYPE_USER_MAIL);
        self::assertIsString($newFolderId);
        self::assertNotFalse($this->backend->GetFolder($newFolderId));

        $contact = new \SyncContact();
        $contact->fileas = 'New Contact';
        $contact->email1address = 'new@example.com';

        $created = $this->backend->ChangeMessage($this->contactServerId(), '', $contact, $this->contentParameters());

        self::assertIsArray($created);
        self::assertNotFalse($this->backend->GetMessage($this->contactServerId(), $created['id'], $this->contentParameters()));
    }

    public function testSendMailQueuesMessageWithoutLicenseHold(): void
    {
        $sendMail = new \SyncSendMail();
        $sendMail->clientid = 'test-client-1';
        $sendMail->mime = "From: info@example.com\r\n"
            ."To: recipient@example.com\r\n"
            ."Subject: Queued from device\r\n"
            ."Content-Type: text/plain; charset=UTF-8\r\n"
            ."\r\n"
            .'Queued body';

        self::assertTrue($this->backend->SendMail($sendMail));

        $email = self::em()->getRepository(Email::class)->findOneBy(['importKey' => 'eas|test-client-1']);
        self::assertNotNull($email);
        self::assertTrue($email->isOutgoing());
        self::assertFalse($email->isLicenseHold());
        self::assertFalse($email->isSent());

        // A repeated import of the same client id does not create duplicates.
        self::assertFalse($this->backend->SendMail($sendMail));
    }

    public function testSendMailStoresMultipartMessageWithAttachment(): void
    {
        $sendMail = new \SyncSendMail();
        $sendMail->clientid = 'test-client-mime';
        $sendMail->mime = implode("\r\n", [
            'From: Info <info@example.com>',
            'To: "Doe, Jane" <jane@example.com>, bob@example.net',
            'Cc: =?UTF-8?Q?J=C3=BCrgen?= <juergen@example.com>',
            'Reply-To: support@example.com',
            'Subject: =?UTF-8?B?QW5oYW5nIMOEbmRlcnVuZw==?=',
            'Content-Type: multipart/mixed; boundary="outer"',
            '',
            '--outer',
            'Content-Type: multipart/alternative; boundary="inner"',
            '',
            '--inner',
            'Content-Type: text/plain; charset=utf-8',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            'Text =E2=82=AC',
            '--inner',
            'Content-Type: text/html; charset=utf-8',
            '',
            '<p>Html €</p>',
            '--inner--',
            '--outer',
            'Content-Type: text/plain; name="notes.txt"',
            'Content-Disposition: attachment; filename="notes.txt"',
            'Content-Transfer-Encoding: base64',
            '',
            base64_encode('attachment payload'),
            '--outer--',
            '',
        ]);

        self::assertTrue($this->backend->SendMail($sendMail));

        self::em()->clear();
        $email = self::em()->getRepository(Email::class)->findOneBy(['importKey' => 'eas|test-client-mime']);
        self::assertNotNull($email);
        self::assertSame('Anhang Änderung', $email->getSubject());

        $addresses = [];
        foreach ($email->getEmailAddresses() as $link) {
            $addresses[$link->getType()->value][] = [$link->getAddress()?->getEmail(), $link->getAddress()?->getName()];
        }
        self::assertSame([['info@example.com', 'Info']], $addresses[\AvalynxOfficeEas\Db\Model\EmailAddressType::SENDER->value] ?? []);
        self::assertSame([['jane@example.com', 'Doe, Jane'], ['bob@example.net', null]], $addresses[\AvalynxOfficeEas\Db\Model\EmailAddressType::RECIPIENT->value] ?? []);
        self::assertSame([['juergen@example.com', 'Jürgen']], $addresses[\AvalynxOfficeEas\Db\Model\EmailAddressType::CC->value] ?? []);
        self::assertSame([['support@example.com', null]], $addresses[\AvalynxOfficeEas\Db\Model\EmailAddressType::REPLY_TO->value] ?? []);

        $bodies = [];
        foreach ($email->getEmailBodies() as $link) {
            $bodies[$link->getContentType()->value] = $link->getBody()?->getContent();
        }
        self::assertSame('Text €', $bodies[\AvalynxOfficeEas\Db\Model\BodyContentType::TEXT->value] ?? null);
        self::assertSame('<p>Html €</p>', $bodies[\AvalynxOfficeEas\Db\Model\BodyContentType::HTML->value] ?? null);

        $attachments = $email->getEmailAttachments();
        self::assertCount(1, $attachments);
        $attachment = $attachments->first() instanceof \AvalynxOfficeEas\Db\Model\EmailAttachmentLink ? $attachments->first()->getAttachment() : null;
        self::assertNotNull($attachment);
        self::assertSame('notes.txt', $attachment->getName());
        self::assertSame('text/plain', $attachment->getMimeType());
        self::assertSame(strlen('attachment payload'), $attachment->getSize());
    }

    public function testSendMailRejectsAttachmentAboveLimit(): void
    {
        // Re-bootstrap with the smallest allowed attachment limit (1 KiB).
        $previous = $_ENV['ATTACHMENT_STORAGE_MAX_BYTES'] ?? null;
        $_ENV['ATTACHMENT_STORAGE_MAX_BYTES'] = '1024';

        try {
            ServiceHolder::reset();
            ServiceHolder::bootstrap(dirname(__DIR__, 2), self::em());
            $backend = new AvalynxBackend();
            self::assertTrue($backend->Logon('admin@example.com', '', 'admin'));

            $sendMail = new \SyncSendMail();
            $sendMail->clientid = 'test-client-oversize';
            $sendMail->mime = implode("\r\n", [
                'From: info@example.com',
                'To: recipient@example.com',
                'Subject: Too large',
                'Content-Type: multipart/mixed; boundary="b"',
                '',
                '--b',
                'Content-Type: text/plain',
                '',
                'body',
                '--b',
                'Content-Type: application/octet-stream; name="big.bin"',
                'Content-Disposition: attachment; filename="big.bin"',
                'Content-Transfer-Encoding: base64',
                '',
                base64_encode(str_repeat('x', 2048)),
                '--b--',
                '',
            ]);

            try {
                $backend->SendMail($sendMail);
                self::fail('Oversized attachments must abort the submission.');
            } catch (\StatusException $exception) {
                self::assertSame(SYNC_COMMONSTATUS_MAILSUBMISSIONFAILED, $exception->getCode());
            }

            // Nothing was stored.
            self::em()->clear();
            self::assertNull(self::em()->getRepository(Email::class)->findOneBy(['importKey' => 'eas|test-client-oversize']));
        } finally {
            if (null === $previous) {
                unset($_ENV['ATTACHMENT_STORAGE_MAX_BYTES']);
            } else {
                $_ENV['ATTACHMENT_STORAGE_MAX_BYTES'] = $previous;
            }
        }
    }

    public function testEmptyFolderMovesMailsToWasteBasketAndEmptyingWasteBasketDeletesPermanently(): void
    {
        self::assertTrue($this->backend->EmptyFolder($this->inboxServerId()));

        // Emptying a regular folder is recoverable: the mail is moved into the Papierkorb.
        self::em()->clear();
        $email = self::em()->find(Email::class, $this->fixture['email']->getId());
        self::assertNotNull($email);
        $folderNames = [];
        foreach ($email->getEmailFolders() as $emailFolder) {
            $folderNames[] = $emailFolder->getFolder()?->getName();
        }
        self::assertSame(['Papierkorb'], $folderNames);
        self::assertSame([], $this->backend->GetMessageList($this->inboxServerId(), 0));

        // Emptying the Papierkorb itself removes the mails permanently.
        $wasteBasketId = $this->backend->GetWasteBasket();
        self::assertIsString($wasteBasketId);
        self::assertTrue($this->backend->EmptyFolder($wasteBasketId));

        self::em()->clear();
        self::assertNull(self::em()->find(Email::class, $this->fixture['email']->getId()));
    }

    public function testReadOnlyModeBlocksMutationsAndHoldsOutgoingMail(): void
    {
        self::em()->getRepository(LicenseState::class)->findAll();
        $this->setLicenseStatus('READ_ONLY');
        self::em()->clear();

        $emailId = $this->emailId();

        self::assertFalse($this->backend->SetReadFlag($this->inboxServerId(), $emailId, 1, $this->contentParameters()));
        self::assertFalse($this->backend->DeleteMessage($this->inboxServerId(), $emailId, $this->contentParameters()));
        self::assertFalse($this->backend->MoveMessage($this->inboxServerId(), $emailId, $this->inboxServerId(), $this->contentParameters()));
        self::assertFalse($this->backend->EmptyFolder($this->inboxServerId()));
        self::assertFalse($this->backend->ChangeFolder('0', false, 'Blocked', SYNC_FOLDER_TYPE_USER_MAIL));

        $favorite = new \SyncMail();
        $favorite->flag = new \SyncMailFlags();
        $favorite->flag->flagstatus = SYNC_FLAGSTATUS_ACTIVE;
        self::assertFalse($this->backend->ChangeMessage($this->inboxServerId(), $emailId, $favorite, $this->contentParameters()));

        // Nothing was changed.
        self::em()->clear();
        $email = self::em()->find(Email::class, $this->fixture['email']->getId());
        self::assertNotNull($email);
        self::assertFalse($email->isRead());
        self::assertFalse($email->isFavorite());

        // Sending is stored with a license hold instead of being queued. A
        // new request re-authenticates after the clear.
        self::assertTrue($this->backend->Logon('admin@example.com', '', 'admin'));
        $sendMail = new \SyncSendMail();
        $sendMail->clientid = 'held-client-1';
        $sendMail->mime = "From: info@example.com\r\nTo: r@example.com\r\nSubject: Held\r\nContent-Type: text/plain\r\n\r\nHeld body";
        self::assertFalse($this->backend->SendMail($sendMail));

        $held = self::em()->getRepository(Email::class)->findOneBy(['importKey' => 'eas|held-client-1']);
        self::assertNotNull($held);
        self::assertTrue($held->isLicenseHold());
        self::assertFalse($held->isSent());
    }

    public function testDeleteMessageAndEmptyFolder(): void
    {
        self::assertTrue($this->backend->DeleteMessage($this->inboxServerId(), $this->emailId(), $this->contentParameters()));

        // The mail lands in the Papierkorb (recoverable) instead of being
        // removed.
        self::em()->clear();
        $email = self::em()->find(Email::class, $this->fixture['email']->getId());
        self::assertNotNull($email);
        $folderNames = [];
        foreach ($email->getEmailFolders() as $emailFolder) {
            $folderNames[] = $emailFolder->getFolder()?->getName();
        }
        self::assertSame(['Papierkorb'], $folderNames);

        // Deleting inside the wastebasket removes the mail permanently.
        $wasteBasketId = $this->backend->GetWasteBasket();
        self::assertIsString($wasteBasketId);
        self::assertTrue($this->backend->DeleteMessage($wasteBasketId, $this->emailId(), $this->contentParameters()));

        self::em()->clear();
        self::assertNull(self::em()->find(Email::class, $this->fixture['email']->getId()));
    }

    public function testGetWasteBasketCreatesFolder(): void
    {
        $wasteBasketId = $this->backend->GetWasteBasket();

        self::assertIsString($wasteBasketId);
        $folder = $this->backend->GetFolder($wasteBasketId);
        self::assertInstanceOf(\SyncFolder::class, $folder);
        self::assertSame('Papierkorb', $folder->displayname);
    }

    public function testGetFolderListContainsEveryAssignedAccount(): void
    {
        // A second account assigned to the same user doubles the folder set.
        $user = self::em()->find(\AvalynxOfficeEas\Db\Model\User::class, $this->fixture['user']->getId());
        self::assertNotNull($user);

        $second = new \AvalynxOfficeEas\Db\Model\Account();
        $second->setName('Office');
        $second->setEmail('office@example.com');
        self::em()->persist($second);

        $secondFolder = new \AvalynxOfficeEas\Db\Model\Folder();
        $secondFolder->setName('Posteingang');
        $secondFolder->setFunctions([\AvalynxOfficeEas\Db\Model\FolderFunction::INBOX->value]);
        self::em()->persist($secondFolder);

        $secondAccountId = $second->getId();
        $secondFolderId = $secondFolder->getId();
        \assert(null !== $secondAccountId && null !== $secondFolderId);

        self::em()->persist(
            new \AvalynxOfficeEas\Db\Model\UserAccount()->setUser($user)->setAccount($second),
        );
        self::em()->persist(
            new \AvalynxOfficeEas\Db\Model\FolderAccount()->setAccount($second)->setFolder($secondFolder)->setSortOrder(0),
        );
        self::em()->flush();
        self::em()->clear();

        self::assertTrue($this->backend->Logon('admin@example.com', '', 'admin'));

        $folders = $this->backend->GetFolderList();

        self::assertIsArray($folders);
        // 2 mail folders + 2 contact folders + 2 calendar folders
        self::assertCount(6, $folders);

        $mapping = new \AvalynxOfficeEas\Service\FolderMapping();
        $secondInboxId = $mapping->folderServerId($secondAccountId, $secondFolderId);
        $folder = $this->backend->GetFolder($secondInboxId);

        self::assertInstanceOf(\SyncFolder::class, $folder);
        // Multi-account: the folder name is prefixed with the account name.
        self::assertSame('Office / Posteingang', $folder->displayname);

        // The single-account fixture folder is prefixed with its account as well.
        $first = $this->backend->GetFolder($this->inboxServerId());
        self::assertInstanceOf(\SyncFolder::class, $first);
        self::assertSame('Info / Posteingang', $first->displayname);
    }

    private function inboxServerId(): string
    {
        $accountId = $this->fixture['account']->getId();
        $folderId = $this->fixture['folder']->getId();
        \assert(null !== $accountId && null !== $folderId);

        return new \AvalynxOfficeEas\Service\FolderMapping()->folderServerId($accountId, $folderId);
    }

    private function contactServerId(): string
    {
        $accountId = $this->fixture['account']->getId();
        \assert(null !== $accountId);

        return new \AvalynxOfficeEas\Service\FolderMapping()->contactServerId($accountId);
    }

    private function contentParameters(): \ContentParameters
    {
        $parameters = new \ContentParameters();

        $bodyPreference = new \BodyPreference();
        SyncProps::set($bodyPreference, 'type', SYNC_BODYPREFERENCE_PLAIN);
        SyncProps::set($bodyPreference, 'truncationsize', 102400);
        SyncProps::set($parameters, 'bodypreference', [$bodyPreference]);

        return $parameters;
    }

    private function emailId(): string
    {
        $id = $this->fixture['email']->getId();
        \assert(null !== $id);

        return $id->toRfc4122();
    }
}
