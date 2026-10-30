<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Mail;

/**
 * Dependency-free RFC 822/2045/2046/2047/2231 parser for messages submitted
 * by EAS clients (SendMail/SmartForward/SmartReply).
 *
 * Deliberately lenient: malformed input never throws, it only degrades to
 * fewer headers/parts. Only the features the EAS backend actually needs are
 * implemented (header unfolding, encoded words, nested multiparts, transfer
 * decoding, charset normalization, address lists).
 *
 * The only exceptions are resource limits (input size, number of parts):
 * exceeding them throws a RuntimeException before the message is processed.
 */
final readonly class MimeParser
{
    private const int MAX_DEPTH = 32;

    /** Largest raw message accepted (matches the 64M edge/PHP upload limit). */
    public const int MAX_INPUT_BYTES = 64 * 1024 * 1024;

    /** Maximum number of MIME entities (root included) in one message. */
    public const int MAX_PARTS = 200;

    /**
     * @throws \RuntimeException when the message exceeds MAX_INPUT_BYTES or MAX_PARTS
     */
    public function parse(string $raw): MimePart
    {
        if (strlen($raw) > self::MAX_INPUT_BYTES) {
            throw new \RuntimeException(sprintf('Message (%d bytes) exceeds the limit of %d bytes', strlen($raw), self::MAX_INPUT_BYTES));
        }

        $partCount = 0;

        return $this->parseEntity($raw, 0, $partCount);
    }

    private function parseEntity(string $raw, int $depth, int &$partCount): MimePart
    {
        if (++$partCount > self::MAX_PARTS) {
            throw new \RuntimeException(sprintf('Message exceeds the limit of %d MIME parts', self::MAX_PARTS));
        }

        [$headerBlock, $body] = $this->splitHeadersAndBody($raw);
        $headers = $this->parseHeaders($headerBlock);

        $contentType = $headers['content-type'][0] ?? '';
        $mediaType = strtolower(trim((string) strtok($contentType, ';')));

        if ($depth < self::MAX_DEPTH && str_starts_with($mediaType, 'multipart/')) {
            $boundary = self::headerParameter($contentType, 'boundary');

            if (null !== $boundary && '' !== $boundary) {
                $parts = [];

                foreach ($this->splitMultipart($body, $boundary) as $rawPart) {
                    $parts[] = $this->parseEntity($rawPart, $depth + 1, $partCount);
                }

                if ([] !== $parts) {
                    return new MimePart($headers, '', $parts);
                }
            }
        }

        return new MimePart($headers, $body, []);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitHeadersAndBody(string $raw): array
    {
        $raw = ltrim($raw, "\r\n");

        if (1 === preg_match('/\r?\n\r?\n/', $raw, $matches, PREG_OFFSET_CAPTURE)) {
            $offset = (int) $matches[0][1];

            return [substr($raw, 0, $offset), substr($raw, $offset + strlen($matches[0][0]))];
        }

        // Only headers (e.g. attachment part without body) or only body.
        if (1 === preg_match('/^[!-9;-~]+:/', $raw)) {
            return [$raw, ''];
        }

        return ['', $raw];
    }

    /**
     * @return array<string, list<string>>
     */
    private function parseHeaders(string $headerBlock): array
    {
        $headers = [];

        if ('' === trim($headerBlock)) {
            return $headers;
        }

        // unfold continuation lines (RFC 5322 §2.2.3)
        $unfolded = preg_replace('/\r?\n[ \t]+/', ' ', $headerBlock) ?? $headerBlock;

        foreach (preg_split('/\r?\n/', $unfolded) ?: [] as $line) {
            $separator = strpos($line, ':');

            if (false === $separator || 0 === $separator) {
                continue;
            }

            $name = strtolower(trim(substr($line, 0, $separator)));

            if ('' === $name || 1 !== preg_match('/^[!-9;-~]+$/', $name)) {
                continue;
            }

            $headers[$name][] = trim(substr($line, $separator + 1));
        }

        return $headers;
    }

    /**
     * @return list<string>
     */
    private function splitMultipart(string $body, string $boundary): array
    {
        $delimiter = '--'.$boundary;
        $lines = preg_split('/\r?\n/', $body) ?: [];
        $parts = [];
        $current = null;

        foreach ($lines as $line) {
            $trimmed = rtrim($line);

            if ($trimmed === $delimiter || $trimmed === $delimiter.'--') {
                if (null !== $current) {
                    $parts[] = implode("\r\n", $current);
                }

                if ($trimmed === $delimiter.'--') {
                    $current = null;

                    break;
                }

                $current = [];

                continue;
            }

            if (null !== $current) {
                $current[] = $line;
            }
        }

        if (null !== $current) {
            // missing closing delimiter, keep what we have
            $parts[] = implode("\r\n", $current);
        }

        return $parts;
    }

    /**
     * Extracts a (possibly RFC 2231 encoded/continued) parameter from a
     * structured header value such as Content-Type or Content-Disposition.
     */
    public static function headerParameter(string $value, string $parameter): ?string
    {
        $parameter = strtolower($parameter);
        $plain = null;
        $continuations = [];
        $extendedCharset = null;
        $extended = false;

        foreach (self::splitParameters($value) as $name => $paramValue) {
            $lowerName = strtolower($name);

            if ($lowerName === $parameter) {
                $plain ??= $paramValue;

                continue;
            }

            if (1 !== preg_match('/^'.preg_quote($parameter, '/').'(?:\*(\d+))?(\*)?$/i', $name, $matches)) {
                continue;
            }

            $index = isset($matches[1]) && '' !== $matches[1] ? (int) $matches[1] : 0;
            $isEncoded = isset($matches[2]) && '*' === $matches[2];

            if ($isEncoded) {
                $extended = true;

                if (0 === $index && 2 === substr_count($paramValue, "'")) {
                    [$charset, , $paramValue] = explode("'", $paramValue, 3);
                    $extendedCharset = '' !== $charset ? $charset : null;
                }

                $paramValue = rawurldecode($paramValue);
            }

            $continuations[$index] ??= $paramValue;
        }

        if ([] !== $continuations) {
            ksort($continuations);
            $joined = implode('', $continuations);

            return $extended ? self::convertToUtf8($joined, $extendedCharset) : $joined;
        }

        if (null === $plain) {
            return null;
        }

        // Some clients put RFC 2047 encoded words into plain parameters.
        return self::decodeHeaderValue($plain);
    }

    /**
     * Splits "type; a=b; c="d;e"" into ['a' => 'b', 'c' => 'd;e'] (type is skipped).
     * When a parameter is repeated the first occurrence wins, so a trailing
     * duplicate cannot override what the receiving side has already decided on.
     *
     * @return array<string, string>
     */
    private static function splitParameters(string $value): array
    {
        $parameters = [];
        $length = strlen($value);
        $position = 0;
        $segments = [];
        $current = '';
        $inQuotes = false;

        while ($position < $length) {
            $char = $value[$position];

            if ($inQuotes) {
                if ('\\' === $char && $position + 1 < $length) {
                    $current .= $value[++$position];
                } elseif ('"' === $char) {
                    $inQuotes = false;
                } else {
                    $current .= $char;
                }
            } elseif ('"' === $char) {
                $inQuotes = true;
            } elseif (';' === $char) {
                $segments[] = $current;
                $current = '';
            } else {
                $current .= $char;
            }

            ++$position;
        }

        $segments[] = $current;
        array_shift($segments); // media type / disposition type

        foreach ($segments as $segment) {
            $separator = strpos($segment, '=');

            if (false === $separator) {
                continue;
            }

            $name = trim(substr($segment, 0, $separator));
            $paramValue = trim(substr($segment, $separator + 1));

            if ('' !== $name && !isset($parameters[$name])) {
                $parameters[$name] = $paramValue;
            }
        }

        return $parameters;
    }

    /**
     * Decodes RFC 2047 encoded words into UTF-8.
     */
    public static function decodeHeaderValue(string $value): string
    {
        if (!str_contains($value, '=?')) {
            return self::convertToUtf8($value, null);
        }

        // whitespace between adjacent encoded words must be ignored (RFC 2047 §6.2)
        $value = preg_replace('/(\?=)\s+(=\?)/', '$1$2', $value) ?? $value;

        $decoded = preg_replace_callback(
            '/=\?([^?]+)\?([bBqQ])\?([^?]*)\?=/',
            static function (array $matches): string {
                $charset = $matches[1];
                $encoding = strtoupper($matches[2]);
                $text = $matches[3];

                if ('B' === $encoding) {
                    $bytes = base64_decode($text, true);
                    $bytes = false === $bytes ? '' : $bytes;
                } else {
                    $bytes = quoted_printable_decode(str_replace('_', ' ', $text));
                }

                $languagePosition = strpos($charset, '*');
                if (false !== $languagePosition) {
                    $charset = substr($charset, 0, $languagePosition);
                }

                return self::convertToUtf8($bytes, $charset);
            },
            $value,
        );

        return $decoded ?? $value;
    }

    /**
     * Parses an address list header (From/To/Cc/Bcc/Reply-To).
     *
     * @return list<array{email: string, name: ?string}>
     */
    public static function parseAddressList(string $value): array
    {
        $addresses = [];

        foreach (self::splitAddresses($value) as $token) {
            $token = trim($token);

            if ('' === $token) {
                continue;
            }

            $address = self::parseMailbox($token);

            if (null !== $address) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    /**
     * Splits on commas that are outside quotes, angle brackets and comments.
     *
     * @return list<string>
     */
    private static function splitAddresses(string $value): array
    {
        $tokens = [];
        $current = '';
        $inQuotes = false;
        $angleDepth = 0;
        $commentDepth = 0;
        $length = strlen($value);

        for ($position = 0; $position < $length; ++$position) {
            $char = $value[$position];

            if ($inQuotes) {
                $current .= $char;

                if ('\\' === $char && $position + 1 < $length) {
                    $current .= $value[++$position];
                } elseif ('"' === $char) {
                    $inQuotes = false;
                }

                continue;
            }

            switch ($char) {
                case '"':
                    $inQuotes = true;
                    $current .= $char;

                    break;
                case '(':
                    ++$commentDepth;
                    $current .= $char;

                    break;
                case ')':
                    $commentDepth = max(0, $commentDepth - 1);
                    $current .= $char;

                    break;
                case '<':
                    ++$angleDepth;
                    $current .= $char;

                    break;
                case '>':
                    $angleDepth = max(0, $angleDepth - 1);
                    $current .= $char;

                    break;
                case ',':
                case ';':
                    if (0 === $angleDepth && 0 === $commentDepth) {
                        $tokens[] = $current;
                        $current = '';
                    } else {
                        $current .= $char;
                    }

                    break;
                default:
                    $current .= $char;
            }
        }

        $tokens[] = $current;

        return $tokens;
    }

    /**
     * @return array{email: string, name: ?string}|null
     */
    private static function parseMailbox(string $token): ?array
    {
        // group syntax "Name: addr, addr;" – strip the group label
        if (1 === preg_match('/^[^"<@]*:(.*)$/s', $token, $matches)) {
            $token = trim($matches[1]);

            if ('' === $token) {
                return null;
            }
        }

        $name = null;

        if (1 === preg_match('/^(.*?)<([^<>]*)>\s*(?:\([^)]*\))?\s*$/s', $token, $matches)) {
            $email = trim($matches[2]);
            $name = trim($matches[1]);
        } else {
            $email = $token;

            if (1 === preg_match('/^(.*?)\(([^)]*)\)\s*$/s', $token, $matches)) {
                $email = trim($matches[1]);
                $name = trim($matches[2]);
            }
        }

        $email = trim($email, " \t\r\n<>");

        if ('' === $email || !str_contains($email, '@')) {
            return null;
        }

        if (null !== $name) {
            $name = self::decodeHeaderValue($name);
            $name = trim($name);

            if (strlen($name) >= 2 && str_starts_with($name, '"') && str_ends_with($name, '"')) {
                $name = stripcslashes(substr($name, 1, -1));
            }

            $name = trim($name);

            if ('' === $name) {
                $name = null;
            }
        }

        return ['email' => $email, 'name' => $name];
    }

    /**
     * Converts raw bytes from the given charset to valid UTF-8.
     *
     * Only a fixed allowlist of real character sets is handed to mbstring;
     * anything else (unknown labels, mbstring-internal names such as
     * "HTML-ENTITIES" or "BASE64", garbage) is treated as UTF-8 with a
     * fallback cleanup so the result is always valid UTF-8.
     */
    public static function convertToUtf8(string $data, ?string $charset): string
    {
        if ('' === $data) {
            return '';
        }

        $charset = self::normalizeCharset($charset);

        if (null === $charset) {
            if (mb_check_encoding($data, 'UTF-8')) {
                return $data;
            }

            // fall back to a safe re-encoding instead of returning invalid UTF-8
            return self::latin1ToUtf8($data);
        }

        try {
            $converted = mb_convert_encoding($data, 'UTF-8', $charset);
        } catch (\ValueError) {
            $converted = false;
        }

        if (!is_string($converted)) {
            return mb_check_encoding($data, 'UTF-8') ? $data : self::latin1ToUtf8($data);
        }

        return $converted;
    }

    /**
     * Maps a charset label to the allowlisted mbstring encoding name, or
     * returns null when the data must be treated as UTF-8.
     */
    private static function normalizeCharset(?string $charset): ?string
    {
        $charset = null === $charset ? '' : strtoupper(trim($charset, " \t\"'"));

        if ('' === $charset || 'UTF-8' === $charset || 'UTF8' === $charset || 'US-ASCII' === $charset || 'ASCII' === $charset) {
            return null;
        }

        $normalized = match ($charset) {
            'LATIN1' => 'ISO-8859-1',
            'LATIN2' => 'ISO-8859-2',
            'LATIN9' => 'ISO-8859-15',
            'GB2312', 'EUC-CN' => 'EUC-CN',
            'GBK', 'CP936' => 'CP936',
            'GB18030' => 'GB18030',
            'BIG5', 'BIG-5' => 'BIG-5',
            'SHIFT_JIS', 'SHIFT-JIS', 'SJIS' => 'SJIS',
            'EUC-JP', 'EUCJP' => 'EUC-JP',
            'EUC-KR', 'EUCKR' => 'EUC-KR',
            'ISO-2022-JP' => 'ISO-2022-JP',
            default => null,
        };

        if (null === $normalized) {
            if (1 === preg_match('/^ISO[-_]?8859[-_](\d{1,2})$/', $charset, $matches)) {
                $normalized = 'ISO-8859-'.$matches[1];
            } elseif (1 === preg_match('/^(?:WINDOWS|CP|WIN)[-_]?(125\d)$/', $charset, $matches)) {
                $normalized = 'WINDOWS-'.$matches[1];
            } elseif (1 === preg_match('/^KOI8[-_]?([RU])$/', $charset, $matches)) {
                $normalized = 'KOI8-'.$matches[1];
            } elseif (1 === preg_match('/^UTF[-_]?(16|32)(LE|BE)?$/', $charset, $matches)) {
                $normalized = 'UTF-'.$matches[1].($matches[2] ?? '');
            } else {
                return null;
            }
        }

        // mbstring builds differ; never pass a name it does not know
        $known = array_map(strtoupper(...), mb_list_encodings());

        return in_array($normalized, $known, true) ? $normalized : null;
    }

    private static function latin1ToUtf8(string $data): string
    {
        $converted = mb_convert_encoding($data, 'UTF-8', 'ISO-8859-1');

        return is_string($converted) ? $converted : '';
    }
}
