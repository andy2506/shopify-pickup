<?php
/**
 * Orchestrates the three order update operations after parsing the externalId:
 *
 *   Shipping line title  – via Order Editing API (remove + re-add)
 *   Metafields           – delivery.method and delivery.branch_code
 *   Tags                 – delivery:<method>, branch:<code>, click-and-collect-<method>
 *
 * All three updates are applied in sequence.  Failures in one step are caught
 * and logged; the function returns a summary so the webhook handler can decide
 * whether to return 200 or 500.
 */

require_once __DIR__ . '/shopify_api.php';
require_once __DIR__ . '/data_parser.php';
require_once __DIR__ . '/logger.php';

/**
 * Apply all enrichments to an order once we have the parsed pickup data.
 *
 */
function updateOrder(string $orderGid, array $parsed): array
{
    $result = [
        'success'              => false,
        'shippingLineUpdated'  => false,
        'metafieldsUpdated'    => false,
        'tagsUpdated'          => false,
        'errors'               => [],
    ];

    Logger::info('Starting order update', [
        'order_gid'           => $orderGid,
        'shipping_line_title' => $parsed['shippingLineTitle'],
        'method'              => $parsed['method'],
        'branch_code'         => $parsed['branchCode'],
    ]);

    // ------------------------------------------------------------------
    // Shipping line title via Order Editing API
    // ------------------------------------------------------------------
    try {
        $shippingLine = fetchFirstShippingLine($orderGid);

        if ($shippingLine === null) {
            $result['errors'][] = 'No shipping line found – skipping title update';
            Logger::warning('No shipping line found for order', ['order_gid' => $orderGid]);
        } else {
            Logger::info('Original shipping line', [
                'id'       => $shippingLine['id'],
                'title'    => $shippingLine['title'],
                'code'     => $shippingLine['code'],   // READ-ONLY – we never change this
                'price'    => $shippingLine['priceAmount'] . ' ' . $shippingLine['priceCurrency'],
            ]);

            // Begin edit session
            $calculatedOrderId = orderEditBegin($orderGid);

            // Remove the existing shipping line (keeps code intact; we re-add with new title)
            orderEditRemoveShippingLine($calculatedOrderId, $shippingLine['id']);

            orderEditAddShippingLine(
                $calculatedOrderId,
                $parsed['shippingLineTitle'],
                $shippingLine['priceAmount'],
                $shippingLine['priceCurrency']
            );

            // Commit
            orderEditCommit($calculatedOrderId);

            $result['shippingLineUpdated'] = true;
            Logger::info('Shipping line title updated', [
                'new_title' => $parsed['shippingLineTitle'],
                'price'     => $shippingLine['priceAmount'] . ' ' . $shippingLine['priceCurrency'],
            ]);
        }
    } catch (Throwable $e) {
        $msg = "Shipping line update failed: " . $e->getMessage();
        $result['errors'][] = $msg;
        Logger::error($msg, ['exception' => get_class($e)]);
    }

    // ------------------------------------------------------------------
    // Metafields
    // ------------------------------------------------------------------
    try {
        updateOrderMetafields($orderGid, [
            [
                'namespace' => 'delivery',
                'key'       => 'method',
                'value'     => $parsed['method'],
                'type'      => 'single_line_text_field',
            ],
            [
                'namespace' => 'delivery',
                'key'       => 'branch_code',
                'value'     => $parsed['branchCode'],
                'type'      => 'single_line_text_field',
            ],
        ]);
        $result['metafieldsUpdated'] = true;
    } catch (Throwable $e) {
        $msg = "Metafields update failed: " . $e->getMessage();
        $result['errors'][] = $msg;
        Logger::error($msg, ['exception' => get_class($e)]);
    }

    // ------------------------------------------------------------------
    // Tags
    // ------------------------------------------------------------------
    try {
        addOrderTags($orderGid, $parsed['tags']);
        $result['tagsUpdated'] = true;
    } catch (Throwable $e) {
        $msg = "Tags update failed: " . $e->getMessage();
        $result['errors'][] = $msg;
        Logger::error($msg, ['exception' => get_class($e)]);
    }

    // ------------------------------------------------------------------
    // Summarise
    // ------------------------------------------------------------------
    $result['success'] = $result['shippingLineUpdated']
                      && $result['metafieldsUpdated']
                      && $result['tagsUpdated'];

    Logger::info('Order update complete', [
        'order_gid'            => $orderGid,
        'success'              => $result['success'],
        'shippingLineUpdated'  => $result['shippingLineUpdated'],
        'metafieldsUpdated'    => $result['metafieldsUpdated'],
        'tagsUpdated'          => $result['tagsUpdated'],
        'error_count'          => count($result['errors']),
    ]);

    return $result;
}
