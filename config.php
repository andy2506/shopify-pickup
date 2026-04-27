<?php
/**
 * Loads environment variables and defines application constants
 */

// ---------------------------------------------------------------------------
// Load .env file if it exists (simple key=value parser, no external deps)
// ---------------------------------------------------------------------------
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Helper – read env with a fallback
// ---------------------------------------------------------------------------
function env(string $key, string $default = ''): string
{
    return $_ENV[$key] ?? getenv($key) ?: $default;
}

// ---------------------------------------------------------------------------
// Core Shopify credentials
// ---------------------------------------------------------------------------
define('SHOPIFY_SHOP_DOMAIN',    env('SHOPIFY_SHOP_DOMAIN'));
define('SHOPIFY_ACCESS_TOKEN',   env('SHOPIFY_ACCESS_TOKEN'));
define('SHOPIFY_WEBHOOK_SECRET', env('SHOPIFY_WEBHOOK_SECRET'));

// ---------------------------------------------------------------------------
// API versions
// UNSTABLE is required for pickup-point fulfillment data (Pickup Generator beta)
// STABLE   is used for all write operations (metafields, tags, order editing)
// ---------------------------------------------------------------------------
define('SHOPIFY_API_VERSION_UNSTABLE', env('SHOPIFY_API_VERSION_UNSTABLE', '2026-01'));
define('SHOPIFY_API_VERSION_STABLE',   env('SHOPIFY_API_VERSION_STABLE',   '2025-01'));

// ---------------------------------------------------------------------------
// Logging
// ---------------------------------------------------------------------------
define('LOG_FILE', env('LOG_FILE', __DIR__ . '/logs/webhook.log'));
define('LOG_LEVEL', env('LOG_LEVEL', 'DEBUG')); // DEBUG | INFO | WARNING | ERROR

// ---------------------------------------------------------------------------
// Sanity-check at boot so we fail loudly if credentials are missing
// ---------------------------------------------------------------------------
foreach (['SHOPIFY_SHOP_DOMAIN', 'SHOPIFY_ACCESS_TOKEN', 'SHOPIFY_WEBHOOK_SECRET'] as $required) {
    if (constant($required) === '') {
        // During webhook handling we can't throw HTML errors – log and exit cleanly
        error_log("[CONFIG] Missing required env var: {$required}");
    }
}
