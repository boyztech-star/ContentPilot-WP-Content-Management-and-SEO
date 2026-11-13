<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CP_Analyzer {

	public static function analyze( $post ) {
		$html  = $post->post_content;
		$text  = CP_Helpers::clean_text( $html );
		$words = CP_Helpers::word_count( $text );
		$id    = $post->ID;

		preg_match_all( '/<h[2-4][\s>]/i', $html, $hm );
		$headings = count( $hm[0] );

		preg_match_all( '/<img\b[^>]*>/i', $html, $im );
		$images  = count( $im[0] );
		$missing = 0;
		foreach ( $im[0] as $tag ) {
			if ( ! preg_match( '/\balt\s*=\s*(["\'])(.*?)\1/i', $tag, $a ) || '' === trim( $a[2] ) ) {
				$missing++;
			}
		}

		$host     = wp_parse_url( home_url(), PHP_URL_HOST );
		$internal = 0;
		preg_match_all( '/<a\b[^>]*href\s*=\s*(["\'])(.*?)\1/i', $html, $lm );
		foreach ( $lm[2] as $href ) {
			if ( 0 === strpos( $href, '#' ) || 0 === strpos( $href, 'mailto:' ) || 0 === strpos( $href, 'tel:' ) ) {
				continue;
			}
			$h = wp_parse_url( $href, PHP_URL_HOST );
			if ( ! $h || $h === $host ) {
				$internal++;
			}
		}

		$has_desc  = '' !== (string) get_post_meta( $id, '_cp_desc', true ) || '' !== trim( $post->post_excerpt );
		$has_thumb = has_post_thumbnail( $id );
		$kw        = (string) get_post_meta( $id, '_cp_kw', true );
		$days      = (int) floor( ( time() - strtotime( $post->post_modified_gmt . ' UTC' ) ) / DAY_IN_SECONDS );

		$score  = 100;
		$issues = array();
		if ( $words < 300 ) {
			$score   -= 25;
			$issues[] = 'Thin content (' . $words . ' words)';
		} elseif ( $words < 600 && 'post' === $post->post_type ) {
			$score   -= 8;
			$issues[] = 'Short for a blog post (' . $words . ' words)';
		}
		if ( 0 === $headings && $words > 300 ) {
			$score   -= 10;
			$issues[] = 'No subheadings';
		}
		if ( 0 === $images && ! $has_thumb ) {
			$score   -= 10;
			$issues[] = 'No images';
		}
		if ( $missing > 0 ) {
			$score   -= 10;
			$issues[] = $missing . ' image(s) missing alt text';
		}
		if ( 0 === $internal && $words > 300 ) {
			$score   -= 10;
			$issues[] = 'No internal links';
		}
		if ( ! $has_desc ) {
			$score   -= 10;
			$issues[] = 'No meta description';
		}
		if ( ! $has_thumb ) {
			$score   -= 5;
			$issues[] = 'No featured image (weak social preview)';
		}
		if ( '' === $kw ) {
			$score   -= 5;
			$issues[] = 'No focus keyword';
		} elseif ( false === strpos( CP_Helpers::lower( $post->post_title ), CP_Helpers::lower( $kw ) ) ) {
			$score   -= 5;
			$issues[] = 'Focus keyword not in title';
		}
		if ( $days > 540 ) {
			$score   -= 10;
			$issues[] = 'Not updated for ' . $days . ' days';
		}

		return array(
			'id'          => $id,
			'title'       => get_the_title( $post ),
			'type'        => $post->post_type,
			'ts'          => strtotime( $post->post_date_gmt . ' UTC' ),
			'words'       => $words,
			'headings'    => $headings,
			'images'      => $images,
			'missing_alt' => $missing,
			'internal'    => $internal,
			'has_desc'    => $has_desc,
			'has_thumb'   => $has_thumb,
			'days'        => $days,
			'score'       => max( 0, $score ),
			'issues'      => $issues,
		);
	}

	public static function scan() {
		$cached = get_transient( 'cp_scan' );
		if ( false !== $cached ) {
			return $cached;
		}
		$posts = get_posts(
			array(
				'post_type'        => array( 'post', 'page' ),
				'post_status'      => 'publish',
				'numberposts'      => max( 10, (int) CP_Helpers::opt( 'scan_limit' ) ),
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => true,
			)
		);
		$rows  = array();
		foreach ( $posts as $p ) {
			$rows[] = self::analyze( $p );
		}
		set_transient( 'cp_scan', $rows, HOUR_IN_SECONDS );
		return $rows;
	}

	public static function category_stats( $rows ) {
		$out = array();
		foreach ( $rows as $r ) {
			if ( 'post' !== $r['type'] ) {
				continue;
			}
			foreach ( wp_get_post_categories( $r['id'], array( 'fields' => 'all' ) ) as $c ) {
				if ( ! isset( $out[ $c->term_id ] ) ) {
					$out[ $c->term_id ] = array( 'name' => $c->name, 'slug' => $c->slug, 'count' => 0, 'words' => 0, 'score' => 0, 'last' => 0 );
				}
				$out[ $c->term_id ]['count']++;
				$out[ $c->term_id ]['words'] += $r['words'];
				$out[ $c->term_id ]['score'] += $r['score'];
				$out[ $c->term_id ]['last']   = max( $out[ $c->term_id ]['last'], $r['ts'] );
			}
		}
		foreach ( $out as &$o ) {
			$o['avg_words'] = (int) round( $o['words'] / $o['count'] );
			$o['avg_score'] = (int) round( $o['score'] / $o['count'] );
		}
		unset( $o );
		uasort(
			$out,
			function ( $a, $b ) {
				return $b['count'] <=> $a['count'];
			}
		);
		return $out;
	}

	/** Keywords describing what the site already covers. */
	public static function own_topics() {
		$text = implode( ' ', wp_list_pluck( self::scan(), 'title' ) );
		$terms = get_terms( array( 'taxonomy' => array( 'category', 'post_tag' ), 'hide_empty' => true, 'number' => 200 ) );
		if ( ! is_wp_error( $terms ) ) {
			$text .= ' ' . implode( ' ', wp_list_pluck( $terms, 'name' ) );
		}
		return array_keys( CP_Helpers::keywords( $text, 400 ) );
	}
}
