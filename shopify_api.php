<?php
/**
 * shopify_api.php
 * Wraps every Shopify GraphQL Admin API call used by this integration.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';

// ---------------------------------------------------------------------------
// Core HTTP transport
// ---------------------------------------------------------------------------

/**
 * Execute a single GraphQL request against the Shopify Admin API
 */
function queryShopify(string $query, array $variables = [], string $apiVersion = ''): array
{
    if ($apiVersion === '') {
        $apiVersion = SHOPIFY_API_VERSION_STABLE;
    }

    $shop   = SHOPIFY_SHOP_DOMAIN;
    $token  = SHOPIFY_ACCESS_TOKEN;
    $url    = "https://{$shop}/admin/api/{$apiVersion}/graphql.json";
    $body   = json_encode(['query' => $query, 'variables' => $variables]);

    Logger::debug('GraphQL request', [
        'api_version' => $apiVersion,
        'query_preview' => substr(trim($query), 0, 120),
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CAINFO         => 'C:\wamp64\bin\php\cacert.pem',
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Shopify-Access-Token: ' . $token,
        ],
    ]);

    $response   = curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError  = curl_error($ch);
    //curl_close($ch);

    if ($curlError) {
        Logger::error('cURL error', ['error' => $curlError]);
        throw new RuntimeException("cURL error: {$curlError}");
    }

    if ($httpStatus === 429) {
        // Rate-limit – caller should retry after a short delay
        Logger::warning('Shopify rate limit hit (429)', ['api_version' => $apiVersion]);
        throw new RuntimeException("RATE_LIMIT");
    }

    if ($httpStatus !== 200) {
        Logger::error('Unexpected HTTP status', ['status' => $httpStatus, 'body' => $response]);
        throw new RuntimeException("Shopify returned HTTP {$httpStatus}");
    }

    $decoded = json_decode((string) $response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        Logger::error('JSON decode failure', ['raw' => substr((string) $response, 0, 500)]);
        throw new RuntimeException("Failed to decode Shopify JSON response");
    }

    if (!empty($decoded['errors'])) {
        Logger::warning('GraphQL errors in response', ['errors' => $decoded['errors']]);
    }

    return $decoded;
}

/**
 * Thin wrapper that retries once on rate-limit (429) after a 2-second back-off.
 */
function queryShopifyWithRetry(string $query, array $variables = [], string $apiVersion = ''): array
{
    try {
        return queryShopify($query, $variables, $apiVersion);
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'RATE_LIMIT') {
            Logger::info('Retrying after rate-limit delay (2 s)');
            sleep(2);
            return queryShopify($query, $variables, $apiVersion);
        }
        throw $e;
    }
}

/*
 Fetch fulfillment + pickup-point data   (UNSTABLE API)
*/
function fetchPickupPointExternalId(string $orderGid): array
{
    // This query uses the *unstable* API because `pickupPoint` is a beta field.
    $query = <<<'GQL'
    query GetFulfillmentPickupPoint($orderId: ID!) {
        order(id: $orderId) {
            id
            fulfillments(first: 10) {
                id
                status
                location {
                    id
                    name
                    pickupPoint {
                        externalId
                    }
                }
            }
        }
    }
    GQL;

    $result = queryShopifyWithRetry($query, ['orderId' => $orderGid], SHOPIFY_API_VERSION_UNSTABLE);

    $fulfillments = $result['data']['order']['fulfillments'] ?? [];
    $rawFulfillments = $fulfillments;

    // Walk fulfillments to find one that has a pickupPoint externalId
    foreach ($fulfillments as $fulfillment) {
        $externalId = $fulfillment['location']['pickupPoint']['externalId'] ?? null;
        if ($externalId !== null && $externalId !== '') {
            Logger::info('Found pickup point externalId', [
                'fulfillment_id' => $fulfillment['id'],
                'external_id'    => $externalId,
            ]);
            return [
                'externalId'      => $externalId,
                'fulfillmentId'   => $fulfillment['id'],
                'rawFulfillments' => $rawFulfillments,
            ];
        }
    }

    Logger::info('No pickup point externalId found for order', ['order_gid' => $orderGid]);
    return [
        'externalId'      => null,
        'fulfillmentId'   => null,
        'rawFulfillments' => $rawFulfillments,
    ];
}

// Fetch current shipping lines   (STABLE API)

function fetchFirstShippingLine(string $orderGid): ?array
{
    $query = <<<'GQL'
    query GetShippingLines($orderId: ID!) {
        order(id: $orderId) {
            shippingLines(first: 5) {
                edges {
                    node {
                        id
                        title
                        code
                        originalPriceSet {
                            shopMoney {
                                amount
                                currencyCode
                            }
                        }
                    }
                }
            }
        }
    }
    GQL;

    $result = queryShopifyWithRetry($query, ['orderId' => $orderGid]);

    $edges = $result['data']['order']['shippingLines']['edges'] ?? [];
    if (empty($edges)) {
        Logger::warning('No shipping lines found', ['order_gid' => $orderGid]);
        return null;
    }

    $node = $edges[0]['node'];
    return [
        'id'            => $node['id'],
        'title'         => $node['title'],
        'code'          => $node['code'],
        'priceAmount'   => $node['originalPriceSet']['shopMoney']['amount']       ?? '0.00',
        'priceCurrency' => $node['originalPriceSet']['shopMoney']['currencyCode'] ?? 'ZAR',
    ];
}

// Begin / commit an Order Edit   (STABLE API)

function orderEditBegin(string $orderGid): string
{
    $mutation = <<<'GQL'
    mutation OrderEditBegin($id: ID!) {
        orderEditBegin(id: $id) {
            calculatedOrder {
                id
            }
            userErrors {
                field
                message
            }
        }
    }
    GQL;

    $result = queryShopifyWithRetry($mutation, ['id' => $orderGid]);

    $errors = $result['data']['orderEditBegin']['userErrors'] ?? [];
    if (!empty($errors)) {
        Logger::error('orderEditBegin user errors', ['errors' => $errors]);
        throw new RuntimeException("orderEditBegin failed: " . json_encode($errors));
    }

    $calculatedOrderId = $result['data']['orderEditBegin']['calculatedOrder']['id'] ?? '';
    if ($calculatedOrderId === '') {
        throw new RuntimeException("orderEditBegin returned empty calculatedOrderId");
    }

    Logger::debug('Opened order edit session', ['calculated_order_id' => $calculatedOrderId]);
    return $calculatedOrderId;
}

/**
 * Remove an existing (calculated) shipping line from an open edit session.
 * We first need to find the calculatedShippingLineId that corresponds to
 * the original shipping line.
 */
function orderEditRemoveShippingLine(string $calculatedOrderId, string $originalShippingLineId): void
{
    // The Order Editing API requires fetching shipping lines via REST
    // converted to a CalculatedShippingLine GID format.

    // Extract numeric ID from GID: gid://shopify/ShippingLine/123 → 123
    $numericId = basename($originalShippingLineId);
    $calculatedLineGid = "gid://shopify/CalculatedShippingLine/{$numericId}";

    $mutation = <<<'GQL'
    mutation OrderEditRemoveShippingLine($id: ID!, $shippingLineId: ID!) {
        orderEditRemoveShippingLine(id: $id, shippingLineId: $shippingLineId) {
            calculatedOrder {
                id
            }
            userErrors {
                field
                message
            }
        }
    }
    GQL;

    $result = queryShopifyWithRetry($mutation, [
        'id'             => $calculatedOrderId,
        'shippingLineId' => $calculatedLineGid,
    ]);

    $errors = $result['data']['orderEditRemoveShippingLine']['userErrors'] ?? [];
    if (!empty($errors)) {
        Logger::warning('orderEditRemoveShippingLine user errors', ['errors' => $errors]);
    } else {
        Logger::debug('Removed shipping line', ['calc_line_gid' => $calculatedLineGid]);
    }
}

/**
 * Add a new shipping line into an open edit session
 */
function orderEditAddShippingLine(
    string $calculatedOrderId,
    string $title,
    string $priceAmount,
    string $priceCurrency
): void {
    $mutation = <<<'GQL'
    mutation OrderEditAddShippingLine($id: ID!, $shippingLine: OrderEditAddShippingLineInput!) {
        orderEditAddShippingLine(id: $id, shippingLine: $shippingLine) {
            calculatedOrder {
                id
            }
            userErrors {
                field
                message
            }
        }
    }
    GQL;

    $result = queryShopifyWithRetry($mutation, [
        'id'           => $calculatedOrderId,
        'shippingLine' => [
            'title' => $title,
            'price' => [
                'amount'       => $priceAmount,
                'currencyCode' => 'ZAR' //'currencyCode' => $priceCurrency,
            ],
        ],
    ]);

    $errors = $result['data']['orderEditAddShippingLine']['userErrors'] ?? [];
    if (!empty($errors)) {
        Logger::error('orderEditAddShippingLine user errors', ['errors' => $errors]);
        throw new RuntimeException("orderEditAddShippingLine failed: " . json_encode($errors));
    }

    Logger::debug('Added new shipping line', [
        'title'    => $title,
        'price'    => "{$priceAmount} {$priceCurrency}",
    ]);
}

/**
 * Commit the order edit session (orderEditCommit)
 */
function orderEditCommit(string $calculatedOrderId): void
{
    $mutation = <<<'GQL'
    mutation OrderEditCommit($id: ID!) {
        orderEditCommit(id: $id, notifyCustomer: false, staffNote: "Pickup point title updated by webhook handler") {
            order {
                id
            }
            userErrors {
                field
                message
            }
        }
    }
    GQL;

    $result = queryShopifyWithRetry($mutation, ['id' => $calculatedOrderId]);

    $errors = $result['data']['orderEditCommit']['userErrors'] ?? [];
    if (!empty($errors)) {
        Logger::error('orderEditCommit user errors', ['errors' => $errors]);
        throw new RuntimeException("orderEditCommit failed: " . json_encode($errors));
    }

    Logger::info('Order edit committed', ['calculated_order_id' => $calculatedOrderId]);
}

/*
 Update metafields   (STABLE API)
*/
function updateOrderMetafields(string $orderGid, array $metafields): void
{
    $mutation = <<<'GQL'
    mutation MetafieldsSet($metafields: [MetafieldsSetInput!]!) {
        metafieldsSet(metafields: $metafields) {
            metafields {
                namespace
                key
                value
            }
            userErrors {
                field
                message
                code
            }
        }
    }
    GQL;

    // metafieldsSet requires ownerType + ownerId (not just id) in some API versions
    $inputMetafields = array_map(fn($mf) => [
        'ownerId'   => $orderGid,
        'namespace' => $mf['namespace'],
        'key'       => $mf['key'],
        'value'     => $mf['value'],
        'type'      => $mf['type'] ?? 'single_line_text_field',
    ], $metafields);

    $result = queryShopifyWithRetry($mutation, ['metafields' => $inputMetafields]);

    $errors = $result['data']['metafieldsSet']['userErrors'] ?? [];
    if (!empty($errors)) {
        Logger::error('metafieldsSet user errors', ['errors' => $errors]);
        throw new RuntimeException("metafieldsSet failed: " . json_encode($errors));
    }

    Logger::info('Metafields updated', ['count' => count($metafields), 'order_gid' => $orderGid]);
}

// Update tags   (STABLE API)

function addOrderTags(string $orderGid, array $tags): void
{
    $mutation = <<<'GQL'
    mutation TagsAdd($id: ID!, $tags: [String!]!) {
        tagsAdd(id: $id, tags: $tags) {
            node {
                id
            }
            userErrors {
                field
                message
            }
        }
    }
    GQL;

    $result = queryShopifyWithRetry($mutation, ['id' => $orderGid, 'tags' => $tags]);

    $errors = $result['data']['tagsAdd']['userErrors'] ?? [];
    if (!empty($errors)) {
        Logger::error('tagsAdd user errors', ['errors' => $errors]);
        throw new RuntimeException("tagsAdd failed: " . json_encode($errors));
    }

    Logger::info('Tags added to order', ['tags' => $tags, 'order_gid' => $orderGid]);
}
