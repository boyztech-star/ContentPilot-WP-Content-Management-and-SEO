<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CP_Images {

	public static function init() {
		add_action( 'add_attachment', array( __CLASS__, 'auto_alt' ) );
		add_action( 'admin_post_cp_save_alts', array( __CLASS__, 'save_alts' ) );
	}

	public static function clean_name( $file ) {
		$n = pathinfo( (string) $file, PATHINFO_FILENAME );
		$n = preg_replace( '/[-_]+/', ' ', $n );
		$n = preg_replace( '/\b\d{2,5}x\d{2,5}\b/', '', $n );
		return trim( preg_replace( '/\s+/', ' ', $n ) );
	}

	public static function suggest( $att ) {
		$name = self::clean_name( get_attached_file( $att->ID ) );
		if ( strlen( $name ) < 3 || preg_match( '/^(img|dsc|image|screenshot|photo|pxl|wp)?\s*\d*$/i', $name ) ) {
			$parent = $att->post_parent ? get_the_title( $att->post_parent ) : '';
			return '' !== $parent ? $parent : $name;
		}
		return $name;
	}

	public static function auto_alt( $id ) {
		if ( ! CP_Helpers::opt( 'auto_alt' ) || ! wp_attachment_is_image( $id ) ) {
			return;
		}
		if ( '' !== (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) {
			return;
		}
		$alt = self::clean_name( get_attached_file( $id ) );
		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}
	}

	public static function missing_query( $paged = 1, $per = 30 ) {
		return new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'posts_per_page' => $per,
				'paged'          => max( 1, (int) $paged ),
				'meta_query'     => array(
					'relation' => 'OR',
					array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ),
					array( 'key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '=' ),
				),
			)
		);
	}

	public static function save_alts() {
		check_admin_referer( 'cp_save_alts' );
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( 'Permission denied.' );
		}
		$saved = 0;
		$alts  = isset( $_POST['alt'] ) ? (array) wp_unslash( $_POST['alt'] ) : array(); // phpcs:ignore
		foreach ( $alts as $id => $val ) {
			$id  = (int) $id;
			$val = sanitize_text_field( $val );
			if ( $id && '' !== $val && current_user_can( 'edit_post', $id ) && wp_attachment_is_image( $id ) ) {
				update_post_meta( $id, '_wp_attachment_image_alt', $val );
				$saved++;
			}
		}
		delete_transient( 'cp_scan' );
		wp_safe_redirect( add_query_arg( array( 'page' => 'contentpilot', 'tab' => 'images', 'saved' => $saved ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
