<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Returns the product catalog as an array.
 * Priority: WP options (editable via Admin) → data/products.json bundled with plugin.
 *
 * Each entry: { "name": string, "url": string, "allergens": string, "tags": string[] }
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

/**
 * Generates a URL-safe slug from a product name.
 * Used to build QR code URLs: ?spicehaus_product=SLUG
 */
function spicehaus_product_slug( string $name ): string {
    $map  = [ 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
              'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue' ];
    $slug = strtr( strtolower( $name ), $map );
    $slug = preg_replace( '/[^a-z0-9]+/', '-', $slug );
    return trim( $slug, '-' );
}

/**
 * Finds a catalog product by its slug.
 * Returns the product array or null if not found.
 */
function spicehaus_find_product_by_slug( string $slug ): ?array {
    $catalog = spicehaus_get_product_catalog();
    foreach ( $catalog as $product ) {
        if ( ! empty( $product['name'] ) && spicehaus_product_slug( $product['name'] ) === $slug ) {
            return $product;
        }
    }
    return null;
}

/**
 * Parses a CSV string into a product catalog array.
 * Expected columns: name, url, allergens, tags
 * Tags column may be pipe-separated or comma-separated within quotes.
 *
 * Returns array of products on success, WP_Error on failure.
 */
function spicehaus_parse_csv( string $csv_content ): array|\WP_Error {
    $lines = array_filter( array_map( 'trim', explode( "\n", $csv_content ) ) );

    if ( count( $lines ) < 2 ) {
        return new \WP_Error( 'csv_empty', 'CSV must have a header row and at least one product row.' );
    }

    // Parse header (first line)
    $header = str_getcsv( array_shift( $lines ) );
    $header = array_map( 'strtolower', array_map( 'trim', $header ) );

    $name_idx      = array_search( 'name',      $header, true );
    $url_idx       = array_search( 'url',       $header, true );
    $allergens_idx = array_search( 'allergens', $header, true );
    $tags_idx      = array_search( 'tags',      $header, true );

    if ( $name_idx === false || $url_idx === false ) {
        return new \WP_Error( 'csv_missing_columns', 'CSV must have at least "name" and "url" columns.' );
    }

    $products = [];
    foreach ( $lines as $line ) {
        if ( empty( trim( $line ) ) ) continue;
        $cols = str_getcsv( $line );

        $name = trim( $cols[ $name_idx ] ?? '' );
        $url  = trim( $cols[ $url_idx ]  ?? '' );
        if ( empty( $name ) || empty( $url ) ) continue;

        $allergens = $allergens_idx !== false ? trim( $cols[ $allergens_idx ] ?? 'none' ) : 'none';
        if ( empty( $allergens ) ) $allergens = 'none';

        $tags_raw = $tags_idx !== false ? trim( $cols[ $tags_idx ] ?? '' ) : '';
        $tags     = [];
        if ( ! empty( $tags_raw ) ) {
            // Support pipe or comma as separator within the tags cell
            $sep  = str_contains( $tags_raw, '|' ) ? '|' : ',';
            $tags = array_values( array_filter( array_map( 'trim', explode( $sep, $tags_raw ) ) ) );
        }

        $products[] = [
            'name'      => $name,
            'url'       => esc_url_raw( $url ),
            'allergens' => $allergens,
            'tags'      => $tags,
        ];
    }

    if ( empty( $products ) ) {
        return new \WP_Error( 'csv_no_products', 'No valid products found in the CSV.' );
    }

    return $products;
}
