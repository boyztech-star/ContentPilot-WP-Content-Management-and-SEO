<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CP_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_cp_rescan', array( __CLASS__, 'do_rescan' ) );
		add_action( 'admin_post_cp_refresh_comp', array( __CLASS__, 'do_refresh_comp' ) );
		add_action(
			'save_post',
			function () {
				delete_transient( 'cp_scan' );
			}
		);
		add_filter( 'plugin_action_links_' . plugin_basename( CP_FILE ), array( __CLASS__, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
	}

	private static function tabs() {
		return array(
			'overview'    => 'Content Analysis',
			'ideas'       => 'Content Ideas',
			'competitors' => 'Competitors',
			'images'      => 'Image SEO',
			'settings'    => 'Settings',
			'donate'      => '♥ Donate',
		);
	}

	private static function url( $tab, $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'contentpilot', 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	public static function menu() {
		add_menu_page( 'ContentPilot', 'ContentPilot', 'manage_options', 'contentpilot', array( __CLASS__, 'page' ), 'dashicons-chart-area', 58 );
	}

	public static function assets( $hook ) {
		if ( 'toplevel_page_contentpilot' === $hook ) {
			CP_Helpers::enqueue();
		}
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url( 'overview' ) ) . '">Open</a>' );
		return $links;
	}

	public static function row_meta( $links, $file ) {
		if ( plugin_basename( CP_FILE ) === $file ) {
			$links[] = '<a href="' . esc_url( self::url( 'donate' ) ) . '">♥ Donate</a>';
		}
		return $links;
	}

	public static function register() {
		register_setting(
			'cp_group',
			'cp_settings',
			array( 'type' => 'array', 'sanitize_callback' => array( __CLASS__, 'sanitize' ), 'default' => array() )
		);
	}

	public static function sanitize( $in ) {
		$old = get_option( 'cp_settings', array() );
		$out = is_array( $old ) ? $old : array();
		$in  = is_array( $in ) ? $in : array();

		$provider = isset( $in['ai_provider'] ) ? sanitize_key( $in['ai_provider'] ) : 'none';
		$out['ai_provider'] = in_array( $provider, array( 'none', 'anthropic', 'openai' ), true ) ? $provider : 'none';
		if ( ! empty( $in['ai_key'] ) ) {
			$out['ai_key'] = sanitize_text_field( $in['ai_key'] );
		}
		if ( 'none' === $out['ai_provider'] ) {
			$out['ai_key'] = '';
		}
		$out['ai_model']         = isset( $in['ai_model'] ) ? sanitize_text_field( $in['ai_model'] ) : '';
		$out['og_default_image'] = isset( $in['og_default_image'] ) ? esc_url_raw( $in['og_default_image'] ) : '';
		$out['twitter_handle']   = isset( $in['twitter_handle'] ) ? sanitize_text_field( ltrim( $in['twitter_handle'], '@' ) ) : '';
		$out['scan_limit']       = isset( $in['scan_limit'] ) ? min( 1000, max( 20, (int) $in['scan_limit'] ) ) : 300;
		foreach ( array( 'output_head', 'enable_schema', 'auto_alt' ) as $cb ) {
			$out[ $cb ] = ! empty( $in[ $cb ] ) ? 1 : 0;
		}
		delete_transient( 'cp_scan' );
		return $out;
	}

	public static function do_rescan() {
		check_admin_referer( 'cp_rescan' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permission denied.' );
		}
		delete_transient( 'cp_scan' );
		wp_safe_redirect( self::url( 'overview', array( 'rescanned' => 1 ) ) );
		exit;
	}

	public static function do_refresh_comp() {
		check_admin_referer( 'cp_refresh_comp' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permission denied.' );
		}
		$lines = array();
		$raw   = isset( $_POST['cp_competitors'] ) ? sanitize_textarea_field( wp_unslash( $_POST['cp_competitors'] ) ) : '';
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $l ) {
			$u = esc_url_raw( trim( $l ), array( 'http', 'https' ) );
			if ( $u ) {
				$lines[] = $u;
			}
		}
		$opts                = get_option( 'cp_settings', array() );
		$opts                = is_array( $opts ) ? $opts : array();
		$opts['competitors'] = implode( "\n", array_slice( array_unique( $lines ), 0, 5 ) );
		update_option( 'cp_settings', $opts );
		delete_transient( 'cp_comp' );
		if ( $opts['competitors'] ) {
			CP_Competitors::refresh();
		}
		wp_safe_redirect( self::url( 'competitors', array( 'done' => 1 ) ) );
		exit;
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tabs = self::tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'overview';
		}
		echo '<div class="wrap cp-wrap"><h1>ContentPilot</h1><nav class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $label ) {
			printf( '<a href="%s" class="nav-tab %s">%s</a>', esc_url( self::url( $k ) ), $k === $tab ? 'nav-tab-active' : '', esc_html( $label ) );
		}
		echo '</nav><div class="cp-tab-body">';
		call_user_func( array( __CLASS__, 'tab_' . $tab ) );
		echo '</div>';
		if ( 'donate' !== $tab ) {
			printf( '<p class="cp-foot">Free for personal use (see LICENSE.txt). <a href="%s">♥ Support ContentPilot</a></p>', esc_url( self::url( 'donate' ) ) );
		}
		echo '</div>';
	}

	/* ---------- Tabs ---------- */

	private static function tab_overview() {
		if ( isset( $_GET['rescanned'] ) ) { // phpcs:ignore
			echo '<div class="notice notice-success inline"><p>Content re-scanned.</p></div>';
		}
		$rows = CP_Analyzer::scan();
		if ( ! $rows ) {
			echo '<p>No published posts or pages found yet.</p>';
			return;
		}
		$n       = count( $rows );
		$avg     = (int) round( array_sum( wp_list_pluck( $rows, 'score' ) ) / $n );
		$words   = (int) round( array_sum( wp_list_pluck( $rows, 'words' ) ) / $n );
		$missing = array_sum( wp_list_pluck( $rows, 'missing_alt' ) );
		$nodesc  = count( array_filter( $rows, function ( $r ) { return ! $r['has_desc']; } ) );
		$old     = count( array_filter( $rows, function ( $r ) { return $r['days'] > 540; } ) );
		$thin    = count( array_filter( $rows, function ( $r ) { return $r['words'] < 300; } ) );

		echo '<div class="cp-cards">';
		$cards = array(
			array( $avg . '/100', 'Average content score' ),
			array( $n, 'Posts & pages scanned' ),
			array( $words, 'Average word count' ),
			array( $thin, 'Thin pages (<300 words)' ),
			array( $missing, 'Images missing alt text' ),
			array( $nodesc, 'Without meta description' ),
			array( $old, 'Not updated 18+ months' ),
		);
		foreach ( $cards as $c ) {
			printf( '<div class="cp-stat"><b>%s</b><span>%s</span></div>', esc_html( $c[0] ), esc_html( $c[1] ) );
		}
		echo '</div>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="cp_rescan">';
		wp_nonce_field( 'cp_rescan' );
		echo '<p><button class="button">Re-scan now</button> <span class="description">Results are cached for one hour.</span></p></form>';

		usort( $rows, function ( $a, $b ) { return $a['score'] <=> $b['score']; } );
		echo '<h2>Pages that need the most work</h2><table class="widefat striped"><thead><tr><th>Title</th><th>Score</th><th>Words</th><th>Issues</th></tr></thead><tbody>';
		foreach ( array_slice( $rows, 0, 25 ) as $r ) {
			printf(
				'<tr><td><a href="%s">%s</a></td><td><span class="cp-score cp-s%d">%d</span></td><td>%d</td><td>%s</td></tr>',
				esc_url( get_edit_post_link( $r['id'] ) ),
				esc_html( $r['title'] ),
				$r['score'] >= 80 ? 3 : ( $r['score'] >= 60 ? 2 : 1 ),
				(int) $r['score'],
				(int) $r['words'],
				esc_html( implode( ' · ', $r['issues'] ) )
			);
		}
		echo '</tbody></table>';

		$cats = CP_Analyzer::category_stats( $rows );
		if ( $cats ) {
			echo '<h2>Category coverage</h2><table class="widefat striped"><thead><tr><th>Category</th><th>Posts</th><th>Avg words</th><th>Avg score</th><th>Last post</th></tr></thead><tbody>';
			foreach ( $cats as $c ) {
				printf(
					'<tr><td>%s</td><td>%d</td><td>%d</td><td>%d</td><td>%s</td></tr>',
					esc_html( $c['name'] ),
					(int) $c['count'],
					(int) $c['avg_words'],
					(int) $c['avg_score'],
					esc_html( $c['last'] ? wp_date( get_option( 'date_format' ), $c['last'] ) : '–' )
				);
			}
			echo '</tbody></table>';
		}
	}

	private static function tab_ideas() {
		$has_ai = in_array( CP_Helpers::opt( 'ai_provider' ), array( 'anthropic', 'openai' ), true ) && CP_Helpers::opt( 'ai_key' );
		?>
		<p>Ideas are built from your categories, most-used keywords, old posts and (if you ran a comparison) competitor gaps.</p>
		<p>
			<button class="button button-primary" id="cp-gen-basic">Generate ideas (free, built-in)</button>
			<button class="button" id="cp-gen-ai" <?php disabled( ! $has_ai ); ?>>Generate with AI</button>
			<?php if ( ! $has_ai ) : ?>
				<span class="description">Optional: add your own AI key in <a href="<?php echo esc_url( self::url( 'settings' ) ); ?>">Settings</a> for smarter ideas.</span>
			<?php endif; ?>
		</p>
		<div id="cp-ideas-msg"></div>
		<ul id="cp-ideas-out" class="cp-ideas"></ul>
		<?php
	}

	private static function tab_competitors() {
		if ( isset( $_GET['done'] ) ) { // phpcs:ignore
			echo '<div class="notice notice-success inline"><p>Competitors saved and compared.</p></div>';
		}
		$urls = implode( "\n", CP_Competitors::urls() );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="cp_refresh_comp">
			<?php wp_nonce_field( 'cp_refresh_comp' ); ?>
			<p><label for="cp_competitors"><b>Competitor websites</b> (one per line, up to 5)</label></p>
			<textarea id="cp_competitors" name="cp_competitors" rows="5" class="large-text code" placeholder="https://example.com"><?php echo esc_textarea( $urls ); ?></textarea>
			<p><button class="button button-primary">Save &amp; compare</button> <span class="description">Reads each site's public RSS feed (or sitemap) – no login needed. May take up to a minute.</span></p>
		</form>
		<?php
		$d = CP_Competitors::get();
		if ( ! $d ) {
			echo '<p>No comparison yet. Add competitor sites above.</p>';
			return;
		}
		echo '<p class="description">Last compared: ' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $d['time'] ) ) . '</p>';

		echo '<h2>You vs competitors</h2><table class="widefat striped"><thead><tr><th>Site</th><th>Posts sampled</th><th>Avg words/post</th><th>Posts per month</th><th>Top topics</th></tr></thead><tbody>';
		printf( '<tr><td><b>Your site</b></td><td>–</td><td>%d</td><td>%s</td><td>–</td></tr>', (int) $d['own_words'], esc_html( $d['own_pm'] ) );
		foreach ( $d['sites'] as $s ) {
			printf(
				'<tr><td>%s</td><td>%d</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( wp_parse_url( $s['url'], PHP_URL_HOST ) ),
				(int) $s['count'],
				$s['avg_words'] ? (int) $s['avg_words'] : 'n/a',
				null !== $s['per_month'] ? esc_html( $s['per_month'] ) : 'n/a',
				esc_html( $s['error'] ? $s['error'] : implode( ', ', array_slice( $s['keywords'], 0, 8 ) ) )
			);
		}
		echo '</tbody></table>';

		if ( $d['tips'] ) {
			echo '<h2>Suggested improvements</h2><ul class="cp-tips">';
			foreach ( $d['tips'] as $t ) {
				echo '<li>' . esc_html( $t ) . '</li>';
			}
			echo '</ul>';
		}
		if ( $d['gap'] ) {
			echo '<h2>Content gap keywords</h2><p>';
			foreach ( $d['gap'] as $g ) {
				echo '<span class="cp-chip">' . esc_html( $g ) . '</span> ';
			}
			echo '</p>';
		}
		foreach ( $d['sites'] as $s ) {
			if ( ! $s['posts'] ) {
				continue;
			}
			echo '<h3>' . esc_html( wp_parse_url( $s['url'], PHP_URL_HOST ) ) . ' – latest posts</h3><ul class="cp-list">';
			foreach ( $s['posts'] as $p ) {
				printf(
					'<li><a href="%s" target="_blank" rel="noopener noreferrer">%s</a> <span class="description">%s%s</span></li>',
					esc_url( $p['url'] ),
					esc_html( $p['title'] ),
					$p['ts'] ? esc_html( wp_date( get_option( 'date_format' ), $p['ts'] ) ) : '',
					$p['words'] ? ' · ' . (int) $p['words'] . ' words' : ''
				);
			}
			echo '</ul>';
		}
	}

	private static function tab_images() {
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore
			printf( '<div class="notice notice-success inline"><p>%d alt text(s) saved.</p></div>', (int) $_GET['saved'] ); // phpcs:ignore
		}
		$paged = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore
		$q     = CP_Images::missing_query( $paged );
		printf( '<p><b>%d</b> image(s) in your media library have no alt text. Suggestions are pre-filled – edit them, then save.</p>', (int) $q->found_posts );
		if ( ! $q->have_posts() ) {
			echo '<p>🎉 All images have alt text.</p>';
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="cp_save_alts">';
		wp_nonce_field( 'cp_save_alts' );
		echo '<table class="widefat striped"><thead><tr><th>Image</th><th>File</th><th>Alt text</th></tr></thead><tbody>';
		foreach ( $q->posts as $att ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td><input type="text" class="large-text" name="alt[%d]" value="%s"></td></tr>',
				wp_get_attachment_image( $att->ID, array( 60, 60 ) ), // phpcs:ignore
				esc_html( basename( (string) get_attached_file( $att->ID ) ) ),
				(int) $att->ID,
				esc_attr( CP_Images::suggest( $att ) )
			);
		}
		echo '</tbody></table><p><button class="button button-primary">Save alt text</button></p></form>';
		$links = paginate_links( array( 'base' => add_query_arg( 'paged', '%#%', self::url( 'images' ) ), 'format' => '', 'current' => $paged, 'total' => (int) $q->max_num_pages ) );
		if ( $links ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $links ) . '</div></div>';
		}
		wp_reset_postdata();
	}

	private static function tab_settings() {
		$o = wp_parse_args( get_option( 'cp_settings', array() ), CP_Helpers::defaults() );
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'cp_group' ); ?>
			<h2>SEO &amp; social output</h2>
			<table class="form-table" role="presentation">
				<tr><th>Output meta tags</th><td><label><input type="checkbox" name="cp_settings[output_head]" value="1" <?php checked( $o['output_head'] ); ?>> Add meta description, Open Graph &amp; Twitter tags</label><p class="description">Automatically turned off when Yoast, Rank Math, AIOSEO or SEOPress is active, to avoid duplicates.</p></td></tr>
				<tr><th>Article schema</th><td><label><input type="checkbox" name="cp_settings[enable_schema]" value="1" <?php checked( $o['enable_schema'] ); ?>> Add Article structured data (JSON-LD) to posts</label></td></tr>
				<tr><th>Default share image</th><td><input type="text" class="regular-text" id="cp_default_img" name="cp_settings[og_default_image]" value="<?php echo esc_attr( $o['og_default_image'] ); ?>"> <button type="button" class="button cp-pick" data-target="#cp_default_img">Choose</button><p class="description">Used when a post has no custom or featured image. 1200×630 works best.</p></td></tr>
				<tr><th>Twitter / X username</th><td><input type="text" class="regular-text" name="cp_settings[twitter_handle]" value="<?php echo esc_attr( $o['twitter_handle'] ); ?>" placeholder="yourhandle"></td></tr>
				<tr><th>Auto alt text</th><td><label><input type="checkbox" name="cp_settings[auto_alt]" value="1" <?php checked( $o['auto_alt'] ); ?>> Create alt text from the file name when an image is uploaded</label></td></tr>
				<tr><th>Scan limit</th><td><input type="number" min="20" max="1000" name="cp_settings[scan_limit]" value="<?php echo (int) $o['scan_limit']; ?>"><p class="description">Maximum number of posts/pages analyzed.</p></td></tr>
			</table>
			<h2>AI ideas (optional)</h2>
			<p>The built-in idea generator is free and needs nothing. For smarter ideas, add your own API key – you pay your AI provider directly.</p>
			<table class="form-table" role="presentation">
				<tr><th>Provider</th><td><select name="cp_settings[ai_provider]">
					<option value="none" <?php selected( $o['ai_provider'], 'none' ); ?>>None</option>
					<option value="anthropic" <?php selected( $o['ai_provider'], 'anthropic' ); ?>>Anthropic (Claude)</option>
					<option value="openai" <?php selected( $o['ai_provider'], 'openai' ); ?>>OpenAI</option>
				</select></td></tr>
				<tr><th>API key</th><td><input type="password" class="regular-text" name="cp_settings[ai_key]" value="" autocomplete="new-password" placeholder="<?php echo $o['ai_key'] ? esc_attr( '•••••••• (saved)' ) : ''; ?>"><p class="description">Leave blank to keep the saved key. Set provider to “None” to remove it.</p></td></tr>
				<tr><th>Model (optional)</th><td><input type="text" class="regular-text" name="cp_settings[ai_model]" value="<?php echo esc_attr( $o['ai_model'] ); ?>"><p class="description">Leave empty for the default model.</p></td></tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	private static function tab_donate() {
		echo '<div class="cp-donate"><h2>♥ Support ContentPilot</h2>';
		echo '<p>ContentPilot is 100% free with every feature unlocked. If it saves you time or helps your site grow, a small donation helps keep it maintained and improving.</p>';
		$links = CP_Helpers::donate_links();
		if ( $links ) {
			echo '<p>';
			foreach ( $links as $l ) {
				printf( '<a class="button button-primary button-hero" href="%s" target="_blank" rel="noopener noreferrer">%s</a> ', esc_url( $l['url'] ), esc_html( $l['label'] ) );
			}
			echo '</p>';
		}
		if ( CP_DONATE_WALLET ) {
			echo '<p><b>Mobile wallet:</b> <code>' . esc_html( CP_DONATE_WALLET ) . '</code></p>';
		}
		if ( ! $links && ! CP_DONATE_WALLET ) {
			echo '<p class="description">Donation links are not configured yet. (Plugin author: edit the CP_DONATE_* constants at the top of contentpilot.php.)</p>';
		}
		echo '<p>Other ways to help: leave a 5★ review, share the plugin, or send feature ideas. Thank you! 🙏</p></div>';
	}
}
