<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Mail;

/**
 * Immutable representation of a parsed MIME entity (message or body part).
 *
 * Header names are stored lowercase, header values are unfolded but
 * otherwise raw (RFC 2047 decoding happens in the accessors).
 */
final readonly class MimePart
{
    /**
     * @param array<string, list<string>> $headers lowercase header name => raw values
     * @param list<self>                  $parts   child parts for multipart entities
     */
    public function __construct(
        private array $headers,
        private string $body,
        private array $parts,
    ) {
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    /**
     * Returns the raw (unfolded, undecoded) value of the first header with that name.
     */
    public function getRawHeader(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? [];

        return $values[0] ?? null;
    }

    /**
     * Returns the RFC 2047 decoded header value as UTF-8.
     */
    public function getHeader(string $name): ?string
    {
        $raw = $this->getRawHeader($name);

        return null === $raw ? null : MimeParser::decodeHeaderValue($raw);
    }

    /**
     * Returns the parsed address list of an address header.
     *
     * @return list<array{email: string, name: ?string}>
     */
    public function getAddresses(string $name): array
    {
        $values = $this->headers[strtolower($name)] ?? [];
        $addresses = [];

        foreach ($values as $value) {
            foreach (MimeParser::parseAddressList($value) as $address) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    /**
     * Returns the lowercase media type (e.g. "text/plain") without parameters.
     */
    public function getContentType(): string
    {
        $raw = $this->getRawHeader('content-type');

        if (null === $raw) {
            return [] !== $this->parts ? 'multipart/mixed' : 'text/plain';
        }

        $type = strtolower(trim((string) strtok($raw, ';')));

        return '' !== $type ? $type : 'application/octet-stream';
    }

    public function getContentTypeParameter(string $parameter): ?string
    {
        $raw = $this->getRawHeader('content-type');

        return null === $raw ? null : MimeParser::headerParameter($raw, $parameter);
    }

    public function getDispositionType(): ?string
    {
        $raw = $this->getRawHeader('content-disposition');

        if (null === $raw) {
            return null;
        }

        $type = strtolower(trim((string) strtok($raw, ';')));

        return '' !== $type ? $type : null;
    }

    public function getDispositionParameter(string $parameter): ?string
    {
        $raw = $this->getRawHeader('content-disposition');

        return null === $raw ? null : MimeParser::headerParameter($raw, $parameter);
    }

    /**
     * File name from Content-Disposition (filename) or Content-Type (name).
     */
    public function getFileName(): ?string
    {
        $name = $this->getDispositionParameter('filename') ?? $this->getContentTypeParameter('name');

        if (null === $name) {
            return null;
        }

        $name = trim($name);

        return '' !== $name ? $name : null;
    }

    public function isAttachment(): bool
    {
        return 'attachment' === $this->getDispositionType() || null !== $this->getFileName();
    }

    public function isMultipart(): bool
    {
        return [] !== $this->parts;
    }

    /**
     * @return list<self>
     */
    public function getParts(): array
    {
        return $this->parts;
    }

    /**
     * Raw, still transfer-encoded body (empty for multipart entities).
     */
    public function getRawBody(): string
    {
        return $this->body;
    }

    /**
     * Body with the Content-Transfer-Encoding removed (binary safe).
     */
    public function getDecodedBody(): string
    {
        $encoding = strtolower(trim((string) $this->getRawHeader('content-transfer-encoding')));

        return match ($encoding) {
            'quoted-printable' => quoted_printable_decode($this->body),
            'base64' => base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $this->body) ?? '', true) ?: '',
            default => $this->body,
        };
    }

    /**
     * Decoded body converted from the declared charset to UTF-8 (text parts).
     */
    public function getTextBody(): string
    {
        $data = $this->getDecodedBody();
        $charset = $this->getContentTypeParameter('charset');

        return MimeParser::convertToUtf8($data, $charset);
    }
}
