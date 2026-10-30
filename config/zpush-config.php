<?php

declare(strict_types=1);

/*
 * Z-Push configuration for avalynxofficeeas.
 *
 * This file is included by the Z-Push request processor via the ZPUSH_CONFIG
 * constant. It is based on the Z-Push 2.7.6 default configuration
 * (z-push/src/config.php, AGPL-3.0) with environment-driven overrides. All
 * constants must be defined here because Z-Push uses unguarded define()
 * calls and undefined constants are fatal errors on PHP 8.5+.
 */

define('TIMEZONE', 'Europe/Berlin');
define('BASE_PATH', dirname(__DIR__).'/vendor/z-push/z-push-dev/src/');
define('SCRIPT_TIMEOUT', 0);
define('USE_CUSTOM_REMOTE_IP_HEADER', 'HTTP_X_REAL_IP');
define('CERTIFICATE_OWNER_PARAMETER', 'SSL_CLIENT_S_DN_CN');
define('USE_FULLEMAIL_FOR_LOGIN', true);
define('STATE_MACHINE', 'FILE');
define('STATE_DIR', rtrim(($_SERVER['EAS_STATE_DIR'] ?? 'var/eas-state'), '/').'/');
define('IPC_PROVIDER', '');
define('LOGBACKEND', 'filelog');
define('LOGLEVEL', (int) ($_SERVER['EAS_LOGLEVEL'] ?? LOGLEVEL_INFO));
define('LOGAUTHFAIL', true);
define('LOGUSERLEVEL', LOGLEVEL_DEVICEID);
/*
 * Included from within a service method, so these must be bound to the
 * global scope explicitly (Z-Push reads them via `global`).
 */
global $specialLogUsers, $additionalFolders;
$specialLogUsers = [];
define('LOGFILEDIR', rtrim(($_SERVER['EAS_LOG_DIR'] ?? 'var/log/eas'), '/').'/');
define('LOGFILE', LOGFILEDIR.'z-push.log');
define('LOGERRORFILE', LOGFILEDIR.'z-push-error.log');
define('LOG_SYSLOG_HOST', false);
define('LOG_SYSLOG_PORT', 514);
define('LOG_SYSLOG_PROGRAM', 'z-push');
define('LOG_SYSLOG_FACILITY', LOG_LOCAL0);
define('PROVISIONING', true);
define('LOOSE_PROVISIONING', false);
define('PROVISIONING_POLICYFILE', 'policies.ini');
define('SYNC_CONFLICT_DEFAULT', SYNC_CONFLICT_OVERWRITE_PIM);
define('SYNC_FILTERTIME_MAX', SYNC_FILTERTYPE_ALL);
define('PING_INTERVAL', 30);
define('FILEAS_ORDER', SYNC_FILEAS_LASTFIRST);
define('SYNC_MAX_ITEMS', 512);
define('UNSET_UNDEFINED_PROPERTIES', false);
define('SYNC_CONTACTS_MAXPICTURESIZE', 5242880);
define('ALLOW_WEBSERVICE_USERS_ACCESS', false);
define('USE_PARTIAL_FOLDERSYNC', false);
define('PING_LOWER_BOUND_LIFETIME', false);
// Proxy timeouts at 600s - keep the long poll below that so answers always
// reach the device instead of ending as HTTP 504.
define('PING_HIGHER_BOUND_LIFETIME', 480);
define('SYNC_TIMEOUT_MEDIUM_DEVICETYPES', 'SAMSUNGGTI');
define('SYNC_TIMEOUT_LONG_DEVICETYPES', 'iPod, iPad, iPhone, WP, WindowsOutlook, WindowsMail');
define('RETRY_AFTER_DELAY', 300);
define('BACKEND_PROVIDER', 'AvalynxOfficeEas\Backend\AvalynxBackend');
define('SEARCH_PROVIDER', '');
define('SEARCH_WAIT', 10);
define('SEARCH_MAXRESULTS', 10);
define('KOE_CAPABILITY_GAB', false);
define('KOE_CAPABILITY_RECEIVEFLAGS', false);
define('KOE_CAPABILITY_SENDFLAGS', false);
define('KOE_CAPABILITY_OOF', false);
define('KOE_CAPABILITY_OOFTIMES', false);
define('KOE_CAPABILITY_NOTES', false);
define('KOE_CAPABILITY_SHAREDFOLDER', false);
define('KOE_CAPABILITY_SENDAS', false);
define('KOE_CAPABILITY_SECONDARYCONTACTS', false);
define('KOE_CAPABILITY_SIGNATURES', false);
define('KOE_CAPABILITY_RECEIPTS', false);
define('KOE_CAPABILITY_IMPERSONATE', false);
define('KOE_GAB_STORE', 'SYSTEM');
define('KOE_GAB_FOLDERID', '');
define('KOE_GAB_NAME', 'Z-Push-KOE-GAB');
$additionalFolders = [];
