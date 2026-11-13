<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CP_SEO {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'wp_head', array( __CLASS__, 'head' ), 1 );
		add_filter( 'pre_get_document_title', array( __CLASS__, 'title' ), 20 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_cp_suggest_tags', array( __CLASS__, 'ajax_suggest_tags' ) );
		add_action( 'wp_ajax_cp_apply_tags', array( __CLASS__, 'ajax_apply_tags' ) );
	}

	private static function external_seo() {
		return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' );
	}

	private static function should_output() {
		return (bool) CP_Helpers::opt( 'output_head' ) && ! self::external_seo();
	}

	private static function post_types() {
		$types = get_post_types( array( 'public' => true ) );
		unset( $types['attachment'] );
		return array_values( $types );
	}

	public static function add_box() {
		foreach ( self::post_types() as $pt ) {
			add_meta_box( 'cp_seo_box', 'ContentPilot – SEO & Social', array( __CLASS__, 'box' ), $pt, 'normal', 'high' );
		}
	}

	public static function assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		global $post;
		$thumb = ( $post && $post->ID ) ? (string) get_the_post_thumbnail_url( $post->ID, 'large' ) : '';
		CP_Helpers::enqueue( array( 'thumb' => $thumb ) );
	}

	public static function box( $post ) {
		wp_nonce_field( 'cp_save_meta', 'cp_meta_nonce' );
		$v = function ( $k ) use ( $post ) {
			return (string) get_post_meta( $post->ID, $k, true );
		};
		?>
		<div class="cp-box" data-post-id="<?php echo (int) $post->ID; ?>">
			<div class="cp-tabs">
				<button type="button" class="cp-tab active" data-tab="seo">SEO</button>
				<button type="button" class="cp-tab" data-tab="social">Social preview</button>
				<button type="button" class="cp-tab" data-tab="check">Analysis &amp; tags</button>
			</div>

			<div class="cp-pane active" data-pane="seo">
				<p><label>Focus keyword<br><input type="text" class="widefat" id="cp_kw" name="_cp_kw" value="<?php echo esc_attr( $v( '_cp_kw' ) ); ?>"></label></p>
				<p><label>SEO title <span class="cp-count" data-for="cp_title" data-min="30" data-max="60"></span><br><input type="text" class="widefat" id="cp_title" name="_cp_title" value="<?php echo esc_attr( $v( '_cp_title' ) ); ?>" placeholder="Leave empty to use the post title"></label></p>
				<p><label>Meta description <span class="cp-count" data-for="cp_desc" data-min="70" data-max="160"></span><br><textarea class="widefat" rows="3" id="cp_desc" name="_cp_desc"><?php echo esc_textarea( $v( '_cp_desc' ) ); ?></textarea></label></p>
				<p><label><input type="checkbox" name="_cp_noindex" value="1" <?php checked( $v( '_cp_noindex' ), '1' ); ?>> Hide this page from search engines (noindex)</label></p>
				<div class="cp-serp">
					<div class="cp-serp-url"></div>
					<div class="cp-serp-title"></div>
					<div class="cp-serp-desc"></div>
				</div>
			</div>

			<div class="cp-pane" data-pane="social">
				<p><label>Social title<br><input type="text" class="widefat" id="cp_og_title" name="_cp_og_title" value="<?php echo esc_attr( $v( '_cp_og_title' ) ); ?>" placeholder="Defaults to SEO title"></label></p>
				<p><label>Social description<br><textarea class="widefat" rows="2" id="cp_og_desc" name="_cp_og_desc" placeholder="Defaults to meta description"><?php echo esc_textarea( $v( '_cp_og_desc' ) ); ?></textarea></label></p>
				<p><label>Preview image URL (recommended 1200×630)<br>
					<input type="text" class="widefat" id="cp_og_image" name="_cp_og_image" value="<?php echo esc_attr( $v( '_cp_og_image' ) ); ?>" placeholder="Falls back to featured image, then site default"></label>
					<button type="button" class="button cp-pick" data-target="#cp_og_image">Choose image</button>
					<span class="cp-img-note"></span></p>
				<p class="cp-platforms">
					<label><input type="radio" name="cp_platform" value="cp-fb" checked> Facebook</label>
					<label><input type="radio" name="cp_platform" value="cp-x"> X / Twitter</label>
					<label><input type="radio" name="cp_platform" value="cp-li"> LinkedIn</label>
					<label><input type="radio" name="cp_platform" value="cp-wa"> WhatsApp</label>
				</p>
				<div class="cp-card cp-fb" id="cp-card">
					<div class="cp-card-img"></div>
					<div class="cp-card-body">
						<div class="cp-card-domain"></div>
						<div class="cp-card-title"></div>
						<div class="cp-card-desc"></div>
					</div>
				</div>
			</div>

			<div class="cp-pane" data-pane="check">
				<p><button type="button" class="button button-primary" id="cp-run-checks">Analyze this content</button></p>
				<ul class="cp-checks"></ul>
				<hr>
				<p><strong>Tag suggestions</strong> – based on the words used most in this content.</p>
				<p><button type="button" class="button" id="cp-suggest-tags">Suggest tags</button></p>
				<div class="cp-tags"></div>
				<p><button type="button" class="button button-secondary" id="cp-apply-tags" style="display:none">Add selected tags to this post</button> <span class="cp-tags-msg"></span></p>
			</div>
		</div>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['cp_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cp_meta_nonce'] ) ), 'cp_save_meta' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$text  = array( '_cp_title', '_cp_kw', '_cp_og_title' );
		$areas = array( '_cp_desc', '_cp_og_desc' );
		foreach ( $text as $k ) {
			$val = isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : '';
			'' === $val ? delete_post_meta( $post_id, $k ) : update_post_meta( $post_id, $k, $val );
		}
		foreach ( $areas as $k ) {
			$val = isset( $_POST[ $k ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $k ] ) ) : '';
			'' === $val ? delete_post_meta( $post_id, $k ) : update_post_meta( $post_id, $k, $val );
		}
		$img = isset( $_POST['_cp_og_image'] ) ? esc_url_raw( wp_unslash( $_POST['_cp_og_image'] ) ) : '';
		'' === $img ? delete_post_meta( $post_id, '_cp_og_image' ) : update_post_meta( $post_id, '_cp_og_image', $img );
		isset( $_POST['_cp_noindex'] ) ? update_post_meta( $post_id, '_cp_noindex', '1' ) : delete_post_meta( $post_id, '_cp_noindex' );
	}

	private static function meta( $id, $key ) {
		return (string) get_post_meta( $id, $key, true );
	}

	public static function title( $title ) {
		if ( ! self::should_output() || ! is_singular() ) {
			return $title;
		}
		$t = self::meta( get_queried_object_id(), '_cp_title' );
		return '' !== $t ? $t : $title;
	}

	public static function robots( $robots ) {
		if ( self::should_output() && is_singular() && '1' === self::meta( get_queried_object_id(), '_cp_noindex' ) ) {
			$robots['noindex'] = true;
			unset( $robots['max-image-preview'] );
		}
		return $robots;
	}

	public static function head() {
		if ( ! self::should_output() ) {
			return;
		}
		$type = 'website';
		$img  = (string) CP_Helpers::opt( 'og_default_image' );

		if ( is_singular() ) {
			$id    = get_queried_object_id();
			$p     = get_post( $id );
			$title = self::meta( $id, '_cp_title' );
			$title = '' !== $title ? $title : get_the_title( $id );
			$desc  = self::meta( $id, '_cp_desc' );
			if ( '' === $desc ) {
				$desc = '' !== trim( $p->post_excerpt ) ? wp_strip_all_tags( $p->post_excerpt ) : wp_trim_words( CP_Helpers::clean_text( $p->post_content ), 30, '' );
			}
			$og_title = self::meta( $id, '_cp_og_title' );
			$og_title = '' !== $og_title ? $og_title : $title;
			$og_desc  = self::meta( $id, '_cp_og_desc' );
			$og_desc  = '' !== $og_desc ? $og_desc : $desc;
			$custom   = self::meta( $id, '_cp_og_image' );
			if ( '' !== $custom ) {
				$img = $custom;
			} elseif ( has_post_thumbnail( $id ) ) {
				$img = (string) get_the_post_thumbnail_url( $id, 'large' );
			}
			$url  = get_permalink( $id );
			$type = ( 'post' === $p->post_type ) ? 'article' : 'website';
		} elseif ( is_front_page() || is_home() ) {
			$og_title = get_bloginfo( 'name' );
			$desc     = get_bloginfo( 'description' );
			$og_desc  = $desc;
			$url      = home_url( '/' );
		} else {
			return;
		}

		echo "\n<!-- ContentPilot -->\n";
		if ( '' !== $desc ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
		}
		echo '<meta property="og:type" content="' . esc_attr( $type ) . '">' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( $og_title ) . '">' . "\n";
		if ( '' !== $og_desc ) {
			echo '<meta property="og:description" content="' . esc_attr( $og_desc ) . '">' . "\n";
		}
		echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
		echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '">' . "\n";
		if ( '' !== $img ) {
			echo '<meta property="og:image" content="' . esc_url( $img ) . '">' . "\n";
		}
		echo '<meta name="twitter:card" content="' . ( '' !== $img ? 'summary_large_image' : 'summary' ) . '">' . "\n";
		echo '<meta name="twitter:title" content="' . esc_attr( $og_title ) . '">' . "\n";
		if ( '' !== $og_desc ) {
			echo '<meta name="twitter:description" content="' . esc_attr( $og_desc ) . '">' . "\n";
		}
		if ( '' !== $img ) {
			echo '<meta name="twitter:image" content="' . esc_url( $img ) . '">' . "\n";
		}
		$handle = ltrim( (string) CP_Helpers::opt( 'twitter_handle' ), '@' );
		if ( '' !== $handle ) {
			echo '<meta name="twitter:site" content="@' . esc_attr( $handle ) . '">' . "\n";
		}

		if ( CP_Helpers::opt( 'enable_schema' ) && is_singular( 'post' ) ) {
			$schema = array(
				'@context'         => 'https://schema.org',
				'@type'            => 'Article',
				'headline'         => wp_strip_all_tags( $og_title ),
				'description'      => wp_strip_all_tags( $desc ),
				'datePublished'    => get_the_date( 'c', $id ),
				'dateModified'     => get_the_modified_date( 'c', $id ),
				'mainEntityOfPage' => $url,
				'author'           => array( '@type' => 'Person', 'name' => get_the_author_meta( 'display_name', $p->post_author ) ),
				'publisher'        => array( '@type' => 'Organization', 'name' => get_bloginfo( 'name' ) ),
			);
			if ( '' !== $img ) {
				$schema['image'] = $img;
			}
			echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo "<!-- /ContentPilot -->\n";
	}

	public static function ajax_suggest_tags() {
		check_ajax_referer( 'cp_ajax', 'nonce' );
		$id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( 'Permission denied.', 403 );
		}
		$content = isset( $_POST['content'] ) ? substr( wp_unslash( $_POST['content'] ), 0, 200000 ) : ''; // phpcs:ignore
		$title   = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$kw      = CP_Helpers::keywords( $title . ' ' . $title . ' ' . $content, 14, 4 );
		wp_send_json_success( array_keys( $kw ) );
	}

	public static function ajax_apply_tags() {
		check_ajax_referer( 'cp_ajax', 'nonce' );
		$id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		if ( ! $id || ! current_user_can( 'edit_post', $id ) || ! is_object_in_taxonomy( get_post_type( $id ), 'post_tag' ) ) {
			wp_send_json_error( 'Cannot add tags to this item.', 403 );
		}
		$tags = isset( $_POST['tags'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['tags'] ) ) : array();
		$tags = array_slice( array_filter( $tags ), 0, 15 );
		wp_set_post_tags( $id, $tags, true );
		wp_send_json_success( count( $tags ) );
	}
}
