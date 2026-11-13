<?php
/**
 * Plugin Name:       ContentPilot – Content Ideas, Competitor Analysis & SEO
 * Description:       Analyze your content, generate content ideas, compare with competitors, add social share previews, image alt text, tag suggestions and more SEO tools. 100% free.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Your Name
 * License:           Personal Use Only (see LICENSE.txt)
 * License URI:       LICENSE.txt
 * Text Domain:       contentpilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CP_VERSION', '1.0.0' );
define( 'CP_FILE', __FILE__ );
define( 'CP_DIR', plugin_dir_path( __FILE__ ) );
define( 'CP_URL', plugin_dir_url( __FILE__ ) );

/*
 * ---- DONATION LINKS (plugin author: fill these in) ----
 * Leave a value empty to hide that option.
 */
define( 'CP_DONATE_PAYPAL', '' ); // e.g. https://paypal.me/yourname
define( 'CP_DONATE_COFFEE', '' ); // e.g. https://buymeacoffee.com/yourname
define( 'CP_DONATE_WALLET', '' ); // e.g. "bKash 01XXXXXXXXX (Personal)" or "Nagad 01XXXXXXXXX"
define( 'CP_DONATE_OTHER', '' );  // any other donation page URL

foreach ( array( 'helpers', 'analyzer', 'ideas', 'competitors', 'seo', 'images', 'admin' ) as $cp_file ) {
	require_once CP_DIR . 'includes/class-cp-' . $cp_file . '.php';
}

add_action(
	'plugins_loaded',
	function () {
		CP_SEO::init();
		CP_Images::init();
		CP_Ideas::init();
		CP_Admin::init();
	}
);
