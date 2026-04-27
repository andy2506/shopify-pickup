# Shopify Pickup Point Webhook Handler

Processes Shopify `orders/create` webhooks, extracts pickup-point data from the Pickup Generator beta API, and enriches the order with:

- Updated **shipping line title** (e.g. `Ackermans - EXPRESS - 1569`)
- Two **metafields** (`delivery.method`, `delivery.branch_code`)
- Three **tags** (`delivery:express`, `branch:1569`, `click-and-collect-express`)

---

## File Structure

```
shopify-pickup/
├── webhook_handler.php   # Entry point – receives & validates Shopify webhooks
├── shopify_api.php       # All GraphQL query/mutation functions
├── order_updater.php     # Orchestrates the three update operations
├── data_parser.php       # Parses externalId format <COURIER>-<METHOD>-<BRANCH>
├── logger.php            # Structured file logger
├── config.php            # Loads .env and defines constants
├── .env.example          # Template – copy to .env
├── logs/                 # Created automatically at runtime
├── README.md
├── TEST_PLAN.md
└── ARCHITECTURE.md
```

---

## Quick Start

```bash
# 1. Clone and enter directory
git clone <repo-url> && cd shopify-pickup

# 2. Copy and fill in credentials
cp .env.example .env
nano .env

# 3. Start local server
php -S 0.0.0.0:8080

# 4. Expose via ngrok (separate terminal)
ngrok http 8080
```

Then register the webhook URL in Shopify Admin → Settings → Notifications → Webhooks.  
See **TEST_PLAN.md** for full setup walkthrough.

---

## Requirements

- PHP 8.1+
- cURL extension enabled (`php -m | grep curl`)
- Shopify Custom App with scopes: `read_orders`, `write_orders`, `read_fulfillments`, `write_fulfillments`
- Public HTTPS URL (ngrok works for local development)

---

## How It Works

```
Shopify Order Created
        │
        ▼
webhook_handler.php
  ├─ Validate HMAC signature
  ├─ Check topic = orders/create
  └─ Call processPickupOrder()
            │
            ▼
  shopify_api.php → fetchPickupPointExternalId()
  [UNSTABLE API – pickup point beta data]
            │
            ▼
  data_parser.php → parseExternalId()
  e.g. "Ackermans-EXPRESS-1569"
    → courier="Ackermans", method="EXPRESS", branchCode="1569"
            │
            ▼
  order_updater.php → updateOrder()
  ├─ [STABLE API] Update shipping line title via Order Editing API
  ├─ [STABLE API] Upsert 2 metafields (delivery.method, delivery.branch_code)
  └─ [STABLE API] Add 3 tags
```

### Why Two API Versions?

| Operation | API Version | Reason |
|---|---|---|
| Read pickup-point `externalId` | `2026-01` (unstable) | Pickup Generator is a beta feature only in unstable |
| All write operations | `2025-01` (stable) | Production-safe; downstream systems use stable |

### Why Order Editing API for Shipping Lines?

Shopify does not allow direct mutation of `shippingLines.title` on a placed order.  
The Order Editing API (`orderEditBegin` → `orderEditRemoveShippingLine` → `orderEditAddShippingLine` → `orderEditCommit`) is the only supported workflow.

> The `shipping_lines.code` field (e.g. `"PickUp"`) is **read-only** and cannot be changed via any API. Our handler never attempts to modify it.

---

## Environment Variables

| Variable | Example | Description |
|---|---|---|
| `SHOPIFY_SHOP_DOMAIN` | `yourstore.myshopify.com` | Store domain |
| `SHOPIFY_ACCESS_TOKEN` | `shpat_xxx` | Admin API token |
| `SHOPIFY_WEBHOOK_SECRET` | `xxxxx` | Webhook signing secret |
| `SHOPIFY_API_VERSION_UNSTABLE` | `2026-01` | For reading pickup data |
| `SHOPIFY_API_VERSION_STABLE` | `2025-01` | For all writes |
| `LOG_FILE` | `logs/webhook.log` | Path to log file |
| `LOG_LEVEL` | `DEBUG` | DEBUG / INFO / WARNING / ERROR |
