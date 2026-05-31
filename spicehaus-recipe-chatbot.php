<?php
/**
 * Plugin Name: Spicehaus Recipe Chatbot
 * Plugin URI:  https://spice-haus.de
 * Description: AI-powered recipe chatbot (Claude or Gemini) — recipes based on your shop's products, with barcode scanning and QR code product pages.
 * Version:     1.2.0
 * Author:      Spicehaus
 * Text Domain: spicehaus-chatbot
 * Requires PHP: 8.0
 * Requires at least: 6.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'SPICEHAUS_CHATBOT_VERSION', '1.2.0' );
define( 'SPICEHAUS_CHATBOT_PATH',    plugin_dir_path( __FILE__ ) );
define( 'SPICEHAUS_CHATBOT_URL',     plugin_dir_url( __FILE__ ) );

require_once SPICEHAUS_CHATBOT_PATH . 'includes/product-catalog.php';
require_once SPICEHAUS_CHATBOT_PATH . 'includes/ajax-handler.php';

if ( is_admin() ) {
    require_once SPICEHAUS_CHATBOT_PATH . 'admin/settings-page.php';
}

add_action( 'wp_enqueue_scripts', function () {
    wp_enqueue_style(
        'spicehaus-chatbot',
        SPICEHAUS_CHATBOT_URL . 'assets/chatbot.css',
        [],
        SPICEHAUS_CHATBOT_VERSION
    );

    wp_enqueue_script(
        'spicehaus-chatbot',
        SPICEHAUS_CHATBOT_URL . 'assets/chatbot.js',
        [],
        SPICEHAUS_CHATBOT_VERSION,
        true
    );

    // Detect QR code product context from URL parameter
    $product_context = null;
    if ( isset( $_GET['spicehaus_product'] ) ) {
        $slug    = sanitize_key( wp_unslash( $_GET['spicehaus_product'] ) );
        $product = spicehaus_find_product_by_slug( $slug );
        if ( $product ) {
            $product_context = [
                'name'      => $product['name'],
                'allergens' => $product['allergens'] ?? 'none',
                'url'       => $product['url'] ?? '',
            ];
        } elseif ( ! empty( $slug ) ) {
            // Unknown slug — still open the bot with the raw slug as a hint
            $product_context = [
                'name'      => $slug,
                'allergens' => '',
                'url'       => '',
            ];
        }
    }

    wp_localize_script( 'spicehaus-chatbot', 'SpicehausChatbot', [
        'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
        'nonce'          => wp_create_nonce( 'spicehaus_chatbot' ),
        'productContext' => $product_context,
        'i18n'           => [
            'title'           => __( 'Spicehaus Recipe Assistant', 'spicehaus-chatbot' ),
            'subtitle'        => __( 'Recipes with our spices & ingredients', 'spicehaus-chatbot' ),
            'greeting'        => __( 'Hello! Ask me for a recipe or scan a product barcode 📷 and I\'ll suggest something using our Spicehaus products. 🌶️', 'spicehaus-chatbot' ),
            'placeholder'     => __( 'e.g. "What can I make with turmeric?"', 'spicehaus-chatbot' ),
            'send'            => __( 'Send', 'spicehaus-chatbot' ),
            'error'           => __( 'Something went wrong. Please try again.', 'spicehaus-chatbot' ),
            'scanTitle'       => __( 'Scan a barcode', 'spicehaus-chatbot' ),
            'scanHint'        => __( 'Point camera at the barcode on the product...', 'spicehaus-chatbot' ),
            'scanManual'      => __( 'Or enter barcode manually:', 'spicehaus-chatbot' ),
            'scanPlaceholder' => __( 'e.g. 4000417025005', 'spicehaus-chatbot' ),
            'scanLookingUp'   => __( '🔍 Looking up product...', 'spicehaus-chatbot' ),
            'scanFound'       => __( '📦 Scanned: ', 'spicehaus-chatbot' ),
            'scanNotFound'    => __( '📦 Scanned barcode: ', 'spicehaus-chatbot' ),
            'scanNoSupport'   => __( 'Live scanning requires Chrome or Safari 17.4+. Enter the barcode below.', 'spicehaus-chatbot' ),
            'scanNoCam'       => __( 'Camera access denied. Please enter the barcode manually.', 'spicehaus-chatbot' ),
            'productGreeting' => __( '🌶️ Scanning product info...', 'spicehaus-chatbot' ),
        ],
    ] );
} );

add_action( 'wp_footer', function () {
    echo '<div id="spicehaus-chatbot-root"></div>' . "\n";
} );
