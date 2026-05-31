# Spicehaus Recipe Chatbot

A WordPress plugin that adds an AI-powered recipe chat widget to your WooCommerce shop. The chatbot **only suggests recipes using products you actually sell** — and links back to each product page.

## How it looks

A 🌶️ button appears in the bottom-right corner of every page. Clicking it opens a dark-themed chat window where visitors can ask for recipe ideas. The AI responds in the visitor's language (German or English) and includes buy links for the Spicehaus products used.

---

## Installation

### 1. Get an Anthropic API key

1. Go to [console.anthropic.com](https://console.anthropic.com) and sign up / log in.
2. Click **API Keys** → **Create Key**.
3. Copy the key (starts with `sk-ant-...`).

> **Cost note:** The plugin uses `claude-haiku-4-5-20251001`, the most cost-efficient Claude model. A typical recipe response costs roughly €0.0002–0.0005. For most shops, the monthly cost is well under €5.

---

### 2. Install the plugin

**Option A — Upload via WordPress Admin**

1. Zip this folder: `spicehaus-recipe-chatbot.zip`
2. Go to **Plugins → Add New → Upload Plugin**.
3. Upload the zip and click **Install Now** → **Activate**.

**Option B — Copy to server**

```bash
cp -r spicehaus-recipe-chatbot/ /var/www/html/wp-content/plugins/
```

Then activate via **Plugins** in the WordPress admin.

---

### 3. Configure

Go to **Settings → Spicehaus Chatbot** and:

1. Paste your Anthropic API key.
2. Update the product catalog JSON (see below).
3. Click **Save Settings**.

That's it — the chat widget is now live.

---

## Product catalog

The product list lives in two places (in priority order):

1. **WP Admin** — the JSON textarea under Settings → Spicehaus Chatbot (easiest to edit).
2. **`data/products.json`** — the fallback file bundled with the plugin.

### JSON format

```json
[
  {
    "name": "Kurkuma gemahlen",
    "url":  "https://spice-haus.de/products/kurkuma-gemahlen",
    "tags": ["spice", "indian"]
  }
]
```

| Field  | Required | Description |
|--------|----------|-------------|
| `name` | Yes      | Exact product name as it appears in your shop. The AI uses this verbatim. |
| `url`  | Yes      | Full URL to the product page. Used to create buy links in chat responses. |
| `tags` | No       | Free-form labels. Help the AI categorize products (e.g. `"hot"`, `"baking"`). |

**Important:** Replace the placeholder URLs in `data/products.json` with your real product URLs. The bundled list contains ~50 common spice products as a starting template.

---

## Customization

### Change the AI model

In `includes/ajax-handler.php`, find:

```php
'model' => 'claude-haiku-4-5-20251001',
```

You can switch to `claude-sonnet-4-6` for higher quality responses (higher cost).

### Change colors / branding

Edit `assets/chatbot.css`. The CSS variables at the top control all colors:

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
  │  sanitize input
  │  load product catalog from DB
  │  build system prompt
  │  (POST https://api.anthropic.com/v1/messages)
  ▼
Anthropic / Claude API
  │  recipe response
  ▼
WordPress → JSON response
  ▼
chatbot.js renders response, auto-links product names
```

The visitor **never** contacts the Anthropic API directly. Your API key stays server-side only.

---

## Security

- All AJAX requests require a WordPress nonce (CSRF protection).
- User input is sanitized via `sanitize_text_field` / `sanitize_textarea_field`.
- Conversation history is capped at the last 18 turns.
- The API key is stored in `wp_options` and never output to the browser.

---

## Requirements

- WordPress 6.0+
- PHP 8.0+
- WooCommerce (any version) — the plugin works independently but is designed for WooCommerce shops
- Anthropic API key
