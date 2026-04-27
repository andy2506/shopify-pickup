<?php
$ch = curl_init('https://www.shopify.com');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CAINFO, 'C:/wamp64/bin/php/cacert.pem');
$result = curl_exec($ch);
echo curl_error($ch) ?: 'SSL OK';
curl_close($ch);