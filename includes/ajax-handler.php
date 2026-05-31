<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ── Chat AJAX ──────────────────────────────────────────────────────────── */

add_action( 'wp_ajax_spicehaus_chat',        'spicehaus_handle_chat' );
add_action( 'wp_ajax_nopriv_spicehaus_chat', 'spicehaus_handle_chat' );

function spicehaus_handle_chat(): void {
    check_ajax_referer( 'spicehaus_chatbot', 'nonce' );

    $message     = sanitize_text_field( wp_unslash( $_POST['message'] ?? '' ) );
    $raw_history = wp_unslash( $_POST['history'] ?? '[]' );
    $history     = json_decode( $raw_history, true );
    if ( ! is_array( $history ) ) {
        $history = [];
    }

    if ( empty( $message ) ) {
        wp_send_json_error( [ 'message' => 'Empty message' ], 400 );
    }

    $provider = get_option( 'spicehaus_chatbot_provider', 'claude' );

    if ( $provider === 'gemini' ) {
        $api_key = get_option( 'spicehaus_chatbot_gemini_key', '' );
        $label   = 'Gemini';
    } else {
        $api_key = get_option( 'spicehaus_chatbot_api_key', '' );
        $label   = 'Claude';
    }

    if ( empty( $api_key ) ) {
        wp_send_json_error( [ 'message' => "$label API key not configured — add it under Settings → Spicehaus Chatbot." ], 500 );
    }

    $catalog = spicehaus_get_product_catalog();
    $system  = spicehaus_build_system_prompt( $catalog );

    // Sanitize and cap history at 18 turns
    $messages = [];
    foreach ( array_slice( (array) $history, -18 ) as $entry ) {
        if ( ! isset( $entry['role'], $entry['content'] ) ) continue;
        if ( ! in_array( $entry['role'], [ 'user', 'assistant' ], true ) ) continue;
        $messages[] = [
            'role'    => $entry['role'],
            'content' => sanitize_textarea_field( $entry['content'] ),
        ];
    }
    $messages[] = [ 'role' => 'user', 'content' => $message ];

    $reply = $provider === 'gemini'
        ? spicehaus_call_gemini( $api_key, $system, $messages )
        : spicehaus_call_claude( $api_key, $system, $messages );

    if ( is_wp_error( $reply ) ) {
        wp_send_json_error( [ 'message' => $reply->get_error_message() ], 500 );
    }

    wp_send_json_success( [ 'reply' => $reply ] );
}

/* ── Barcode lookup AJAX ────────────────────────────────────────────────── */

add_action( 'wp_ajax_spicehaus_barcode',        'spicehaus_handle_barcode' );
add_action( 'wp_ajax_nopriv_spicehaus_barcode', 'spicehaus_handle_barcode' );

function spicehaus_handle_barcode(): void {
    check_ajax_referer( 'spicehaus_chatbot', 'nonce' );

    $barcode = sanitize_text_field( wp_unslash( $_POST['barcode'] ?? '' ) );

    if ( empty( $barcode ) || ! preg_match( '/^\d{6,14}$/', $barcode ) ) {
        wp_send_json_error( [ 'message' => 'Invalid barcode' ], 400 );
    }

    // Open Food Facts — free, no auth required
    $url      = 'https://world.openfoodfacts.org/api/v0/product/' . rawurlencode( $barcode ) . '.json';
    $response = wp_remote_get( $url, [
        'timeout' => 8,
        'headers' => [ 'User-Agent' => 'SpicehausRecipeChatbot/1.2 (https://spice-haus.de)' ],
    ] );

    if ( is_wp_error( $response ) ) {
        wp_send_json_error( [ 'message' => 'Lookup failed' ], 500 );
    }

    $data   = json_decode( wp_remote_retrieve_body( $response ), true );
    $status = $data['status'] ?? 0;

    if ( $status !== 1 || empty( $data['product'] ) ) {
        wp_send_json_error( [ 'message' => 'Product not found' ], 404 );
    }

    $p    = $data['product'];
    $name = $p['product_name_de']
        ?? $p['product_name_en']
        ?? $p['product_name']
        ?? '';

    if ( empty( $name ) ) {
        wp_send_json_error( [ 'message' => 'Product name unavailable' ], 404 );
    }

    wp_send_json_success( [
        'name'     => $name,
        'brand'    => $p['brands']     ?? '',
        'category' => $p['categories'] ?? '',
    ] );
}

/* ── CSV import AJAX ────────────────────────────────────────────────────── */

add_action( 'wp_ajax_spicehaus_import_csv', 'spicehaus_handle_csv_import' );

function spicehaus_handle_csv_import(): void {
    check_ajax_referer( 'spicehaus_csv_import', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
    }

    if ( empty( $_FILES['csv_file'] ) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK ) {
        wp_send_json_error( [ 'message' => 'No file uploaded or upload error.' ], 400 );
    }

    $file     = $_FILES['csv_file'];
    $ext      = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
    if ( $ext !== 'csv' ) {
        wp_send_json_error( [ 'message' => 'Only CSV files are allowed.' ], 400 );
    }

    $content = file_get_contents( $file['tmp_name'] );
    if ( $content === false ) {
        wp_send_json_error( [ 'message' => 'Could not read uploaded file.' ], 500 );
    }

    // Strip BOM if present
    $content = ltrim( $content, "\xEF\xBB\xBF" );

    $products = spicehaus_parse_csv( $content );
    if ( is_wp_error( $products ) ) {
        wp_send_json_error( [ 'message' => $products->get_error_message() ], 400 );
    }

    $json = wp_json_encode( $products, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
    update_option( 'spicehaus_chatbot_products', $json );

    wp_send_json_success( [
        'message' => sprintf( '%d products imported successfully.', count( $products ) ),
        'count'   => count( $products ),
        'json'    => $json,
    ] );
}

/* ── System prompt ──────────────────────────────────────────────────────── */

function spicehaus_default_system_prompt(): string {
    return 'You are the friendly recipe assistant for Spicehaus (spice-haus.de), a German shop that sells quality spices, grains, and groceries.

Your primary goal is to help customers discover delicious recipes using products from the Spicehaus catalog — and to upsell by highlighting which catalog items they can pick up in the store.

STRICT RULES:
1. ONLY reference spices and pantry items from the Spicehaus catalog below. Everyday basics like water, oil, butter, eggs, flour, fresh vegetables, and meat are always assumed available — you do not need to list them unless they are in the catalog.
2. When mentioning a catalog product, use its EXACT name as listed.
3. Respond in the SAME LANGUAGE the visitor writes in (German if they write German, English if English).
4. Structure every recipe: short intro → ingredients list (mark Spicehaus products with ★) → numbered steps.
5. End each recipe with a "Shop these ingredients" block listing the Spicehaus products used with their URLs (format: **Product Name**: URL).
6. If asked for a recipe requiring spices not in the catalog, say so politely and suggest what you CAN make.
7. Keep responses concise — one recipe per reply unless asked for more. Be warm and enthusiastic.
8. UPSELL: Always try to incorporate additional Spicehaus products into recipes beyond the obvious main spice — suggest complementary items the customer can also grab in the store.
9. BARCODE SCANS: When a visitor says they scanned a product (message contains "Scanned:"), treat that product as the main ingredient. Suggest a recipe featuring it alongside Spicehaus spices and ingredients from the catalog. The scanned item does not need to be in the catalog.
10. PRODUCT PAGE (QR code scan): When the message starts with "[PRODUCT_PAGE:", a customer just scanned a QR code sticker in the store next to that product. Respond with:
    a) A warm welcome (1–2 sentences) about that product — what it is, its origin, best uses.
    b) An "⚠️ Allergen information" section (even if there are none — state "keine Allergene / no allergens").
    c) One or two recipe ideas that feature this product as the star, with other Spicehaus items as supporting ingredients.
    d) End with the "Shop these ingredients" block as usual.

SPICEHAUS PRODUCT CATALOG:
{{product_list}}';
}

function spicehaus_build_system_prompt( array $catalog ): string {
    $lines = [];
    foreach ( $catalog as $product ) {
        if ( empty( $product['name'] ) ) continue;
        $line = '- ' . $product['name'];
        if ( ! empty( $product['url'] ) ) {
            $line .= ' → ' . $product['url'];
        }
        if ( ! empty( $product['allergens'] ) && $product['allergens'] !== 'none' ) {
            $line .= ' [⚠️ ' . $product['allergens'] . ']';
        }
        if ( ! empty( $product['tags'] ) && is_array( $product['tags'] ) ) {
            $line .= ' (' . implode( ', ', $product['tags'] ) . ')';
        }
        $lines[] = $line;
    }
    $product_list = implode( "\n", $lines );

    $template = get_option( 'spicehaus_chatbot_system_prompt', '' );
    if ( empty( trim( $template ) ) ) {
        $template = spicehaus_default_system_prompt();
    }

    return str_replace( '{{product_list}}', $product_list, $template );
}

/* ── Claude API ─────────────────────────────────────────────────────────── */

function spicehaus_call_claude( string $api_key, string $system, array $messages ): string|\WP_Error {
    $body = wp_json_encode( [
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => 1500,
        'system'     => $system,
        'messages'   => $messages,
    ] );

    $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
        'timeout' => 30,
        'headers' => [
            'x-api-key'         => $api_key,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ],
        'body' => $body,
    ] );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 ) {
        return new \WP_Error( 'claude_api_error', $data['error']['message'] ?? "Claude API error (HTTP $code)" );
    }

    return $data['content'][0]['text'] ?? '';
}

/* ── Gemini API ─────────────────────────────────────────────────────────── */

function spicehaus_call_gemini( string $api_key, string $system, array $messages ): string|\WP_Error {
    $contents = [];
    foreach ( $messages as $msg ) {
        $contents[] = [
            'role'  => $msg['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [ [ 'text' => $msg['content'] ] ],
        ];
    }

    $body = wp_json_encode( [
        'system_instruction' => [
            'parts' => [ [ 'text' => $system ] ],
        ],
        'contents'         => $contents,
        'generationConfig' => [
            'maxOutputTokens' => 1500,
            'temperature'     => 0.7,
        ],
    ] );

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent?key=' . rawurlencode( $api_key );

    $response = wp_remote_post( $url, [
        'timeout' => 30,
        'headers' => [ 'content-type' => 'application/json' ],
        'body'    => $body,
    ] );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code !== 200 ) {
        return new \WP_Error( 'gemini_api_error', $data['error']['message'] ?? "Gemini API error (HTTP $code)" );
    }

    return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
}
