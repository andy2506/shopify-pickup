# ARCHITECTURE.md – Design Decisions

## 1. Module Separation

The solution is split into five focused files rather than one large script:

| File | Responsibility |
|---|---|
| `webhook_handler.php` | HTTP entry point, HMAC auth, routing |
| `shopify_api.php` | All GraphQL I/O – pure functions, no business logic |
| `order_updater.php` | Orchestrates the three update operations |
| `data_parser.php` | Pure parsing logic – stateless, easily unit-tested |
| `logger.php` | Structured logging – decoupled from business logic |

This separation means each module can be tested, replaced, or extended independently.

---

## 2. Dual API Version Strategy

Pickup Generator data lives only in the **unstable** API branch (`2026-01`).  
All write mutations use the **stable** API (`2025-01`).

This is handled by passing the version explicitly to `queryShopify()` rather than using a global constant everywhere – it's explicit at each call site.

---

## 3. HMAC Validation

`hash_equals()` is used instead of `===` to prevent timing-based attacks.  
The raw request body is read once with `php://input` before any parsing, because `$_POST` would consume the stream.

---

## 4. Shipping Line Update via Order Editing API

Shopify's REST and GraphQL APIs do not expose a direct mutation to update `shippingLines.title` on a placed order.  The Order Editing API is the only supported path.

**Workflow:**
1. `orderEditBegin` → opens a mutable "calculated order" session
2. `orderEditRemoveShippingLine` → removes the existing line from the session
3. `orderEditAddShippingLine` → inserts replacement with new title, same price
4. `orderEditCommit` → writes changes to the live order, no customer notification

The shipping line `code` (e.g. `"PickUp"`) is assigned by the Shopify shipping rate configuration and **cannot** be set or changed via API.  We intentionally do not pass a `code` when adding the new line.

---

## 5. Error Handling Philosophy

- **HMAC failure** → HTTP 401 (no retry by Shopify)
- **Unparseable payload** → HTTP 422 (no retry)
- **Invalid externalId format** → HTTP 200 + log (data problem, retrying won't help)
- **No pickup point on order** → HTTP 200 + log (valid scenario)
- **API failure / partial update** → HTTP 500 (Shopify retries delivery)

This means transient failures (API downtime, rate limits) will be retried by Shopify, while permanent data problems silently succeed from Shopify's perspective but are flagged in logs for manual review.

---

## 6. Rate Limit Handling

`queryShopifyWithRetry()` wraps every API call with one automatic retry after a 2-second sleep when a 429 is received.  For production, a proper exponential back-off queue (e.g. Redis + a worker process) would be more robust.

---

## 7. Logging

Every significant step emits a structured log line (`[timestamp] [level] message {json_context}`).  The context object is machine-parseable so log aggregators (Datadog, CloudWatch, etc.) can index individual fields.

Sensitive fields (email addresses) are redacted before logging.

---

## 8. No External Dependencies

The solution uses only PHP's built-in `cURL` extension and standard library functions.  No Composer packages are required, which keeps deployment simple (just PHP + the five source files).
