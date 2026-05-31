<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', function () {
    add_options_page(
        'Spicehaus Chatbot',
        'Spicehaus Chatbot',
        'manage_options',
        'spicehaus-chatbot',
        'spicehaus_render_settings_page'
    );
} );

add_action( 'admin_init', function () {
    register_setting( 'spicehaus_chatbot', 'spicehaus_chatbot_provider', [
        'sanitize_callback' => function ( $v ) {
            return in_array( $v, [ 'claude', 'gemini' ], true ) ? $v : 'claude';
        },
    ] );
    register_setting( 'spicehaus_chatbot', 'spicehaus_chatbot_api_key', [
        'sanitize_callback' => 'sanitize_text_field',
    ] );
    register_setting( 'spicehaus_chatbot', 'spicehaus_chatbot_gemini_key', [
        'sanitize_callback' => 'sanitize_text_field',
    ] );
    register_setting( 'spicehaus_chatbot', 'spicehaus_chatbot_products', [
        'sanitize_callback' => 'spicehaus_sanitize_products_json',
    ] );
    register_setting( 'spicehaus_chatbot', 'spicehaus_chatbot_system_prompt', [
        'sanitize_callback' => 'sanitize_textarea_field',
    ] );
} );

function spicehaus_sanitize_products_json( string $input ): string {
    $decoded = json_decode( $input, true );
    if ( ! is_array( $decoded ) ) {
        add_settings_error(
            'spicehaus_chatbot_products',
            'invalid_json',
            'Product list must be valid JSON. Changes were not saved.'
        );
        return get_option( 'spicehaus_chatbot_products', '' );
    }
    return wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
}

function spicehaus_render_settings_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $provider      = get_option( 'spicehaus_chatbot_provider',      'claude' );
    $claude_key    = get_option( 'spicehaus_chatbot_api_key',       '' );
    $gemini_key    = get_option( 'spicehaus_chatbot_gemini_key',    '' );
    $products      = get_option( 'spicehaus_chatbot_products',      '' );
    $saved_prompt  = get_option( 'spicehaus_chatbot_system_prompt', '' );
    $system_prompt = ! empty( trim( $saved_prompt ) ) ? $saved_prompt : spicehaus_default_system_prompt();

    if ( empty( $products ) ) {
        $file = SPICEHAUS_CHATBOT_PATH . 'data/products.json';
        if ( file_exists( $file ) ) {
            $raw      = file_get_contents( $file );
            $products = wp_json_encode(
                json_decode( $raw, true ),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            );
        }
    }

    $catalog   = spicehaus_get_product_catalog();
    $site_url  = trailingslashit( home_url() );
    $csv_nonce = wp_create_nonce( 'spicehaus_csv_import' );
    ?>
    <div class="wrap">
        <h1>🌶️ Spicehaus Recipe Chatbot</h1>

        <p>The chatbot widget appears in the <strong>bottom-right corner</strong> on every page of your shop.
           Visitors can ask recipe questions, <strong>scan a product barcode</strong>, or scan a
           <strong>QR code sticker</strong> next to a product to get instant product info, allergen details,
           and recipe ideas.</p>

        <?php settings_errors(); ?>

        <form method="post" action="options.php">
            <?php settings_fields( 'spicehaus_chatbot' ); ?>

            <h2>AI Provider</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Provider</th>
                    <td>
                        <label style="margin-right:20px">
                            <input type="radio" name="spicehaus_chatbot_provider" value="claude"
                                <?php checked( $provider, 'claude' ); ?> id="sc_provider_claude">
                            <strong>Claude</strong> (Anthropic) — <em>Haiku model, very fast &amp; affordable</em>
                        </label>
                        <br><br>
                        <label>
                            <input type="radio" name="spicehaus_chatbot_provider" value="gemini"
                                <?php checked( $provider, 'gemini' ); ?> id="sc_provider_gemini">
                            <strong>Gemini</strong> (Google) — <em>Gemini 2.0 Flash model</em>
                        </label>
                    </td>
                </tr>

                <tr id="sc_row_claude" <?php if ( $provider !== 'claude' ) echo 'style="display:none"'; ?>>
                    <th scope="row">
                        <label for="spicehaus_chatbot_api_key">Anthropic API Key</label>
                    </th>
                    <td>
                        <input
                            type="password"
                            id="spicehaus_chatbot_api_key"
                            name="spicehaus_chatbot_api_key"
                            value="<?php echo esc_attr( $claude_key ); ?>"
                            class="regular-text"
                            autocomplete="off"
                        >
                        <p class="description">
                            Get your key at <a href="https://console.anthropic.com" target="_blank" rel="noopener">console.anthropic.com</a>.
                            API keys are stored server-side and never exposed to visitors.
                        </p>
                    </td>
                </tr>

                <tr id="sc_row_gemini" <?php if ( $provider !== 'gemini' ) echo 'style="display:none"'; ?>>
                    <th scope="row">
                        <label for="spicehaus_chatbot_gemini_key">Google AI API Key</label>
                    </th>
                    <td>
                        <input
                            type="password"
                            id="spicehaus_chatbot_gemini_key"
                            name="spicehaus_chatbot_gemini_key"
                            value="<?php echo esc_attr( $gemini_key ); ?>"
                            class="regular-text"
                            autocomplete="off"
                        >
                        <p class="description">
                            Get your key at <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener">aistudio.google.com</a>.
                            Free tier available.
                        </p>
                    </td>
                </tr>
            </table>

            <h2>AI System Prompt</h2>
            <p>This is the instruction the AI receives before every conversation. Use <code>{{product_list}}</code> as the placeholder where the product catalog will be inserted — <strong>do not remove it</strong>.</p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="spicehaus_chatbot_system_prompt">System Prompt</label>
                    </th>
                    <td>
                        <textarea
                            id="spicehaus_chatbot_system_prompt"
                            name="spicehaus_chatbot_system_prompt"
                            rows="22"
                            class="large-text code"
                            spellcheck="false"
                        ><?php echo esc_textarea( $system_prompt ); ?></textarea>
                        <p class="description">
                            <button type="button" id="sc-reset-prompt" class="button button-secondary" style="margin-top:4px">↩ Reset to default prompt</button>
                        </p>
                    </td>
                </tr>
            </table>

            <h2>Product Catalog (JSON)</h2>
            <p>Edit the JSON array below, or use the <strong>CSV Import</strong> section below to upload your store's product list.
               Each entry supports: <code>name</code>, <code>url</code>, <code>allergens</code>, <code>tags</code>.</p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="spicehaus_chatbot_products">Products (JSON)</label>
                    </th>
                    <td>
                        <textarea
                            id="spicehaus_chatbot_products"
                            name="spicehaus_chatbot_products"
                            rows="20"
                            class="large-text code"
                            spellcheck="false"
                        ><?php echo esc_textarea( $products ); ?></textarea>
                        <p class="description">
                            Format: <code>[{"name": "Kurkuma gemahlen", "url": "https://spice-haus.de/products/kurkuma", "allergens": "none", "tags": ["spice", "indian"]}]</code>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button( 'Save Settings' ); ?>
        </form>

        <hr>

        <!-- ── CSV Import ───────────────────────────────────────────── -->
        <h2>📥 Import Products from CSV</h2>
        <p>Upload a CSV file to replace the product catalog. The bot will use these products to suggest recipes
           and generate QR code URLs for stickers.</p>
        <p><strong>Required columns:</strong> <code>name</code>, <code>url</code><br>
           <strong>Optional columns:</strong> <code>allergens</code>, <code>tags</code> (use <code>|</code> or <code>,</code> to separate multiple tags)</p>
        <p>
            <button type="button" id="sc-csv-download" class="button button-secondary">⬇ Download sample CSV template</button>
        </p>

        <div id="sc-csv-wrap">
            <input type="file" id="sc-csv-file" accept=".csv" style="margin-right:10px">
            <button id="sc-csv-import" class="button button-primary">Import CSV</button>
            <span id="sc-csv-status" style="margin-left:12px; color:#666;"></span>
        </div>
        <pre id="sc-csv-preview" style="display:none; margin-top:12px; max-height:200px; overflow:auto; background:#f5f5f5; padding:10px; font-size:12px;"></pre>

        <hr>

        <!-- ── QR Code Generator ────────────────────────────────────── -->
        <h2>📱 QR Code Stickers</h2>
        <p>Print these QR codes and stick them next to the corresponding products in your store.
           When a customer scans the code, the chatbot opens automatically, explains the product,
           shows allergen info, and suggests recipes using ingredients from your store.</p>
        <p>
            <button onclick="window.print()" class="button button-secondary">🖨 Print All QR Codes</button>
        </p>

        <div id="sc-qr-grid" style="display:flex; flex-wrap:wrap; gap:20px; margin-top:16px;">
        <?php foreach ( $catalog as $product ) :
            if ( empty( $product['name'] ) ) continue;
            $slug       = spicehaus_product_slug( $product['name'] );
            $qr_url     = $site_url . '?spicehaus_product=' . rawurlencode( $slug );
            $allergens  = ! empty( $product['allergens'] ) && $product['allergens'] !== 'none'
                            ? $product['allergens']
                            : 'Keine / None';
            $qr_img_url = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . rawurlencode( $qr_url );
        ?>
            <div class="sc-qr-card" style="border:1px solid #ddd; border-radius:8px; padding:16px; text-align:center; width:180px; background:#fff;">
                <img src="<?php echo esc_url( $qr_img_url ); ?>"
                     alt="QR <?php echo esc_attr( $product['name'] ); ?>"
                     width="150" height="150"
                     style="display:block; margin:0 auto 8px;">
                <strong style="display:block; font-size:13px; margin-bottom:4px;"><?php echo esc_html( $product['name'] ); ?></strong>
                <small style="color:#888; font-size:11px;">⚠️ <?php echo esc_html( $allergens ); ?></small><br>
                <a href="<?php echo esc_url( $qr_url ); ?>" target="_blank"
                   style="font-size:10px; color:#aaa; word-break:break-all;">Test link</a>
            </div>
        <?php endforeach; ?>
        </div>

        <style>
        @media print {
            /* Hide everything except QR cards */
            body > *:not(.wrap) { display: none !important; }
            .wrap > *:not(#sc-qr-grid):not(h2:last-of-type) { display: none !important; }
            #sc-qr-grid { display: flex !important; flex-wrap: wrap; gap: 16px; }
            .sc-qr-card { page-break-inside: avoid; border: 1px solid #ccc !important; }
            button, a.button { display: none !important; }
            p, hr { display: none !important; }
        }
        </style>
    </div>

    <script>
    (function () {
        // Provider toggle
        var radios = document.querySelectorAll('input[name="spicehaus_chatbot_provider"]');
        function toggle() {
            var val = document.querySelector('input[name="spicehaus_chatbot_provider"]:checked').value;
            document.getElementById('sc_row_claude').style.display = val === 'claude' ? '' : 'none';
            document.getElementById('sc_row_gemini').style.display = val === 'gemini' ? '' : 'none';
        }
        radios.forEach(function (r) { r.addEventListener('change', toggle); });

        // CSV import
        document.getElementById('sc-csv-import').addEventListener('click', function () {
            var file = document.getElementById('sc-csv-file').files[0];
            if (!file) { alert('Please select a CSV file first.'); return; }

            var status  = document.getElementById('sc-csv-status');
            var preview = document.getElementById('sc-csv-preview');
            status.textContent  = 'Importing…';
            preview.style.display = 'none';

            var fd = new FormData();
            fd.append('action',   'spicehaus_import_csv');
            fd.append('nonce',    '<?php echo esc_js( $csv_nonce ); ?>');
            fd.append('csv_file', file);

            fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                method: 'POST',
                body: fd,
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    status.style.color  = 'green';
                    status.textContent  = '✅ ' + data.data.message + ' Reload the page to see the updated QR codes.';
                    // Update the JSON textarea live
                    var ta = document.getElementById('spicehaus_chatbot_products');
                    if (ta) ta.value = data.data.json;
                    preview.textContent   = data.data.json;
                    preview.style.display = 'block';
                } else {
                    status.style.color = 'red';
                    status.textContent = '❌ ' + (data.data && data.data.message ? data.data.message : 'Import failed.');
                }
            })
            .catch(function () {
                status.style.color = 'red';
                status.textContent = '❌ Network error. Please try again.';
            });
        });

        // Sample CSV download (JS blob — avoids server file-serving issues)
        document.getElementById('sc-csv-download').addEventListener('click', function () {
            var csv = "name,url,allergens,tags\n" +
                "Kurkuma gemahlen,https://spice-haus.de/products/kurkuma-gemahlen,none,spice|indian|anti-inflammatory\n" +
                "Paprika edelsüß,https://spice-haus.de/products/paprika-edelsuess,none,spice|hungarian|mild\n" +
                "Paprika scharf,https://spice-haus.de/products/paprika-scharf,none,spice|hot|hungarian\n" +
                "Garam Masala,https://spice-haus.de/products/garam-masala,none,spice-blend|indian\n" +
                "Curry Pulver mild,https://spice-haus.de/products/curry-pulver-mild,contains mustard,spice-blend|indian|mild\n" +
                "Za'atar,https://spice-haus.de/products/zaatar,contains sesame,spice-blend|middle-eastern\n" +
                "Schwarzer Sesam,https://spice-haus.de/products/schwarzer-sesam,contains sesame,seed|asian|topping\n" +
                "Senfsamen gelb,https://spice-haus.de/products/senfsamen-gelb,contains mustard,spice|indian|pickling\n" +
                "Basmati Reis,https://spice-haus.de/products/basmati-reis,none,grain|rice|indian\n" +
                "Kichererbsen,https://spice-haus.de/products/kichererbsen,none,legume|protein|middle-eastern\n";
            var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            var url  = URL.createObjectURL(blob);
            var a    = document.createElement('a');
            a.href     = url;
            a.download = 'sample-products.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        });

        // Reset system prompt to default
        document.getElementById('sc-reset-prompt').addEventListener('click', function () {
            if (!confirm('Reset the prompt to the default? Your current edits will be lost.')) return;
            var defaultPrompt = <?php echo wp_json_encode( spicehaus_default_system_prompt() ); ?>;
            document.getElementById('spicehaus_chatbot_system_prompt').value = defaultPrompt;
        });
    })();
    </script>
    <?php
}
