# TEST_PLAN.md – Shopify Pickup Point Webhook Integration

## Overview

This document describes how to set up, run, and verify the Shopify Pickup Point webhook
handler from scratch. It reflects the actual steps taken during development and testing,
including real issues encountered and how they were resolved.

---

## 1. Environment Setup

### What You Need

- Windows machine with WampServer installed (we used WampServer 3.x with PHP 8.5+)
- A free Shopify Partners account
- ngrok account (free tier works fine)
- PowerShell

### Getting PHP Working

WampServer ships with its own PHP. Rather than installing PHP separately, we pointed
PowerShell at WampServer's PHP binary directly:

```powershell
$env:Path += ";C:\wamp64\bin\php\php8.5.0"
php --version
php -m | findstr curl
```

You should see PHP version output and `curl` listed. The xdebug warning that appears
is harmless — ignore it.

> **Note:** If you get an SSL certificate error when PHP tries to call Shopify's API,
> your antivirus is likely intercepting HTTPS traffic. Temporarily disable real-time
> scanning while testing, or add an exception for php.exe. This was the cause of the
> SSL error we hit during development.

---

## 2. ngrok Setup

ngrok gives your local PHP server a public HTTPS URL so Shopify can send webhooks to it.

1. Sign up at https://ngrok.com (free)
2. Download the Windows 64-bit zip, extract `ngrok.exe`
3. Copy `ngrok.exe` into `C:\wamp64\bin\php\php8.5.0\` so it's already in your PATH
4. Get your authtoken from https://dashboard.ngrok.com/get-started/your-authtoken
5. Run:

```powershell
ngrok config add-authtoken YOUR_TOKEN_HERE
ngrok http 8080
```

ngrok will give you a URL like `https://abc123.ngrok-free.app` — this is your public
webhook URL. Note it down.

> **Important:** The ngrok URL changes every time you restart ngrok on the free plan.
> If you restart ngrok you must update the webhook URL in Shopify.

---

## 3. Shopify Setup

### 3.1 Create a Partners Account and Dev Store

1. Go to https://partners.shopify.com and sign up
2. In the dashboard go to **Stores → Add store → Development store**
3. Choose **Test my own app or theme** as the purpose
4. Give it a name — your store domain will be `your-store-name.myshopify.com`

### 3.2 Create a Custom App

1. In your store admin go to **Settings → Apps and sales channels → Develop apps**
2. Click **Allow custom app development** if prompted
3. Click **Create an app** and name it `Pickup Webhook Handler`
4. Go to the **Configuration** tab → **Admin API integration → Edit**
5. Enable these scopes:
   - `read_orders`
   - `write_orders`
   - `read_fulfillments`
   - `write_fulfillments`
   - `read_locations`
   - `write_order_edits`
6. Click **Save**
7. Go to **API credentials** tab → click **Install app → Install**
8. Copy the access token immediately — it is only shown once

> **Heads up:** If you add new scopes later you need to uninstall and reinstall the app.
> The access token changes each time you reinstall, so update your `.env` file.

### 3.3 Set Up a Pickup Location

1. Go to **Settings → Locations → Add location**
2. Name: `Ackermans Branch 1569`
3. Address: any valid South African address
4. Enable **Fulfill online orders**
5. Click **Save**

### 3.4 Set Up Local Pickup Shipping Rate

1. Go to **Settings → Shipping and delivery**
2. Find the **Local pickup** section → click **Manage**
3. Toggle on `Ackermans Branch 1569`
4. Set the fee to `50.00`
5. Click **Save**

### 3.5 Set Store Currency to ZAR

The Order Editing API requires prices in the store's currency. Set it to ZAR:

1. Go to **Settings → General**
2. Scroll to **Store currency**
3. Change to **ZAR – South African Rand**
4. Click **Save**

### 3.6 Register the Webhook

1. Go to **Settings → Notifications → Webhooks**
2. Click **Create webhook**
   - Event: `Order creation`
   - Format: `JSON`
   - URL: `https://your-ngrok-url.ngrok-free.app/webhook_handler.php`
   - API version: `2025-01`
3. Click **Save**
4. Copy the **signing secret** shown at the top of the webhooks page
5. Paste it into your `.env` file as `SHOPIFY_WEBHOOK_SECRET`

---

## 4. Project Setup

```powershell
# Unzip the project
Expand-Archive shopify-pickup-solution.zip -DestinationPath C:\projects\
cd C:\projects\shopify-pickup

# Create your .env from the example
copy .env.example .env
notepad .env
```

Fill in `.env`:
```
SHOPIFY_SHOP_DOMAIN=your-store-name.myshopify.com
SHOPIFY_ACCESS_TOKEN=shpat_xxxxxxxxxxxxxxxxxxxxxxxx
SHOPIFY_WEBHOOK_SECRET=xxxxxxxxxxxxxxxxxxxxxxxx
SHOPIFY_API_VERSION_UNSTABLE=2026-01
SHOPIFY_API_VERSION_STABLE=2025-01
LOG_FILE=logs/webhook.log
LOG_LEVEL=DEBUG
```

```powershell
# Create logs directory
mkdir C:\projects\shopify-pickup\logs
```

---

## 5. Running the Handler

Open **two PowerShell windows**:

**Window 1 — PHP server:**
```powershell
$env:Path += ";C:\wamp64\bin\php\php8.5.0"
cd C:\projects\shopify-pickup
php -S 0.0.0.0:8080
```

**Window 2 — ngrok tunnel:**
```powershell
ngrok http 8080
```

**Window 3 — Watch logs in real time:**
```powershell
Get-Content C:\projects\shopify-pickup\logs\webhook.log -Wait
```

---

## 6. Placing a Test Order

1. Open your storefront: `https://your-store-name.myshopify.com`
2. Add the test product to cart
3. Go to checkout
4. Enter any name, email, and South African address
5. At the shipping step — select **Pick up** and choose `Ackermans Branch 1569`
6. At payment — use the Shopify bogus gateway, card number `1`
7. Complete the order

The webhook fires within a few seconds of the order being placed.

---

## 7. Verifying the Results

### 7.1 Check the Log File

A successful run looks like this:

```
[INFO]  Received orders/create webhook {"order_id":6668211880164,"order_number":1007}
[INFO]  No pickup point externalId found — simulated from shipping line (dev store)
[DEBUG] Parsed externalId {"courier":"Ackermans","method":"EXPRESS","branch_code":"1569"}
[INFO]  Starting order update
[INFO]  Original shipping line {"title":"Cape Town Warehouse","price":"826.21 ZAR"}
[DEBUG] Opened order edit session
[DEBUG] Removed shipping line
[DEBUG] Added new shipping line {"title":"Ackermans - EXPRESS - 1569","price":"826.21 ZAR"}
[INFO]  Order edit committed
[INFO]  Metafields updated {"count":2}
[INFO]  Tags added {"tags":["delivery:express","branch:1569","click-and-collect-express"]}
[INFO]  Order update complete {"success":true,"error_count":0}
[INFO]  Order fully updated with pickup point data
```

### 7.2 Verify via GraphQL

Open the Shopify GraphiQL app at:
```
https://shopify-graphiql-app.shopifycloud.com/login
```

Run this query (API version `2025-01`):

```graphql
{
  order(id: "gid://shopify/Order/YOUR_ORDER_ID") {
    name
    tags
    shippingLines(first: 3) {
      edges {
        node {
          code
          title
          originalPriceSet {
            shopMoney { amount currencyCode }
          }
        }
      }
    }
    metafields(first: 10, namespace: "delivery") {
      edges {
        node {
          namespace
          key
          value
        }
      }
    }
  }
}
```

### 7.3 Expected Output

```json
{
  "name": "#1007",
  "tags": ["branch:1569", "click-and-collect-express", "delivery:express"],
  "shippingLines": {
    "edges": [{
      "node": {
        "code": "custom",
        "title": "Ackermans - EXPRESS - 1569",
        "originalPriceSet": {
          "shopMoney": { "amount": "826.21", "currencyCode": "ZAR" }
        }
      }
    }]
  },
  "metafields": {
    "edges": [
      { "node": { "namespace": "delivery", "key": "method", "value": "EXPRESS" }},
      { "node": { "namespace": "delivery", "key": "branch_code", "value": "1569" }}
    ]
  }
}
```

---

## 8. Edge Cases Tested

| Scenario | What Happens |
|---|---|
| Order with standard delivery (no pickup point) | Handler exits cleanly with HTTP 200 and logs "no pickup point" |
| Shopify test notification (fake order ID) | Metafields and tags fail gracefully — logged, not crashed |
| Invalid HMAC signature | HTTP 401 returned immediately, no processing |
| Empty request body | HTTP 422 returned |
| Antivirus blocking PHP SSL | SSL error in logs — disable AV scanning for php.exe |
| Missing `write_order_edits` scope | orderEditBegin fails with ACCESS_DENIED — add scope and reinstall app |
| Wrong currency in Order Editing API | Shopify rejects the price — set store currency to ZAR in Settings |
| ngrok URL expired | Webhook deliveries fail — restart ngrok and update webhook URL in Shopify |

---

## 9. A Note on the Pickup Point Generator Beta

The `pickupPoint.externalId` field used in production is part of Shopify's Pickup
Point Generator, which is a beta feature not available on standard development stores.

In this implementation, when the fulfillment API does not return a `pickupPoint`
(as is the case on all dev stores), the handler falls back to detecting the pickup
location from the shipping line title and simulates a realistic `externalId` in the
format `Ackermans-EXPRESS-1569`.

In a production environment with the Pickup Point Generator enabled, the real
`externalId` would be read directly from:

```
order → fulfillments → location → pickupPoint → externalId
```

The rest of the pipeline — parsing, updating the shipping title, metafields, and tags —
is identical regardless of whether the externalId comes from the real beta API or
the simulation.
