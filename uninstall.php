<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'cp_settings' );
delete_transient( 'cp_scan' );
delete_transient( 'cp_comp' );
foreach ( array( '_cp_title', '_cp_desc', '_cp_kw', '_cp_og_title', '_cp_og_desc', '_cp_og_image', '_cp_noindex' ) as $key ) {
	delete_post_meta_by_key( $key );
}
