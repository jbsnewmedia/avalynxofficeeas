<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Backend;

use AvalynxOfficeEas\Db\Model\Address;
use AvalynxOfficeEas\Db\Model\CalendarEvent;
use AvalynxOfficeEas\Db\Model\Email;
use AvalynxOfficeEas\Db\Model\EmailAttachmentLink;
use AvalynxOfficeEas\Db\Model\Folder;
use AvalynxOfficeEas\Db\Model\Mailbox;
use AvalynxOfficeEas\Dto\EasMailboxContext;
use AvalynxOfficeEas\Licensing\MutationBlockedException;
use AvalynxOfficeEas\Service\AttachmentService;
use AvalynxOfficeEas\Service\AuthThrottle;
use AvalynxOfficeEas\Service\CalendarMapper;
use AvalynxOfficeEas\Service\CalendarService;
use AvalynxOfficeEas\Service\ContactMapper;
use AvalynxOfficeEas\Service\ContactService;
use AvalynxOfficeEas\Service\FolderMapping;
use AvalynxOfficeEas\Service\FolderService;
use AvalynxOfficeEas\Service\MailboxResolver;
use AvalynxOfficeEas\Service\MailFolderService;
use AvalynxOfficeEas\Service\MessageMapper;
use AvalynxOfficeEas\Service\MessageMutationService;
use AvalynxOfficeEas\Service\SendMailService;
use AvalynxOfficeEas\Service\ServiceHolder;
use Symfony\Component\Uid\UuidV7;

/**
 * Z-Push backend bridging Exchange ActiveSync to the AvalynxOffice mail
 * storage. Folder ids are deterministic base64 encoded
 * "mail|mailboxId|folderId" and "contacts|mailboxId" strings so they stay
 * stable across devices.
 */
final class AvalynxBackend extends \BackendDiff
{
    private ?EasMailboxContext $context = null;
    private ?Folder $wasteBasket = null;

    private readonly MailboxResolver $mailboxResolver;
    private readonly MailFolderService $mailFolderService;
    private readonly MessageMapper $messageMapper;
    private readonly SendMailService $sendMailService;
    private readonly FolderMapping $folderMapping;
    private readonly AttachmentService $attachmentService;
    private readonly ContactService $contactService;
    private readonly ContactMapper $contactMapper;
    private readonly CalendarService $calendarService;
    private readonly CalendarMapper $calendarMapper;
    private readonly MessageMutationService $mutationService;
    private readonly FolderService $folderService;
    private readonly \AvalynxOfficeEas\Licensing\LicenseState $licenseState;
    private readonly \Doctrine\ORM\EntityManagerInterface $entityManager;
    private readonly \AvalynxOfficeEas\Service\LastAuthenticationRecorder $lastAuthenticationRecorder;
    private readonly AuthThrottle $authThrottle;

    public function __construct()
    {
        $holder = ServiceHolder::instance();

        $this->mailboxResolver = $holder->getMailboxResolver();
        $this->mailFolderService = $holder->getMailFolderService();
        $this->messageMapper = $holder->getMessageMapper();
        $this->sendMailService = $holder->getSendMailService();
        $this->folderMapping = $holder->getFolderMapping();
        $this->attachmentService = $holder->getAttachmentService();
        $this->contactService = $holder->getContactService();
        $this->contactMapper = $holder->getContactMapper();
        $this->calendarService = $holder->getCalendarService();
        $this->calendarMapper = $holder->getCalendarMapper();
        $this->mutationService = $holder->getMutationService();
        $this->folderService = $holder->getFolderService();
        $this->licenseState = $holder->getLicenseState();
        $this->entityManager = $holder->getEntityManager();
        $this->lastAuthenticationRecorder = $holder->getLastAuthenticationRecorder();
        $this->authThrottle = $holder->getAuthThrottle();
    }

    public function Logon($username, $domain, $password): bool
    {
        if (!is_string($username) || !is_string($password)) {
            \ZLog::Write(LOGLEVEL_WARN, 'AvalynxBackend->Logon(): invalid credentials provided');

            return false;
        }

        // Brute-force guard: block further attempts from a client IP that
        // exceeded the failure limit; a successful logon clears the counter.
        $clientIp = AuthThrottle::clientIp();

        if ($this->authThrottle->isBlocked($clientIp)) {
            \ZLog::Write(LOGLEVEL_WARN, sprintf('AvalynxBackend->Logon(): blocked, too many failed attempts from %s', $clientIp));

            return false;
        }

        $context = $this->mailboxResolver->resolve($username, $password);

        if (null === $context) {
            $this->authThrottle->recordFailure($clientIp);
            \ZLog::Write(LOGLEVEL_WARN, sprintf("AvalynxBackend->Logon('%s'): failed", $username));

            return false;
        }

        $this->authThrottle->reset($clientIp);

        $this->context = $context;
        $this->wasteBasket = null;

        // Successful device authentication: refresh the seat activity (APL
        // 1.0 Active Seat definition, last 30 calendar days). The recorder
        // is deliberately invoked after the context is set so the request
        // proceeds even if the accounting write fails.
        try {
            $this->lastAuthenticationRecorder->record($context->getUser());
        } catch (\Throwable $exception) {
            \ZLog::Write(LOGLEVEL_WARN, sprintf('AvalynxBackend->Logon(): seat activity could not be recorded (%s)', $exception->getMessage()));
        }

        \ZLog::Write(LOGLEVEL_DEBUG, sprintf("AvalynxBackend->Logon('%s'): success", $username));

        return true;
    }

    public function Logoff(): bool
    {
        $this->context = null;
        $this->wasteBasket = null;

        return true;
    }

    public function GetSupportedASVersion(): string
    {
        return \ZPush::ASV_141;
    }

    /**
     * @return list<array{id: string, parent: string, mod: string}>|false
     */
    public function GetFolderList(): array|false
    {
        if (!$this->requireMailbox()) {
            return false;
        }

        $mailbox = $this->mailbox();
        $mailboxId = $mailbox->getId();

        if (null === $mailboxId) {
            return false;
        }

        $folders = [];

        foreach ($this->mailFolderService->foldersForMailbox($mailbox) as $folder) {
            $folderId = $folder->getId();

            if (null === $folderId) {
                continue;
            }

            $folders[] = [
                'id' => $this->folderMapping->folderServerId($mailboxId, $folderId),
                'parent' => '0',
                'mod' => $this->folderStatHash($folder),
            ];
        }

        $folders[] = [
            'id' => $this->folderMapping->contactServerId($mailboxId),
            'parent' => '0',
            'mod' => $this->contactFolderStatHash($mailbox),
        ];

        $folders[] = [
            'id' => $this->folderMapping->calendarServerId($mailboxId),
            'parent' => '0',
            'mod' => $this->calendarFolderStatHash($mailbox),
        ];

        return $folders;
    }

    public function GetFolder($id): \SyncFolder|false
    {
        if (!is_string($id)) {
            return false;
        }

        $contactsFolder = $this->contactsFolderFromServerId($id);

        if (null !== $contactsFolder) {
            $syncFolder = new \SyncFolder();
            $syncFolder->serverid = $id;
            $syncFolder->parentid = '0';
            $syncFolder->displayname = FolderMapping::CONTACTS_NAME;
            $syncFolder->type = SYNC_FOLDER_TYPE_CONTACT;

            return $syncFolder;
        }

        if (null !== $this->calendarFolderFromServerId($id)) {
            $syncFolder = new \SyncFolder();
            $syncFolder->serverid = $id;
            $syncFolder->parentid = '0';
            $syncFolder->displayname = FolderMapping::CALENDAR_NAME;
            $syncFolder->type = SYNC_FOLDER_TYPE_APPOINTMENT;

            return $syncFolder;
        }

        $folder = $this->folderFromServerId($id);

        if (null === $folder) {
            \ZLog::Write(LOGLEVEL_WARN, sprintf("AvalynxBackend->GetFolder('%s'): unknown folder", $id));

            return false;
        }

        $syncFolder = new \SyncFolder();
        $syncFolder->serverid = $id;
        $syncFolder->parentid = '0';
        $syncFolder->displayname = $folder->getName();
        $syncFolder->type = $this->folderMapping->easType($folder);

        return $syncFolder;
    }

    /**
     * @return array{id: string, parent: string, mod: string}|false
     */
    public function StatFolder($id): array|false
    {
        if (!is_string($id)) {
            return false;
        }

        $contactsFolder = $this->contactsFolderFromServerId($id);

        if (null !== $contactsFolder) {
            return [
                'id' => $id,
                'parent' => '0',
                'mod' => $this->contactFolderStatHash($this->mailbox()),
            ];
        }

        if (null !== $this->calendarFolderFromServerId($id)) {
            return [
                'id' => $id,
                'parent' => '0',
                'mod' => $this->calendarFolderStatHash($this->mailbox()),
            ];
        }

        $folder = $this->folderFromServerId($id);

        if (null === $folder) {
            return false;
        }

        return [
            'id' => $id,
            'parent' => '0',
            'mod' => $this->folderStatHash($folder),
        ];
    }

    /**
     * @param string       $folderid
     * @param string|false $oldid
     * @param string       $displayname
     * @param int          $type
     */
    public function ChangeFolder($folderid, $oldid, $displayname, $type): string|false
    {
        if (false !== $oldid) {
            \ZLog::Write(LOGLEVEL_WARN, 'AvalynxBackend->ChangeFolder(): renaming folders is not supported');

            return false;
        }

        if (!$this->requireMailbox() || !is_string($displayname) || '' === $displayname) {
            return false;
        }

        $parent = is_string($folderid) ? $this->folderFromServerId($folderid) : null;

        if ('0' !== $folderid && null === $parent) {
            \ZLog::Write(LOGLEVEL_WARN, sprintf("AvalynxBackend->ChangeFolder('%s'): parent folder not found", $folderid));

            return false;
        }

        $mailbox = $this->mailbox();
        $mailboxId = $mailbox->getId();

        try {
            $this->licenseState->assertMutationAllowed('SETTINGS_WRITE');
            $folderId = $this->folderService->ensureFolder($mailbox, $displayname)->getId();
        } catch (MutationBlockedException $exception) {
            \ZLog::Write(LOGLEVEL_WARN, sprintf('AvalynxBackend->ChangeFolder(): blocked by the license policy (%s)', $exception->operation));

            return false;
        }

        if (null === $mailboxId || null === $folderId) {
            return false;
        }

        return $this->folderMapping->folderServerId($mailboxId, $folderId);
    }

    /**
     * @param string      $id
     * @param string|bool $parentid
     */
    public function DeleteFolder($id, $parentid): bool
    {
        \ZLog::Write(LOGLEVEL_WARN, 'AvalynxBackend->DeleteFolder(): deleting folders is not supported');

        return false;
    }

    /**
     * @param string $folderid
     * @param mixed  $cutoffdate Z-Push annotates this as legacy "long"
     *
     * @return list<array{id: string, mod: string, flags: int, star: int}>|false
     */
    public function GetMessageList($folderid, $cutoffdate): array|false
    {
        if (!$this->requireMailbox()) {
            return false;
        }

        $contactsFolder = $this->contactsFolderFromServerId((string) $folderid);

        if (null !== $contactsFolder) {
            $messages = [];

            foreach ($this->contactService->contactsForMailbox($this->mailbox()) as $contact) {
                $messages[] = $this->contactMapper->stat($contact);
            }

            return $messages;
        }

        if (null !== $this->calendarFolderFromServerId((string) $folderid)) {
            $messages = [];

            foreach ($this->calendarService->eventsForMailbox($this->mailbox()) as $event) {
                $messages[] = $this->calendarMapper->stat($event);
            }

            return $messages;
        }

        $folder = $this->folderFromServerId($folderid);

        if (null === $folder) {
            return false;
        }

        $cutoff = \is_int($cutoffdate) && $cutoffdate > 0 ? $cutoffdate : 0;
        $messages = [];

        foreach ($this->mailFolderService->emailsForFolder($this->mailbox(), $folder, $cutoff) as $email) {
            $messages[] = $this->messageMapper->statMessage($email);
        }

        return $messages;
    }

    public function GetMessage($folderid, $id, $contentparameters): \SyncMail|\SyncContact|\SyncAppointment|false
    {
        $contact = $this->contactFromServerId($folderid, $id);

        if (null !== $contact) {
            return $this->contactMapper->buildSyncContact($contact);
        }

        $event = $this->eventFromServerId($folderid, $id);

        if (null !== $event) {
            return $this->calendarMapper->buildSyncAppointment($event);
        }

        $email = $this->messageFromServerId($folderid, $id);

        if (null === $email) {
            return false;
        }

        if (!$contentparameters instanceof \ContentParameters) {
            return false;
        }

        return $this->messageMapper->buildSyncMail($email, $contentparameters);
    }

    /**
     * @return array{id: string, mod: string, flags: int, star: int}|false
     */
    public function StatMessage($folderid, $id): array|false
    {
        $contact = $this->contactFromServerId($folderid, $id);

        if (null !== $contact) {
            return $this->contactMapper->stat($contact);
        }

        $event = $this->eventFromServerId($folderid, $id);

        if (null !== $event) {
            return $this->calendarMapper->stat($event);
        }

        $email = $this->messageFromServerId($folderid, $id);

        if (null === $email) {
            return false;
        }

        return $this->messageMapper->statMessage($email);
    }

    /**
     * @param string                                  $folderid
     * @param string|false                            $id
     * @param \SyncMail|\SyncContact|\SyncAppointment $message
     * @param \ContentParameters                      $contentparameters
     *
     * @return array{id: string, mod: string, flags: int, star: int}|false
     */
    public function ChangeMessage($folderid, $id, $message, $contentparameters): array|false
    {
        try {
            $calendarEvent = $this->eventFromServerId($folderid, (string) $id);

            if (null !== $calendarEvent || null !== $this->calendarFolderFromServerId((string) $folderid)) {
                if (!$message instanceof \SyncAppointment) {
                    return false;
                }

                if (null !== $calendarEvent) {
                    $this->calendarService->update($calendarEvent, $message);
                    $storedEvent = $calendarEvent;
                } else {
                    $storedEvent = $this->calendarService->create($this->mailbox(), $message);
                }

                \ZLog::Write(LOGLEVEL_DEBUG, sprintf('AvalynxBackend->ChangeMessage event=%s', (string) $storedEvent->getId()?->toRfc4122()));

                return $this->calendarMapper->stat($storedEvent);
            }

            $contact = $this->contactFromServerId($folderid, $id);

            if (null !== $contact || (is_string($id) && '' === $id) || false === $id) {
                if (!$message instanceof \SyncContact) {
                    return false;
                }

                if (null !== $contact) {
                    $this->contactService->update($contact, $message);
                    $address = $contact;
                } else {
                    $address = $this->contactService->create($this->mailbox(), $message);
                }

                \ZLog::Write(LOGLEVEL_DEBUG, sprintf('AvalynxBackend->ChangeMessage contact=%s', (string) $address->getId()?->toRfc4122()));

                return $this->contactMapper->stat($address);
            }

            $email = $this->messageFromServerId($folderid, $id);

            \ZLog::Write(LOGLEVEL_DEBUG, sprintf('AvalynxBackend->ChangeMessage folderid=%s id=%s read=%s email=%s', (string) $folderid, (string) $id, isset($message->read) ? var_export($message->read, true) : 'unset', null === $email ? 'NOT-FOUND' : $email->getId()?->toRfc4122()));

            if (null === $email || !$message instanceof \SyncMail) {
                return false;
            }

            if (isset($message->read)) {
                $this->mutationService->setRead($email, (bool) $message->read);
            }

            if (isset($message->flag) && $message->flag instanceof \SyncMailFlags) {
                $flagStatus = $this->flagToInt($message->flag->flagstatus);
                $this->mutationService->setFavorite($email, null !== $flagStatus && SYNC_FLAGSTATUS_ACTIVE === $flagStatus);
            }

            return $this->messageMapper->statMessage($this->refreshEmail($email));
        } catch (MutationBlockedException $exception) {
            \ZLog::Write(LOGLEVEL_WARN, sprintf('AvalynxBackend->ChangeMessage(): blocked by the license policy (%s)', $exception->operation));

            return false;
        }
    }

    /**
     * @return array{id: string, mod: string, flags: int, star: int}|false
     */
    public function SetReadFlag($folderid, $id, $flags, $contentparameters): array|false
    {
        \ZLog::Write(LOGLEVEL_DEBUG, sprintf('AvalynxBackend->SetReadFlag folderid=%s id=%s flags=%s', (string) $folderid, (string) $id, var_export($flags, true)));

        if (null !== $this->contactsFolderFromServerId((string) $folderid)) {
            return false;
        }

        if (null !== $this->calendarFolderFromServerId((string) $folderid)) {
            return false;
        }

        $email = $this->messageFromServerId($folderid, $id);

        \ZLog::Write(LOGLEVEL_DEBUG, sprintf('AvalynxBackend->SetReadFlag email=%s', null === $email ? 'NOT-FOUND' : $email->getId()?->toRfc4122()));

        if (null === $email) {
            return false;
        }

        $flagValue = $this->flagToInt($flags);

        try {
            if (null !== $flagValue) {
                $this->mutationService->setRead($email, ($flagValue & 1) === 1);
            }
        } catch (MutationBlockedException $exception) {
            \ZLog::Write(LOGLEVEL_WARN, sprintf('AvalynxBackend->SetReadFlag(): blocked by the license policy (%s)', $exception->operation));

            return false;
        }

        \ZLog::Write(LOGLEVEL_DEBUG, sprintf('AvalynxBackend->SetReadFlag after-setRead email=%s', $email->isRead() ? 'TRUE' : 'FALSE'));

        return $this->messageMapper->statMessage($this->refreshEmail($email));
    }

    public function DeleteMessage($folderid, $id, $contentparameters): bool
    {
        try {
            $contact = $this->contactFromServerId($folderid, $id);

            if (null !== $contact) {
                $this->contactService->delete($contact);

                return true;
            }

            $calendarEvent = $this->eventFromServerId($folderid, $id);

            if (null !== $calendarEvent) {
                $this->calendarService->delete($calendarEvent);

                return true;
            }

            $email = $this->messageFromServerId($folderid, $id);

            if (null === $email) {
                return false;
            }

            $this->mutationService->delete($email);

            return true;
        } catch (MutationBlockedException $exception) {
            \ZLog::Write(LOGLEVEL_WARN, sprintf('AvalynxBackend->DeleteMessage(): blocked by the license policy (%s)', $exception->operation));

            return false;
        }
    }

    public function MoveMessage($folderid, $id, $newfolderid, $contentparameters): bool
    {
        if (null !== $this->contactsFolderFromServerId((string) $folderid)) {
            \ZLog::Write(LOGLEVEL_WARN, 'AvalynxBackend->MoveMessage(): moving contacts is not supported');

            return false;
        }

        if (null !== $this->calendarFolderFromServerId((string) $folderid)) {
            \ZLog::Write(LOGLEVEL_WARN, 'AvalynxBackend->MoveMessage(): moving calendar events is not supported');

            return false;
        }

        if (!is_string($newfolderid) || !is_string($id)) {
            return false;
        }

        $email = $this->messageFromServerId($folderid, $id);

        if (null === $email) {
            return false;
        }

        $target = $this->folderFromServerId($newfolderid);

        if (null === $target) {
            \ZLog::Write(LOGLEVEL_WARN, sprintf("AvalynxBackend->MoveMessage('%s'): target folder not found", $newfolderid));

            return false;
        }

        $source = $this->folderFromServerId($folderid);

        try {
            $this->mutationService->move($email, $target, $source);
        } catch (MutationBlockedException $exception) {
            \ZLog::Write(LOGLEVEL_WARN, sprintf('AvalynxBackend->MoveMessage(): blocked by the license policy (%s)', $exception->operation));

            return false;
        }

        return true;
    }

    /**
     * @param string $folderid
     * @param bool   $includeSubfolders
     */
    public function EmptyFolder($folderid, $includeSubfolders = true): bool
    {
        if (!$this->requireMailbox()) {
            return false;
        }

        $folder = $this->folderFromServerId($folderid);

        if (null === $folder) {
            return false;
        }

        try {
            $this->mutationService->emptyFolder($this->mailbox(), $folder);
        } catch (MutationBlockedException $exception) {
            \ZLog::Write(LOGLEVEL_WARN, sprintf('AvalynxBackend->EmptyFolder(): blocked by the license policy (%s)', $exception->operation));

            return false;
        }

        return true;
    }

    public function GetWasteBasket(): string|false
    {
        if (!$this->requireMailbox()) {
            return false;
        }

        $this->wasteBasket ??= $this->wasteBasketFolder($this->mailbox());

        $mailboxId = $this->mailbox()->getId();
        $folderId = $this->wasteBasket->getId();

        if (null === $mailboxId || null === $folderId) {
            return false;
        }

        return $this->folderMapping->folderServerId($mailboxId, $folderId);
    }

    public function SendMail($sm): bool
    {
        if (!$sm instanceof \SyncSendMail) {
            return false;
        }

        try {
            return $this->sendMailService->send($sm, $this->mailbox());
        } catch (\RuntimeException $exception) {
            \ZLog::Write(LOGLEVEL_ERROR, sprintf('AvalynxBackend->SendMail(): %s', $exception->getMessage()));

            throw new \StatusException('SendMail failed', SYNC_COMMONSTATUS_MAILSUBMISSIONFAILED);
        }
    }

    public function Fetch($folderid, $id, $contentparameters): \SyncMail|false
    {
        $message = $this->GetMessage($folderid, $id, $contentparameters);

        return $message instanceof \SyncMail ? $message : false;
    }

    public function GetAttachmentData($attname): \SyncItemOperationsAttachment
    {
        $link = $this->attachmentFromAttName($attname);

        if (null === $link) {
            throw new \StatusException(sprintf("AvalynxBackend->GetAttachmentData('%s'): attachment not found", $attname), SYNC_ITEMOPERATIONSSTATUS_INVALIDATT);
        }

        $attachment = $link->getAttachment();
        $content = $this->attachmentService->contentOf($link);

        if (null === $content) {
            throw new \StatusException('AvalynxBackend->GetAttachmentData(): attachment content unavailable', SYNC_ITEMOPERATIONSSTATUS_SERVERERROR);
        }

        $syncAttachment = new \SyncItemOperationsAttachment();
        $syncAttachment->data = \StringStreamWrapper::Open($content);
        $syncAttachment->contenttype = $attachment?->getMimeType() ?? 'application/octet-stream';

        return $syncAttachment;
    }

    public function MeetingResponse($requestid, $folderid, $response): string
    {
        throw new \StatusException('AvalynxBackend->MeetingResponse(): meetings are not supported', SYNC_COMMONSTATUS_ITEMNOTFOUND);
    }

    private function requireMailbox(): bool
    {
        if (null === $this->context) {
            \ZLog::Write(LOGLEVEL_WARN, 'AvalynxBackend: no authenticated mailbox');

            return false;
        }

        return true;
    }

    private function mailbox(): Mailbox
    {
        $context = $this->context;

        if (null === $context) {
            throw new \RuntimeException('AvalynxBackend is not authenticated');
        }

        return $context->getMailbox();
    }

    private function folderFromServerId(string $serverId): ?Folder
    {
        if (!$this->requireMailbox()) {
            return null;
        }

        $decoded = $this->folderMapping->decode($serverId);

        if (null === $decoded) {
            return null;
        }

        $mailboxId = $this->mailbox()->getId();

        if (null === $mailboxId || !$decoded->getMailboxId()->equals($mailboxId)) {
            return null;
        }

        $folderId = $decoded->getFolderId();

        return $this->mailFolderService->folderForMailbox($this->mailbox(), $folderId);
    }

    /**
     * Real folder stats let Z-Push's ping detect backend changes (new events,
     * contacts) on every cycle instead of only once per hour.
     */
    public function HasFolderStats(): bool
    {
        return true;
    }

    /**
     * @return string|false stat hash for the contacts/calendar folder, false when unknown
     */
    public function GetFolderStat(mixed $store, mixed $folderid)
    {
        if (!is_string($folderid)) {
            return false;
        }

        if (null !== $this->contactsFolderFromServerId($folderid)) {
            return $this->contactFolderStatHash($this->mailbox());
        }

        if (null !== $this->calendarFolderFromServerId($folderid)) {
            return $this->calendarFolderStatHash($this->mailbox());
        }

        // Mail folders are detected through the regular message diff.
        return false;
    }

    private function calendarFolderFromServerId(string $serverId): ?UuidV7
    {
        $mailboxId = $this->folderMapping->decodeCalendar($serverId);

        if (null === $mailboxId || !$this->requireMailbox() || !$mailboxId->equals($this->mailbox()->getId())) {
            return null;
        }

        return $mailboxId;
    }

    private function contactsFolderFromServerId(string $serverId): ?UuidV7
    {
        $mailboxId = $this->folderMapping->decodeContact($serverId);

        if (null === $mailboxId || !$this->requireMailbox() || !$mailboxId->equals($this->mailbox()->getId())) {
            return null;
        }

        return $mailboxId;
    }

    private function eventFromServerId(mixed $folderid, mixed $id): ?CalendarEvent
    {
        if (!is_string($folderid) || null === $this->calendarFolderFromServerId($folderid) || !is_string($id)) {
            return null;
        }

        try {
            $eventId = UuidV7::fromString($id);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $this->calendarService->eventForMailbox($this->mailbox(), $eventId);
    }

    private function calendarFolderStatHash(Mailbox $mailbox): string
    {
        $events = $this->calendarService->eventsForMailbox($mailbox);
        $latest = 0;

        foreach ($events as $event) {
            $latest = max($latest, $event->getUpdatedAt()?->getTimestamp() ?? 0);
        }

        return md5('calendar|'.count($events).'|'.$latest);
    }

    private function contactFromServerId(mixed $folderid, mixed $id): ?Address
    {
        $contactsFolder = $this->contactsFolderFromServerId(is_string($folderid) ? $folderid : '');

        if (null === $contactsFolder || !is_string($id)) {
            return null;
        }

        try {
            $addressId = UuidV7::fromString($id);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $this->contactService->contactForMailbox($this->mailbox(), $addressId->toRfc4122());
    }

    private function messageFromServerId(mixed $folderid, mixed $id): ?Email
    {
        $folder = $this->folderFromServerId(is_string($folderid) ? $folderid : '');

        if (null === $folder || !is_string($id)) {
            return null;
        }

        try {
            $emailId = UuidV7::fromString($id);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $this->mailFolderService->emailForFolder($this->mailbox(), $folder, $emailId);
    }

    /**
     * @param string $attname
     */
    private function attachmentFromAttName($attname): ?EmailAttachmentLink
    {
        if (!str_starts_with($attname, 'att|')) {
            return null;
        }

        $parts = explode('|', substr($attname, 4));

        if (2 !== count($parts)) {
            return null;
        }

        if (!$this->requireMailbox()) {
            return null;
        }

        try {
            $emailId = UuidV7::fromString($parts[0]);
            $attachmentId = UuidV7::fromString($parts[1]);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $this->attachmentService->linkFor($this->mailbox(), $emailId, $attachmentId);
    }

    private function refreshEmail(Email $email): Email
    {
        $this->entityManager->refresh($email);

        return $email;
    }

    /**
     * Z-Push's WBXML decoder always delivers element contents as strings, so
     * flags sent by devices arrive as "1"/"0" instead of integers. This
     * normalizes them; anything not representing a digit is unknown (null).
     */
    private function flagToInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    private function contactFolderStatHash(Mailbox $mailbox): string
    {
        $contacts = $this->contactService->contactsForMailbox($mailbox);
        $latest = 0;

        foreach ($contacts as $contact) {
            $latest = max($latest, $contact->getUpdatedAt()?->getTimestamp() ?? 0);
        }

        return md5('contacts|'.count($contacts).'|'.$latest);
    }

    private function folderStatHash(Folder $folder): string
    {
        return md5($folder->getName().'|'.implode(',', $folder->getFunctions()).'|'.($folder->getUpdatedAt()?->getTimestamp() ?? 0));
    }

    private function wasteBasketFolder(Mailbox $mailbox): Folder
    {
        $existing = array_find($this->mailFolderService->foldersForMailbox($mailbox), fn ($folder) => FolderMapping::WASTE_BASKET_NAME === $folder->getName());
        if (null !== $existing) {
            return $existing;
        }

        return $this->folderService->ensureFolder($mailbox, FolderMapping::WASTE_BASKET_NAME);
    }
}
