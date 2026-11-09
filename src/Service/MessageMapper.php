<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Service;

use AvalynxOfficeEas\Db\Model\BodyContentType;
use AvalynxOfficeEas\Db\Model\Email;
use AvalynxOfficeEas\Db\Model\EmailAddressType;

/**
 * Builds Z-Push SyncMail objects and message stat entries from Email models.
 */
final readonly class MessageMapper
{
    public function __construct(
        private IdnService $idnService,
    ) {
    }

    /**
     * @return array{id: string, mod: string, flags: int, star: int}
     */
    public function statMessage(Email $email): array
    {
        $date = $email->getReceivedAt() ?? $email->getSentAt();

        return [
            'id' => (string) $email->getId()?->toRfc4122(),
            'mod' => (string) ($date?->getTimestamp() ?? 0),
            'flags' => $email->isRead() ? 1 : 0,
            'star' => $email->isFavorite() ? 1 : 0,
        ];
    }

    public function buildSyncMail(Email $email, \ContentParameters $contentparameters): \SyncMail
    {
        $stat = $this->statMessage($email);
        $body = $this->preferredBody($email);

        $output = new \SyncMail();
        $output->read = $stat['flags'];
        $output->subject = $email->getSubject() ?? '';
        $output->messageclass = 'IPM.Note';
        $output->contentclass = DEFAULT_EMAIL_CONTENTCLASS;
        $output->internetcpid = INTERNET_CPID_UTF8;
        // 0 = low, 1 = normal, 2 = high; the value is always sent because
        // some clients (e.g. Android 2.2) read a missing value as "low".
        $output->importance = $email->isImportant() ? 2 : 1;
        $output->datereceived = ($email->getReceivedAt() ?? $email->getSentAt())?->getTimestamp();
        $output->threadtopic = $output->subject;
        $output->flag = $this->buildFlag($email);

        $this->assignAddresses($email, $output);
        $this->assignBody($output, $body, $contentparameters);
        $this->assignAttachments($email, $output);

        return $output;
    }

    /**
     * The follow-up flag maps to the mail favorite: an active flag marks
     * the mail as favorite, every other status (cleared or completed)
     * clears it.
     */
    private function buildFlag(Email $email): \SyncMailFlags
    {
        $flag = new \SyncMailFlags();
        $flag->flagstatus = $email->isFavorite() ? SYNC_FLAGSTATUS_ACTIVE : SYNC_FLAGSTATUS_CLEAR;

        if ($email->isFavorite()) {
            $flag->flagtype = 'for follow up';
        }

        return $flag;
    }

    /**
     * @return string[]
     */
    private function addresses(Email $email, EmailAddressType $type): array
    {
        $addresses = [];

        foreach ($email->getEmailAddresses() as $link) {
            $address = $link->getAddress();

            if (null !== $address && $link->getType() === $type) {
                // Stored punycode domains are decoded so the device shows
                // "user@bästoff.de" instead of "user@xn--bstoff-bua.de".
                $addressString = $this->idnService->toUnicode($address->getEmail());
                $addresses[] = null !== $address->getName()
                    ? sprintf('"%s" <%s>', $address->getName(), $addressString)
                    : $addressString;
            }
        }

        return $addresses;
    }

    private function assignAddresses(Email $email, \SyncMail $output): void
    {
        $recipients = $this->addresses($email, EmailAddressType::RECIPIENT);

        $output->to = $recipients;
        $output->cc = $this->addresses($email, EmailAddressType::CC);
        $output->reply_to = $this->addresses($email, EmailAddressType::REPLY_TO);
        $output->displayto = implode(', ', $recipients);
        $output->from = implode(', ', $this->addresses($email, EmailAddressType::SENDER));
    }

    /**
     * @return array{0: string, 1: int} body content and native body type
     */
    private function preferredBody(Email $email): array
    {
        $html = null;
        $text = null;

        foreach ($email->getEmailBodies() as $link) {
            $body = $link->getBody();

            if (null === $body) {
                continue;
            }

            if (BodyContentType::HTML === $link->getContentType()) {
                $html = $body->getContent();
            } else {
                $text = $body->getContent();
            }
        }

        $type = null !== $html ? SYNC_BODYPREFERENCE_HTML : SYNC_BODYPREFERENCE_PLAIN;
        $content = $html ?? $text ?? '';

        return [$content, $type];
    }

    /**
     * @param array{0: string, 1: int} $body
     */
    private function assignBody(\SyncMail $output, array $body, \ContentParameters $contentparameters): void
    {
        [$content, $type] = $body;

        if ('' === $content) {
            return;
        }

        /** @var int $truncsize Z-Push's legacy docblocks annotate the return as "long". */
        $truncsize = \Utils::GetTruncSize($this->truncation($contentparameters));
        // Devices that do not announce a truncation size receive the body
        // untruncated (GetTruncSize maps unknown values to 1024).
        $truncsize = $truncsize > 0 ? $truncsize : 1024 * 1024;

        if (\Request::GetProtocolVersion() >= 12.0) {
            $output->nativebodytype = $type;
            $asbody = new \SyncBaseBody();

            if (strlen($content) > $truncsize) {
                $content = \Utils::Utf8_truncate($content, strval($truncsize));
                $asbody->truncated = 1;
            } else {
                $asbody->truncated = 0;
            }

            $asbody->data = \StringStreamWrapper::Open($content, SYNC_BODYPREFERENCE_HTML === $type);
            $asbody->estimatedDataSize = strlen($content);
            $asbody->type = $type;

            $output->asbody = $asbody;

            return;
        }

        $output->mimesize = strlen($content);

        if (strlen($content) > $truncsize) {
            $output->mimetruncated = 1;
            $output->mimedata = \StringStreamWrapper::Open(\Utils::Utf8_truncate($content, strval($truncsize)));
        } else {
            $output->mimetruncated = 0;
            $output->mimedata = \StringStreamWrapper::Open($content);
        }
    }

    private function truncation(object $contentparameters): int|false
    {
        $callback = [$contentparameters, 'GetTruncation'];

        if (!\is_callable($callback)) {
            return false;
        }

        $value = \call_user_func($callback);

        return is_int($value) ? $value : false;
    }

    private function assignAttachments(Email $email, \SyncMail $output): void
    {
        $attachments = [];
        $asattachments = [];
        $emailId = (string) $email->getId()?->toRfc4122();

        foreach ($email->getEmailAttachments() as $link) {
            $attachment = $link->getAttachment();

            if (null === $attachment || '' === $emailId) {
                continue;
            }

            $syncAttachment = new \SyncAttachment();
            $syncAttachment->attname = 'att|'.$emailId.'|'.$attachment->getId()?->toRfc4122();
            $syncAttachment->attmethod = 6;
            $syncAttachment->displayname = $attachment->getName();
            $syncAttachment->attsize = $attachment->getSize();

            $attachments[] = $syncAttachment;
            $asattachments[] = $syncAttachment;
        }

        $output->attachments = $attachments;
        $output->asattachments = $asattachments;
    }
}
