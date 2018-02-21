<?php
/**
 * Plugin Name:     Hc Auth
 * Plugin URI:      https://github.com/mlaa/hc-auth
 * Description:     Miscellaneous actions & filters for Humanities Commons authentication.
 * Author:          MLA
 * Author URI:      https://github.com/mlaa
 * Text Domain:     hc-auth
 * Domain Path:     /languages
 * Version:         0.1.0
 *
 * @package         Hc_Auth
 */
require_once trailingslashit( __DIR__ ) . 'includes/class.mla-hcommons.php';

add_action('plugins_loaded', array('MLA_Hcommons', 'singleton'));

