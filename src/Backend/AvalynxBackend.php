<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Backend;

use AvalynxOfficeEas\Db\Model\Account;
use AvalynxOfficeEas\Db\Model\Address;
use AvalynxOfficeEas\Db\Model\CalendarEvent;
use AvalynxOfficeEas\Db\Model\Email;
use AvalynxOfficeEas\Db\Model\EmailAttachmentLink;
use AvalynxOfficeEas\Db\Model\Folder;
use AvalynxOfficeEas\Db\Model\FolderFunction;
use AvalynxOfficeEas\Dto\EasUserContext;
use AvalynxOfficeEas\Licensing\MutationBlockedException;
use AvalynxOfficeEas\Service\AttachmentService;
use AvalynxOfficeEas\Service\AuthThrottle;
use AvalynxOfficeEas\Service\CalendarMapper;
use AvalynxOfficeEas\Service\CalendarService;
use AvalynxOfficeEas\Service\ContactMapper;
use AvalynxOfficeEas\Service\ContactService;
use AvalynxOfficeEas\Service\FolderMapping;
use AvalynxOfficeEas\Service\FolderService;
use AvalynxOfficeEas\Service\MailFolderService;
use AvalynxOfficeEas\Service\MessageMapper;
use AvalynxOfficeEas\Service\MessageMutationService;
use AvalynxOfficeEas\Service\SendMailService;
use AvalynxOfficeEas\Service\ServiceHolder;
use AvalynxOfficeEas\Service\UserResolver;
use Symfony\Component\Uid\UuidV7;

/**
 * Z-Push backend bridging Exchange ActiveSync to the AvalynxOffice mail
 * storage. The device logs in with an application user (same login as the
 * AvalynxOffice web app) and synchronizes every account assigned to that
 * user: all mail folders, the contacts folder and the calendar folder of
 * each account.
 *
 * Folder ids are deterministic base64 encoded "mail|accountId|folderId" and
 * "contacts|accountId"/"calendar|accountId" strings so they stay stable
 * across devices; the account scope is always part of the server id.
 */
final class AvalynxBackend extends \BackendDiff
{
    private ?EasUserContext $context = null;
    private ?Folder $wasteBasket = null;

    private readonly UserResolver $userResolver;
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

        $this->userResolver = $holder->getUserResolver();
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

        $context = $this->userResolver->resolve($username, $password);

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

        \ZLog::Write(LOGLEVEL_DEBUG, sprintf("AvalynxBackend->Logon('%s'): success (%d account(s))", $username, count($context->getAccounts())));

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
        if (!$this->requireContext()) {
            return false;
        }

        $folders = [];

        foreach ($this->context()->getAccounts() as $account) {
            $accountId = $account->getId();

            if (null === $accountId) {
                continue;
            }

            foreach ($this->mailFolderService->foldersForAccount($account) as $folder) {
                $folderId = $folder->getId();

                if (null === $folderId) {
                    continue;
                }

                $folders[] = [
                    'id' => $this->folderMapping->folderServerId($accountId, $folderId),
                    'parent' => '0',
                    'mod' => $this->folderStatHash($folder),
                ];
            }

            $folders[] = [
                'id' => $this->folderMapping->contactServerId($accountId),
                'parent' => '0',
                'mod' => $this->contactFolderStatHash($account),
            ];

            $folders[] = [
                'id' => $this->folderMapping->calendarServerId($accountId),
                'parent' => '0',
                'mod' => $this->calendarFolderStatHash($account),
            ];
        }

        return $folders;
    }

    public function GetFolder($id): \SyncFolder|false
    {
        if (!is_string($id)) {
            return false;
        }

        $contactsAccount = $this->accountFromContactsServerId($id);

        if (null !== $contactsAccount) {
            return $this->scopedFolder($id, $this->scopedName($contactsAccount, FolderMapping::CONTACTS_NAME), SYNC_FOLDER_TYPE_CONTACT);
        }

        $calendarAccount = $this->accountFromCalendarServerId($id);

        if (null !== $calendarAccount) {
            return $this->scopedFolder($id, $this->scopedName($calendarAccount, FolderMapping::CALENDAR_NAME), SYNC_FOLDER_TYPE_APPOINTMENT);
        }

        $folderContext = $this->folderContextFromServerId($id);

        if (null === $folderContext) {
            \ZLog::Write(LOGLEVEL_WARN, sprintf("AvalynxBackend->GetFolder('%s'): unknown folder", $id));

            return false;
        }

        return $this->scopedFolder(
            $id,
            $this->scopedName($folderContext['account'], $folderContext['folder']->getName()),
            $this->folderMapping->easType($folderContext['folder']),
        );
    }

    /**
     * @return array{id: string, parent: string, mod: string}|false
     */
    public function StatFolder($id): array|false
    {
        if (!is_string($id)) {
            return false;
        }

        $contactsAccount = $this->accountFromContactsServerId($id);

        if (null !== $contactsAccount) {
            return [
                'id' => $id,
                'parent' => '0',
                'mod' => $this->contactFolderStatHash($contactsAccount),
            ];
        }

        $calendarAccount = $this->accountFromCalendarServerId($id);

        if (null !== $calendarAccount) {
            return [
                'id' => $id,
                'parent' => '0',
                'mod' => $this->calendarFolderStatHash($calendarAccount),
            ];
        }

        $folderContext = $this->folderContextFromServerId($id);

        if (null === $folderContext) {
            return false;
        }

        return [
            'id' => $id,
            'parent' => '0',
            'mod' => $this->folderStatHash($folderContext['folder']),
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

        if (!$this->requireContext() || !is_string($displayname) || '' === $displayname) {
            return false;
        }

        // Folder creation at the root level carries no account scope: the
        // folder is created on the user's primary account. A parent folder
        // pins the account instead.
        $parentContext = is_string($folderid) && '0' !== $folderid
            ? $this->folderContextFromServerId($folderid)
            : null;
        $account = $parentContext['account'] ?? $this->context()->getPrimaryAccount();

        if (null === $account) {
            \ZLog::Write(LOGLEVEL_WARN, sprintf("AvalynxBackend->ChangeFolder('%s'): parent folder not found", (string) $folderid));

            return false;
        }

        try {
            $this->licenseState->assertMutationAllowed('SETTINGS_WRITE');
            $folderId = $this->folderService->ensureFolder($account, $displayname)->getId();
        } catch (MutationBlockedException $exception) {
            \ZLog::Write(LOGLEVEL_WARN, sprintf('AvalynxBackend->ChangeFolder(): blocked by the license policy (%s)', $exception->operation));

            return false;
        }

        $accountId = $account->getId();

        if (null === $accountId || null === $folderId) {
            return false;
        }

        return $this->folderMapping->folderServerId($accountId, $folderId);
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
        if (!$this->requireContext()) {
            return false;
        }

        $contactsAccount = $this->accountFromContactsServerId((string) $folderid);

        if (null !== $contactsAccount) {
            $messages = [];

            foreach ($this->contactService->contactsForAccount($contactsAccount) as $contact) {
                $messages[] = $this->contactMapper->stat($contact);
            }

            return $messages;
        }

        $calendarAccount = $this->accountFromCalendarServerId((string) $folderid);

        if (null !== $calendarAccount) {
            $messages = [];

            foreach ($this->calendarService->eventsForAccount($calendarAccount) as $event) {
                $messages[] = $this->calendarMapper->stat($event);
            }

            return $messages;
        }

        $folderContext = $this->folderContextFromServerId((string) $folderid);

        if (null === $folderContext) {
            return false;
        }

        $cutoff = \is_int($cutoffdate) && $cutoffdate > 0 ? $cutoffdate : 0;
        $messages = [];

        foreach ($this->mailFolderService->emailsForFolder($folderContext['account'], $folderContext['folder'], $cutoff) as $email) {
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
            $calendarAccount = is_string($folderid) ? $this->accountFromCalendarServerId($folderid) : null;
            $calendarEvent = $this->eventFromServerId($folderid, (string) $id);

            if (null !== $calendarEvent || null !== $calendarAccount) {
                if (!$message instanceof \SyncAppointment) {
                    return false;
                }

                if (null !== $calendarEvent) {
                    $this->calendarService->update($calendarEvent, $message);
                    $storedEvent = $calendarEvent;
                } else {
                    $storedEvent = $this->calendarService->create($this->accountFromFolderIdOrFail($folderid), $message);
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
                    $address = $this->contactService->create($this->accountFromFolderIdOrFail($folderid), $message);
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

        if (null !== $this->accountFromContactsServerId((string) $folderid)) {
            return false;
        }

        if (null !== $this->accountFromCalendarServerId((string) $folderid)) {
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
        if (null !== $this->accountFromContactsServerId((string) $folderid)) {
            \ZLog::Write(LOGLEVEL_WARN, 'AvalynxBackend->MoveMessage(): moving contacts is not supported');

            return false;
        }

        if (null !== $this->accountFromCalendarServerId((string) $folderid)) {
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

        $targetContext = $this->folderContextFromServerId($newfolderid);

        if (null === $targetContext) {
            \ZLog::Write(LOGLEVEL_WARN, sprintf("AvalynxBackend->MoveMessage('%s'): target folder not found", $newfolderid));

            return false;
        }

        try {
            $this->mutationService->move($email, $targetContext['folder']);
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
        if (!$this->requireContext()) {
            return false;
        }

        $folderContext = $this->folderContextFromServerId((string) $folderid);

        if (null === $folderContext) {
            return false;
        }

        try {
            $this->mutationService->emptyFolder($folderContext['account'], $folderContext['folder']);
        } catch (MutationBlockedException $exception) {
            \ZLog::Write(LOGLEVEL_WARN, sprintf('AvalynxBackend->EmptyFolder(): blocked by the license policy (%s)', $exception->operation));

            return false;
        }

        return true;
    }

    public function GetWasteBasket(): string|false
    {
        if (!$this->requireContext()) {
            return false;
        }

        $account = $this->context()->getPrimaryAccount();

        if (null === $account) {
            return false;
        }

        $this->wasteBasket ??= $this->wasteBasketFolder($account);

        $accountId = $account->getId();
        $folderId = $this->wasteBasket->getId();

        if (null === $accountId || null === $folderId) {
            return false;
        }

        return $this->folderMapping->folderServerId($accountId, $folderId);
    }

    public function SendMail($sm): bool
    {
        if (!$sm instanceof \SyncSendMail) {
            return false;
        }

        try {
            return $this->sendMailService->send($sm, $this->accountForOutgoingMessage($sm));
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

        $contactsAccount = $this->accountFromContactsServerId($folderid);

        if (null !== $contactsAccount) {
            return $this->contactFolderStatHash($contactsAccount);
        }

        $calendarAccount = $this->accountFromCalendarServerId($folderid);

        if (null !== $calendarAccount) {
            return $this->calendarFolderStatHash($calendarAccount);
        }

        // Mail folders are detected through the regular message diff.
        return false;
    }

    private function requireContext(): bool
    {
        if (null === $this->context) {
            \ZLog::Write(LOGLEVEL_WARN, 'AvalynxBackend: no authenticated user');

            return false;
        }

        return true;
    }

    private function context(): EasUserContext
    {
        $context = $this->context;

        if (null === $context) {
            throw new \RuntimeException('AvalynxBackend is not authenticated');
        }

        return $context;
    }

    /**
     * @return array{account: Account, folder: Folder}|null
     */
    private function folderContextFromServerId(string $serverId): ?array
    {
        if (!$this->requireContext()) {
            return null;
        }

        $decoded = $this->folderMapping->decode($serverId);

        if (null === $decoded) {
            return null;
        }

        $account = $this->context()->getAccount($decoded->getAccountId());

        if (null === $account) {
            return null;
        }

        $folder = $this->mailFolderService->folderForAccount($account, $decoded->getFolderId());

        if (null === $folder) {
            return null;
        }

        return ['account' => $account, 'folder' => $folder];
    }

    /**
     * The account that owns an outgoing message: replies/forwards belong to
     * the account of the source message, fresh messages go to the primary
     * account of the user.
     */
    private function accountForOutgoingMessage(\SyncSendMail $sendMail): Account
    {
        // Replies/forwards carry their source folder in SyncSendMailSource:
        // the message pins the account that owns the conversation.
        $source = $sendMail->source;

        if ($source instanceof \SyncSendMailSource
            && is_string($source->folderid)
            && is_string($source->itemid)
        ) {
            $sourceMessage = $this->messageFromServerId($source->folderid, $source->itemid);
            $sourceAccount = $sourceMessage?->getAccount();

            if (null !== $sourceAccount) {
                return $sourceAccount;
            }
        }

        $account = $this->context()->getPrimaryAccount();

        if (null === $account) {
            throw new \RuntimeException('No account assigned to this user');
        }

        return $account;
    }

    /**
     * Resolves the account of a contacts or calendar server id (new events
     * and contacts are created without an item id, so the folder id is the
     * only scope carrier).
     */
    private function accountFromFolderIdOrFail(mixed $folderid): Account
    {
        $identifier = is_string($folderid) ? $folderid : '';
        $account = $this->accountFromContactsServerId($identifier) ?? $this->accountFromCalendarServerId($identifier);

        if (null === $account) {
            throw new \RuntimeException(sprintf("AvalynxBackend: folder '%s' has no account scope", $identifier));
        }

        return $account;
    }

    private function accountFromContactsServerId(string $serverId): ?Account
    {
        $accountId = $this->folderMapping->decodeContact($serverId);

        if (null === $accountId || !$this->requireContext() || !$this->context()->hasAccount($accountId)) {
            return null;
        }

        return $this->context()->getAccount($accountId);
    }

    private function accountFromCalendarServerId(string $serverId): ?Account
    {
        $accountId = $this->folderMapping->decodeCalendar($serverId);

        if (null === $accountId || !$this->requireContext() || !$this->context()->hasAccount($accountId)) {
            return null;
        }

        return $this->context()->getAccount($accountId);
    }

    private function eventFromServerId(mixed $folderid, mixed $id): ?CalendarEvent
    {
        $account = is_string($folderid) ? $this->accountFromCalendarServerId($folderid) : null;

        if (null === $account || !is_string($id)) {
            return null;
        }

        try {
            $eventId = UuidV7::fromString($id);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $this->calendarService->eventForAccount($account, $eventId);
    }

    private function contactFromServerId(mixed $folderid, mixed $id): ?Address
    {
        $account = is_string($folderid) ? $this->accountFromContactsServerId($folderid) : null;

        if (null === $account || !is_string($id)) {
            return null;
        }

        try {
            $addressId = UuidV7::fromString($id);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $this->contactService->contactForAccount($account, $addressId->toRfc4122());
    }

    private function messageFromServerId(mixed $folderid, mixed $id): ?Email
    {
        $folderContext = $this->folderContextFromServerId(is_string($folderid) ? $folderid : '');

        if (null === $folderContext || !is_string($id)) {
            return null;
        }

        try {
            $emailId = UuidV7::fromString($id);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $this->mailFolderService->emailForFolder($folderContext['account'], $folderContext['folder'], $emailId);
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

        if (!$this->requireContext()) {
            return null;
        }

        try {
            $emailId = UuidV7::fromString($parts[0]);
            $attachmentId = UuidV7::fromString($parts[1]);
        } catch (\InvalidArgumentException) {
            return null;
        }

        // The attachment belongs to the account of its message: the message
        // pins the scope, the context decides whether it is visible.
        $email = $this->entityManager->find(Email::class, $emailId);
        $account = $email?->getAccount();

        if (null === $account || null === $account->getId() || !$this->context()->hasAccount($account->getId())) {
            return null;
        }

        return $this->attachmentService->linkFor($account, $emailId, $attachmentId);
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

    /**
     * With more than one account the folder names would collide on the
     * device ("Posteingang" several times), so every folder is prefixed with
     * its account name. A single account stays unprefixed.
     */
    private function scopedName(Account $account, string $name): string
    {
        if (count($this->context()->getAccounts()) < 2) {
            return $name;
        }

        return $account->getName().' / '.$name;
    }

    private function scopedFolder(string $serverId, string $displayName, int $type): \SyncFolder
    {
        $syncFolder = new \SyncFolder();
        $syncFolder->serverid = $serverId;
        $syncFolder->parentid = '0';
        $syncFolder->displayname = $displayName;
        $syncFolder->type = $type;

        return $syncFolder;
    }

    private function calendarFolderStatHash(Account $account): string
    {
        $events = $this->calendarService->eventsForAccount($account);
        $latest = 0;

        foreach ($events as $event) {
            $latest = max($latest, $event->getUpdatedAt()?->getTimestamp() ?? 0);
        }

        return md5('calendar|'.count($events).'|'.$latest);
    }

    private function contactFolderStatHash(Account $account): string
    {
        $contacts = $this->contactService->contactsForAccount($account);
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

    private function wasteBasketFolder(Account $account): Folder
    {
        return $this->folderService->ensureSystemFolder($account, FolderFunction::TRASH);
    }
}
