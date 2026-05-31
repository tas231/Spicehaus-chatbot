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
        'headers' => [ 'User-Agent' => 'SpicehausRecipeChatbot/1.1 (https://spice-haus.de)' ],
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

/* ── System prompt ──────────────────────────────────────────────────────── */

function spicehaus_build_system_prompt( array $catalog ): string {
    $lines = [];
    foreach ( $catalog as $product ) {
        if ( empty( $product['name'] ) ) continue;
        $line = '- ' . $product['name'];
        if ( ! empty( $product['url'] ) ) {
            $line .= ' → ' . $product['url'];
        }
        if ( ! empty( $product['tags'] ) && is_array( $product['tags'] ) ) {
            $line .= ' [' . implode( ', ', $product['tags'] ) . ']';
        }
        $lines[] = $line;
    }
    $product_list = implode( "\n", $lines );

    return <<<PROMPT
You are the friendly recipe assistant for Spicehaus (spice-haus.de), a German online shop that sells quality spices, grains, and groceries.

Your role is to suggest delicious, practical recipes that use products from the Spicehaus catalog.

STRICT RULES:
1. ONLY reference spices and pantry items from the Spicehaus catalog below. Everyday basics like water, oil, butter, eggs, flour, fresh vegetables, and meat are always assumed available — you do not need to list them unless they are in the catalog.
2. When mentioning a catalog product, use its EXACT name as listed.
3. Respond in the SAME LANGUAGE the visitor writes in (German if they write German, English if English).
4. Structure every recipe: short intro → ingredients list (mark Spicehaus products with ★) → numbered steps.
5. End each recipe with a "Shop these ingredients" block listing the Spicehaus products used with their URLs (format: **Product Name**: URL).
6. If asked for a recipe requiring spices not in the catalog, say so politely and suggest what you CAN make.
7. Keep responses concise — one recipe per reply unless asked for more. Be warm and enthusiastic.
8. BARCODE SCANS: When a visitor says they scanned a product (message contains "Scanned:"), treat that product as the main ingredient. Suggest a recipe featuring it alongside Spicehaus spices and ingredients from the catalog. The scanned item does not need to be in the catalog.

SPICEHAUS PRODUCT CATALOG:
$product_list
PROMPT;
}

/* ── Claude API ─────────────────────────────────────────────────────────── */

function spicehaus_call_claude( string $api_key, string $system, array $messages ): string|\WP_Error {
    $body = wp_json_encode( [
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => 1200,
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
    // Convert Claude-style messages (role: assistant) to Gemini style (role: model)
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
            'maxOutputTokens' => 1200,
            'temperature'     => 0.7,
        ],
    ] );

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . rawurlencode( $api_key );

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
