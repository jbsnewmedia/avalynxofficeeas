<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Tests\Service;

use AvalynxOfficeEas\Db\Model\Body;
use AvalynxOfficeEas\Db\Model\BodyContentType;
use AvalynxOfficeEas\Db\Model\Email;
use AvalynxOfficeEas\Db\Model\EmailBodyLink;
use AvalynxOfficeEas\Service\IdnService;
use AvalynxOfficeEas\Service\MessageMapper;
use AvalynxOfficeEas\Tests\Support\SyncProps;
use PHPUnit\Framework\TestCase;

/**
 * Mail flags that live on the Email model itself: importance, follow-up
 * flag, content class and the received date fallback.
 */
final class MessageMapperTest extends TestCase
{
    protected function setUp(): void
    {
        // The mapper branches on the negotiated AS version; emulate a
        // modern device header since there is no request processor.
        new \ReflectionProperty(\Request::class, 'asProtocolVersion')->setValue(null, '14.1');
    }

    protected function tearDown(): void
    {
        new \ReflectionProperty(\Request::class, 'asProtocolVersion')->setValue(null, null);
    }

    public function testBuildMapsImportanceAndFlag(): void
    {
        $email = new Email();
        $email->setSubject('Important');
        $email->setIsImportant(true);
        $email->setIsFavorite(true);
        $email->setReceivedAt(new \DateTimeImmutable('2026-11-09 12:00'));

        $mail = new MessageMapper(new IdnService())->buildSyncMail($email, self::contentParameters());

        self::assertSame(2, $mail->importance);
        self::assertInstanceOf(\SyncMailFlags::class, $mail->flag);
        self::assertSame(SYNC_FLAGSTATUS_ACTIVE, $mail->flag->flagstatus);
        self::assertSame('for follow up', $mail->flag->flagtype);
    }

    public function testBuildDefaultsToNormalImportanceAndClearedFlag(): void
    {
        $email = new Email();
        $email->setSubject('Normal');
        $email->setReceivedAt(new \DateTimeImmutable('2026-11-09 12:00'));

        $mail = new MessageMapper(new IdnService())->buildSyncMail($email, self::contentParameters());

        self::assertSame(1, $mail->importance);
        self::assertInstanceOf(\SyncMailFlags::class, $mail->flag);
        self::assertSame(SYNC_FLAGSTATUS_CLEAR, $mail->flag->flagstatus);
    }

    public function testBuildAnnouncesContentClass(): void
    {
        $email = new Email();
        $email->setSubject('Any');

        $mail = new MessageMapper(new IdnService())->buildSyncMail($email, self::contentParameters());

        self::assertSame(DEFAULT_EMAIL_CONTENTCLASS, $mail->contentclass);
        self::assertSame('IPM.Note', $mail->messageclass);
    }

    public function testBuildFallsBackToSentAtAsDateReceived(): void
    {
        $email = new Email();
        $email->setSubject('Sent');
        $email->setSentAt(new \DateTimeImmutable('2026-11-09 08:30'));

        $mail = new MessageMapper(new IdnService())->buildSyncMail($email, self::contentParameters());

        self::assertSame(new \DateTimeImmutable('2026-11-09 08:30')->getTimestamp(), $mail->datereceived);
    }

    public function testBuildPrefersHtmlBody(): void
    {
        $email = new Email();
        $email->setSubject('Bodies');
        $this->attachBody($email, BodyContentType::TEXT, 'Plain');
        $this->attachBody($email, BodyContentType::HTML, '<b>Rich</b>');

        $mail = new MessageMapper(new IdnService())->buildSyncMail($email, self::contentParameters());

        self::assertInstanceOf(\SyncBaseBody::class, $mail->asbody);
        self::assertSame(SYNC_BODYPREFERENCE_HTML, $mail->asbody->type);
        self::assertIsResource($mail->asbody->data);
        self::assertSame('<b>Rich</b>', (string) stream_get_contents($mail->asbody->data));
    }

    private function attachBody(Email $email, BodyContentType $type, string $content): void
    {
        $body = new Body();
        $body->setContent($content);

        $link = new EmailBodyLink();
        $link->setBody($body);
        $link->setContentType($type);
        $email->addEmailBody($link);
    }

    private static function contentParameters(): \ContentParameters
    {
        $parameters = new \ContentParameters();

        $bodyPreference = new \BodyPreference();
        SyncProps::set($bodyPreference, 'type', SYNC_BODYPREFERENCE_PLAIN);
        SyncProps::set($bodyPreference, 'truncationsize', 102400);
        SyncProps::set($parameters, 'bodypreference', [$bodyPreference]);

        return $parameters;
    }
}
