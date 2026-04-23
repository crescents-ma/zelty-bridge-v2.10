# TRYB Loyalty × Zelty POS — Marketplace App

Symfony PHP middleware connecting **Zelty POS** to **TRYB Loyalty** (Boomerangme marketplace).  
Built from the official Zelty OpenAPI spec (`Default_module_openapi.json`).

---

## How it works

| Endpoint | Called by | Purpose |
|---|---|---|
| `POST /check-credentials` | TRYB (at install) | Validates the merchant's Zelty Bearer API key |
| `POST /get-inventory` | TRYB (at install) | Fetches Zelty tags (categories) + dishes → TRYB inventory tree |
| `POST /on-order` | Zelty (webhook) | Receives `order.ended` webhook, accrues points on TRYB |
| `POST /postback` | TRYB (after install) | Auto-registers the `order.ended` webhook on Zelty via `POST /webhooks` |

---

## Confirmed from the OpenAPI spec

| Question | Answer |
|---|---|
| Auth method | `Authorization: Bearer <api_key>` (securitySchemes.bearer) |
| Prices | **Integer in cents** — `555` = 5.55€ |
| Order total field | `data.price` (integer, cents) |
| Customer email field | `mail` (not `email`) |
| Customer name fields | `fname` (first), `name` (last) |
| Menu = Tags + Dishes | `GET /catalog/tags` + `GET /catalog/dishes` |
| Order items field | `items` (REST) / `contents` (webhook) |
| Response envelope | `{ "resource": [...], "errno": 0 }` |
| Webhook registration | `POST /webhooks` with `{ webhooks: { "order.ended": { target, version } }, secret_key }` |
| Webhook payload | `{ event_name, event_id, brand_id, restaurant_id, data: { id, price, contents, customer, ... } }` |

---

## Requirements

- PHP 8.1+, Composer, ext-bcmath, ext-ctype, ext-iconv

---

## Installation

```bash
composer install
cp .env .env.local
# Edit .env.local with your real values
```

`.env.local`:
```dotenv
APP_ENV=prod
APP_SECRET=<random-32-chars>
MARKETPLACE_API_APP_TOKEN=<your-tryb-app-token>
ZELTY_API_BASE_URI=https://api.zelty.fr/2.10
```

---

## Deploy on Railway

1. Create a new Railway project and deploy this folder as a service.
2. Railway will build the included `Dockerfile`.
3. Add a Railway volume mounted at `/data`.
4. Set these environment variables in Railway:

```dotenv
APP_ENV=prod
APP_SECRET=<random-32-char-hex>
APP_PUBLIC_URL=https://your-service.up.railway.app
APP_STORAGE_PATH=/data/app-storage
MARKETPLACE_API_BASE_URI=https://api.digitalwallet.cards/api/v2/marketplace
MARKETPLACE_API_APP_TOKEN=<your-tryb-app-token>
ZELTY_API_BASE_URI=https://api.zelty.fr/2.10
```

5. After deploy, confirm the service is live at:

```text
GET https://your-service.up.railway.app/health
```

Expected response:

```json
{"ok":true}
```

6. In TRYB, create the marketplace app with these URLs:

- Check credentials: `https://your-service.up.railway.app/check-credentials`
- Get inventory: `https://your-service.up.railway.app/get-inventory`
- Webhook postback: `https://your-service.up.railway.app/postback`

7. In TRYB credential fields, add:

| API name | UI title | Required |
|---|---|---|
| `zelty_api_key` | Clé API Zelty | Yes |

8. Install the TRYB app for the merchant. During install:

- TRYB calls `/check-credentials` to validate the Zelty API key
- TRYB calls `/get-inventory` to import categories and dishes
- TRYB calls `/postback`, which makes this app register Zelty webhooks automatically

Important:

- `APP_PUBLIC_URL` must be the final public HTTPS Railway URL or a custom HTTPS domain.
- `APP_STORAGE_PATH` should point to the mounted Railway volume so webhook secrets survive redeploys.
- Railway should use `/health` as the health check path if you configure one manually.

## Deploy on Hostinger

1. `composer install --no-dev --optimize-autoloader` (locally)
2. Upload to `public_html/` via FTP
3. Point web root to `public_html/public`
4. PHP 8.1+ in hPanel → PHP Configuration
5. Create `.env.local` on server
6. `chmod 755 var/cache var/log`

---

## TRYB App Form (Settings → Apps → Create App)

**URLs:**
- Check credentials: `https://yourdomain.com/check-credentials`
- Get inventory: `https://yourdomain.com/get-inventory`  
- Webhook postback: `https://yourdomain.com/postback`

**Accrual rules:** ✅ Per amount, ✅ Per item, ✅ Per category

**Credential fields:**

| API name | UI title | Required |
|---|---|---|
| `zelty_api_key` | Clé API Zelty | Yes |

---

## What changed vs the GloriaFood example

| File | Change |
|---|---|
| `GloriaFoodClient.php` | **Deleted** |
| `ZeltyClient.php` | **New** — Bearer auth, exact endpoints from OpenAPI: `/catalog/tags`, `/catalog/dishes`, `/customers/{id}/add_loyalty`, `/webhooks` |
| `AppController.php` | **Rewritten** — exact `order.ended` webhook envelope (`event_name`, `restaurant_id`, `data.price`, `data.contents`, `data.customer.mail`), auto-webhook registration via `/postback` |
| `OrderStatus.php` | **Updated** — Zelty statuses: `opened`, `cancelled`, `ended` |
| `.env` | Added `ZELTY_API_BASE_URI` |
| `services.yaml` | Added `zelty_api_base_url` parameter |
