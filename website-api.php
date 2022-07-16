<?php

/**
 * Plugin Name: Website API
 * Description: APIs to expose the content of a website
 * Author: Sadiq Odunsi
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Include the main class.
if ( ! class_exists( 'AD_BLIS\Setup' ) ) {
    require_once 'includes/setup.php';
}

AD_BLIS\Setup::get_instance();

/**
 * Remove auth on specific apis
 */
AD_BLIS\Setup::auth_whitelist();