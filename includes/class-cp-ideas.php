<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CP_Ideas {

	public static function init() {
		add_action( 'wp_ajax_cp_generate_ideas', array( __CLASS__, 'ajax_generate' ) );
		add_action( 'wp_ajax_cp_create_draft', array( __CLASS__, 'ajax_draft' ) );
	}

	public static function context() {
		$rows   = CP_Analyzer::scan();
		$titles = wp_list_pluck( $rows, 'title' );
		return array(
			'rows'   => $rows,
			'cats'   => CP_Analyzer::category_stats( $rows ),
			'kw'     => array_keys( CP_Helpers::keywords( implode( ' ', $titles ), 15 ) ),
			'titles' => array_slice( $titles, 0, 20 ),
		);
	}

	/** Free, built-in idea generator (no API needed). */
	public static function basic() {
		$c     = self::context();
		$year  = wp_date( 'Y' );
		$ideas = array();
		$cats  = array_filter(
			$c['cats'],
			function ( $x ) {
				return 'uncategorized' !== $x['slug'];
			}
		);
		uasort(
			$cats,
			function ( $a, $b ) {
				return $a['count'] <=> $b['count'];
			}
		);

		$i = 0;
		foreach ( $cats as $cat ) {
			if ( ++$i > 6 ) {
				break;
			}
			$n   = $cat['name'];
			$why = $cat['count'] < 5
				? sprintf( 'Only %d post(s) in this category – build topical authority.', $cat['count'] )
				: 'Strengthen an existing topic with a pillar page.';
			$ideas[] = array( 'title' => sprintf( "The Complete Beginner's Guide to %s", $n ), 'type' => 'Pillar guide', 'reason' => $why );
			$ideas[] = array( 'title' => sprintf( '7 Common %s Mistakes (and How to Avoid Them)', $n ), 'type' => 'Listicle', 'reason' => 'Mistake-style lists attract clicks and shares.' );
			$ideas[] = array( 'title' => sprintf( '%s FAQ: Your Top Questions Answered', $n ), 'type' => 'FAQ', 'reason' => 'FAQ content can win featured snippets.' );
		}

		$names = array_values( wp_list_pluck( $cats, 'name' ) );
		for ( $a = 0; $a < min( 3, count( $names ) - 1 ); $a++ ) {
			$ideas[] = array( 'title' => sprintf( '%s vs %s: Which One Is Right for You?', $names[ $a ], $names[ $a + 1 ] ), 'type' => 'Comparison', 'reason' => 'Connects two of your categories and targets comparison searches.' );
		}

		foreach ( array_slice( $c['kw'], 0, 5 ) as $kw ) {
			$k       = CP_Helpers::title_case( $kw );
			$ideas[] = array( 'title' => sprintf( 'How to Get Started with %s in %s', $k, $year ), 'type' => 'How-to', 'reason' => 'Built on a keyword you already use often.' );
		}

		$old = array_filter(
			$c['rows'],
			function ( $r ) {
				return 'post' === $r['type'] && $r['days'] > 365 && $r['words'] >= 300;
			}
		);
		usort(
			$old,
			function ( $a, $b ) {
				return $b['words'] <=> $a['words'];
			}
		);
		foreach ( array_slice( $old, 0, 5 ) as $r ) {
			$ideas[] = array( 'title' => sprintf( 'Updated for %s: %s', $year, $r['title'] ), 'type' => 'Refresh', 'reason' => sprintf( 'Not updated for %d days – refreshing old posts is the fastest SEO win.', $r['days'] ) );
		}

		$comp = CP_Competitors::get();
		if ( $comp && ! empty( $comp['gap'] ) ) {
			foreach ( array_slice( $comp['gap'], 0, 6 ) as $kw ) {
				$ideas[] = array( 'title' => sprintf( 'Everything You Need to Know About %s', CP_Helpers::title_case( $kw ) ), 'type' => 'Competitor gap', 'reason' => 'Your competitors cover this topic and you do not.' );
			}
		}
		return array_slice( $ideas, 0, 45 );
	}

	/** Optional AI-powered ideas (user supplies their own API key). */
	public static function ai( $c ) {
		$provider = CP_Helpers::opt( 'ai_provider' );
		$key      = CP_Helpers::opt( 'ai_key' );
		if ( ! in_array( $provider, array( 'anthropic', 'openai' ), true ) || ! $key ) {
			return new WP_Error( 'cp_no_key', 'Add an AI provider and API key in the Settings tab first.' );
		}
		$cat_lines = array();
		foreach ( $c['cats'] as $cat ) {
			$cat_lines[] = $cat['name'] . ' (' . $cat['count'] . ' posts)';
		}
		$comp = CP_Competitors::get();
		$gap  = ( $comp && ! empty( $comp['gap'] ) ) ? implode( ', ', array_slice( $comp['gap'], 0, 15 ) ) : 'none';

		$prompt = 'You are an SEO content strategist. Website: ' . get_bloginfo( 'name' ) . ' – ' . get_bloginfo( 'description' ) . "\n"
			. 'Categories: ' . implode( '; ', $cat_lines ) . "\n"
			. 'Top keywords: ' . implode( ', ', $c['kw'] ) . "\n"
			. 'Recent titles: ' . implode( ' | ', $c['titles'] ) . "\n"
			. 'Topics competitors cover that this site does not: ' . $gap . "\n\n"
			. 'Suggest 15 NEW, specific, non-duplicate article ideas in the same language as the recent titles. '
			. 'Return ONLY a JSON array of objects with keys "title", "type" (e.g. How-to, Listicle, Guide, Comparison, FAQ) and "reason" (one short sentence).';

		if ( 'anthropic' === $provider ) {
			$model = CP_Helpers::opt( 'ai_model' ) ? CP_Helpers::opt( 'ai_model' ) : 'claude-sonnet-5-5';
			$res   = wp_remote_post(
				'https://api.anthropic.com/v1/messages',
				array(
					'timeout' => 60,
					'headers' => array( 'x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json' ),
					'body'    => wp_json_encode( array( 'model' => $model, 'max_tokens' => 2000, 'messages' => array( array( 'role' => 'user', 'content' => $prompt ) ) ) ),
				)
			);
		} else {
			$model = CP_Helpers::opt( 'ai_model' ) ? CP_Helpers::opt( 'ai_model' ) : 'gpt-4o-mini';
			$res   = wp_remote_post(
				'https://api.openai.com/v1/chat/completions',
				array(
					'timeout' => 60,
					'headers' => array( 'Authorization' => 'Bearer ' . $key, 'content-type' => 'application/json' ),
					'body'    => wp_json_encode( array( 'model' => $model, 'messages' => array( array( 'role' => 'user', 'content' => $prompt ) ) ) ),
				)
			);
		}
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		$text = 'anthropic' === $provider
			? ( isset( $body['content'][0]['text'] ) ? $body['content'][0]['text'] : '' )
			: ( isset( $body['choices'][0]['message']['content'] ) ? $body['choices'][0]['message']['content'] : '' );
		if ( '' === $text ) {
			$msg = isset( $body['error']['message'] ) ? $body['error']['message'] : 'Empty response from AI provider.';
			return new WP_Error( 'cp_ai_fail', sanitize_text_field( $msg ) );
		}
		$start = strpos( $text, '[' );
		$end   = strrpos( $text, ']' );
		$list  = ( false !== $start && false !== $end ) ? json_decode( substr( $text, $start, $end - $start + 1 ), true ) : null;
		if ( ! is_array( $list ) ) {
			return new WP_Error( 'cp_ai_parse', 'Could not read the AI response. Try again.' );
		}
		$ideas = array();
		foreach ( $list as $it ) {
			if ( empty( $it['title'] ) ) {
				continue;
			}
			$ideas[] = array(
				'title'  => sanitize_text_field( $it['title'] ),
				'type'   => isset( $it['type'] ) ? sanitize_text_field( $it['type'] ) : 'Idea',
				'reason' => isset( $it['reason'] ) ? sanitize_text_field( $it['reason'] ) : '',
			);
		}
		return $ideas;
	}

	public static function ajax_generate() {
		check_ajax_referer( 'cp_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.', 403 );
		}
		$mode = ( isset( $_POST['mode'] ) && 'ai' === $_POST['mode'] ) ? 'ai' : 'basic';
		if ( 'ai' === $mode ) {
			$r = self::ai( self::context() );
			if ( is_wp_error( $r ) ) {
				wp_send_json_error( $r->get_error_message() );
			}
			wp_send_json_success( $r );
		}
		wp_send_json_success( self::basic() );
	}

	public static function ajax_draft() {
		check_ajax_referer( 'cp_ajax', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Permission denied.', 403 );
		}
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		if ( '' === $title ) {
			wp_send_json_error( 'Missing title.' );
		}
		$id = wp_insert_post( array( 'post_title' => $title, 'post_status' => 'draft', 'post_type' => 'post' ), true );
		if ( is_wp_error( $id ) ) {
			wp_send_json_error( $id->get_error_message() );
		}
		wp_send_json_success( array( 'edit' => get_edit_post_link( $id, 'raw' ) ) );
	}
}
