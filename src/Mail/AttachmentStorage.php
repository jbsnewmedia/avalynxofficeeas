<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Mail;

use AvalynxOfficeEas\Db\Model\Attachment;

/**
 * Segment based attachment file storage, layout-compatible with the core
 * (both sides mount the same storage volume).
 */
final readonly class AttachmentStorage
{
    public function __construct(
        private string $projectDir,
        private string $storageDir,
        private int $segmentLength,
        private int $segmentDepth,
        private int $maxBytes,
    ) {
    }

    public function getBaseDir(): string
    {
        if ($this->isAbsolutePath($this->storageDir)) {
            return rtrim($this->storageDir, '/');
        }

        return rtrim($this->projectDir, '/').'/'.ltrim($this->storageDir, '/');
    }

    public function getMaxBytes(): int
    {
        return $this->maxBytes;
    }

    public function store(Attachment $attachment, string $data): string
    {
        $size = strlen($data);
        if ($size > $this->maxBytes) {
            throw new \RuntimeException(sprintf('Maximal %d Bytes erlaubt, erhalten: %d Bytes.', $this->maxBytes, $size));
        }

        $relativePath = $this->buildRelativePath($attachment);
        $absolutePath = $this->resolveAbsolutePath($relativePath);
        $directory = dirname($absolutePath);

        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Attachment-Verzeichnis "%s" konnte nicht erstellt werden.', $directory));
        }

        if (false === @file_put_contents($absolutePath, $data)) {
            throw new \RuntimeException(sprintf('Attachment-Datei "%s" konnte nicht geschrieben werden.', $absolutePath));
        }

        return $relativePath;
    }

    public function resolveAbsolutePath(string $filePath): string
    {
        if ('' === trim($filePath)) {
            throw new \RuntimeException('Attachment-Pfad darf nicht leer sein.');
        }

        if ($this->isAbsolutePath($filePath)) {
            return $filePath;
        }

        return rtrim($this->getBaseDir(), '/').'/'.ltrim($filePath, '/');
    }

    private function buildRelativePath(Attachment $attachment): string
    {
        $uuid = $this->normalizeUuid($attachment);
        $segments = [];

        for ($level = 0; $level < max(1, $this->segmentDepth); ++$level) {
            $offset = $level * max(1, $this->segmentLength);
            $segment = substr($uuid, $offset, max(1, $this->segmentLength));
            if ('' === $segment) {
                break;
            }

            $segments[] = $segment;
        }

        $extension = $this->normalizeExtension($attachment->getName());
        $segments[] = '' === $extension ? $uuid : sprintf('%s.%s', $uuid, $extension);

        return implode('/', $segments);
    }

    private function normalizeUuid(Attachment $attachment): string
    {
        $id = $attachment->getId();
        if (null === $id) {
            throw new \RuntimeException('Attachment-ID fehlt.');
        }

        return strtolower(str_replace('-', '', (string) $id));
    }

    private function normalizeExtension(string $fileName): string
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        return substr(preg_replace('/[^a-z0-9]+/', '', $extension) ?? '', 0, 16);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || 1 === preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
    }
}
