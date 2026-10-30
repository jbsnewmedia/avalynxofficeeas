<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Tests\Mail;

use AvalynxOfficeEas\Mail\MimeParser;
use PHPUnit\Framework\TestCase;

final class MimeParserTest extends TestCase
{
    private MimeParser $parser;

    protected function setUp(): void
    {
        $this->parser = new MimeParser();
    }

    public function testParsesMultipartMessageWithAlternativeBodiesAndAttachment(): void
    {
        $attachment = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\nfake pdf content\n";
        $mime = implode("\r\n", [
            'From: "Müller, Hans" <hans@example.com>',
            'To: Anna Schmidt <anna@example.org>, bob@example.net',
            'Cc: =?UTF-8?Q?J=C3=BCrgen_K=C3=B6nig?= <juergen@example.com>',
            'Bcc: hidden@example.com',
            'Reply-To: Support <support@example.com>',
            'Subject: =?UTF-8?B?UHLDvGZ1bmcg4oKsIMOEbmRlcnVuZw==?=',
            'Message-ID: <abc123@example.com>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed;',
            "\tboundary=\"outer-boundary\"",
            '',
            'This is a multi-part message in MIME format.',
            '--outer-boundary',
            'Content-Type: multipart/alternative; boundary=inner-boundary',
            '',
            '--inner-boundary',
            'Content-Type: text/plain; charset="utf-8"',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            'Hallo Welt =E2=82=AC',
            'Zweite Zeile mit =C3=9Cmlaut',
            '--inner-boundary',
            'Content-Type: text/html; charset=utf-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode('<html><body><p>Hallo <b>Welt</b> €</p></body></html>'), 76, "\r\n"),
            '--inner-boundary--',
            '--outer-boundary',
            'Content-Type: application/pdf; name="fallback.pdf"',
            'Content-Transfer-Encoding: base64',
            'Content-Disposition: attachment;',
            " filename*=UTF-8''Rechnung%20M%C3%A4rz.pdf",
            '',
            chunk_split(base64_encode($attachment), 76, "\r\n"),
            '--outer-boundary--',
            '',
        ]);

        $message = $this->parser->parse($mime);

        self::assertSame('Prüfung € Änderung', $message->getHeader('subject'));
        self::assertSame('<abc123@example.com>', $message->getHeader('message-id'));
        self::assertSame('multipart/mixed', $message->getContentType());
        self::assertTrue($message->isMultipart());

        self::assertSame([['email' => 'hans@example.com', 'name' => 'Müller, Hans']], $message->getAddresses('from'));
        self::assertSame([
            ['email' => 'anna@example.org', 'name' => 'Anna Schmidt'],
            ['email' => 'bob@example.net', 'name' => null],
        ], $message->getAddresses('to'));
        self::assertSame([['email' => 'juergen@example.com', 'name' => 'Jürgen König']], $message->getAddresses('cc'));
        self::assertSame([['email' => 'hidden@example.com', 'name' => null]], $message->getAddresses('bcc'));
        self::assertSame([['email' => 'support@example.com', 'name' => 'Support']], $message->getAddresses('reply-to'));

        $parts = $message->getParts();
        self::assertCount(2, $parts);

        $alternative = $parts[0];
        self::assertSame('multipart/alternative', $alternative->getContentType());
        self::assertCount(2, $alternative->getParts());

        [$text, $html] = $alternative->getParts();
        self::assertSame('text/plain', $text->getContentType());
        self::assertFalse($text->isAttachment());
        self::assertSame("Hallo Welt €\r\nZweite Zeile mit Ümlaut", $text->getTextBody());

        self::assertSame('text/html', $html->getContentType());
        self::assertSame('<html><body><p>Hallo <b>Welt</b> €</p></body></html>', $html->getTextBody());

        $pdf = $parts[1];
        self::assertTrue($pdf->isAttachment());
        self::assertSame('application/pdf', $pdf->getContentType());
        self::assertSame('attachment', $pdf->getDispositionType());
        self::assertSame('Rechnung März.pdf', $pdf->getFileName());
        self::assertSame($attachment, $pdf->getDecodedBody());
    }

    public function testParsesSimpleTextMessageWithLatin1Charset(): void
    {
        $mime = "From: sender@example.com\r\nTo: recipient@example.com\r\nSubject: Plain\r\nContent-Type: text/plain; charset=iso-8859-1\r\n\r\nGr\xFC\xDFe aus M\xFCnchen\r\n";

        $message = $this->parser->parse($mime);

        self::assertFalse($message->isMultipart());
        self::assertSame('Plain', $message->getHeader('subject'));
        self::assertSame('text/plain', $message->getContentType());
        self::assertSame("Grüße aus München\r\n", $message->getTextBody());
        self::assertSame([['email' => 'sender@example.com', 'name' => null]], $message->getAddresses('from'));
    }

    public function testInlineImageWithFileNameIsTreatedAsAttachment(): void
    {
        $mime = implode("\r\n", [
            'Content-Type: multipart/related; boundary="rel"',
            '',
            '--rel',
            'Content-Type: text/html',
            '',
            '<img src="cid:logo">',
            '--rel',
            'Content-Type: image/png; name="logo.png"',
            'Content-Transfer-Encoding: base64',
            'Content-Disposition: inline; filename="logo.png"',
            'Content-ID: <logo>',
            '',
            base64_encode('PNGDATA'),
            '--rel--',
        ]);

        $message = $this->parser->parse($mime);
        $parts = $message->getParts();

        self::assertCount(2, $parts);
        self::assertFalse($parts[0]->isAttachment());
        self::assertSame('<img src="cid:logo">', $parts[0]->getTextBody());
        self::assertTrue($parts[1]->isAttachment());
        self::assertSame('logo.png', $parts[1]->getFileName());
        self::assertSame('inline', $parts[1]->getDispositionType());
        self::assertSame('PNGDATA', $parts[1]->getDecodedBody());
    }

    public function testHandlesContinuedRfc2231ParametersAndEncodedWordFileNames(): void
    {
        self::assertSame(
            'sehr langer Dateiname.txt',
            MimeParser::headerParameter("attachment; filename*0*=UTF-8''sehr%20langer; filename*1*=%20Dateiname.txt", 'filename'),
        );
        self::assertSame(
            'Übersicht.xlsx',
            MimeParser::headerParameter('attachment; filename="=?UTF-8?B?w5xiZXJzaWNodC54bHN4?="', 'filename'),
        );
        self::assertSame('utf-8', MimeParser::headerParameter('text/plain; charset=utf-8; format=flowed', 'charset'));
        self::assertNull(MimeParser::headerParameter('text/plain', 'charset'));
    }

    public function testAddressListParsingEdgeCases(): void
    {
        $addresses = MimeParser::parseAddressList('"Doe, John" <john@example.com>, <jane@example.com>, Group: a@example.com, b@example.com;, plain@example.com (Plain Comment)');

        self::assertSame([
            ['email' => 'john@example.com', 'name' => 'Doe, John'],
            ['email' => 'jane@example.com', 'name' => null],
            ['email' => 'a@example.com', 'name' => null],
            ['email' => 'b@example.com', 'name' => null],
            ['email' => 'plain@example.com', 'name' => 'Plain Comment'],
        ], $addresses);

        self::assertSame([], MimeParser::parseAddressList('undisclosed-recipients:;'));
        self::assertSame([], MimeParser::parseAddressList(''));
    }

    public function testMalformedInputDoesNotThrow(): void
    {
        $message = $this->parser->parse("Content-Type: multipart/mixed; boundary=missing\r\n\r\nno boundary here");

        self::assertFalse($message->isMultipart());
        self::assertSame('no boundary here', $message->getTextBody());

        $bodyOnly = $this->parser->parse('just some text');
        self::assertSame('just some text', $bodyOnly->getTextBody());
        self::assertNull($bodyOnly->getHeader('subject'));

        $invalidUtf8 = $this->parser->parse("Content-Type: text/plain; charset=utf-8\r\n\r\n\xFFbroken");
        self::assertTrue(mb_check_encoding($invalidUtf8->getTextBody(), 'UTF-8'));
    }
}
