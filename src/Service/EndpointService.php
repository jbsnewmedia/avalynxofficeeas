<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the GNU Affero General Public License v3.0.
 */

namespace AvalynxOfficeEas\Service;

/**
 * Runs the Z-Push request processor for the /Microsoft-Server-ActiveSync endpoint.
 *
 * The flow mirrors the Z-Push 2.7.6 index.php entry point, but collects the
 * response body and headers so the front controller can emit a clean
 * response. Runs as its own process: the superglobals are populated by the
 * web server, no framework request bridging is required.
 */
final class EndpointService
{
    /** @var array<string, string> collected response headers by lowercase name */
    private array $headers = [];

    private ?string $data = null;

    private ?string $bridgeBody = null;

    public function __construct(private readonly string $projectDir)
    {
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function handle(?string $requestBody = null): array
    {
        $this->headers = [];
        $this->data = null;
        $this->bridgeBody = $requestBody;

        $cwd = getcwd();

        if (false === $cwd) {
            throw new \RuntimeException('Unable to determine the current working directory.');
        }

        chdir($this->projectDir);

        $this->prepareDirectories();

        self::bootZPush($this->projectDir);

        // Reset the SAPI response code register: Z-Push sets it only when
        // throwing HTTP-code exceptions. A reused FPM worker would otherwise
        // inherit the code of a previous request.
        http_response_code(200);

        $startLevel = ob_get_level();
        $body = '';

        ob_start();

        try {
            $this->run();
        } catch (\Throwable $throwable) {
            // Z-Push handles its own exceptions; a fatal escape is logged and
            // results in an empty body with a server error status.
            error_log(sprintf('EAS endpoint failure: %s', $throwable->getMessage()));
            http_response_code(500);
        }

        while (ob_get_level() > $startLevel) {
            $body = ob_get_clean().$body;
        }

        chdir($cwd);

        // Raw header() emissions (Z-Push internals) must not leak into the
        // final response next to the collected headers.
        header_remove();

        $content = $this->data ?? $body;
        $statusCode = http_response_code();
        $status = is_int($statusCode) ? $statusCode : 200;

        return [
            'status' => $status,
            'headers' => $this->responseHeaders($content),
            'body' => $content,
        ];
    }

    /**
     * Includes the Z-Push configuration and boots its runtime constants.
     * The define() calls are not idempotent, so both steps are guarded; the
     * PHPUnit bootstrap calls this as well to cover the first-boot path.
     */
    public static function bootZPush(string $projectDir): void
    {
        defined('ZPUSH_CONFIG') || define('ZPUSH_CONFIG', $projectDir.'/config/zpush-config.php');
        include_once ZPUSH_CONFIG;
        defined('LOGBACKEND_CLASS') || \ZPush::CheckConfig();
    }

    private function run(): void
    {
        // Z-Push caches its collaborators in static properties; reset them so
        // every request (and every PHP-FPM worker reuse) starts with a clean
        // slate bound to the current container.
        self::resetZPushStatics();

        // ignore user abortions because this can lead to weird errors - see ZP-239
        ignore_user_abort(true);

        $scriptTimeout = \constant('SCRIPT_TIMEOUT');
        $timeout = is_int($scriptTimeout) ? $scriptTimeout : 0;
        ini_set('max_execution_time', (string) $timeout);
        set_time_limit($timeout);

        ob_start(null, 1048576);

        try {
            \Request::Initialize();
            $this->bridgeBody();
            \ZLog::Initialize();

            \ZLog::Write(LOGLEVEL_DEBUG, '-------- Start');
            \ZLog::Write(LOGLEVEL_DEBUG, sprintf("cmd='%s' devType='%s' devId='%s' getUser='%s' from='%s' version='%s' method='%s'",
                $this->str(\Request::GetCommand()), $this->str(\Request::GetDeviceType()), $this->str(\Request::GetDeviceID()), $this->str(\Request::GetGETUser()), $this->str(\Request::GetRemoteAddr()), $this->str(@constant('ZPUSH_VERSION')), $this->str(\Request::GetMethod())));

            // always request the authorization header
            if (!\Request::HasAuthenticationInfo() || !\Request::GetGETUser()) {
                throw new \AuthenticationRequiredException('Access denied. Please send authorisation information');
            }

            \ZPush::CheckAdvancedConfig();

            // Process request headers and look for AS headers
            \Request::ProcessHeaders();

            // Stop here if this is an OPTIONS request
            if (\Request::IsMethodOPTIONS()) {
                \RequestProcessor::Authenticate();
                throw new \NoPostRequestException('Options request', \NoPostRequestException::OPTIONS_REQUEST);
            }

            // Check required GET parameters
            if (\Request::IsMethodPOST() && (false === \Request::GetCommandCode() || null === \Request::GetDeviceID() || null === \Request::GetDeviceType())) {
                throw new \FatalException('Requested the Z-Push URL without the required GET parameters');
            }

            // Load the backend
            $backend = \ZPush::GetBackend();

            // check the provisioning information
            $provisioning = \constant('PROVISIONING');
            $looseProvisioning = \constant('LOOSE_PROVISIONING');
            $commandCode = \Request::GetCommandCode();
            if (true === $provisioning && \Request::IsMethodPOST() && \ZPush::CommandNeedsProvisioning(strval($this->intOrNull($commandCode) ?? 0))
                && ((\Request::WasPolicyKeySent() && 0 == \Request::GetPolicyKey()) || $this->callOn(\ZPush::GetDeviceManager(), 'ProvisioningRequired', \Request::GetPolicyKey()))
                && (false === $looseProvisioning
                || (true === $looseProvisioning && \Request::WasPolicyKeySent()))) {
                throw new \ProvisioningRequiredException();
            }

            // most commands require an authenticated user
            if (\ZPush::CommandNeedsAuthentication($this->intOrNull($commandCode) ?? 0)) {
                \RequestProcessor::Authenticate();
            }

            if (\Request::IsMethodGET()) {
                throw new \NoPostRequestException('This is the Z-Push location and can only be accessed by Microsoft ActiveSync-capable devices', \NoPostRequestException::GET_REQUEST);
            }

            $this->sendHeader(\ZPush::GetServerHeader());

            if (\RequestProcessor::isUserAuthenticated()) {
                $this->sendHeader('X-Z-Push-Version: '.@constant('ZPUSH_VERSION'));
            }

            // announce the supported AS versions (if not already sent to device)
            if (true === $this->callOn(\ZPush::GetDeviceManager(), 'AnnounceASVersion')) {
                $versions = $this->str(\ZPush::GetSupportedProtocolVersions(true));
                \ZLog::Write(LOGLEVEL_INFO, sprintf('Announcing latest AS version to device: %s', $versions));
                $this->sendHeader('X-MS-RP: '.$versions);
            }

            \RequestProcessor::Initialize();
            \RequestProcessor::HandleRequest();

            // eventually the RequestProcessor wants to send other headers to the mobile
            foreach (\RequestProcessor::GetSpecialHeaders() as $specialHeader) {
                \ZLog::Write(LOGLEVEL_DEBUG, sprintf('Special header: %s', $this->str($specialHeader)));
                $this->sendHeader($this->str($specialHeader));
            }

            // stream the data
            $len = ob_get_length();
            $data = (string) ob_get_contents();
            ob_end_clean();

            $this->callOn(\ZPush::GetDeviceManager(), 'SentData', $len);

            if (0 == $len) {
                $this->sendHeader('Content-Type: application/vnd.ms-sync.wbxml');
            }

            $this->data = $data;

            // destruct backend after all data is on the stream
            $this->callOn($backend, 'Logoff');
        } catch (\NoPostRequestException $nopostex) {
            if (\NoPostRequestException::OPTIONS_REQUEST == $nopostex->getCode()) {
                $this->sendHeader(\ZPush::GetServerHeader());
                $this->sendHeader(\ZPush::GetSupportedProtocolVersions());
                $this->sendHeader(\ZPush::GetSupportedCommands());
                \ZLog::Write(LOGLEVEL_INFO, $nopostex->getMessage());
            } elseif (\NoPostRequestException::GET_REQUEST == $nopostex->getCode()) {
                $getUserAgent = \Request::GetUserAgent();
                if (is_string($getUserAgent) && '' !== $getUserAgent) {
                    \ZLog::Write(LOGLEVEL_INFO, sprintf("User-agent: '%s'", $getUserAgent));
                }

                if ($nopostex->showLegalNotice()) {
                    \ZPush::PrintZPushLegal('GET not supported', $nopostex->getMessage());
                }
            }
        } catch (\Exception $ex) {
            $this->handleException($ex);
        }

        // save device data if the DeviceManager is available
        try {
            if (false !== \ZPush::GetDeviceManager(false) && \Request::IsMethodPOST()) {
                $this->callOn(\ZPush::GetDeviceManager(), 'Save');
            }
        } catch (\Throwable) {
            // requests without a device id cannot have device state
        }

        $this->finalLog();
    }

    private function finalLog(): void
    {
        try {
            $peakCurrent = memory_get_peak_usage(false);
            $peakReal = memory_get_peak_usage(true);
            // end gracefully
            \ZLog::Write(LOGLEVEL_INFO, sprintf("cmd='%s' memory='%s/%s' time='%ss' devType='%s' devId='%s' getUser='%s' from='%s' idle='%ss' version='%s' method='%s' httpcode='%s'",
                $this->str(\Request::GetCommand()), \Utils::FormatBytes(is_int($peakCurrent) ? $peakCurrent : 0), \Utils::FormatBytes(is_int($peakReal) ? $peakReal : 0),
                number_format(microtime(true) - $this->requestTimeFloat(), 2),
                $this->str(\Request::GetDeviceType()), $this->str(\Request::GetDeviceID()), $this->str(\Request::GetGETUser()), $this->str(\Request::GetRemoteAddr()),
                \RequestProcessor::GetWaitTime(), $this->str(@constant('ZPUSH_VERSION')), $this->str(\Request::GetMethod()), $this->intOrNull(http_response_code()) ?? 0));

            \ZLog::Write(LOGLEVEL_DEBUG, '-------- End');
        } catch (\Throwable) {
            // logging must never break the response
        }
    }

    private function handleException(\Throwable $ex): void
    {
        // Extract any previous exception message for logging purpose.
        $exclass = $ex::class;
        $exceptionMessage = substr($ex->getMessage(), 0, 2000);

        if (null !== $ex->getPrevious()) {
            $seen = [];
            $currentException = $ex;

            while (null !== $currentException->getPrevious()
                && !in_array(spl_object_id($currentException->getPrevious()), $seen, true)
                && mb_strlen($exceptionMessage) < 2000) {
                $seen[] = spl_object_id($currentException);
                $currentException = $currentException->getPrevious();
                $exceptionMessage .= ' -> '.substr($currentException->getMessage(), 0, 500);
            }
        }

        $userAgent = \Request::GetUserAgent();
        if (is_string($userAgent) && '' !== $userAgent) {
            \ZLog::Write(LOGLEVEL_INFO, sprintf("User-agent: '%s'", $userAgent));
        }

        \ZLog::Write(LOGLEVEL_FATAL, sprintf('Exception: (%s) - %s', $exclass, $exceptionMessage));

        if ($ex instanceof \ZPushException) {
            $this->sendHeader('HTTP/1.1 '.$this->str($ex->getHTTPCodeString()));

            $httpHeaders = $ex->getHTTPHeaders();

            if (is_array($httpHeaders)) {
                foreach ($httpHeaders as $httpHeader) {
                    $this->sendHeader($this->str($httpHeader));
                }
            }
        } else {
            $this->sendHeader('HTTP/1.1 500 Internal Server Error');
        }

        if ($ex instanceof \AuthenticationRequiredException) {
            if (\Request::IsMethodGET()) {
                \ZPush::PrintZPushLegal($exclass, sprintf('<pre>%s</pre>', $ex->getMessage()));
            }

            $logAuthFail = \constant('LOGAUTHFAIL');
            if (false !== $logAuthFail) {
                \ZLog::Write(LOGLEVEL_WARN, sprintf("IP: %s failed to authenticate user '%s'", $this->str(\Request::GetRemoteAddr()), $this->str(\Request::GetAuthUser() ?? \Request::GetGETUser())));
            }
        } elseif ($ex instanceof \WBXMLException) {
            \ZLog::Write(LOGLEVEL_FATAL, "Request could not be processed correctly due to a WBXMLException. Please report this including the 'WBXML debug data' logged. Be aware that the debug data could contain confidential information.");
        } elseif (!$ex instanceof \ZPushException) {
            // Internal errors (Doctrine, PostgreSQL, ...) may leak schema or
            // connection details: keep message and trace in the log only.
            $cmdinfo = (null !== \Request::GetCommand()) ? sprintf(' processing command <i>%s</i>', $this->str(\Request::GetCommand())) : '';
            \ZLog::Write(LOGLEVEL_ERROR, sprintf('EAS internal error (%s): %s%s', $exclass, $exceptionMessage, $this->traceSummary($ex)));
            \ZPush::PrintZPushLegal('Internal server error'.$cmdinfo, '<pre>Internal server error</pre>');
        } elseif ($ex->showLegalNotice()) {
            $cmdinfo = (null !== \Request::GetCommand()) ? sprintf(' processing command <i>%s</i>', $this->str(\Request::GetCommand())) : '';
            $trace = $this->traceSummary($ex);
            if ('' !== $trace) {
                \ZLog::Write(LOGLEVEL_ERROR, sprintf('EAS exception trace: %s', $trace));
            }
            \ZPush::PrintZPushLegal($exclass.$cmdinfo, sprintf('<pre>%s</pre>', $ex->getMessage()));
        }

        // Announce exception to process loop detection
        try {
            $deviceManager = \ZPush::GetDeviceManager(false);

            if (null !== $deviceManager) {
                $this->callOn($deviceManager, 'AnnounceProcessException', $ex);
            }
        } catch (\Throwable) {
            // requests without a device id cannot have device state
        }

        // Announce exception if the TopCollector if available
        try {
            $this->callOn(\ZPush::GetTopCollector(), 'AnnounceInformation', $ex::class, true);
        } catch (\Throwable) {
            // requests without a device id cannot have device state
        }
    }

    private function traceSummary(\Throwable $ex): string
    {
        $frames = [];

        foreach ($ex->getTrace() as $frame) {
            $frames[] = sprintf('%s:%s %s%s%s',
                $frame['file'] ?? 'unknown',
                $frame['line'] ?? 0,
                $frame['class'] ?? '',
                $frame['type'] ?? '',
                $frame['function'] ?? '');
        }

        return [] === $frames ? '' : "\n\nTrace:\n".implode("\n", $frames);
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function strOr(mixed $value, string $default): string
    {
        return is_string($value) && '' !== $value ? $value : $default;
    }

    private function requestTimeFloat(): float
    {
        $value = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;

        return is_float($value) || is_int($value) ? (float) $value : microtime(true);
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    /**
     * Z-Push returns untyped objects for its internal managers; calls are
     * dispatched dynamically and guarded with is_callable.
     */
    private function callOn(object $target, string $method, mixed ...$args): mixed
    {
        $callback = [$target, $method];

        if (!\is_callable($callback)) {
            throw new \RuntimeException(sprintf('Method %s is not callable on %s.', $method, $target::class));
        }

        return \call_user_func($callback, ...$args);
    }

    private function prepareDirectories(): void
    {
        $stateDir = rtrim($this->strOr(\AvalynxOfficeEas\Db\Database::env('EAS_STATE_DIR'), 'var/eas-state'), '/');
        $logDir = rtrim($this->strOr(\AvalynxOfficeEas\Db\Database::env('EAS_LOG_DIR'), 'var/log/eas'), '/');

        foreach ([$stateDir, $logDir] as $directory) {
            if (!is_dir($directory)) {
                @mkdir($directory, 0770, true);
            }
        }

        // A freshly touched-but-empty 'users' state file makes Z-Push's
        // FileStateMachine->GetStateVersion()/LinkUserDevice() fatal
        // (SafeGetContents retries on empty content). Seed the settings file
        // and the user/device list so both are readable.
        $settingsFile = $stateDir.'/settings';

        if (!file_exists($settingsFile)) {
            @file_put_contents($settingsFile, serialize(['version' => '2']));
        }

        $usersFile = $stateDir.'/users';

        if (!file_exists($usersFile)) {
            @file_put_contents($usersFile, serialize([]));
        }
    }

    /**
     * Z-Push reads the raw body from the php://input stream opened in
     * Request::Initialize. A body passed explicitly (test client) replaces
     * that stream; production reads php://input natively.
     */
    private function bridgeBody(): void
    {
        $body = $this->bridgeBody;

        if (null === $body || '' === $body) {
            return;
        }

        new \ReflectionProperty(\Request::class, 'input')->setValue(null, \StringStreamWrapper::Open($body));
    }

    /**
     * Z-Push caches its collaborators in static properties. The PHP-FPM
     * worker (and the long living PHPUnit process) outlives requests, so the
     * caches must be cleared before each request to avoid stale services.
     */
    public static function resetZPushStatics(): void
    {
        foreach (['stateMachine', 'searchProvider', 'deviceManager', 'topCollector', 'backend', 'addSyncFolders'] as $property) {
            new \ReflectionProperty(\ZPush::class, $property)->setValue(null, null);
        }

        new \ReflectionProperty(\ZLog::class, 'logger')->setValue(null, null);

        foreach ([
            'input', 'output', 'headers', 'command', 'method', 'remoteAddr', 'getUser', 'devid', 'devtype',
            'authUserString', 'authUser', 'authDomain', 'authPassword', 'impersonatedUser', 'asProtocolVersion',
            'policykey', 'useragent', 'attachmentName', 'collectionId', 'itemId', 'longId', 'occurence',
            'saveInSent', 'acceptMultipart', 'base64QueryDecoded',
        ] as $property) {
            new \ReflectionProperty(\Request::class, $property)->setValue(null, null);
        }
    }

    /**
     * @param string $header raw "Name: value" header or HTTP status line
     */
    public function sendHeader(string $header): void
    {
        if (str_starts_with($header, 'HTTP/')) {
            $code = (int) substr($header, 9, 3);

            if ($code > 0) {
                http_response_code($code);
            }

            return;
        }

        $separator = strpos($header, ':');

        if (false === $separator) {
            return;
        }

        $name = strtolower(trim(substr($header, 0, $separator)));
        $this->headers[$name] = $header;
    }

    /**
     * @return array<string, string>
     */
    private function responseHeaders(string $content): array
    {
        $headers = $this->headers;

        if (!isset($headers['content-type'])) {
            $contentType = '' === $content
                ? 'application/vnd.ms-sync.wbxml'
                : ($this->looksLikeWbxml($content) ? 'application/vnd.ms-sync.wbxml' : 'text/html; charset=UTF-8');
            $headers['content-type'] = 'Content-Type: '.$contentType;
        }

        $parsed = [];

        foreach ($headers as $header) {
            $separator = strpos($header, ':');

            if (false === $separator) {
                continue;
            }

            $parsed[trim(substr($header, 0, $separator))] = trim(substr($header, $separator + 1));
        }

        return $parsed;
    }

    private function looksLikeWbxml(string $content): bool
    {
        return strlen($content) >= 1 && "\x03" === $content[0];
    }
}
