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
use AvalynxOfficeEas\Db\Model\EmailAddressLink;
use AvalynxOfficeEas\Db\Model\EmailAddressType;
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

    public function testBuildMapsRecipientsSenderAndReplyTo(): void
    {
        $email = new Email();
        $email->setSubject('Addresses');
        $this->attachAddress($email, 'jane@example.com', 'Jane Doe', EmailAddressType::RECIPIENT);
        $this->attachAddress($email, 'max@example.com', null, EmailAddressType::RECIPIENT);
        $this->attachAddress($email, 'carol@example.com', null, EmailAddressType::CC);
        $this->attachAddress($email, 'reply@example.com', null, EmailAddressType::REPLY_TO);
        $this->attachAddress($email, 'sender@example.com', 'Sender Name', EmailAddressType::SENDER);
        $this->attachAddress($email, 'bcc@example.com', null, EmailAddressType::BCC);

        // A link without an address row is skipped.
        $orphan = new EmailAddressLink();
        $orphan->setType(EmailAddressType::RECIPIENT);
        $email->addEmailAddress($orphan);

        $mail = new MessageMapper(new IdnService())->buildSyncMail($email, self::contentParameters());

        self::assertSame(['"Jane Doe" <jane@example.com>', 'max@example.com'], $mail->to);
        self::assertSame(['carol@example.com'], $mail->cc);
        self::assertSame(['reply@example.com'], $mail->reply_to);
        self::assertSame('"Sender Name" <sender@example.com>', $mail->from);
        self::assertSame('"Jane Doe" <jane@example.com>, max@example.com', $mail->displayto);
    }

    public function testBuildTruncatesLongBodies(): void
    {
        $email = new Email();
        $email->setSubject('Long');
        $this->attachBody($email, BodyContentType::TEXT, str_repeat('a', 3000));

        $mail = new MessageMapper(new IdnService())->buildSyncMail($email, self::truncatingContentParameters());

        self::assertInstanceOf(\SyncBaseBody::class, $mail->asbody);
        self::assertSame(1, $mail->asbody->truncated);
        self::assertLessThanOrEqual(1024, $mail->asbody->estimatedDataSize);
    }

    public function testBuildUsesLegacyMimeBodyBelowProtocol12(): void
    {
        new \ReflectionProperty(\Request::class, 'asProtocolVersion')->setValue(null, '2.5');

        $email = new Email();
        $email->setSubject('Short');
        $this->attachBody($email, BodyContentType::TEXT, 'Short body');

        $mail = new MessageMapper(new IdnService())->buildSyncMail($email, self::contentParameters());

        self::assertNull($mail->asbody);
        self::assertSame(strlen('Short body'), $mail->mimesize);
        self::assertSame(0, $mail->mimetruncated);
        self::assertIsResource($mail->mimedata);
        self::assertSame('Short body', (string) stream_get_contents($mail->mimedata));
    }

    public function testBuildTruncatesLegacyMimeBodyBelowProtocol12(): void
    {
        new \ReflectionProperty(\Request::class, 'asProtocolVersion')->setValue(null, '2.5');

        $email = new Email();
        $email->setSubject('Long legacy');
        $this->attachBody($email, BodyContentType::TEXT, str_repeat('b', 3000));

        $mail = new MessageMapper(new IdnService())->buildSyncMail($email, self::truncatingContentParameters());

        self::assertSame(1, $mail->mimetruncated);
        self::assertIsResource($mail->mimedata);
        self::assertLessThanOrEqual(1024, strlen((string) stream_get_contents($mail->mimedata)));
    }

    public function testBuildMapsAttachments(): void
    {
        $email = new Email();
        $email->setSubject('With attachment');
        $this->attachBody($email, BodyContentType::TEXT, 'Body');

        $attachment = new \AvalynxOfficeEas\Db\Model\Attachment();
        $attachment->setName('doc.pdf');
        $attachment->setSize(321);
        $link = new \AvalynxOfficeEas\Db\Model\EmailAttachmentLink();
        $link->setAttachment($attachment);
        $email->addEmailAttachment($link);

        // A link without an attachment row is skipped.
        $email->addEmailAttachment(new \AvalynxOfficeEas\Db\Model\EmailAttachmentLink());

        $mail = new MessageMapper(new IdnService())->buildSyncMail($email, self::contentParameters());

        self::assertIsArray($mail->asattachments);
        self::assertCount(1, $mail->asattachments);
        $syncAttachment = $mail->asattachments[0];
        self::assertInstanceOf(\SyncAttachment::class, $syncAttachment);
        self::assertSame('doc.pdf', $syncAttachment->displayname);
        self::assertSame(321, $syncAttachment->attsize);
        self::assertSame($mail->attachments, $mail->asattachments);
    }

    public function testBuildSkipsBodyLinksWithoutBody(): void
    {
        $email = new Email();
        $email->setSubject('Bodies');
        $email->addEmailBody(new EmailBodyLink());
        $this->attachBody($email, BodyContentType::TEXT, 'Plain');

        $mail = new MessageMapper(new IdnService())->buildSyncMail($email, self::contentParameters());

        self::assertInstanceOf(\SyncBaseBody::class, $mail->asbody);
        self::assertIsResource($mail->asbody->data);
        self::assertSame('Plain', (string) stream_get_contents($mail->asbody->data));
    }

    private function attachAddress(Email $email, string $address, ?string $name, EmailAddressType $type): void
    {
        $addressModel = new \AvalynxOfficeEas\Db\Model\Address();
        $addressModel->setEmail($address);
        $addressModel->setName($name);

        $link = new EmailAddressLink();
        $link->setAddress($addressModel);
        $link->setType($type);
        $email->addEmailAddress($link);
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

    /**
     * Device announcing a 1K truncation preference: GetTruncation serves
     * the truncation code, GetTruncSize maps it to the byte limit.
     */
    private static function truncatingContentParameters(): \ContentParameters
    {
        $parameters = self::contentParameters();
        SyncProps::set($parameters, 'truncation', SYNC_TRUNCATION_1K);

        return $parameters;
    }
}
