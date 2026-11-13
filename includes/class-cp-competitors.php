<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CP_Competitors {

	public static function urls() {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) CP_Helpers::opt( 'competitors' ) ) as $line ) {
			$u = esc_url_raw( trim( $line ), array( 'http', 'https' ) );
			if ( $u ) {
				$out[ untrailingslashit( $u ) ] = untrailingslashit( $u );
			}
		}
		return array_slice( array_values( $out ), 0, 5 );
	}

	public static function get() {
		$d = get_transient( 'cp_comp' );
		return is_array( $d ) ? $d : null;
	}

	private static function request( $url ) {
		$res = wp_safe_remote_get( $url, array( 'timeout' => 12, 'redirection' => 3, 'user-agent' => 'ContentPilot/' . CP_VERSION . '; ' . home_url() ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return '';
		}
		return wp_remote_retrieve_body( $res );
	}

	private static function parse_feed( $body ) {
		$items = array();
		if ( '' === $body ) {
			return $items;
		}
		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
		if ( ! $xml || ! isset( $xml->channel->item ) ) {
			return $items;
		}
		foreach ( $xml->channel->item as $it ) {
			$content = (string) $it->children( 'content', true )->encoded;
			if ( '' === $content ) {
				$content = (string) $it->description;
			}
			$cats = array();
			foreach ( $it->category as $c ) {
				$cats[] = sanitize_text_field( (string) $c );
			}
			$items[] = array(
				'title' => sanitize_text_field( (string) $it->title ),
				'url'   => esc_url_raw( (string) $it->link ),
				'ts'    => (int) strtotime( (string) $it->pubDate ),
				'words' => CP_Helpers::word_count( CP_Helpers::clean_text( $content ) ),
				'cats'  => $cats,
			);
		}
		return $items;
	}

	private static function from_sitemap( $base ) {
		$items = array();
		foreach ( array( '/wp-sitemap.xml', '/sitemap_index.xml', '/sitemap.xml' ) as $path ) {
			$body = self::request( $base . $path );
			if ( '' === $body ) {
				continue;
			}
			preg_match_all( '#<loc>\s*(.*?)\s*</loc>#i', $body, $m );
			$locs = $m[1];
			$subs = array_filter( $locs, function ( $l ) {
				return '.xml' === substr( $l, -4 );
			} );
			if ( $subs ) {
				$pick = '';
				foreach ( $subs as $s ) {
					if ( false !== stripos( $s, 'post' ) ) {
						$pick = $s;
						break;
					}
				}
				$pick = $pick ? $pick : reset( $subs );
				$body = self::request( esc_url_raw( $pick ) );
				preg_match_all( '#<loc>\s*(.*?)\s*</loc>#i', $body, $m2 );
				$locs = $m2[1];
			}
			foreach ( array_slice( $locs, 0, 50 ) as $loc ) {
				$slug    = basename( rtrim( wp_parse_url( $loc, PHP_URL_PATH ), '/' ) );
				$items[] = array( 'title' => sanitize_text_field( str_replace( '-', ' ', $slug ) ), 'url' => esc_url_raw( $loc ), 'ts' => 0, 'words' => 0, 'cats' => array() );
			}
			if ( $items ) {
				break;
			}
		}
		return $items;
	}

	private static function fetch_site( $base ) {
		$posts  = array();
		$source = 'feed';
		for ( $p = 1; $p <= 3; $p++ ) {
			$url   = $base . '/feed/' . ( $p > 1 ? '?paged=' . $p : '' );
			$items = self::parse_feed( self::request( $url ) );
			if ( ! $items ) {
				break;
			}
			$posts = array_merge( $posts, $items );
		}
		if ( ! $posts ) {
			$posts  = self::from_sitemap( $base );
			$source = 'sitemap';
		}
		return array( 'url' => $base, 'source' => $source, 'posts' => $posts, 'error' => $posts ? '' : 'Could not read a feed or sitemap from this site.' );
	}

	public static function refresh() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore
		}
		$rows  = CP_Analyzer::scan();
		$posts = array_filter( $rows, function ( $r ) {
			return 'post' === $r['type'];
		} );
		$own_words = $posts ? (int) round( array_sum( wp_list_pluck( $posts, 'words' ) ) / count( $posts ) ) : 0;
		$recent    = count( array_filter( $posts, function ( $r ) {
			return $r['ts'] > time() - 90 * DAY_IN_SECONDS;
		} ) );
		$own_pm    = round( $recent / 3, 1 );
		$own_topics = array_flip( CP_Analyzer::own_topics() );

		$sites = array();
		$gap   = array();
		$tips  = array();
		foreach ( self::urls() as $base ) {
			$s     = self::fetch_site( $base );
			$items = $s['posts'];
			$with  = array_filter( wp_list_pluck( $items, 'words' ) );
			$avg   = $with ? (int) round( array_sum( $with ) / count( $with ) ) : 0;
			$ts    = array_filter( wp_list_pluck( $items, 'ts' ) );
			$pm    = null;
			if ( count( $ts ) > 1 ) {
				$span = max( 14, max( $ts ) - min( $ts ) ) / ( 30 * DAY_IN_SECONDS );
				$pm   = round( count( $ts ) / max( 0.5, $span ), 1 );
			}
			$kw = CP_Helpers::keywords( implode( ' ', wp_list_pluck( $items, 'title' ) ), 20 );
			foreach ( $kw as $word => $n ) {
				if ( $n >= 2 && ! isset( $own_topics[ $word ] ) ) {
					$gap[ $word ] = ( isset( $gap[ $word ] ) ? $gap[ $word ] : 0 ) + $n;
				}
			}
			$cats = array();
			foreach ( $items as $it ) {
				foreach ( $it['cats'] as $c ) {
					$cats[ $c ] = ( isset( $cats[ $c ] ) ? $cats[ $c ] : 0 ) + 1;
				}
			}
			arsort( $cats );
			$s['avg_words'] = $avg;
			$s['per_month'] = $pm;
			$s['keywords']  = array_keys( $kw );
			$s['cats']      = array_slice( array_keys( $cats ), 0, 8 );
			$s['posts']     = array_slice( $items, 0, 10 );
			$s['count']     = count( $items );
			$sites[]        = $s;

			$host = wp_parse_url( $base, PHP_URL_HOST );
			if ( $avg && $own_words && $avg > $own_words * 1.2 ) {
				$tips[] = sprintf( '%s averages %d words per post vs your %d. Make key posts deeper (add sections, examples, FAQs).', $host, $avg, $own_words );
			}
			if ( $pm && $pm > $own_pm * 1.3 ) {
				$tips[] = sprintf( '%s publishes about %s posts/month vs your %s. Consider a more regular schedule.', $host, $pm, $own_pm );
			}
		}
		arsort( $gap );
		$gap = array_slice( array_keys( $gap ), 0, 20 );
		if ( $gap ) {
			$tips[] = 'Topics your competitors write about that you do not: ' . implode( ', ', array_slice( $gap, 0, 8 ) ) . '. Create content for the ones that fit your site.';
		}
		$tips[] = 'Use headings, FAQ sections, images with alt text and internal links in every post – then compare again next week.';

		$data = array( 'time' => time(), 'own_words' => $own_words, 'own_pm' => $own_pm, 'sites' => $sites, 'gap' => $gap, 'tips' => $tips );
		set_transient( 'cp_comp', $data, 6 * HOUR_IN_SECONDS );
		return $data;
	}
}
