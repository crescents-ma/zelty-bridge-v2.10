# TRYB Loyalty x Zelty POS

Symfony PHP middleware connecting Zelty POS to TRYB Loyalty through the TRYB marketplace app flow.

## Overview

This app currently does five main things:

1. Validates a merchant's Zelty API key during TRYB app installation.
2. Returns the merchant's Zelty categories and dishes as a TRYB inventory tree.
3. Registers Zelty webhooks from TRYB's `POST /postback` call.
4. Receives Zelty order webhooks and sends accrual or reversal requests to TRYB.
5. Exposes a Zelty POS WebView page for TRYB loyalty reward actions.

This README documents the current reverted pre-security version in this project.

## Main endpoints

| Method | Path | Called by | Purpose |
|---|---|---|---|
| `GET` | `/health` | health checks | Returns `{"ok": true}` |
| `GET` | `/` | browser | Returns a small status payload with endpoint list |
| `POST` | `/check-credentials` | TRYB | Validates `zelty_api_key` |
| `POST` | `/get-inventory` | TRYB | Returns Zelty categories and dishes in TRYB inventory format |
| `POST` | `/postback` | TRYB | Registers Zelty webhooks and stores the merchant Zelty API key locally |
| `POST` | `/on-order` | Zelty | Processes `order.ended` and `order.status.update` events |
| `GET` | `/tryb-loyalty-webview` | Zelty POS WebView | Opens the TRYB reward-actions WebView |

The app also exposes informational `GET` responses on:

- `/check-credentials`
- `/get-inventory`
- `/postback`
- `/on-order`

Those `GET` routes simply confirm that the endpoint is live and expects `POST`.

## Current TRYB install flow

During app installation in TRYB:

1. TRYB calls `POST /check-credentials`
2. TRYB calls `POST /get-inventory`
3. TRYB calls `POST /postback`
4. This app registers these Zelty webhooks pointing back to `POST /on-order`:
   - `order.ended`
   - `order.status.update`
5. The app stores the merchant's Zelty API key using the submitted `zelty_restaurant_id`

## Current webhook flow

`POST /on-order`:

1. Validates payload size
2. Parses JSON
3. Reads:
   - `event_id`
   - `event_name`
   - `restaurant_id`
   - `data.id`
4. Verifies the webhook signature using the global `ZELTY_WEBHOOK_SECRET`
5. Checks idempotency with the webhook `event_id`
6. Runs one of these actions:
   - `order.ended` -> accrual to TRYB
   - `order.status.update` with `cancelled` -> reversal in TRYB

## Current accrual behavior

For `order.ended`, the app:

- loads the stored Zelty API key for the restaurant
- builds a TRYB `AccrueInput`
- sends:
  - transaction id
  - customer phone/email/name
  - order amount
  - currency
  - selections
- supports product/category-based rules by attaching `groupId` using Zelty dish tags
- if TRYB returns awarded points, pushes those points back to Zelty customer loyalty with `add_loyalty`

## Current reverse behavior

For `order.status.update`, the app:

- checks the order status
- if the status is `cancelled`, sends a TRYB `reverse`

## Current inventory behavior

`POST /get-inventory`:

- reads `zelty_api_key`
- fetches:
  - `GET /catalog/tags`
  - `GET /catalog/dishes`
- builds a nested inventory tree for TRYB
- returns top-level groups, sub-groups, and items

The current tree builder supports:

- parent categories
- child categories
- direct dishes under categories
- stable group/item structure for TRYB category rule setup

## Current WebView behavior

`GET /tryb-loyalty-webview` returns a standalone HTML page for the Zelty POS WebView.

The page currently:

- shows a TRYB-branded reward-actions layout
- exposes these Zelty callbacks:
  - `window.zeltySetOrder`
  - `window.zeltySetVersion`
  - `window.zeltyHandleFunction`
- requests bootstrap data from Zelty:
  - `get_order`
  - `get_version`
- displays customer and order context when Zelty provides it
- includes reward action preview buttons such as:
  - `Apply reward`
  - `View details`
  - `Show loyalty card`
  - `Close`

Important:

- the page expects branding assets in `public/branding/`
- if those files are missing, the WebView still loads, but logos will not render

## Current credential fields expected from TRYB

The current app expects these TRYB credentials:

| API name | Required | Used for |
|---|---|---|
| `zelty_api_key` | Yes | Zelty API access |
| `zelty_restaurant_id` | Yes | Local merchant mapping for webhook-driven accrual |

## Environment variables

The current app uses:

```dotenv
APP_ENV=prod
APP_SECRET=...
APP_PUBLIC_URL=https://your-domain.example
ZELTY_WEBHOOK_SECRET=...
APP_STORAGE_PATH=/data/app-storage
MARKETPLACE_API_BASE_URI=https://api.trybloyalty.com/api/v2/marketplace
MARKETPLACE_API_APP_TOKEN=...
ZELTY_API_BASE_URI=https://api.zelty.fr/2.10
```

Notes:

- `APP_PUBLIC_URL` must be a public HTTPS URL
- `APP_STORAGE_PATH` is optional but recommended on Railway
- if `APP_STORAGE_PATH` is not set, the app falls back to `var/`

## Local storage used by the app

This current version uses file-based storage:

- `credentials/merchant_<restaurant_id>.json`
  Stores the Zelty API key for that restaurant
- `idempotency/<event_id>`
  Marks a webhook event as already processed

Base directory:

- `APP_STORAGE_PATH` if set
- otherwise `<project>/var`

## Deployment

### Railway

Recommended setup:

1. Deploy this folder as a Railway service
2. Set all required environment variables
3. Set `APP_PUBLIC_URL`
4. Set `APP_STORAGE_PATH` to a mounted volume path like `/data/app-storage`
5. Confirm health:

```text
GET /health
```

Expected response:

```json
{"ok":true}
```

### TRYB app configuration

Use these URLs in TRYB:

- Check credentials: `https://your-domain/check-credentials`
- Get inventory: `https://your-domain/get-inventory`
- Webhook postback: `https://your-domain/postback`

Recommended TRYB rule types:

- per amount
- per item
- per category/group

## Known characteristics of this reverted version

This is the reverted pre-security version, which means:

- webhook verification uses one global secret
- merchant API keys are stored in plaintext local files
- marketplace request logging is verbose
- no per-merchant webhook secret flow is active

If you later harden the app again, update this README to match the new behavior.
