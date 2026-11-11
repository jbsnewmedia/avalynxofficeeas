<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Tests\Service;

use AvalynxOfficeEas\Service\AutodiscoverService;
use AvalynxOfficeEas\Tests\Support\TestEnvironment;
use PHPUnit\Framework\TestCase;

/**
 * Autodiscover endpoint integration tests against the shared core
 * database schema (logon with the seeded app password).
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
final class AutodiscoverServiceTest extends TestCase
{
    use TestEnvironment;

    private const string REQUEST_XML = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<Autodiscover xmlns="http://schemas.microsoft.com/exchange/autodiscover/responseschema/2006">
  <Request>
    <EMailAddress>admin@example.com</EMailAddress>
    <AcceptableResponseSchema>http://schemas.microsoft.com/exchange/autodiscover/mobilesync/responseschema/2006</AcceptableResponseSchema>
  </Request>
</Autodiscover>
XML;

    private const string WRONG_SCHEMA_XML = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<Autodiscover xmlns="http://schemas.microsoft.com/exchange/autodiscover/responseschema/2006">
  <Request>
    <EMailAddress>admin@example.com</EMailAddress>
    <AcceptableResponseSchema>http://schemas.microsoft.com/exchange/autodiscover/mobileaccess/responseschema/2006</AcceptableResponseSchema>
  </Request>
</Autodiscover>
XML;

    private AutodiscoverService $service;

    protected function setUp(): void
    {
        self::bootEnvironment();
        self::seed();
        $this->service = new AutodiscoverService(dirname(__DIR__, 2));

        $_SERVER['REQUEST_METHOD'] = 'POST';
        unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['HTTP_HOST'], $_SERVER['CONTENT_LENGTH']);
        $_SERVER['HTTP_HOST'] = 'eas.example.com';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['HTTP_HOST'], $_SERVER['CONTENT_LENGTH']);
    }

    public function testGetRequestIsRejectedWithAuthChallenge(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $response = $this->service->handle();

        self::assertSame(401, $response['status']);
        self::assertSame('Basic realm="ZPush"', $response['headers']['WWW-Authenticate']);
        self::assertStringContainsString('GET not supported', $response['body']);
    }

    public function testPostWithoutCredentialsIsChallenged(): void
    {
        $response = $this->service->handle(self::REQUEST_XML);

        self::assertSame(401, $response['status']);
        self::assertSame('Basic realm="ZPush"', $response['headers']['WWW-Authenticate']);
    }

    public function testPostWithValidCredentialsAnswersWithServerUrl(): void
    {
        $_SERVER['PHP_AUTH_USER'] = 'admin@example.com';
        $_SERVER['PHP_AUTH_PW'] = self::SEED_APP_PASSWORD;

        $response = $this->service->handle(self::REQUEST_XML);

        self::assertSame(200, $response['status']);
        self::assertSame('text/xml; charset=utf-8', $response['headers']['Content-Type']);

        $xml = simplexml_load_string($response['body']);
        self::assertNotFalse($xml);
        self::assertSame('admin@example.com', (string) $xml->Response->User->EMailAddress);
        self::assertNotSame('', (string) $xml->Response->User->DisplayName);
        self::assertSame('https://eas.example.com/Microsoft-Server-ActiveSync', (string) $xml->Response->Action->Settings->Server->Url);
        self::assertSame('https://eas.example.com/Microsoft-Server-ActiveSync', (string) $xml->Response->Action->Settings->Server->Name);
    }

    public function testPostWithWrongPasswordIsChallenged(): void
    {
        $_SERVER['PHP_AUTH_USER'] = 'admin@example.com';
        $_SERVER['PHP_AUTH_PW'] = 'totally-wrong';

        $response = $this->service->handle(self::REQUEST_XML);

        self::assertSame(401, $response['status']);
        self::assertSame('Basic realm="ZPush"', $response['headers']['WWW-Authenticate']);
    }

    public function testUnsupportedResponseSchemaIsRejected(): void
    {
        $_SERVER['PHP_AUTH_USER'] = 'admin@example.com';
        $_SERVER['PHP_AUTH_PW'] = self::SEED_APP_PASSWORD;

        $response = $this->service->handle(self::WRONG_SCHEMA_XML);

        self::assertSame(400, $response['status']);
    }

    public function testMalformedXmlIsRejected(): void
    {
        $_SERVER['PHP_AUTH_USER'] = 'admin@example.com';
        $_SERVER['PHP_AUTH_PW'] = self::SEED_APP_PASSWORD;

        $response = $this->service->handle('<?xml version="1.0"?><Autodiscover><broken');

        self::assertSame(400, $response['status']);
    }
}
