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
 */
final readonly class MimeParser
{
    private const int MAX_DEPTH = 32;

    public function parse(string $raw): MimePart
    {
        return $this->parseEntity($raw, 0);
    }

    private function parseEntity(string $raw, int $depth): MimePart
    {
        [$headerBlock, $body] = $this->splitHeadersAndBody($raw);
        $headers = $this->parseHeaders($headerBlock);

        $contentType = $headers['content-type'][0] ?? '';
        $mediaType = strtolower(trim((string) strtok($contentType, ';')));

        if ($depth < self::MAX_DEPTH && str_starts_with($mediaType, 'multipart/')) {
            $boundary = self::headerParameter($contentType, 'boundary');

            if (null !== $boundary && '' !== $boundary) {
                $parts = [];

                foreach ($this->splitMultipart($body, $boundary) as $rawPart) {
                    $parts[] = $this->parseEntity($rawPart, $depth + 1);
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
                $plain = $paramValue;

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

            $continuations[$index] = $paramValue;
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

            if ('' !== $name) {
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
     */
    public static function convertToUtf8(string $data, ?string $charset): string
    {
        if ('' === $data) {
            return '';
        }

        $charset = null === $charset ? '' : strtoupper(trim($charset, " \t\"'"));

        if ('' === $charset || 'UTF-8' === $charset || 'UTF8' === $charset || 'US-ASCII' === $charset || 'ASCII' === $charset) {
            if (mb_check_encoding($data, 'UTF-8')) {
                return $data;
            }

            // fall back to a safe re-encoding instead of returning invalid UTF-8
            return self::latin1ToUtf8($data);
        }

        $known = array_map(strtoupper(...), mb_list_encodings());

        if (!in_array($charset, $known, true)) {
            $alias = match ($charset) {
                'LATIN1', 'ISO8859-1', 'ISO_8859-1' => 'ISO-8859-1',
                'CP1252', 'WIN-1252' => 'WINDOWS-1252',
                default => null,
            };

            if (null === $alias) {
                return mb_check_encoding($data, 'UTF-8') ? $data : self::latin1ToUtf8($data);
            }

            $charset = $alias;
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

    private static function latin1ToUtf8(string $data): string
    {
        $converted = mb_convert_encoding($data, 'UTF-8', 'ISO-8859-1');

        return is_string($converted) ? $converted : '';
    }
}
