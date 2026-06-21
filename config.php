<?php
/**
 * config.php
 * ENG_251885 APP — Kids Store TMA
 *
 * Central configuration. Reads sensitive values from environment variables
 * so credentials are never hard-coded into version control. On most hosts
 * you can set these in your .htaccess (SetEnv), nginx fastcgi_param block,
 * a process manager (systemd/pm2), or a `.env` loaded by your deploy script.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Error handling — never leak stack traces to the client in production.
// ---------------------------------------------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', '0'); // flip to '1' only in local dev
define('APP_ENV', getenv('APP_ENV') ?: 'production');

// ---------------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------------
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'eng251885_kidstore');
define('DB_USER', getenv('DB_USER') ?: 'kidstore_app');
define('DB_PASS', getenv('DB_PASS') ?: '');

// ---------------------------------------------------------------------------
// Telegram
// ---------------------------------------------------------------------------
define('TELEGRAM_BOT_TOKEN', getenv('TELEGRAM_BOT_TOKEN') ?: '');
// Max age (seconds) an initData payload remains valid for. Telegram
// recommends rejecting stale auth_date values to mitigate replay attacks.
define('TELEGRAM_AUTH_MAX_AGE', 86400);

// ---------------------------------------------------------------------------
// Payment provider credentials (all optional — PaymentRouter degrades
// gracefully and reports a clear error if a given provider isn't configured)
// ---------------------------------------------------------------------------
define('TELEBIRR_APP_ID',        getenv('TELEBIRR_APP_ID') ?: '');
define('TELEBIRR_APP_KEY',       getenv('TELEBIRR_APP_KEY') ?: '');
define('TELEBIRR_SHORT_CODE',    getenv('TELEBIRR_SHORT_CODE') ?: '');
define('TELEBIRR_PUBLIC_KEY',    getenv('TELEBIRR_PUBLIC_KEY') ?: '');
define('TELEBIRR_NOTIFY_URL',    getenv('TELEBIRR_NOTIFY_URL') ?: '');

define('CBE_MERCHANT_ID',        getenv('CBE_MERCHANT_ID') ?: '');
define('CBE_API_KEY',            getenv('CBE_API_KEY') ?: '');
define('CBE_API_BASE',           getenv('CBE_API_BASE') ?: 'https://api.cbe.com.et/v1');

define('DASHEN_MERCHANT_ID',     getenv('DASHEN_MERCHANT_ID') ?: '');
define('DASHEN_API_KEY',         getenv('DASHEN_API_KEY') ?: '');
define('DASHEN_API_BASE',        getenv('DASHEN_API_BASE') ?: 'https://api.amole.dashenbanksc.com/v1');

define('AWASH_MERCHANT_ID',      getenv('AWASH_MERCHANT_ID') ?: '');
define('AWASH_API_KEY',          getenv('AWASH_API_KEY') ?: '');
define('AWASH_API_BASE',         getenv('AWASH_API_BASE') ?: 'https://api.awashbank.com/v1');

define('PAYPAL_CLIENT_ID',       getenv('PAYPAL_CLIENT_ID') ?: '');
define('PAYPAL_CLIENT_SECRET',   getenv('PAYPAL_CLIENT_SECRET') ?: '');
define('PAYPAL_API_BASE',        getenv('PAYPAL_ENV') === 'live'
    ? 'https://api-m.paypal.com'
    : 'https://api-m.sandbox.paypal.com');

define('MPGS_MERCHANT_ID',       getenv('MPGS_MERCHANT_ID') ?: '');
define('MPGS_API_PASSWORD',      getenv('MPGS_API_PASSWORD') ?: '');
define('MPGS_API_BASE',          getenv('MPGS_API_BASE') ?: 'https://ap-gateway.mastercard.com/api/rest/version/77');

/**
 * Returns a shared PDO connection. Throws on failure — callers should wrap
 * in try/catch and respond with a generic 500 (never echo the PDO message).
 */
function get_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        DB_HOST,
        DB_PORT,
        DB_NAME
    );

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}
