<?php
/**
 * Compatibility entry point for the historical ArvindShop extractor ID.
 *
 * ArvindShop currently runs on Mixin. The maintained Mixin extractor owns
 * discovery, safe HTTP requests and product/variation parsing; its file also
 * declares Arvindshop_Product_Extractor as a backwards-compatible alias.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once plugin_dir_path( __FILE__ ) . 'extractor-mixin.php';
