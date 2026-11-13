<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CP_Helpers {

	public static function defaults() {
		return array(
			'ai_provider'      => 'none',
			'ai_key'           => '',
			'ai_model'         => '',
			'competitors'      => '',
			'og_default_image' => '',
			'twitter_handle'   => '',
			'output_head'      => 1,
			'enable_schema'    => 1,
			'auto_alt'         => 1,
			'scan_limit'       => 300,
		);
	}

	public static function opt( $key ) {
		$o = wp_parse_args( get_option( 'cp_settings', array() ), self::defaults() );
		return isset( $o[ $key ] ) ? $o[ $key ] : null;
	}

	public static function lower( $s ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}

	public static function title_case( $s ) {
		return function_exists( 'mb_convert_case' ) ? mb_convert_case( $s, MB_CASE_TITLE, 'UTF-8' ) : ucwords( $s );
	}

	public static function stopwords() {
		return array_flip( explode( ',', 'the,and,for,that,this,with,you,your,are,was,were,have,has,had,not,but,all,can,will,from,they,their,what,when,where,which,who,how,why,out,about,into,more,than,then,them,its,our,his,her,she,him,one,two,get,use,just,also,been,being,some,any,may,such,only,over,other,like,most,very,make,made,many,much,each,these,those,there,here,would,could,should,does,did,doing,say,said,see,way,new,now,best,top,guide,tips' ) );
	}

	public static function words( $text ) {
		preg_match_all( '/\p{L}[\p{L}\p{N}\'’-]*/u', $text, $m );
		return $m[0];
	}

	public static function word_count( $text ) {
		return count( self::words( $text ) );
	}

	public static function clean_text( $html ) {
		return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( strip_shortcodes( $html ) ) ) );
	}

	/** Top keywords as word => count. */
	public static function keywords( $text, $limit = 10, $min_len = 3 ) {
		$stop   = self::stopwords();
		$counts = array();
		foreach ( self::words( self::lower( wp_strip_all_tags( $text ) ) ) as $w ) {
			if ( mb_strlen( $w ) < $min_len || isset( $stop[ $w ] ) || is_numeric( $w ) ) {
				continue;
			}
			$counts[ $w ] = isset( $counts[ $w ] ) ? $counts[ $w ] + 1 : 1;
		}
		arsort( $counts );
		return array_slice( $counts, 0, $limit, true );
	}

	public static function donate_links() {
		$links = array();
		if ( CP_DONATE_PAYPAL ) {
			$links[] = array( 'label' => 'Donate with PayPal', 'url' => CP_DONATE_PAYPAL );
		}
		if ( CP_DONATE_COFFEE ) {
			$links[] = array( 'label' => 'Buy me a coffee', 'url' => CP_DONATE_COFFEE );
		}
		if ( CP_DONATE_OTHER ) {
			$links[] = array( 'label' => 'Donate', 'url' => CP_DONATE_OTHER );
		}
		return $links;
	}

	public static function enqueue( $extra = array() ) {
		wp_enqueue_style( 'cp-admin', CP_URL . 'assets/admin.css', array(), CP_VERSION );
		wp_enqueue_media();
		wp_enqueue_script( 'cp-admin', CP_URL . 'assets/admin.js', array( 'jquery' ), CP_VERSION, true );
		wp_localize_script(
			'cp-admin',
			'cpData',
			array_merge(
				array(
					'ajax'         => admin_url( 'admin-ajax.php' ),
					'nonce'        => wp_create_nonce( 'cp_ajax' ),
					'host'         => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
					'siteName'     => get_bloginfo( 'name' ),
					'defaultImage' => (string) self::opt( 'og_default_image' ),
					'thumb'        => '',
				),
				$extra
			)
		);
	}
}
