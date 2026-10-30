<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Service;

use AvalynxOfficeEas\Db\Database;
use AvalynxOfficeEas\Licensing\LicenseState;
use AvalynxOfficeEas\Mail\AttachmentStorage;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Holds the services required by the Z-Push backend.
 *
 * Z-Push instantiates the backend provider with plain `new $class` without
 * dependency injection, so the endpoint bootstrap places the required services
 * into this holder before the request is processed.
 */
final class ServiceHolder
{
    private static ?ServiceHolder $instance = null;

    private function __construct(
        private readonly string $projectDir,
        private readonly UserResolver $userResolver,
        private readonly MailFolderService $mailFolderService,
        private readonly MessageMapper $messageMapper,
        private readonly SendMailService $sendMailService,
        private readonly FolderMapping $folderMapping,
        private readonly AttachmentService $attachmentService,
        private readonly ContactService $contactService,
        private readonly ContactMapper $contactMapper,
        private readonly CalendarService $calendarService,
        private readonly CalendarMapper $calendarMapper,
        private readonly MessageMutationService $mutationService,
        private readonly FolderService $folderService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LicenseState $licenseState,
        private readonly LastAuthenticationRecorder $lastAuthenticationRecorder,
        private readonly AuthThrottle $authThrottle,
    ) {
    }

    public static function bootstrap(
        string $projectDir,
        ?EntityManagerInterface $entityManager = null,
    ): void {
        $entityManager ??= Database::entityManager();
        $attachmentStorage = new AttachmentStorage(
            $projectDir,
            Database::env('ATTACHMENT_STORAGE_DIR', 'data/attachments') ?? 'data/attachments',
            max(1, (int) (Database::env('ATTACHMENT_STORAGE_SEGMENT_LENGTH', '3') ?? 3)),
            max(1, (int) (Database::env('ATTACHMENT_STORAGE_SEGMENT_DEPTH', '3') ?? 3)),
            max(1024, (int) (Database::env('ATTACHMENT_STORAGE_MAX_BYTES', '26214400') ?? 26214400)),
        );
        $licenseState = new LicenseState($entityManager->getConnection());
        $contactMapper = new ContactMapper();
        $calendarMapper = new CalendarMapper();
        $folderMapping = new FolderMapping();
        $messageMapper = new MessageMapper();
        $folderService = new FolderService($entityManager);
        $mailFolderService = new MailFolderService($entityManager);
        $userResolver = new UserResolver($entityManager);
        $mutationService = new MessageMutationService($entityManager, $folderService, $licenseState);
        $contactService = new ContactService($entityManager, $contactMapper, $licenseState);
        $calendarService = new CalendarService($entityManager, $calendarMapper, $licenseState);
        $attachmentService = new AttachmentService($entityManager, $attachmentStorage);
        $sendMailService = new SendMailService($entityManager, $folderService, $attachmentStorage, $licenseState);
        $lastAuthenticationRecorder = new LastAuthenticationRecorder($entityManager);
        $authThrottle = new AuthThrottle(new \Symfony\Component\Cache\Adapter\FilesystemAdapter(
            'avalynxofficeeas-auth',
            0,
            $projectDir.'/var/cache/auth-throttle',
        ));

        self::$instance = new self(
            $projectDir,
            $userResolver,
            $mailFolderService,
            $messageMapper,
            $sendMailService,
            $folderMapping,
            $attachmentService,
            $contactService,
            $contactMapper,
            $calendarService,
            $calendarMapper,
            $mutationService,
            $folderService,
            $entityManager,
            $licenseState,
            $lastAuthenticationRecorder,
            $authThrottle,
        );
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public static function instance(): self
    {
        if (null === self::$instance) {
            throw new \RuntimeException('The EAS service holder has not been bootstrapped.');
        }

        return self::$instance;
    }

    public function getProjectDir(): string
    {
        return $this->projectDir;
    }

    public function getUserResolver(): UserResolver
    {
        return $this->userResolver;
    }

    public function getMailFolderService(): MailFolderService
    {
        return $this->mailFolderService;
    }

    public function getMessageMapper(): MessageMapper
    {
        return $this->messageMapper;
    }

    public function getSendMailService(): SendMailService
    {
        return $this->sendMailService;
    }

    public function getFolderMapping(): FolderMapping
    {
        return $this->folderMapping;
    }

    public function getAttachmentService(): AttachmentService
    {
        return $this->attachmentService;
    }

    public function getContactService(): ContactService
    {
        return $this->contactService;
    }

    public function getContactMapper(): ContactMapper
    {
        return $this->contactMapper;
    }

    public function getCalendarService(): CalendarService
    {
        return $this->calendarService;
    }

    public function getCalendarMapper(): CalendarMapper
    {
        return $this->calendarMapper;
    }

    public function getMutationService(): MessageMutationService
    {
        return $this->mutationService;
    }

    public function getFolderService(): FolderService
    {
        return $this->folderService;
    }

    public function getEntityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }

    public function getLicenseState(): LicenseState
    {
        return $this->licenseState;
    }

    public function getLastAuthenticationRecorder(): LastAuthenticationRecorder
    {
        return $this->lastAuthenticationRecorder;
    }

    public function getAuthThrottle(): AuthThrottle
    {
        return $this->authThrottle;
    }
}
