<?php
/**
 * Parses the pickup-point externalId produced by Shopify's Pickup Generator
 */

require_once __DIR__ . '/logger.php';

/**
 * Parse a pickup-point externalId string.
 *
 */
function parseExternalId(string $externalId): array
{
    $externalId = trim($externalId);

    if ($externalId === '') {
        throw new InvalidArgumentException("externalId is empty");
    }

    $parts = explode('-', $externalId);

    if (count($parts) < 3) {
        throw new InvalidArgumentException(
            "externalId '{$externalId}' must contain at least 3 dash-delimited segments"
        );
    }

    // Last segment is always the branch code
    $branchCode = array_pop($parts);

    // Second-to-last segment is always the method
    $method = strtoupper((string) array_pop($parts));

    // Everything remaining is the courier name
    $courier = implode('-', $parts);

    // Validate individual segments
    if ($courier === '') {
        throw new InvalidArgumentException("Courier name is empty in externalId '{$externalId}'");
    }
    if (!preg_match('/^[A-Z]+$/', $method)) {
        throw new InvalidArgumentException(
            "Method '{$method}' is not a valid uppercase word in externalId '{$externalId}'"
        );
    }
    if (!preg_match('/^\w+$/', $branchCode)) {
        throw new InvalidArgumentException(
            "Branch code '{$branchCode}' contains invalid characters in externalId '{$externalId}'"
        );
    }

    // Build derived values ---------------------------------------------------

    // Shipping line title: "Ackermans - EXPRESS - 1569"
    $shippingLineTitle = "{$courier} - {$method} - {$branchCode}";

    // Tags
    $methodLower = strtolower($method);
    $tags = [
        "delivery:{$methodLower}",                     // e.g. delivery:express
        "branch:{$branchCode}",                        // e.g. branch:1569
        "click-and-collect-{$methodLower}",            // e.g. click-and-collect-express
    ];

    Logger::debug('Parsed externalId', [
        'external_id'          => $externalId,
        'courier'              => $courier,
        'method'               => $method,
        'branch_code'          => $branchCode,
        'shipping_line_title'  => $shippingLineTitle,
        'tags'                 => $tags,
    ]);

    return [
        'courier'            => $courier,
        'method'             => $method,
        'branchCode'         => $branchCode,
        'shippingLineTitle'  => $shippingLineTitle,
        'tags'               => $tags,
    ];
}
