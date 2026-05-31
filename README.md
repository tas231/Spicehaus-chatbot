# Spicehaus Recipe Chatbot

A WordPress plugin that adds an AI-powered recipe chat widget to your shop. The chatbot **only suggests recipes using products you actually sell** — and links back to each product page to drive upsells.

## How it looks

A 🌶️ button appears in the bottom-right corner of every page. Visitors can:

- **Ask recipe questions** — type freely and get recipes using your store's products.
- **Scan a grocery barcode** — tap 📷 to identify any packaged food and get recipe ideas.
- **Scan a QR sticker** — scan a QR code placed next to a product in your store. The chatbot opens instantly and explains the product, shows allergen information, and suggests recipes using other items from your store.

The AI responds in the visitor's language (German or English).

---

## Installation

### 1. Get an API key

**Claude (Anthropic) — recommended**

1. Go to [console.anthropic.com](https://console.anthropic.com) and sign up / log in.
2. Click **API Keys → Create Key**.
3. Copy the key (starts with `sk-ant-...`).

> **Cost note:** The plugin uses `claude-haiku-4-5-20251001`, the most cost-efficient Claude model. A typical recipe response costs roughly €0.0002–0.0005. For most shops the monthly cost is well under €5.

**Gemini (Google) — alternative**

Get a free key at [aistudio.google.com](https://aistudio.google.com/app/apikey).

---

### 2. Install the plugin

**Option A — Upload via WordPress Admin**

1. Zip this folder: `spicehaus-recipe-chatbot.zip`
2. Go to **Plugins → Add New → Upload Plugin**.
3. Upload the zip and click **Install Now → Activate**.

**Option B — Copy to server**

```bash
cp -r spicehaus-recipe-chatbot/ /var/www/html/wp-content/plugins/
```

Then activate via **Plugins** in the WordPress admin.

---

### 3. Configure

Go to **Settings → Spicehaus Chatbot** and:

1. Select your AI provider and paste the API key.
2. Update the product catalog (via JSON editor or CSV import — see below).
3. Click **Save Settings**.

The chat widget is now live.

---

## Product catalog

The product list controls which items the bot recommends and links to. It can be managed in three ways (in priority order):

1. **CSV import** — upload your store's product list as a CSV file (recommended).
2. **JSON editor** — edit the JSON textarea directly in the admin settings.
3. **`data/products.json`** — the fallback file bundled with the plugin (~49 spice products).

### CSV import

Go to **Settings → Spicehaus Chatbot → Import Products from CSV**.

Download the sample template to get started, then fill in your actual products:

```csv
name,url,allergens,tags
Kurkuma gemahlen,https://spice-haus.de/products/kurkuma,none,spice|indian
Curry Pulver mild,https://spice-haus.de/products/curry-mild,contains mustard,spice-blend|indian|mild
Schwarzer Sesam,https://spice-haus.de/products/schwarzer-sesam,contains sesame,seed|asian
```

| Column | Required | Description |
|--------|----------|-------------|
| `name` | Yes | Exact product name. The AI uses this verbatim. |
| `url` | Yes | Full URL to the product page. Used for buy links in chat responses. |
| `allergens` | No | EU allergen info shown in QR product pages (e.g. `contains sesame`, `none`). |
| `tags` | No | Free-form labels separated by `\|` or `,` (e.g. `spice\|indian\|hot`). |

After importing, the JSON textarea updates automatically and the QR code grid refreshes on page reload.

### JSON format

If you prefer editing JSON directly:

```json
[
  {
    "name": "Kurkuma gemahlen",
    "url":  "https://spice-haus.de/products/kurkuma-gemahlen",
    "allergens": "none",
    "tags": ["spice", "indian"]
  }
]
```

---

## QR code stickers

Go to **Settings → Spicehaus Chatbot → QR Code Stickers**.

A QR code is generated for every product in your catalog. Print them and stick them next to the corresponding product in your store.

**What happens when a customer scans:**

1. Phone camera scans the QR code → browser opens your shop.
2. The chatbot pops open automatically.
3. The bot immediately provides:
   - A short introduction to the product (origin, uses).
   - **⚠️ Allergen information** (clearly labeled, sourced from your catalog).
   - 1–2 recipe ideas that feature the product alongside other items from your store.
   - "Shop these ingredients" links back to your product pages.

The QR URL format is:

```
https://your-shop.de/?spicehaus_product=kurkuma-gemahlen
```

The slug is auto-generated from the product name (umlauts converted: ä→ae, ö→oe, ü→ue).

**Printing:** Click **Print All QR Codes** for a clean print layout — all non-QR content is hidden automatically.

---

## Customization

### Change the AI model

In `includes/ajax-handler.php`:

```php
'model' => 'claude-haiku-4-5-20251001',
```

Switch to `claude-sonnet-4-6` for higher quality responses (higher cost).

### Change colors / branding

Edit `assets/chatbot.css`. CSS variables at the top control all colors:

```css
:root {
  --sc-accent:   #c0392b;  /* chilli red — toggle button & user bubbles */
  --sc-bg:       #1c1208;  /* dark background */
  --sc-bg-panel: #2d1f0e;  /* header & composer background */
  ...
}
```

### Change the greeting message

Edit `spicehaus-recipe-chatbot.php` and update the `i18n.greeting` value in `wp_localize_script`.

---

## Architecture

```
Visitor browser
  │  (POST /wp-admin/admin-ajax.php)
  ▼
WordPress (PHP)
  │  validate nonce, sanitize input
  │  load product catalog from DB or products.json
  │  build system prompt with product list + allergen data
  │  (POST https://api.anthropic.com/v1/messages)
  ▼
Anthropic / Claude API
  │  recipe + allergen response
  ▼
WordPress → JSON response
  ▼
chatbot.js renders response, auto-links product names
```

The visitor **never** contacts the AI API directly. Your API key stays server-side only.

### QR code flow

```
QR sticker (printed) → customer scans with phone
  ↓
?spicehaus_product=slug in URL
  ↓
PHP matches slug to catalog product, passes name + allergens to JS
  ↓
chatbot.js auto-opens widget, sends [PRODUCT_PAGE: name] to API
  ↓
Bot explains product, allergens, recipes with shop links
```

---

## File structure

```
spicehaus-recipe-chatbot/
├── spicehaus-recipe-chatbot.php   Plugin entry point, asset enqueue, QR slug detection
├── admin/
│   └── settings-page.php          Admin UI: API keys, JSON editor, CSV import, QR grid
├── includes/
│   ├── ajax-handler.php           Chat, barcode & CSV import AJAX endpoints; system prompt
│   └── product-catalog.php        Catalog loader, slug generator, CSV parser
├── assets/
│   ├── chatbot.js                 Widget UI, auto-start QR flow, barcode scanner
│   └── chatbot.css                Dark theme, CSS variables, product card styles
└── data/
    ├── products.json              Default catalog (~49 products, with allergen data)
    └── sample-products.csv        CSV template for import
```

---

## Security

- All AJAX requests require a WordPress nonce (CSRF protection).
- User input is sanitized via `sanitize_text_field` / `sanitize_textarea_field`.
- CSV uploads are validated (extension check, column check, content sanitization).
- Conversation history is capped at the last 18 turns to prevent token abuse.
- The API key is stored in `wp_options` and never output to the browser.

---

## Requirements

- WordPress 6.0+
- PHP 8.0+
- Anthropic API key (or Google AI API key for Gemini)
- WooCommerce optional — the plugin works on any WordPress shop
