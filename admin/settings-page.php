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

    $provider    = get_option( 'spicehaus_chatbot_provider',   'claude' );
    $claude_key  = get_option( 'spicehaus_chatbot_api_key',    '' );
    $gemini_key  = get_option( 'spicehaus_chatbot_gemini_key', '' );
    $products    = get_option( 'spicehaus_chatbot_products',   '' );

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
    ?>
    <div class="wrap">
        <h1>🌶️ Spicehaus Recipe Chatbot</h1>

        <p>The chatbot widget appears in the <strong>bottom-right corner</strong> on every page of your shop.
           Visitors can ask recipe questions or <strong>scan a product barcode</strong> to get recipe ideas
           using your Spicehaus products.</p>

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
                            <strong>Claude</strong> (Anthropic) — <em>Haiku model, very fast & affordable</em>
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

            <h2>Product Catalog</h2>
            <p>Edit the JSON array below to match your actual shop products.
               Each entry needs a <code>name</code> and <code>url</code>.
               The <code>tags</code> field is optional but helps the AI categorise products.</p>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="spicehaus_chatbot_products">Products (JSON)</label>
                    </th>
                    <td>
                        <textarea
                            id="spicehaus_chatbot_products"
                            name="spicehaus_chatbot_products"
                            rows="30"
                            class="large-text code"
                            spellcheck="false"
                        ><?php echo esc_textarea( $products ); ?></textarea>
                        <p class="description">
                            Format: <code>[{"name": "Kurkuma gemahlen", "url": "https://spice-haus.de/products/kurkuma", "tags": ["spice", "indian"]}]</code>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button( 'Save Settings' ); ?>
        </form>

        <hr>
        <h2>Preview &amp; Features</h2>
        <p>
            <a href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" class="button button-secondary">
                Open Shop (chatbot — bottom-right corner)
            </a>
        </p>
        <ul>
            <li><strong>Recipe chat</strong> — visitors type questions and get recipes using your products.</li>
            <li><strong>Barcode scan</strong> — visitors tap 📷 to scan any grocery barcode; the chatbot identifies the product and suggests a recipe with Spicehaus seasonings. Works in Chrome and Safari 17.4+. Other browsers show a manual entry fallback.</li>
        </ul>
    </div>

    <script>
    (function () {
        var radios = document.querySelectorAll('input[name="spicehaus_chatbot_provider"]');
        function toggle() {
            var val = document.querySelector('input[name="spicehaus_chatbot_provider"]:checked').value;
            document.getElementById('sc_row_claude').style.display = val === 'claude' ? '' : 'none';
            document.getElementById('sc_row_gemini').style.display = val === 'gemini' ? '' : 'none';
        }
        radios.forEach(function (r) { r.addEventListener('change', toggle); });
    })();
    </script>
    <?php
}
