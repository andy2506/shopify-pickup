<?php
/**
 * Main Shopify webhook endpoint
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/shopify_api.php';
require_once __DIR__ . '/data_parser.php';
require_once __DIR__ . '/order_updater.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// Read raw body ONCE (needed for both HMAC check and JSON decode)
$rawBody = file_get_contents('php://input');

if ($rawBody === false || $rawBody === '') {
    Logger::warning('Empty request body received');
    http_response_code(422);
    exit('Empty body');
}

// HMAC Validation
// Shopify sends the HMAC in X-Shopify-Hmac-Sha256 (Base64-encoded SHA-256)

$hmacHeader = $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? '';

if (!verifyWebhookHmac($rawBody, $hmacHeader, SHOPIFY_WEBHOOK_SECRET)) {
    Logger::warning('HMAC validation failed', [
        'received_hmac' => $hmacHeader,
        'ip'            => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    ]);
    http_response_code(401);
    exit('Unauthorized');
}

// Check topic – we only handle orders/create

$topic = $_SERVER['HTTP_X_SHOPIFY_TOPIC'] ?? '';

if ($topic !== 'orders/create') {
    Logger::info("Ignored webhook topic: {$topic}");
    http_response_code(200);
    exit("Topic '{$topic}' not handled");
}

// Decode payload

$payload = json_decode($rawBody, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
    Logger::error('Could not decode webhook JSON payload');
    http_response_code(422);
    exit('Invalid JSON');
}

$orderId = $payload['id'] ?? null;   // numeric Shopify order ID

if ($orderId === null) {
    Logger::error('Webhook payload missing order id', ['payload_keys' => array_keys($payload)]);
    http_response_code(422);
    exit('Missing order id');
}

// Build the GID used in GraphQL queries
$orderGid = "gid://shopify/Order/{$orderId}";

Logger::info('Received orders/create webhook', [
    'order_id'     => $orderId,
    'order_number' => $payload['order_number'] ?? 'n/a',
    'email'        => isset($payload['email']) ? '***' : 'not provided',  // redacted for privacy
]);

// Main processing – wrapped in try/catch so we always send a response

try {
    processPickupOrder($orderGid, $payload);
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'order_gid' => $orderGid]);
} catch (Throwable $e) {
    Logger::error('Unhandled exception in processPickupOrder', [
        'order_gid' => $orderGid,
        'message'   => $e->getMessage(),
        'class'     => get_class($e),
        'trace'     => $e->getTraceAsString(),
    ]);
    // Return 500 so Shopify retries delivery
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal server error']);
}

// ---------------------------------------------------------------------------
// Functions
// ---------------------------------------------------------------------------

/**
 * HMAC Validation
 */
function verifyWebhookHmac(string $data, string $hmacHeader, string $secret): bool
{
    if ($hmacHeader === '' || $secret === '') {
        return false;
    }
    $calculated = base64_encode(hash_hmac('sha256', $data, $secret, true));
    return hash_equals($calculated, $hmacHeader);
}

/**
 * Core business logic:
 * Query Shopify (unstable API) for the pickup-point externalId
 * Parse the externalId
 * Update the order (shipping title, metafields, tags)
 *
 * Returns quietly (HTTP 200) when the order has no pickup point –
 * that is a valid scenario (e.g. standard delivery order).
 */
function processPickupOrder(string $orderGid, array $payload): void
{
    $pickupData = fetchPickupPointExternalId($orderGid);
    $externalId = $pickupData['externalId'];

    // We simulate the externalId from the shipping line title for demonstration.
    if ($externalId === null) {
        $shippingLines = $payload['shipping_lines'] ?? [];
        $shippingTitle = $shippingLines[0]['title'] ?? '';

        if ($shippingTitle === '' || $shippingTitle === 'Local Delivery') {
            Logger::info('Order has no pickup point – nothing to update', ['order_gid' => $orderGid]);
            return;
        }

        // Simulate externalId format: Ackermans-EXPRESS-1569
        // In production this comes from the Pickup Generator fulfillment API
        $externalId = 'Ackermans-EXPRESS-1569';

        Logger::info('Simulated externalId from shipping line (dev store)', [
            'shipping_title' => $shippingTitle,
            'simulated_external_id' => $externalId,
        ]);
    }

    // Parse the externalId --
    try {
        $parsed = parseExternalId($externalId);
    } catch (InvalidArgumentException $e) {
        Logger::error('Invalid externalId format – cannot process order', [
            'order_gid'   => $orderGid,
            'external_id' => $externalId,
            'reason'      => $e->getMessage(),
        ]);
        return;
    }

    // Update order
    $updateResult = updateOrder($orderGid, $parsed);

    if (!$updateResult['success']) {
        Logger::error('Order update partially or fully failed', [
            'order_gid' => $orderGid,
            'errors'    => $updateResult['errors'],
        ]);
        throw new RuntimeException(
            "Order update failed for {$orderGid}: " . implode('; ', $updateResult['errors'])
        );
    }

    Logger::info('Order fully updated with pickup point data', [
        'order_gid'           => $orderGid,
        'courier'             => $parsed['courier'],
        'method'              => $parsed['method'],
        'branch_code'         => $parsed['branchCode'],
        'shipping_line_title' => $parsed['shippingLineTitle'],
        'tags'                => $parsed['tags'],
    ]);
}