<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Returns the product catalog as an array.
 * Prefers the JSON saved in WP options (editable via Admin).
 * Falls back to data/products.json bundled with the plugin.
 *
 * Each entry: { "name": string, "url": string, "tags": string[] }
 */
function spicehaus_get_product_catalog(): array {
    $saved = get_option( 'spicehaus_chatbot_products', '' );

    if ( ! empty( $saved ) ) {
        $decoded = json_decode( $saved, true );
        if ( is_array( $decoded ) ) {
            return $decoded;
        }
    }

    $file = SPICEHAUS_CHATBOT_PATH . 'data/products.json';
    if ( ! file_exists( $file ) ) {
        return [];
    }

    $json = file_get_contents( $file );
    return json_decode( $json, true ) ?? [];
}
