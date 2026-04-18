<?php
declare(strict_types=1);

if (!function_exists('cogs_load_env_once')) {
    function cogs_load_env_once(): void {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $loaded = true;

        $siteRootEnv = dirname(__DIR__, 3) . '/.env';
        $parentRootEnv = dirname(dirname(__DIR__, 3)) . '/.env';
        $candidates = [$siteRootEnv, $parentRootEnv];

        foreach ($candidates as $envFile) {
            if (!is_file($envFile)) {
                continue;
            }
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim(trim($v), "\"'");
                if ($k === '') {
                    continue;
                }
                $_ENV[$k] = $v;
                putenv($k . '=' . $v);
            }
            if (realpath($envFile) === realpath($siteRootEnv)) {
                break;
            }
        }

        $aliases = [
            'DB_DATABASE' => ['DB_NAME'],
            'DB_USERNAME' => ['DB_USER'],
            'DB_PASSWORD' => ['DB_PASS'],
            'SESSION_COOKIE_NAME' => ['SESSION_NAME'],
        ];
        foreach ($aliases as $canonical => $legacyKeys) {
            $canonicalValue = $_ENV[$canonical] ?? getenv($canonical);
            if ($canonicalValue !== false && $canonicalValue !== null && $canonicalValue !== '') {
                continue;
            }
            foreach ($legacyKeys as $legacyKey) {
                $legacyValue = $_ENV[$legacyKey] ?? getenv($legacyKey);
                if ($legacyValue === false || $legacyValue === null || $legacyValue === '') {
                    continue;
                }
                $_ENV[$canonical] = (string)$legacyValue;
                putenv($canonical . '=' . (string)$legacyValue);
                break;
            }
        }
    }
}

if (!function_exists('env')) {
    function env(string $key, ?string $default = null): ?string {
        cogs_load_env_once();
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return (string)$value;
    }
}

define('APP_BASE_PATH', dirname(__DIR__, 4));
define('APP_ENV', env('APP_ENV', 'production'));
define('SITE_URL', rtrim((string)env('SITE_URL', env('APP_URL', '')), '/'));
// Empty string = same-origin only (no CORS headers). Set CORS_ORIGIN in .env to a
// specific origin (e.g. https://cogsaustralia.org) to allow credentialed cross-origin
// requests. Never use '*' with credentials — browsers block that combination.
define('CORS_ORIGIN', (string)env('CORS_ORIGIN', ''));
define('TOKEN_PRICE', (float)env('TOKEN_PRICE', '4'));
define('KIDS_TOKEN_PRICE', (float)env('KIDS_TOKEN_PRICE', '1'));
define('BNFT_TOKEN_PRICE', (float)env('BNFT_TOKEN_PRICE', '40'));
define('BNFT_FIXED_FEE', (float)env('BNFT_FIXED_FEE', '40'));
define('SESSION_HOURS', (int)env('SESSION_HOURS', '24'));
define('SESSION_REMEMBER_DAYS', (int)env('SESSION_REMEMBER_DAYS', '30'));
define('SESSION_COOKIE_NAME', (string)env('SESSION_COOKIE_NAME', 'cogs_session'));
define('ADMIN_BOOTSTRAP_TOKEN', (string)env('ADMIN_BOOTSTRAP_TOKEN', ''));
define('ADMIN_TOTP_ISSUER', (string)env('ADMIN_TOTP_ISSUER', 'COG$ Australia'));

// ── SMS (Twilio) ──────────────────────────────────────────────────────────
// Used for 2FA OTP delivery to member mobile numbers.
// Get these from console.twilio.com
define('TWILIO_ACCOUNT_SID',  (string)env('TWILIO_ACCOUNT_SID', ''));
define('TWILIO_AUTH_TOKEN',   (string)env('TWILIO_AUTH_TOKEN', ''));
define('TWILIO_FROM_NUMBER',  (string)env('TWILIO_FROM_NUMBER', ''));  // E.164 format e.g. +61400000000
define('SMS_PROVIDER',        strtolower((string)env('SMS_PROVIDER', 'twilio'))); // twilio | off
define('STRIPE_WEBHOOK_SECRET',  (string)env('STRIPE_WEBHOOK_SECRET', ''));
define('STRIPE_SECRET_KEY',     (string)env('STRIPE_SECRET_KEY', ''));
define('STRIPE_BUY_BUTTON_ID',   (string)env('STRIPE_BUY_BUTTON_ID', 'buy_btn_1TJibgRpslKZeWaDYuSR0Dcy'));
define('STRIPE_PUBLISHABLE_KEY', (string)env('STRIPE_PUBLISHABLE_KEY', 'pk_live_51TJaqrRpslKZeWaDTY50Ps5PyBkDMTu6CX8ussZr14ypm8smzoG4WGFW8BCXeNJhdW0YIqFVrNJZUKHZrO0RLtaA00ZpXrutww'));
define('CRM_PROVIDER', strtolower((string)env('CRM_PROVIDER', 'null')));
define('SNFT_MEMBER_PREFIX', preg_replace('/\D+/', '', (string)env('SNFT_MEMBER_PREFIX', '608200')) ?: '608200');

define('MAIL_PROVIDER', strtolower((string)env('MAIL_PROVIDER', 'null')));
define('MAIL_FROM_EMAIL', (string)env('MAIL_FROM_EMAIL', ''));
define('MAIL_FROM_NAME', (string)env('MAIL_FROM_NAME', 'COG$ Australia'));
define('MAIL_REPLY_TO', (string)env('MAIL_REPLY_TO', ''));
define('MAIL_ADMIN_EMAIL', (string)env('MAIL_ADMIN_EMAIL', 'members@cogsaustralia.org'));
define('SMTP_HOST', (string)env('SMTP_HOST', ''));
define('SMTP_PORT', (int)env('SMTP_PORT', '587'));
define('SMTP_USERNAME', (string)env('SMTP_USERNAME', ''));
define('SMTP_PASSWORD', (string)env('SMTP_PASSWORD', ''));
define('SMTP_ENCRYPTION', strtolower((string)env('SMTP_ENCRYPTION', 'tls')));
define('SMTP_TIMEOUT', (int)env('SMTP_TIMEOUT', '20'));

// Apply APP_TIMEZONE to PHP so that admin date() / strtotime() calls are
// consistent with the configured timezone (default: Australia/Sydney).
// Without this, PHP uses the server php.ini timezone which on many cPanel
// hosts is UTC, causing admin form times to be stored wrong.
(static function (): void {
    $tz = (string)(env('APP_TIMEZONE') ?? 'Australia/Sydney');
    if ($tz === '') {
        $tz = 'Australia/Sydney';
    }
    date_default_timezone_set($tz);
})();
