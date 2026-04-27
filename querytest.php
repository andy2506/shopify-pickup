<?php
require_once 'config.php';

$shop  = SHOPIFY_SHOP_DOMAIN;
$token = SHOPIFY_ACCESS_TOKEN;

$url   = "https://{$shop}/admin/api/2026-01/graphql.json";
$query = <<<'GQL'
{
  order(id: "gid://shopify/Order/6668162236644") {
    id
    name
    fulfillments(first: 5) {
      id
      status
      location {
        id
        name
      }
    }
    shippingLine {
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
GQL;

$body = json_encode(['query' => $query]);
$ch   = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST  => 'POST',
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'X-Shopify-Access-Token: ' . $token,
    ],
]);

$response = curl_exec($ch);
echo json_encode(json_decode($response), JSON_PRETTY_PRINT) . "\n";