<?php

declare(strict_types=1);

namespace AvalynxOfficeEas\Tests\Backend;

use AvalynxOfficeEas\Backend\AvalynxBackend;
use AvalynxOfficeEas\Db\Model\Email;
use AvalynxOfficeEas\Db\Model\LicenseState;
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

    /** @var array{user: \AvalynxOfficeEas\Db\Model\User, mailbox: \AvalynxOfficeEas\Db\Model\Mailbox, folder: \AvalynxOfficeEas\Db\Model\Folder, email: Email, contact: \AvalynxOfficeEas\Db\Model\Address, event: \AvalynxOfficeEas\Db\Model\CalendarEvent} */
    private array $fixture;

    protected function setUp(): void
    {
        self::bootEnvironment();
        $this->fixture = self::seed();
        $this->backend = new AvalynxBackend();
        self::assertTrue($this->backend->Logon('info@example.com', '', 'admin'));
    }

    protected function tearDown(): void
    {
        self::tearDownEnvironment();
    }

    public function testLogonRejectsWrongCredentials(): void
    {
        self::assertFalse($this->backend->Logon('info@example.com', '', 'wrong'));
        self::assertFalse($this->backend->Logon('nobody@example.com', '', 'admin'));
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
        self::assertLessThanOrEqual(5, time() - $user->getLastAuthAt()->getTimestamp());
    }

    public function testLogonRefreshesStaleSeatActivity(): void
    {
        self::em()->clear();
        $userId = $this->fixture['user']->getId();
        $user = self::em()->find(\AvalynxOfficeEas\Db\Model\User::class, $userId);
        self::assertNotNull($user);
        $user->setLastAuthAt(new \DateTimeImmutable('-2 days'));
        self::em()->flush();
        self::em()->clear();

        $backend = new AvalynxBackend();
        self::assertTrue($backend->Logon('info@example.com', '', 'admin'));

        self::em()->clear();
        $reloaded = self::em()->find(\AvalynxOfficeEas\Db\Model\User::class, $userId);
        self::assertNotNull($reloaded);
        self::assertNotNull($reloaded->getLastAuthAt());
        self::assertGreaterThan(new \DateTimeImmutable('-1 hour')->getTimestamp(), $reloaded->getLastAuthAt()->getTimestamp());
    }

    public function testLogonKeepsRecentSeatActivityUnchanged(): void
    {
        self::em()->clear();
        $userId = $this->fixture['user']->getId();
        $recent = new \DateTimeImmutable('-30 minutes');
        $user = self::em()->find(\AvalynxOfficeEas\Db\Model\User::class, $userId);
        self::assertNotNull($user);
        $user->setLastAuthAt($recent);
        self::em()->flush();
        self::em()->clear();

        $backend = new AvalynxBackend();
        self::assertTrue($backend->Logon('info@example.com', '', 'admin'));

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
        self::assertFalse($backend->Logon('info@example.com', '', 'wrong'));

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

        self::assertFalse($this->backend->Logon('info@example.com', '', 'admin'));
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
        self::assertTrue($this->backend->Logon('info@example.com', '', 'admin'));
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

    private function inboxServerId(): string
    {
        $mailboxId = $this->fixture['mailbox']->getId();
        $folderId = $this->fixture['folder']->getId();
        \assert(null !== $mailboxId && null !== $folderId);

        return new \AvalynxOfficeEas\Service\FolderMapping()->folderServerId($mailboxId, $folderId);
    }

    private function contactServerId(): string
    {
        $mailboxId = $this->fixture['mailbox']->getId();
        \assert(null !== $mailboxId);

        return new \AvalynxOfficeEas\Service\FolderMapping()->contactServerId($mailboxId);
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
