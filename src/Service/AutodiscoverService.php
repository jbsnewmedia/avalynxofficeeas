<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Service;

/**
 * Runs the Z-Push autodiscover flow for the /autodiscover/autodiscover.xml
 * endpoint. Mirrors the Z-Push 2.7.6 autodiscover entry point and collects
 * the response like EndpointService does for the ActiveSync processor, so
 * no raw exit()/echo/header() leaks into the front controller.
 *
 * Devices POST an Autodiscover request XML with their credentials; the
 * service authenticates them against the backend (app passwords only),
 * resolves display name and e-mail address and answers with the ActiveSync
 * server URL so smartphones can auto-configure their account. The URL host
 * is ZPUSH_HOST when configured, otherwise the host of the incoming
 * request — serve the endpoint on its real public host (or redirect to it)
 * so the announced URL is correct.
 */
final class AutodiscoverService
{
    private const int MAX_INPUT_BYTES = 8192;
    private const string ACCEPTABLE_RESPONSE_SCHEMA = 'http://schemas.microsoft.com/exchange/autodiscover/mobilesync/responseschema/2006';

    private ?string $requestBody = null;

    public function __construct(private readonly string $projectDir)
    {
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function handle(?string $requestBody = null): array
    {
        $this->requestBody = $requestBody;

        EndpointService::bootZPush($this->projectDir);
        EndpointService::resetZPushStatics();
        \ZLog::Initialize();

        $methodRaw = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $method = is_string($methodRaw) ? strtoupper($methodRaw) : 'GET';

        // Mirror Z-Push's autodiscover: only POST carries the request XML.
        if ('POST' !== $method) {
            \ZLog::Write(LOGLEVEL_INFO, 'Autodiscover: GET request rejected.');

            return $this->challenge('GET not supported');
        }

        try {
            $incoming = $this->incomingXml();
            $backend = \ZPush::GetBackend();
            if (!$backend instanceof \IBackend) {
                throw new \RuntimeException('The Z-Push backend is not available.');
            }

            $username = $this->login($backend);
            $userDetails = $backend->GetUserDetails($username);
            $requestedEmail = $incoming->Request->EMailAddress ?? null;
            $requestedEmail = null !== $requestedEmail ? (string) $requestedEmail : '';
            $email = $this->attrib($userDetails, 'emailaddress') ?? $requestedEmail;
            $fullname = $this->attrib($userDetails, 'fullname') ?? $email;

            $serverUrl = $this->serverUrl();
            \ZLog::Write(LOGLEVEL_INFO, sprintf("Autodiscover: answering user '%s' with server URL '%s'.", $username, $serverUrl));

            return [
                'status' => 200,
                'headers' => [
                    'Content-Type' => 'text/xml; charset=utf-8',
                ],
                'body' => $this->response($email, $fullname, $serverUrl),
            ];
        } catch (\AuthenticationRequiredException $exception) {
            \ZLog::Write(LOGLEVEL_WARN, 'Autodiscover: authentication failed - '.$exception->getMessage());

            return $this->challenge($exception->getMessage());
        } catch (\ZPushException $exception) {
            \ZLog::Write(LOGLEVEL_ERROR, 'Autodiscover: '.$exception->getMessage());

            return [
                'status' => 400,
                'headers' => ['Content-Type' => 'text/plain; charset=utf-8'],
                'body' => $exception->getMessage()."\n",
            ];
        }
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function challenge(string $message): array
    {
        return [
            'status' => 401,
            'headers' => [
                'Content-Type' => 'text/plain; charset=utf-8',
                'WWW-Authenticate' => 'Basic realm="ZPush"',
            ],
            'body' => $message."\n",
        ];
    }

    /**
     * @throws \ZPushException                  when the request is malformed
     * @throws \AuthenticationRequiredException when no credentials are present
     */
    private function incomingXml(): \SimpleXMLElement
    {
        $contentLengthRaw = $_SERVER['CONTENT_LENGTH'] ?? 0;
        $contentLength = is_numeric($contentLengthRaw) ? (int) $contentLengthRaw : 0;
        if ($contentLength > self::MAX_INPUT_BYTES) {
            throw new \ZPushException('The request exceeds the maximum expected input size.');
        }

        $raw = $this->requestBody ?? file_get_contents('php://input', false, null, 0, self::MAX_INPUT_BYTES);
        if (false === $raw || '' === $raw) {
            throw new \AuthenticationRequiredException('No request data.');
        }

        if (self::MAX_INPUT_BYTES === strlen($raw)) {
            throw new \ZPushException('The request exceeds the maximum expected input size.');
        }

        $xml = simplexml_load_string($raw);
        if (false === $xml) {
            throw new \ZPushException('Invalid request XML.');
        }

        if (!isset($xml->Request->EMailAddress)) {
            throw new \ZPushException('Invalid request XML: no email address.');
        }

        $schemaNode = $xml->Request->AcceptableResponseSchema ?? null;
        $schema = null !== $schemaNode ? (string) $schemaNode : '';
        if (0 !== strcasecmp($schema, self::ACCEPTABLE_RESPONSE_SCHEMA)) {
            throw new \ZPushException('Only the mobilesync response schema is supported.');
        }

        return $xml;
    }

    /**
     * Authenticates the request credentials against the backend.
     *
     * @throws \AuthenticationRequiredException when the login fails
     */
    private function login(\IBackend $backend): string
    {
        $authUser = $_SERVER['PHP_AUTH_USER'] ?? null;
        $authPassword = $_SERVER['PHP_AUTH_PW'] ?? null;

        if (!is_string($authUser) || '' === $authUser || !is_string($authPassword) || '' === $authPassword) {
            throw new \AuthenticationRequiredException('No username or password provided.');
        }

        $split = \Utils::SplitDomainUser($authUser);
        $username = is_string($split[0] ?? null) ? $split[0] : $authUser;

        if (!\constant('USE_FULLEMAIL_FOR_LOGIN')) {
            $username = \Utils::GetLocalPartFromEmail($username);
        }

        if ('' === $username || true !== $backend->Logon($username, '', $authPassword)) {
            throw new \AuthenticationRequiredException('Username or password incorrect.');
        }

        return $username;
    }

    /**
     * The ActiveSync URL announced to the device: the configured public
     * host wins (ZPUSH_HOST), otherwise the host the request arrived at.
     */
    private function serverUrl(): string
    {
        $configured = defined('ZPUSH_HOST') ? constant('ZPUSH_HOST') : null;
        $httpHost = $_SERVER['HTTP_HOST'] ?? null;
        $serverName = $_SERVER['SERVER_NAME'] ?? null;
        $host = is_string($configured) && '' !== $configured
            ? $configured
            : (is_string($httpHost) && '' !== $httpHost
                ? $httpHost
                : (is_string($serverName) ? $serverName : ''));

        return 'https://'.$host.'/Microsoft-Server-ActiveSync';
    }

    private function attrib(mixed $userDetails, string $attrib): ?string
    {
        if (!is_array($userDetails)) {
            return null;
        }

        $value = $userDetails[$attrib] ?? null;

        return is_scalar($value) && '' !== $value ? (string) $value : null;
    }

    /**
     * Fills the Z-Push autodiscover response template with the resolved
     * user and the ActiveSync server URL.
     */
    private function response(string $email, string $fullname, string $serverUrl): string
    {
        $template = @file_get_contents(\constant('BASE_PATH').'autodiscover/response.xml');
        if (false === $template || '' === $template) {
            throw new \RuntimeException('The autodiscover response template is missing.');
        }

        $xml = new \SimpleXMLElement($template);
        $xml->Response->User->DisplayName = $fullname;
        $xml->Response->User->EMailAddress = $email;
        $xml->Response->Action->Settings->Server->Url = $serverUrl;
        $xml->Response->Action->Settings->Server->Name = $serverUrl;

        return (string) $xml->asXML();
    }
}
