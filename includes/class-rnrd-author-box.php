<?php
/**
 * Author Box — profile fields, Person schema, renderers.
 *
 * Every profile field mapped here emits Schema.org Person data for EEAT.
 * When a major SEO plugin is active, Person data is merged into its schema graph
 * via this class's filters (rank_math/json_ld, wpseo_schema_graph, …). Article
 * enrichment (speakable, abstract, reviewedBy, …) is handled by RNRD_Schema.
 *
 * @package RankReady
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Author_Box {

	/**
	 * Frontend output for the Author Box (block, widget, shortcode, auto-display).
	 * Profile fields and Person schema stay available when this is off.
	 */
	public static function is_enabled(): bool {
		return 'on' === get_option( RNRD_OPT_AUTHOR_ENABLE, 'on' );
	}

	/**
	 * Whether Author Box auto-display and the Trust panel apply to this post type.
	 */
	public static function is_post_type_enabled( string $post_type ): bool {
		$types = array_values( array_filter( (array) get_option( RNRD_OPT_AUTHOR_POST_TYPES, array( 'post' ) ) ) );
		return in_array( $post_type, $types, true );
	}

	// ── All profile field meta keys (user meta) ──────────────────────────────
	// Kept as a single map so register_meta(), render(), schema build, and
	// uninstall all read from the same source of truth.
	const META_KEYS = array(
		// Identity & work.
		'rnrd_author_job_title',
		'rnrd_author_employer',
		'rnrd_author_employer_url',
		'rnrd_author_bio',
		'rnrd_author_headshot',
		'rnrd_author_headshot_alt',
		// Experience.
		'rnrd_author_started_year',
		'rnrd_author_expertise',
		// Credentials.
		'rnrd_author_credentials_suffix',
		'rnrd_author_education',          // repeater JSON
		'rnrd_author_certifications',     // repeater JSON
		'rnrd_author_memberships',        // repeater JSON
		'rnrd_author_awards',             // repeater JSON
		// Verified identity (priority sameAs).
		'rnrd_author_wikidata',
		'rnrd_author_wikipedia',
		'rnrd_author_orcid',
		'rnrd_author_scholar',
		'rnrd_author_linkedin',
		// Social (lower priority sameAs).
		'rnrd_author_github',
		'rnrd_author_youtube',
		'rnrd_author_twitter',
		'rnrd_author_website',
		// Contact.
		'rnrd_author_contact_url',
	);

	// ── Init ──────────────────────────────────────────────────────────────────

	public static function init(): void {
		// Register all user meta as single, REST-exposed so block editor can read them.
		add_action( 'init', array( self::class, 'register_user_meta' ) );

		// Register per-post meta keys (fact-checked / reviewed / last reviewed / disable).
		add_action( 'init', array( self::class, 'register_post_meta' ) );

		// Profile page — the "RankReady Author Box" section on user-edit.php.
		add_action( 'show_user_profile',        array( self::class, 'render_profile_fields' ), 20 );
		add_action( 'edit_user_profile',        array( self::class, 'render_profile_fields' ), 20 );
		add_action( 'personal_options_update',  array( self::class, 'save_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( self::class, 'save_profile_fields' ) );

		// Block + widget — register server-side renderer (block registered in class-rnrd-block.php).
		// Auto-display via the_content.
		add_filter( 'the_content', array( self::class, 'maybe_auto_display' ), 101 );

		// Standalone Person schema on is_author() archives (wp_head — no visible template override).
		add_action( 'wp_head', array( self::class, 'maybe_inject_archive_person' ), 2 );

		// Merge into SEO plugin Article.author — upgrade the Person with sameAs / knowsAbout / credentials.
		// Covers: Rank Math, Yoast SEO, AIOSEO, SEOPress (free + Pro), The SEO Framework, Slim SEO.
		add_filter( 'rank_math/json_ld',                   array( self::class, 'merge_into_rankmath' ), 100, 2 );
		add_filter( 'wpseo_schema_graph',                  array( self::class, 'merge_into_yoast' ),    100 );
		add_filter( 'aioseo_schema_output',                array( self::class, 'merge_into_aioseo' ),   100 );
		add_filter( 'seopress_schemas_auto_article_json',  array( self::class, 'merge_into_seopress' ), 100 );
		add_filter( 'seopress_pro_get_json_data_article',  array( self::class, 'merge_into_seopress' ), 100 );
		add_filter( 'the_seo_framework_schema_graph_data', array( self::class, 'merge_into_tsf' ),      100 );
		add_filter( 'slim_seo_schema_graph',               array( self::class, 'merge_into_slim_seo' ), 100 );
		// v1.2.0 — Squirrly SEO (sq_json_ld_data); priority 100 runs after the
		// Article merge so the author Person node is upgraded last.
		add_filter( 'sq_json_ld_data',                     array( self::class, 'merge_into_squirrly' ), 100 );

		// reviewedBy + lastReviewed into Article schema (all SEO plugins).
		// Piggybacks on RNRD_Schema's Article merge path by providing data through filter.
	}

	// ══════════════════════════════════════════════════════════════════════════
	// META REGISTRATION
	// ══════════════════════════════════════════════════════════════════════════

	public static function register_user_meta(): void {
		$text_keys = array_diff(
			self::META_KEYS,
			array( 'rnrd_author_education', 'rnrd_author_certifications', 'rnrd_author_memberships', 'rnrd_author_awards', 'rnrd_author_bio' )
		);

		foreach ( $text_keys as $key ) {
			register_meta( 'user', $key, array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => function () { return current_user_can( 'edit_users' ); },
			) );
		}

		// Bio = longer text.
		register_meta( 'user', 'rnrd_author_bio', array(
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => array( self::class, 'sanitize_textarea' ),
			'auth_callback'     => function () { return current_user_can( 'edit_users' ); },
		) );

		// Repeaters = JSON strings.
		foreach ( array( 'rnrd_author_education', 'rnrd_author_certifications', 'rnrd_author_memberships', 'rnrd_author_awards' ) as $key ) {
			register_meta( 'user', $key, array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( self::class, 'sanitize_repeater_json' ),
				'auth_callback'     => function () { return current_user_can( 'edit_users' ); },
			) );
		}
	}

	public static function register_post_meta(): void {
		// Author Trust panel is opt-in. When disabled, the fact-checker / reviewer
		// / last-reviewed meta keys are not registered at all — so they don't
		// appear in the REST API, don't show up in the block editor's document
		// meta panel, and never clutter the post editor for users who don't
		// have a formal review process.
		if ( 'on' !== get_option( RNRD_OPT_AUTHOR_TRUST_ENABLE, 'off' ) ) {
			return;
		}

		$post_types = (array) get_option( RNRD_OPT_AUTHOR_POST_TYPES, array( 'post' ) );

		foreach ( $post_types as $pt ) {
			register_post_meta( $pt, RNRD_META_AUTHOR_FACT_CHECKED_BY, array(
				'type'          => 'integer',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => function () { return current_user_can( 'edit_posts' ); },
			) );
			register_post_meta( $pt, RNRD_META_AUTHOR_REVIEWED_BY, array(
				'type'          => 'integer',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => function () { return current_user_can( 'edit_posts' ); },
			) );
			register_post_meta( $pt, RNRD_META_AUTHOR_LAST_REVIEWED, array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( self::class, 'sanitize_date' ),
				'auth_callback'     => function () { return current_user_can( 'edit_posts' ); },
			) );
			register_post_meta( $pt, RNRD_META_AUTHOR_DISABLE, array(
				'type'          => 'boolean',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => function () { return current_user_can( 'edit_posts' ); },
			) );
		}
	}

	public static function sanitize_textarea( $value ): string {
		return sanitize_textarea_field( (string) $value );
	}

	public static function sanitize_date( $value ): string {
		$value = sanitize_text_field( (string) $value );
		if ( '' === $value ) return '';
		// Only accept YYYY-MM-DD.
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	/**
	 * Repeaters are stored as JSON strings. Sanitize each row as an array of strings.
	 */
	public static function sanitize_repeater_json( $value ): string {
		if ( empty( $value ) ) return '';
		$decoded = is_string( $value ) ? json_decode( $value, true ) : (array) $value;
		if ( ! is_array( $decoded ) ) return '';

		$clean = array();
		foreach ( $decoded as $row ) {
			if ( ! is_array( $row ) ) continue;
			$row_clean = array();
			foreach ( $row as $k => $v ) {
				$row_clean[ sanitize_key( (string) $k ) ] = sanitize_text_field( (string) $v );
			}
			if ( ! empty( array_filter( $row_clean ) ) ) {
				$clean[] = $row_clean;
			}
		}
		// JSON_UNESCAPED_UNICODE preserves non-Latin author content as UTF-8.
		return wp_json_encode( $clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Decode a repeater meta to array. Handles both JSON strings and already-decoded arrays.
	 */
	public static function decode_repeater( $value ): array {
		if ( empty( $value ) ) return array();
		if ( is_array( $value ) ) return $value;
		$decoded = json_decode( (string) $value, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	// ══════════════════════════════════════════════════════════════════════════
	// PROFILE PAGE UI
	// ══════════════════════════════════════════════════════════════════════════

	public static function render_profile_fields( $user ): void {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$m = function ( $key ) use ( $user ) {
			return (string) get_user_meta( $user->ID, $key, true );
		};

		$education     = self::decode_repeater( $m( 'rnrd_author_education' ) );
		$certifications = self::decode_repeater( $m( 'rnrd_author_certifications' ) );
		$memberships   = self::decode_repeater( $m( 'rnrd_author_memberships' ) );
		$awards        = self::decode_repeater( $m( 'rnrd_author_awards' ) );

		wp_nonce_field( 'rnrd_save_author_box', 'rnrd_author_box_nonce' );

		$profile_is_pro = function_exists( 'rnrd_is_pro' ) && rnrd_is_pro();
		?>
		<div class="rnrd-author-card">
			<div class="rnrd-author-card__head">
				<div class="rnrd-author-card__brand">
					<span class="rnrd-author-card__brand-dot" aria-hidden="true"></span>
					<span class="rnrd-author-card__brand-label"><?php esc_html_e( 'RankReady', 'rankready-ai-llm-seo' ); ?></span>
				</div>
				<h2 id="rnrd-author-box" class="rnrd-author-card__title"><?php esc_html_e( 'Author Profile for E-E-A-T', 'rankready-ai-llm-seo' ); ?></h2>
				<p class="rnrd-author-card__desc">
					<?php if ( $profile_is_pro ) : ?>
					<?php esc_html_e( 'Every field below is published as Schema.org Person data that search engines and AI crawlers can read. Fill in only what applies. RankReady merges into Rank Math, Yoast, and AIOSEO Person schema automatically when those plugins are active.', 'rankready-ai-llm-seo' ); ?>
					<?php else : ?>
					<?php esc_html_e( 'These fields power the on-page Author Box and the basic Person schema published on author archive pages. Each input below says which Schema.org property it becomes. Extended schema for credentials, verified identity, and social profiles is listed below, and ships in RankReady Pro.', 'rankready-ai-llm-seo' ); ?>
					<?php endif; ?>
				</p>
				<?php
				/* v1.1.28 — Deep link to Content → Author Box in the RankReady
				 * settings screen. Lets users jump straight from this
				 * profile page to the schema toggles + author box settings
				 * that consume the fields below. */
				$rnrd_eeat_url = admin_url( 'admin.php?page=rankready-ai-llm-seo&tab=content&sub=author' );
				?>
				<p class="rnrd-author-card__head-cta">
					<a class="rnrd-author-card__head-link" href="<?php echo esc_url( $rnrd_eeat_url ); ?>">
						<?php esc_html_e( 'Open RankReady Author Box settings', 'rankready-ai-llm-seo' ); ?>
						<span class="rnrd-author-card__head-link-arrow" aria-hidden="true"></span>
					</a>
				</p>
			</div>

		<table class="form-table rnrd-author-card__table" role="presentation">

			<!-- ── Identity & Work — FREE ───────────────────────────────────── -->
			<tr><th colspan="2">
				<h3><?php esc_html_e( 'Identity & Work', 'rankready-ai-llm-seo' ); ?></h3>
				<p class="rnrd-author-card__section-intro"><?php esc_html_e( 'The core identity block. Job title and employer tell crawlers who wrote the page and where they work.', 'rankready-ai-llm-seo' ); ?></p>
			</th></tr>

			<tr>
				<th><label for="rnrd_author_job_title"><?php esc_html_e( 'Job Title', 'rankready-ai-llm-seo' ); ?></label></th>
				<td>
					<input type="text" name="rnrd_author_job_title" id="rnrd_author_job_title" value="<?php echo esc_attr( $m( 'rnrd_author_job_title' ) ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Your professional role. Emits as Person.jobTitle in the structured data crawlers read.', 'rankready-ai-llm-seo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rnrd_author_employer"><?php esc_html_e( 'Employer / Company', 'rankready-ai-llm-seo' ); ?></label></th>
				<td>
					<input type="text" name="rnrd_author_employer" id="rnrd_author_employer" value="<?php echo esc_attr( $m( 'rnrd_author_employer' ) ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Organization you work for. Emits as Person.worksFor → Organization entity.', 'rankready-ai-llm-seo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rnrd_author_employer_url"><?php esc_html_e( 'Employer URL', 'rankready-ai-llm-seo' ); ?></label></th>
				<td>
					<input type="url" name="rnrd_author_employer_url" id="rnrd_author_employer_url" value="<?php echo esc_attr( $m( 'rnrd_author_employer_url' ) ); ?>" class="regular-text" placeholder="https://" />
					<p class="description"><?php esc_html_e( 'Organization website. Emits as Person.worksFor.url — connects your author entity to the org entity.', 'rankready-ai-llm-seo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rnrd_author_bio"><?php esc_html_e( 'Bio', 'rankready-ai-llm-seo' ); ?></label></th>
				<td>
					<textarea name="rnrd_author_bio" id="rnrd_author_bio" rows="4" class="large-text" maxlength="500"><?php echo esc_textarea( $m( 'rnrd_author_bio' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Short professional bio (max 500 chars). Emits as Person.description. Keep it factual. It is published as written.', 'rankready-ai-llm-seo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rnrd_author_headshot"><?php esc_html_e( 'Headshot URL', 'rankready-ai-llm-seo' ); ?></label></th>
				<td>
					<input type="url" name="rnrd_author_headshot" id="rnrd_author_headshot" value="<?php echo esc_attr( $m( 'rnrd_author_headshot' ) ); ?>" class="regular-text" placeholder="https://" />
					<button type="button" class="button rnrd-media-picker" data-target="rnrd_author_headshot"><?php esc_html_e( 'Select Image', 'rankready-ai-llm-seo' ); ?></button>
					<p class="description"><?php esc_html_e( 'Real photo, square, at least 400x400px. Emits as Person.image. Use a real photo of the author rather than an avatar or stock image.', 'rankready-ai-llm-seo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rnrd_author_headshot_alt"><?php esc_html_e( 'Headshot Alt Text', 'rankready-ai-llm-seo' ); ?></label></th>
				<td>
					<input type="text" name="rnrd_author_headshot_alt" id="rnrd_author_headshot_alt" value="<?php echo esc_attr( $m( 'rnrd_author_headshot_alt' ) ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Accessibility text. Typically "Photo of [Your Name]".', 'rankready-ai-llm-seo' ); ?></p>
				</td>
			</tr>

			<!-- ── Experience ─────────────────────────────────────────────── -->
			<tr><th colspan="2">
				<h3><?php esc_html_e( 'Experience', 'rankready-ai-llm-seo' ); ?></h3>
				<p class="rnrd-author-card__section-intro"><?php esc_html_e( 'First-hand experience and topics the author writes about. Published as Person properties that crawlers can read.', 'rankready-ai-llm-seo' ); ?></p>
			</th></tr>

			<tr>
				<th><label for="rnrd_author_started_year"><?php esc_html_e( 'Started in Field (Year)', 'rankready-ai-llm-seo' ); ?></label></th>
				<td>
					<input type="number" name="rnrd_author_started_year" id="rnrd_author_started_year" value="<?php echo esc_attr( $m( 'rnrd_author_started_year' ) ); ?>" min="1950" max="<?php echo (int) gmdate( 'Y' ); ?>" class="small-text" />
					<p class="description"><?php esc_html_e( 'Year you started working in this field. RankReady auto-calculates years of experience from this — verifiable, not a vanity counter.', 'rankready-ai-llm-seo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rnrd_author_expertise"><?php esc_html_e( 'Topics of Expertise', 'rankready-ai-llm-seo' ); ?></label></th>
				<td>
					<input type="text" name="rnrd_author_expertise" id="rnrd_author_expertise" value="<?php echo esc_attr( $m( 'rnrd_author_expertise' ) ); ?>" class="large-text" placeholder="WordPress, Elementor, SEO, …" />
					<p class="description"><?php esc_html_e( 'Comma-separated topics. Shown as topic chips in the card layout. RankReady Pro also publishes them as Person.knowsAbout[]. Use 3–8 specific topics.', 'rankready-ai-llm-seo' ); ?></p>
				</td>
			</tr>

			<?php if ( ! $profile_is_pro ) : ?>
			<!-- ── Extended author schema — shipped in Pro, previewed here ───
			     IMPORTANT: These fields are BUILT and shipping in RankReady Pro 1.0.0
			     today; the inputs live a few lines down behind $profile_is_pro
			     and Pro's schema class already emits every property named here.
			     This block previews them, it does NOT promise them. If you ever
			     find yourself writing "coming soon" or a future tense into this
			     block, check whether the feature actually shipped first: Free
			     once labelled eight shipped Pro features "COMING SOON", which
			     broke the free promise and killed the upsell at the same time.
			     ─────────────────────────────────────────────────────────────── -->
			<tr>
				<td colspan="2" class="rnrd-author-card__next">
					<div class="rnrd-author-card__next-head">
						<h4 class="rnrd-author-card__next-title"><?php esc_html_e( 'Extended author schema', 'rankready-ai-llm-seo' ); ?></h4>
						<a class="rnrd-pro-tag" href="<?php echo esc_url( rnrd_upgrade_url( 'author-extended-schema' ) ); ?>"><?php esc_html_e( 'Pro', 'rankready-ai-llm-seo' ); ?></a>
					</div>
					<p class="rnrd-author-card__next-desc">
						<?php esc_html_e( 'Four more field groups sit here in RankReady Pro. Each one publishes a distinct Schema.org Person property, and the names below are the exact JSON-LD fields RankReady writes. Like everything else in RankReady, they sit alongside Yoast, Rank Math or AIOSEO rather than competing with them.', 'rankready-ai-llm-seo' ); ?>
					</p>
					<div class="rnrd-author-card__next-grid">
						<div class="rnrd-author-card__next-group">
							<h5 class="rnrd-author-card__next-grouptitle"><?php esc_html_e( 'Credentials', 'rankready-ai-llm-seo' ); ?></h5>
							<ul class="rnrd-author-card__next-list">
								<li><?php esc_html_e( 'Credentials suffix — Person.honorificSuffix', 'rankready-ai-llm-seo' ); ?></li>
								<li><?php esc_html_e( 'Education — Person.alumniOf and hasCredential', 'rankready-ai-llm-seo' ); ?></li>
								<li><?php esc_html_e( 'Certifications — Person.hasCredential with verify URL', 'rankready-ai-llm-seo' ); ?></li>
								<li><?php esc_html_e( 'Memberships — Person.memberOf', 'rankready-ai-llm-seo' ); ?></li>
								<li><?php esc_html_e( 'Awards — Person.award', 'rankready-ai-llm-seo' ); ?></li>
							</ul>
						</div>
						<div class="rnrd-author-card__next-group">
							<h5 class="rnrd-author-card__next-grouptitle"><?php esc_html_e( 'Verified identity', 'rankready-ai-llm-seo' ); ?></h5>
							<ul class="rnrd-author-card__next-list">
								<li><?php esc_html_e( 'Wikidata QID — canonical entity URI', 'rankready-ai-llm-seo' ); ?></li>
								<li><?php esc_html_e( 'Wikipedia URL — high-trust entity anchor', 'rankready-ai-llm-seo' ); ?></li>
								<li><?php esc_html_e( 'ORCID iD — academic identifier (dual emit)', 'rankready-ai-llm-seo' ); ?></li>
								<li><?php esc_html_e( 'Google Scholar URL — academic authority', 'rankready-ai-llm-seo' ); ?></li>
								<li><?php esc_html_e( 'LinkedIn URL — professional identity link', 'rankready-ai-llm-seo' ); ?></li>
							</ul>
						</div>
						<div class="rnrd-author-card__next-group">
							<h5 class="rnrd-author-card__next-grouptitle"><?php esc_html_e( 'Social profiles', 'rankready-ai-llm-seo' ); ?></h5>
							<ul class="rnrd-author-card__next-list">
								<li><?php esc_html_e( 'GitHub URL — sameAs technical authority', 'rankready-ai-llm-seo' ); ?></li>
								<li><?php esc_html_e( 'YouTube URL — sameAs media authority', 'rankready-ai-llm-seo' ); ?></li>
								<li><?php esc_html_e( 'X / Twitter URL — sameAs', 'rankready-ai-llm-seo' ); ?></li>
								<li><?php esc_html_e( 'Personal website URL — sameAs canonical', 'rankready-ai-llm-seo' ); ?></li>
							</ul>
						</div>
						<div class="rnrd-author-card__next-group">
							<h5 class="rnrd-author-card__next-grouptitle"><?php esc_html_e( 'Contact', 'rankready-ai-llm-seo' ); ?></h5>
							<ul class="rnrd-author-card__next-list">
								<li><?php esc_html_e( 'Contact form URL — Person.contactPoint', 'rankready-ai-llm-seo' ); ?></li>
								<li><?php esc_html_e( 'Public email — ContactPoint.email', 'rankready-ai-llm-seo' ); ?></li>
								<li><?php esc_html_e( 'Mailing address — Person.address (PostalAddress)', 'rankready-ai-llm-seo' ); ?></li>
							</ul>
						</div>
					</div>
				</td>
			</tr>
			<?php endif; ?>

			<!-- ── Credentials ─────────────────────────────────────────────── -->
			<?php if ( $profile_is_pro ) : ?>
			<tr><th colspan="2"><h3 style="margin:24px 0 0;"><?php esc_html_e( 'Credentials', 'rankready-ai-llm-seo' ); ?></h3></th></tr>
			<?php endif; ?>
			<?php if ( $profile_is_pro ) : ?>

			<tr>
				<th><label for="rnrd_author_credentials_suffix"><?php esc_html_e( 'Credentials Suffix', 'rankready-ai-llm-seo' ); ?></label></th>
				<td>
					<input type="text" name="rnrd_author_credentials_suffix" id="rnrd_author_credentials_suffix" value="<?php echo esc_attr( $m( 'rnrd_author_credentials_suffix' ) ); ?>" class="regular-text" placeholder="MD, PhD, MPH" />
					<p class="description"><?php esc_html_e( 'Post-nominal letters displayed next to your name in the author box (e.g. "Jane Smith, MD"). Not required.', 'rankready-ai-llm-seo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Education', 'rankready-ai-llm-seo' ); ?></th>
				<td>
					<div class="rnrd-repeater" data-repeater="education" data-fields="degree,institution,year">
						<?php self::render_repeater_rows( $education, array( 'degree' => 'Degree (e.g. MSc Computer Science)', 'institution' => 'Institution', 'year' => 'Year' ) ); ?>
					</div>
					<input type="hidden" name="rnrd_author_education" value="<?php echo esc_attr( wp_json_encode( $education ) ); ?>" />
					<button type="button" class="button rnrd-repeater-add" data-target="education"><?php esc_html_e( '+ Add Education', 'rankready-ai-llm-seo' ); ?></button>
					<p class="description"><?php esc_html_e( 'Degrees and academic qualifications. Each row emits as Person.alumniOf[] + hasCredential[] (credentialCategory: degree).', 'rankready-ai-llm-seo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Certifications', 'rankready-ai-llm-seo' ); ?></th>
				<td>
					<div class="rnrd-repeater" data-repeater="certifications" data-fields="name,issuer,year,url">
						<?php self::render_repeater_rows( $certifications, array( 'name' => 'Certification Name', 'issuer' => 'Issuing Body', 'year' => 'Year', 'url' => 'Verify URL' ) ); ?>
					</div>
					<input type="hidden" name="rnrd_author_certifications" value="<?php echo esc_attr( wp_json_encode( $certifications ) ); ?>" />
					<button type="button" class="button rnrd-repeater-add" data-target="certifications"><?php esc_html_e( '+ Add Certification', 'rankready-ai-llm-seo' ); ?></button>
					<p class="description"><?php esc_html_e( 'Professional certifications with issuer and verify URL. Emits as Person.hasCredential[] (credentialCategory: certification).', 'rankready-ai-llm-seo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Memberships', 'rankready-ai-llm-seo' ); ?></th>
				<td>
					<div class="rnrd-repeater" data-repeater="memberships" data-fields="name,url">
						<?php self::render_repeater_rows( $memberships, array( 'name' => 'Organization Name', 'url' => 'Organization URL' ) ); ?>
					</div>
					<input type="hidden" name="rnrd_author_memberships" value="<?php echo esc_attr( wp_json_encode( $memberships ) ); ?>" />
					<button type="button" class="button rnrd-repeater-add" data-target="memberships"><?php esc_html_e( '+ Add Membership', 'rankready-ai-llm-seo' ); ?></button>
					<p class="description"><?php esc_html_e( 'Professional associations (SPJ, AMA, IEEE, W3C, …). Emits as Person.memberOf[].', 'rankready-ai-llm-seo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Awards', 'rankready-ai-llm-seo' ); ?></th>
				<td>
					<div class="rnrd-repeater" data-repeater="awards" data-fields="name,year">
						<?php self::render_repeater_rows( $awards, array( 'name' => 'Award Name', 'year' => 'Year' ) ); ?>
					</div>
					<input type="hidden" name="rnrd_author_awards" value="<?php echo esc_attr( wp_json_encode( $awards ) ); ?>" />
					<button type="button" class="button rnrd-repeater-add" data-target="awards"><?php esc_html_e( '+ Add Award', 'rankready-ai-llm-seo' ); ?></button>
					<p class="description"><?php esc_html_e( 'Professional recognition. Emits as Person.award[].', 'rankready-ai-llm-seo' ); ?></p>
				</td>
			</tr>

			<!-- ── Verified Identity (Priority sameAs) ─────────────────────── -->
			<tr><th colspan="2"><h3 style="margin:24px 0 0;"><?php esc_html_e( 'Verified Identity (priority sameAs)', 'rankready-ai-llm-seo' ); ?></h3></th></tr>

			<tr>
				<th><label for="rnrd_author_wikidata"><?php esc_html_e( 'Wikidata QID', 'rankready-ai-llm-seo' ); ?></label></th>
				<td>
					<input type="text" name="rnrd_author_wikidata" id="rnrd_author_wikidata" value="<?php echo esc_attr( $m( 'rnrd_author_wikidata' ) ); ?>" class="regular-text" placeholder="Q12345" />
					<p class="description"><?php esc_html_e( 'Your Wikidata identifier if you have one (format: Q12345). Published as a sameAs link so crawlers can match this author to the Wikidata entry.', 'rankready-ai-llm-seo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rnrd_author_wikipedia"><?php esc_html_e( 'Wikipedia URL', 'rankready-ai-llm-seo' ); ?></label></th>
				<td>
					<input type="url" name="rnrd_author_wikipedia" id="rnrd_author_wikipedia" value="<?php echo esc_attr( $m( 'rnrd_author_wikipedia' ) ); ?>" class="regular-text" placeholder="https://en.wikipedia.org/wiki/…" />
					<p class="description"><?php esc_html_e( 'Wikipedia article URL about you, if one exists. Emits early in sameAs[] as a machine-readable link to your identity.', 'rankready-ai-llm-seo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rnrd_author_orcid"><?php esc_html_e( 'ORCID iD', 'rankready-ai-llm-seo' ); ?></label></th>
				<td>
					<input type="text" name="rnrd_author_orcid" id="rnrd_author_orcid" value="<?php echo esc_attr( $m( 'rnrd_author_orcid' ) ); ?>" class="regular-text" placeholder="0000-0000-0000-0000" />
					<p class="description"><?php esc_html_e( 'ORCID identifier (academic ID). Emits both as sameAs (orcid.org URL) and as identifier PropertyValue — dual emission improves disambiguation.', 'rankready-ai-llm-seo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rnrd_author_scholar"><?php esc_html_e( 'Google Scholar URL', 'rankready-ai-llm-seo' ); ?></label></th>
				<td>
					<input type="url" name="rnrd_author_scholar" id="rnrd_author_scholar" value="<?php echo esc_attr( $m( 'rnrd_author_scholar' ) ); ?>" class="regular-text" placeholder="https://scholar.google.com/citations?user=…" />
					<p class="description"><?php esc_html_e( 'Google Scholar profile. Emits in sameAs[] as a Schema.org identity link.', 'rankready-ai-llm-seo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rnrd_author_linkedin"><?php esc_html_e( 'LinkedIn URL', 'rankready-ai-llm-seo' ); ?></label></th>
				<td>
					<input type="url" name="rnrd_author_linkedin" id="rnrd_author_linkedin" value="<?php echo esc_attr( $m( 'rnrd_author_linkedin' ) ); ?>" class="regular-text" placeholder="https://www.linkedin.com/in/…" />
					<p class="description"><?php esc_html_e( 'LinkedIn profile. Emits in sameAs[] as a machine-readable link to your professional profile.', 'rankready-ai-llm-seo' ); ?></p>
				</td>
			</tr>

			<!-- ── Social (Lower Priority sameAs) ──────────────────────────── -->
			<tr><th colspan="2"><h3 style="margin:24px 0 0;"><?php esc_html_e( 'Social', 'rankready-ai-llm-seo' ); ?></h3></th></tr>

			<tr>
				<th><label for="rnrd_author_github"><?php esc_html_e( 'GitHub URL', 'rankready-ai-llm-seo' ); ?></label></th>
				<td>
					<input type="url" name="rnrd_author_github" id="rnrd_author_github" value="<?php echo esc_attr( $m( 'rnrd_author_github' ) ); ?>" class="regular-text" placeholder="https://github.com/…" />
					<p class="description"><?php esc_html_e( 'GitHub profile. Emits in sameAs[] as a Schema.org identity link.', 'rankready-ai-llm-seo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rnrd_author_youtube"><?php esc_html_e( 'YouTube URL', 'rankready-ai-llm-seo' ); ?></label></th>
				<td>
					<input type="url" name="rnrd_author_youtube" id="rnrd_author_youtube" value="<?php echo esc_attr( $m( 'rnrd_author_youtube' ) ); ?>" class="regular-text" placeholder="https://www.youtube.com/@…" />
					<p class="description"><?php esc_html_e( 'YouTube channel. Emits in sameAs[].', 'rankready-ai-llm-seo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rnrd_author_twitter"><?php esc_html_e( 'X / Twitter URL', 'rankready-ai-llm-seo' ); ?></label></th>
				<td>
					<input type="url" name="rnrd_author_twitter" id="rnrd_author_twitter" value="<?php echo esc_attr( $m( 'rnrd_author_twitter' ) ); ?>" class="regular-text" placeholder="https://x.com/…" />
					<p class="description"><?php esc_html_e( 'X (Twitter) profile. Emits in sameAs[].', 'rankready-ai-llm-seo' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rnrd_author_website"><?php esc_html_e( 'Personal Website URL', 'rankready-ai-llm-seo' ); ?></label></th>
				<td>
					<input type="url" name="rnrd_author_website" id="rnrd_author_website" value="<?php echo esc_attr( $m( 'rnrd_author_website' ) ); ?>" class="regular-text" placeholder="https://" />
					<p class="description"><?php esc_html_e( 'Your personal or portfolio site. Emits in sameAs[].', 'rankready-ai-llm-seo' ); ?></p>
				</td>
			</tr>

			<!-- ── Contact ─────────────────────────────────────────────────── -->
			<tr><th colspan="2"><h3 style="margin:24px 0 0;"><?php esc_html_e( 'Contact', 'rankready-ai-llm-seo' ); ?></h3></th></tr>

			<tr>
				<th><label for="rnrd_author_contact_url"><?php esc_html_e( 'Contact Form URL', 'rankready-ai-llm-seo' ); ?></label></th>
				<td>
					<input type="url" name="rnrd_author_contact_url" id="rnrd_author_contact_url" value="<?php echo esc_attr( $m( 'rnrd_author_contact_url' ) ); ?>" class="regular-text" placeholder="https://" />
					<p class="description"><?php esc_html_e( 'Contact form page URL. Emits as Person.contactPoint — preferred over raw email (zero scrape risk).', 'rankready-ai-llm-seo' ); ?></p>
				</td>
			</tr>
			<?php endif; // profile_is_pro — credentials + verified identity + social + contact ?>
		</table>

		</div><!-- /.rnrd-author-card -->

		<?php
		// Repeater + media-picker JS lives in a real file. WP.org hard rules 3 and 13
		// forbid raw script-tag echoes in admin context. The previous block sat in
		// inline-HTML mode rather than a PHP echo, which is why the release gate's
		// echo-based pattern never matched it — see gate checks 3 and 13.
		// wp_enqueue_media() must run first so wp.media exists when the script inits.
		wp_enqueue_media();
		wp_enqueue_script(
			'rnrd-author-profile',
			RNRD_URL . 'assets/author-profile.js',
			array( 'rnrd-i18n' ),
			rnrd_asset_ver( 'assets/author-profile.js' ),
			true
		);
		wp_localize_script( 'rnrd-author-profile', 'rnrdAuthorProfile', array(
			'i18n' => self::profile_js_i18n(),
		) );
	}

	private static function render_repeater_rows( array $rows, array $field_labels ): void {
		$fields = array_keys( $field_labels );
		if ( empty( $rows ) ) {
			echo '<p class="description" style="font-style:italic;margin:4px 0 8px;">' . esc_html__( 'No entries yet. Click "Add" below.', 'rankready-ai-llm-seo' ) . '</p>';
			return;
		}
		foreach ( $rows as $row ) {
			echo '<div class="rnrd-repeater-row" style="display:flex;gap:8px;margin-bottom:6px;align-items:center;">';
			foreach ( $fields as $field ) {
				$value = isset( $row[ $field ] ) ? $row[ $field ] : '';
				echo '<input type="text" data-field="' . esc_attr( $field ) . '" placeholder="' . esc_attr( $field_labels[ $field ] ) . '" value="' . esc_attr( $value ) . '" style="flex:1;" />';
			}
			echo '<button type="button" class="button rnrd-repeater-row-remove" style="min-width:32px;">&times;</button>';
			echo '</div>';
		}
	}

	public static function save_profile_fields( $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		if ( empty( $_POST['rnrd_author_box_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rnrd_author_box_nonce'] ) ), 'rnrd_save_author_box' ) ) {
			return;
		}

		foreach ( self::META_KEYS as $key ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}
			// Nonce + capability checks above. Raw unslashed value is
			// dispatched to a type-specific sanitizer in the if/elseif
			// chain below (sanitize_repeater_json, sanitize_textarea,
			// esc_url_raw, or sanitize_text_field).
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$raw = wp_unslash( $_POST[ $key ] );

			if ( in_array( $key, array( 'rnrd_author_education', 'rnrd_author_certifications', 'rnrd_author_memberships', 'rnrd_author_awards' ), true ) ) {
				update_user_meta( $user_id, $key, self::sanitize_repeater_json( $raw ) );
			} elseif ( 'rnrd_author_bio' === $key ) {
				update_user_meta( $user_id, $key, self::sanitize_textarea( $raw ) );
			} elseif ( in_array( $key, array( 'rnrd_author_employer_url', 'rnrd_author_headshot', 'rnrd_author_wikipedia', 'rnrd_author_scholar', 'rnrd_author_linkedin', 'rnrd_author_github', 'rnrd_author_youtube', 'rnrd_author_twitter', 'rnrd_author_website', 'rnrd_author_contact_url' ), true ) ) {
				update_user_meta( $user_id, $key, esc_url_raw( (string) $raw ) );
			} else {
				update_user_meta( $user_id, $key, sanitize_text_field( (string) $raw ) );
			}
		}
	}

	// ══════════════════════════════════════════════════════════════════════════
	// SCHEMA — Person node builder (shared by all render + merge paths)
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * Build a Schema.org Person node for a user. Returns null if there's nothing
	 * meaningful to emit (just a bare WP username with no RankReady data).
	 */
	public static function build_person_schema( int $user_id ): ?array {
		if ( $user_id <= 0 ) return null;
		$user = get_userdata( $user_id );
		if ( ! $user ) return null;

		$m = function ( $key ) use ( $user_id ) {
			return (string) get_user_meta( $user_id, $key, true );
		};

		$display_name = $user->display_name;
		$suffix       = $m( 'rnrd_author_credentials_suffix' );
		$full_name    = $suffix ? $display_name . ', ' . $suffix : $display_name;

		$person = array(
			'@type' => 'Person',
			'@id'   => get_author_posts_url( $user_id ) . '#person',
			'name'  => $full_name,
			'url'   => get_author_posts_url( $user_id ),
		);

		// Bio → description.
		$bio = $m( 'rnrd_author_bio' );
		if ( '' === $bio && $user->description ) {
			$bio = $user->description;
		}
		if ( '' !== $bio ) {
			$person['description'] = wp_strip_all_tags( $bio );
		}

		// Headshot → image (ImageObject).
		$headshot = $m( 'rnrd_author_headshot' );
		if ( '' !== $headshot ) {
			$person['image'] = array(
				'@type'   => 'ImageObject',
				'url'     => $headshot,
				'caption' => $m( 'rnrd_author_headshot_alt' ) ?: $display_name,
			);
		} else {
			$avatar = get_avatar_url( $user_id, array( 'size' => 400 ) );
			if ( $avatar ) {
				$person['image'] = array(
					'@type' => 'ImageObject',
					'url'   => $avatar,
				);
			}
		}

		// Job title.
		$job = $m( 'rnrd_author_job_title' );
		if ( '' !== $job ) $person['jobTitle'] = $job;

		// Employer → worksFor.
		$employer = $m( 'rnrd_author_employer' );
		if ( '' !== $employer ) {
			$works_for = array( '@type' => 'Organization', 'name' => $employer );
			$emp_url   = $m( 'rnrd_author_employer_url' );
			if ( '' !== $emp_url ) $works_for['url'] = $emp_url;
			$person['worksFor'] = $works_for;
		}

		// ── Advanced EEAT fields are a PRO engine. ──────────────────────────
		// Free emits only the basic Person above (name, jobTitle, worksFor,
		// description, image, employer URL). The Pro add-on (RNRD_Pro_Schema)
		// hooks `rnrd_person_schema` to add knowsAbout, sameAs (Wikidata /
		// Wikipedia / ORCID / Scholar / LinkedIn / GitHub / YouTube / X /
		// website), ORCID + Wikidata identifiers, education (alumniOf +
		// hasCredential), certifications, memberships (memberOf), awards,
		// contactPoint, and publishingPrinciples. When Pro is inactive the
		// filter is a pass-through and only the basic node is emitted.
		$person = apply_filters( 'rnrd_person_schema', $person, $user_id, $m );

		// Filter count: if we only have the baseline (type/@id/name/url), don't bother emitting.
		if ( count( $person ) <= 4 ) {
			return null;
		}

		return $person;
	}

	// ══════════════════════════════════════════════════════════════════════════
	// SCHEMA MERGE — inject Person data into existing SEO plugin schema.
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * Walk a schema graph and enhance any Person node whose url matches the
	 * current post author. Uses a SMART merge strategy (1.7.1+):
	 *
	 *   - For fields the user explicitly filled in the RankReady Author Box
	 *     (image, description, jobTitle, worksFor, knowsAbout, contactPoint),
	 *     RankReady's value OVERWRITES whatever the SEO plugin emitted. This
	 *     fixes the long-standing bug where a Gravatar fallback from Rank Math
	 *     would win over an uploaded real headshot, or where duplicate
	 *     twitter.com / x.com entries from Yoast profile fields would persist.
	 *
	 *   - For sameAs, RankReady MERGES its list with whatever already exists,
	 *     then dedupes + normalizes (twitter.com → x.com collapse, www. strip,
	 *     trailing-slash strip) and re-orders by EEAT priority (Wikidata →
	 *     Wikipedia → ORCID → Scholar → LinkedIn → GitHub → YouTube → X →
	 *     personal site). This keeps any extra profile links the SEO plugin
	 *     knows about while guaranteeing zero duplicates.
	 *
	 *   - For everything else (alumniOf, hasCredential, memberOf, award,
	 *     identifier, publishingPrinciples, @id), RankReady adds the field
	 *     only if the SEO plugin hasn't already set it. Additive, non-
	 *     destructive, no-conflict.
	 */
	private static function enhance_graph( array &$graph, int $post_id ): void {
		if ( 'on' !== get_option( RNRD_OPT_AUTHOR_SCHEMA_ENABLE, 'on' ) ) return;
		$post = get_post( $post_id );
		if ( ! $post ) return;
		// One guard for the seven SEO-plugin author merges that route through
		// here. They gated on is_singular() alone, so a password-protected post
		// still emitted Person, reviewedBy and lastReviewed, and the per-post
		// opt-out applied to the visible box only.
		if ( ! RNRD_Util::can_emit( $post_id, RNRD_META_AUTHOR_DISABLE ) ) return;

		$person = self::build_person_schema( (int) $post->post_author );
		if ( null === $person ) return;

		$author_url = get_author_posts_url( (int) $post->post_author );

		foreach ( $graph as &$node ) {
			if ( ! is_array( $node ) || ! isset( $node['@type'] ) ) continue;
			$type = is_array( $node['@type'] ) ? $node['@type'] : array( $node['@type'] );
			if ( ! in_array( 'Person', $type, true ) ) continue;

			// Match by URL if present, otherwise enhance any Person node
			// (most SEO plugins only emit one Person per article graph).
			$node_url = isset( $node['url'] ) ? $node['url'] : '';
			if ( $node_url && $node_url !== $author_url ) continue;

			self::smart_merge_person( $node, $person, self::has_explicit_headshot( (int) $post->post_author ) );
		}
		unset( $node );

		// Also add reviewedBy / lastReviewed to Article nodes.
		self::inject_review_signals( $graph, $post_id );
	}

	/**
	 * Smart merge: RankReady's Author Box data wins for the fields the user
	 * explicitly filled in, sameAs is merged-and-deduped, everything else is
	 * additive. See enhance_graph() docblock for the full rationale.
	 *
	 * @param array $node   The existing Person node from the SEO plugin graph (modified in place).
	 * @param array $person The Person data built from RankReady's Author Box fields.
	 */
	/**
	 * Did this user actually upload a headshot in the Author Box?
	 *
	 * build_person_schema() falls back to get_avatar_url() when they did not,
	 * and `image` is in $overwrite_keys, so the merge used to replace Yoast's
	 * or Rank Math's real author image with a Gravatar URL. The overwrite set's
	 * own docblock says it is for "fields the user explicitly filled in", and
	 * a fallback is by definition not one of those, so the fallback now stays
	 * additive: it fills an empty `image`, it never displaces a populated one.
	 *
	 * @param int $user_id User to check.
	 * @return bool True when a headshot URL is stored on the user.
	 */
	private static function has_explicit_headshot( int $user_id ): bool {
		return '' !== trim( (string) get_user_meta( $user_id, 'rnrd_author_headshot', true ) );
	}

	/**
	 * @param array $node           Existing Person node from the SEO plugin graph, modified in place.
	 * @param array $person         Person data built from RankReady's Author Box fields.
	 * @param bool  $image_explicit Whether $person['image'] came from an uploaded
	 *                              headshot rather than the Gravatar fallback.
	 */
	private static function smart_merge_person( array &$node, array $person, bool $image_explicit = true ): void {
		// Fields where RankReady's value WINS when present. These are the
		// Author Box fields the user explicitly filled in — they should never
		// be overridden by SEO plugin defaults (Gravatar, WP bio, etc.).
		$overwrite_keys = array(
			'image',
			'description',
			'jobTitle',
			'worksFor',
			'knowsAbout',
			'contactPoint',
		);

		if ( ! $image_explicit ) {
			$overwrite_keys = array_values( array_diff( $overwrite_keys, array( 'image' ) ) );
		}

		foreach ( $person as $key => $value ) {
			if ( '@type' === $key ) continue;

			// sameAs: merge both arrays, dedupe, normalize, priority-sort.
			if ( 'sameAs' === $key ) {
				$existing = array();
				if ( isset( $node['sameAs'] ) ) {
					$existing = is_array( $node['sameAs'] ) ? $node['sameAs'] : array( $node['sameAs'] );
				}
				$merged = array_merge( $existing, (array) $value );
				$node['sameAs'] = self::normalize_same_as( $merged );
				continue;
			}

			// Fields where RankReady's value always wins.
			if ( in_array( $key, $overwrite_keys, true ) ) {
				$node[ $key ] = $value;
				continue;
			}

			// Everything else: additive (alumniOf, hasCredential, memberOf,
			// award, identifier, publishingPrinciples, @id, name, url).
			if ( ! isset( $node[ $key ] ) || ( is_array( $node[ $key ] ) && empty( $node[ $key ] ) ) ) {
				$node[ $key ] = $value;
			}
		}
	}

	/**
	 * Dedupe + normalize + priority-sort a sameAs URL list.
	 *
	 *   - Collapses twitter.com and x.com for the same handle into one x.com URL
	 *   - Strips "www." from the host
	 *   - Strips trailing slash from the path
	 *   - Lowercases the host for comparison
	 *   - Orders by EEAT priority: Wikidata → Wikipedia → ORCID → Scholar →
	 *     LinkedIn → Muck Rack → GitHub → YouTube → X → everything else
	 *
	 * @param array $urls Raw URL list (may contain duplicates and variants).
	 * @return array Canonicalized, deduped, priority-sorted URL list.
	 */
	private static function normalize_same_as( array $urls ): array {
		$seen   = array();
		$output = array();

		foreach ( $urls as $url ) {
			$url = trim( (string) $url );
			if ( '' === $url ) continue;

			$parts = wp_parse_url( $url );
			if ( empty( $parts['host'] ) ) continue;

			$host = strtolower( $parts['host'] );
			$host = preg_replace( '/^www\./', '', $host );
			$path = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
			// Preserve query string — Google Scholar, Twitter search, etc. use it as the author identifier.
			$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';

			// Collapse twitter.com + x.com + mobile variants — same platform, same handle.
			if ( in_array( $host, array( 'twitter.com', 'x.com', 'mobile.twitter.com', 'mobile.x.com' ), true ) ) {
				$host = 'x.com';
			}

			$dedupe_key = $host . $path . $query;
			if ( isset( $seen[ $dedupe_key ] ) ) continue;
			$seen[ $dedupe_key ] = true;

			// Canonical rebuild: always https, normalized host, path, preserved query.
			$output[] = 'https://' . $host . $path . $query;
		}

		// Priority sort by domain. Lower rank = higher priority.
		$priority_map = array(
			'wikidata.org'         => 1,
			'wikipedia.org'        => 2,
			'orcid.org'            => 3,
			'scholar.google.com'   => 4,
			'scholar.google'       => 4,
			'linkedin.com'         => 5,
			'muckrack.com'         => 6,
			'github.com'           => 7,
			'youtube.com'          => 8,
			'x.com'                => 9,
		);

		$rank = function ( $url ) use ( $priority_map ) {
			foreach ( $priority_map as $domain => $r ) {
				if ( false !== strpos( $url, $domain ) ) return $r;
			}
			return 999;
		};

		usort( $output, function ( $a, $b ) use ( $rank ) {
			$ra = $rank( $a );
			$rb = $rank( $b );
			if ( $ra === $rb ) return strcmp( $a, $b );
			return $ra - $rb;
		} );

		return $output;
	}

	private static function inject_review_signals( array &$graph, int $post_id ): void {
		// Trust is opt-in. Reviewer meta left over from when it was on must not
		// keep reaching the schema once the owner has switched it off.
		if ( 'on' !== get_option( RNRD_OPT_AUTHOR_TRUST_ENABLE, 'off' ) ) {
			return;
		}
		$reviewed_by_id    = (int) get_post_meta( $post_id, RNRD_META_AUTHOR_REVIEWED_BY, true );
		$fact_checked_by_id = (int) get_post_meta( $post_id, RNRD_META_AUTHOR_FACT_CHECKED_BY, true );
		$last_reviewed     = (string) get_post_meta( $post_id, RNRD_META_AUTHOR_LAST_REVIEWED, true );

		if ( ! $reviewed_by_id && ! $fact_checked_by_id && '' === $last_reviewed ) return;

		$reviewers = array();
		foreach ( array( $reviewed_by_id, $fact_checked_by_id ) as $uid ) {
			if ( $uid <= 0 ) continue;
			$r = self::build_person_schema( $uid );
			if ( null !== $r ) $reviewers[] = $r;
		}

		$article_types = array( 'Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'ScholarlyArticle', 'Report' );

		foreach ( $graph as &$node ) {
			if ( ! is_array( $node ) || ! isset( $node['@type'] ) ) continue;
			$type = is_array( $node['@type'] ) ? $node['@type'] : array( $node['@type'] );
			if ( ! array_intersect( $type, $article_types ) ) continue;

			if ( ! empty( $reviewers ) && empty( $node['reviewedBy'] ) ) {
				$node['reviewedBy'] = 1 === count( $reviewers ) ? $reviewers[0] : $reviewers;
			}
			if ( '' !== $last_reviewed && empty( $node['lastReviewed'] ) ) {
				$node['lastReviewed'] = $last_reviewed;
			}
		}
		unset( $node );
	}

	public static function merge_into_rankmath( $data, $jsonld ) {
		if ( ! is_singular() || ! is_array( $data ) ) return $data;
		$post_id = get_queried_object_id();
		if ( $post_id ) self::enhance_graph( $data, $post_id );
		return $data;
	}
	public static function merge_into_yoast( $graph ) {
		if ( ! is_singular() || ! is_array( $graph ) ) return $graph;
		$post_id = get_queried_object_id();
		if ( $post_id ) self::enhance_graph( $graph, $post_id );
		return $graph;
	}
	public static function merge_into_aioseo( $graphs ) {
		if ( ! is_singular() || ! is_array( $graphs ) ) return $graphs;
		$post_id = get_queried_object_id();
		if ( ! $post_id ) return $graphs;
		foreach ( $graphs as &$graph ) {
			if ( is_array( $graph ) && isset( $graph['@graph'] ) && is_array( $graph['@graph'] ) ) {
				self::enhance_graph( $graph['@graph'], $post_id );
			} elseif ( is_array( $graph ) && isset( $graph['@type'] ) ) {
				$single = array( &$graph );
				self::enhance_graph( $single, $post_id );
			}
		}
		unset( $graph );
		return $graphs;
	}
	public static function merge_into_seopress( $schema ) {
		if ( ! is_singular() || ! is_array( $schema ) ) return $schema;
		if ( 'on' !== get_option( RNRD_OPT_AUTHOR_SCHEMA_ENABLE, 'on' ) ) return $schema;
		$post_id = get_queried_object_id();
		if ( ! $post_id ) return $schema;
		// SeoPress does not route through enhance_graph(), so it needs the same
		// opt-out and password guard here.
		if ( ! RNRD_Util::can_emit( (int) $post_id, RNRD_META_AUTHOR_DISABLE ) ) return $schema;

		// SEOPress (free + Pro) passes the Article schema directly with an
		// inline `author` Person instead of a separate @graph node. Use the
		// same smart-merge strategy as enhance_graph() so the headshot and
		// sameAs dedupe work identically here.
		if ( isset( $schema['author'] ) && is_array( $schema['author'] ) ) {
			$person = self::build_person_schema( (int) get_post_field( 'post_author', $post_id ) );
			if ( null !== $person ) {
				self::smart_merge_person( $schema['author'], $person, self::has_explicit_headshot( (int) get_post_field( 'post_author', $post_id ) ) );
			}
		}
		return $schema;
	}
	public static function merge_into_tsf( $graph ) {
		if ( ! is_singular() || ! is_array( $graph ) ) return $graph;
		$post_id = get_queried_object_id();
		if ( $post_id ) self::enhance_graph( $graph, $post_id );
		return $graph;
	}
	public static function merge_into_slim_seo( $graph ) {
		if ( ! is_singular() || ! is_array( $graph ) ) return $graph;
		$post_id = get_queried_object_id();
		if ( $post_id ) self::enhance_graph( $graph, $post_id );
		return $graph;
	}

	// ── Squirrly SEO: sq_json_ld_data (node array, like Slim SEO) ────────────
	// No `: array` return type by design — third-party filter, pass non-arrays
	// through rather than risk a TypeError. Mirrors merge_into_slim_seo.
	public static function merge_into_squirrly( $graph ) {
		if ( ! is_singular() || ! is_array( $graph ) ) return $graph;
		$post_id = get_queried_object_id();
		if ( $post_id ) self::enhance_graph( $graph, $post_id );
		return $graph;
	}

	// ══════════════════════════════════════════════════════════════════════════
	// SCHEMA — standalone Person node on is_author() archive (no template override)
	// ══════════════════════════════════════════════════════════════════════════

	public static function maybe_inject_archive_person(): void {
		if ( ! is_author() ) return;
		if ( 'on' !== get_option( RNRD_OPT_AUTHOR_SCHEMA_ENABLE, 'on' ) ) return;

		$user_id = (int) get_queried_object_id();
		if ( $user_id <= 0 ) return;

		$person = self::build_person_schema( $user_id );
		if ( null === $person ) return;

		$profile = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'ProfilePage',
			'mainEntity' => $person,
		);

		printf(
			'<script type="application/ld+json">%s</script>' . "\n",
			wp_json_encode( $profile, JSON_UNESCAPED_UNICODE )
		);
	}

	// ══════════════════════════════════════════════════════════════════════════
	// RENDER — HTML output for block, widget, and auto-display
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * The site-wide author box heading, translated when it is still the default.
	 *
	 * Activation used to store the English literal 'About the Author', which
	 * froze the heading in English on every non-English site. A stored value
	 * that is empty or equal to that literal now reads as the translatable
	 * default; anything the admin typed is returned unchanged.
	 *
	 * @since 1.4.0
	 */
	public static function heading_text(): string {
		$stored = trim( (string) get_option( RNRD_OPT_AUTHOR_HEADING, '' ) );
		if ( '' === $stored || 'About the Author' === $stored ) {
			return __( 'About the Author', 'rankready-ai-llm-seo' );
		}
		return $stored;
	}

	/**
	 * Render the author box. Called by the Gutenberg block render_callback,
	 * the Elementor widget, the [rankready_author] shortcode, and auto-display.
	 *
	 * @param int   $user_id  Author user ID.
	 * @param array $attrs    Block/widget attributes.
	 * @param int   $post_id  Current post ID (for per-post reviewed-by data).
	 */
	public static function render_html( int $user_id, array $attrs = array(), int $post_id = 0, bool $is_block = false ): string {
		if ( $user_id <= 0 ) return '';
		$user = get_userdata( $user_id );
		if ( ! $user ) return '';

		$layout        = isset( $attrs['layout'] ) ? sanitize_key( $attrs['layout'] ) : (string) get_option( RNRD_OPT_AUTHOR_LAYOUT, 'card' );
		$layout        = in_array( $layout, array( 'card', 'compact', 'inline' ), true ) ? $layout : 'card';
		$show_heading  = isset( $attrs['showHeading'] ) ? (bool) $attrs['showHeading'] : true;
		$heading_text  = isset( $attrs['headingText'] ) && '' !== $attrs['headingText']
			? sanitize_text_field( $attrs['headingText'] )
			: self::heading_text();
		$heading_tag   = isset( $attrs['headingTag'] ) && '' !== $attrs['headingTag']
			? $attrs['headingTag']
			: (string) get_option( RNRD_OPT_AUTHOR_HEADING_TAG, 'h3' );
		$heading_tag   = in_array( $heading_tag, array( 'h2', 'h3', 'h4', 'h5', 'h6', 'p' ), true ) ? $heading_tag : 'h3';

		$show_headshot   = isset( $attrs['showHeadshot'] )   ? (bool) $attrs['showHeadshot']   : true;
		$show_title      = isset( $attrs['showJobTitle'] )   ? (bool) $attrs['showJobTitle']   : true;
		$show_employer   = isset( $attrs['showEmployer'] )   ? (bool) $attrs['showEmployer']   : true;
		$show_bio        = isset( $attrs['showBio'] )        ? (bool) $attrs['showBio']        : true;
		$show_expertise  = isset( $attrs['showExpertise'] )  ? (bool) $attrs['showExpertise']  : true;
		$show_socials    = isset( $attrs['showSocials'] )    ? (bool) $attrs['showSocials']    : true;
		$show_credentials = isset( $attrs['showCredentials'] ) ? (bool) $attrs['showCredentials'] : true;
		$show_reviewed   = ( isset( $attrs['showReviewed'] )   ? (bool) $attrs['showReviewed']   : true )
			&& 'on' === get_option( RNRD_OPT_AUTHOR_TRUST_ENABLE, 'off' );
		$show_years_exp  = isset( $attrs['showYearsExp'] )   ? (bool) $attrs['showYearsExp']   : true;

		$m = function ( $key ) use ( $user_id ) {
			return (string) get_user_meta( $user_id, $key, true );
		};

		$display_name = $user->display_name;
		$suffix       = $m( 'rnrd_author_credentials_suffix' );
		$name_html    = esc_html( $display_name ) . ( $suffix ? ' <span class="rnrd-ab-suffix">' . esc_html( $suffix ) . '</span>' : '' );
		$name_link    = get_author_posts_url( $user_id );

		// Headshot.
		$headshot = $m( 'rnrd_author_headshot' );
		if ( '' === $headshot ) $headshot = get_avatar_url( $user_id, array( 'size' => 200 ) );
		$headshot_alt = $m( 'rnrd_author_headshot_alt' ) ?: $display_name;

		// Years of experience (derived).
		$started_year = (int) $m( 'rnrd_author_started_year' );
		$years_exp    = ( $started_year > 1950 && $started_year <= (int) gmdate( 'Y' ) )
			? ( (int) gmdate( 'Y' ) - $started_year )
			: 0;

		// Reviewed / fact-checked signals.
		$reviewed_by_id     = $post_id ? (int) get_post_meta( $post_id, RNRD_META_AUTHOR_REVIEWED_BY, true ) : 0;
		$fact_checked_by_id = $post_id ? (int) get_post_meta( $post_id, RNRD_META_AUTHOR_FACT_CHECKED_BY, true ) : 0;
		$last_reviewed      = $post_id ? (string) get_post_meta( $post_id, RNRD_META_AUTHOR_LAST_REVIEWED, true ) : '';

		// On the block path the outer element must carry the attributes the block
		// editor generated: the user's Additional CSS class, an anchor, and every
		// block-support style (colour, spacing, typography). Rendering our own
		// bare class attribute dropped all of them, so a class typed into the
		// Advanced panel simply never appeared on the front end. Mirrors what
		// RNRD_Summary and RNRD_Faq already do for their blocks.
		$rnrd_ab_classes = 'rnrd-author-box rnrd-ab-' . $layout;
		$rnrd_ab_decls   = self::style_declarations( $attrs );

		if ( $is_block && function_exists( 'get_block_wrapper_attributes' ) ) {
			$rnrd_ab_wrap_args = array( 'class' => $rnrd_ab_classes );
			if ( '' !== $rnrd_ab_decls ) {
				$rnrd_ab_wrap_args['style'] = $rnrd_ab_decls;
			}
			$rnrd_ab_open = '<div ' . get_block_wrapper_attributes( $rnrd_ab_wrap_args ) . '>';
		} else {
			$rnrd_ab_open = '<div class="' . esc_attr( $rnrd_ab_classes ) . '"'
				. ( '' === $rnrd_ab_decls ? '' : ' style="' . esc_attr( $rnrd_ab_decls ) . '"' ) . '>';
		}

		ob_start();
		?>
		<?php echo $rnrd_ab_open; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr()'d parts / get_block_wrapper_attributes(). ?>
			<?php if ( $show_heading && 'inline' !== $layout ) : ?>
				<<?php echo esc_attr( $heading_tag ); ?> class="rnrd-ab-heading"><?php echo esc_html( $heading_text ); ?></<?php echo esc_attr( $heading_tag ); ?>>
			<?php endif; ?>

			<div class="rnrd-ab-inner">
				<?php if ( $show_headshot && $headshot ) : ?>
					<div class="rnrd-ab-headshot">
						<img src="<?php echo esc_url( $headshot ); ?>" alt="<?php echo esc_attr( $headshot_alt ); ?>" loading="lazy" />
					</div>
				<?php endif; ?>

				<div class="rnrd-ab-body">
					<div class="rnrd-ab-name-row">
						<a class="rnrd-ab-name" href="<?php echo esc_url( $name_link ); ?>" rel="author"><?php echo $name_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
					</div>

					<?php if ( $show_title || $show_employer || ( $show_years_exp && $years_exp > 0 ) ) : ?>
						<div class="rnrd-ab-meta">
							<?php
							$meta_parts = array();
							if ( $show_title && $m( 'rnrd_author_job_title' ) ) {
								$meta_parts[] = esc_html( $m( 'rnrd_author_job_title' ) );
							}
							if ( $show_employer && $m( 'rnrd_author_employer' ) ) {
								$emp_name = esc_html( $m( 'rnrd_author_employer' ) );
								$emp_url  = $m( 'rnrd_author_employer_url' );
								$meta_parts[] = $emp_url ? '<a href="' . esc_url( $emp_url ) . '" rel="noopener">' . $emp_name . '</a>' : $emp_name;
							}
							if ( $show_years_exp && $years_exp > 0 ) {
								/* translators: %d number of years */
								$meta_parts[] = esc_html( sprintf( _n( '%d year experience', '%d years experience', $years_exp, 'rankready-ai-llm-seo' ), $years_exp ) );
							}
							echo implode( ' <span class="rnrd-ab-sep">·</span> ', $meta_parts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
						</div>
					<?php endif; ?>

					<?php
					$bio = $m( 'rnrd_author_bio' );
					if ( '' === $bio ) $bio = $user->description;
					if ( $show_bio && $bio && 'inline' !== $layout ) :
					?>
						<p class="rnrd-ab-bio"><?php echo esc_html( $bio ); ?></p>
					<?php endif; ?>

					<?php if ( $show_expertise && 'card' === $layout && $m( 'rnrd_author_expertise' ) ) : ?>
						<div class="rnrd-ab-expertise">
							<?php
							$topics = array_filter( array_map( 'trim', explode( ',', $m( 'rnrd_author_expertise' ) ) ) );
							foreach ( $topics as $topic ) {
								echo '<span class="rnrd-ab-topic">' . esc_html( $topic ) . '</span>';
							}
							?>
						</div>
					<?php endif; ?>

					<?php if ( $show_credentials && 'card' === $layout ) : ?>
						<?php
						$education = self::decode_repeater( $m( 'rnrd_author_education' ) );
						$certs     = self::decode_repeater( $m( 'rnrd_author_certifications' ) );
						if ( ! empty( $education ) || ! empty( $certs ) ) :
						?>
							<div class="rnrd-ab-credentials">
								<?php foreach ( $education as $row ) : if ( empty( $row['degree'] ) && empty( $row['institution'] ) ) continue; ?>
									<div class="rnrd-ab-cred-row">
										<span class="rnrd-ab-cred-icon rnrd-ab-cred-icon--edu" aria-hidden="true"></span>
										<?php echo esc_html( trim( ( $row['degree'] ?? '' ) . ( ! empty( $row['institution'] ) ? ' · ' . $row['institution'] : '' ) ) ); ?>
									</div>
								<?php endforeach; ?>
								<?php foreach ( $certs as $row ) : if ( empty( $row['name'] ) ) continue; ?>
									<div class="rnrd-ab-cred-row">
										<span class="rnrd-ab-cred-icon" aria-hidden="true"></span>
										<?php echo esc_html( trim( $row['name'] . ( ! empty( $row['issuer'] ) ? ' · ' . $row['issuer'] : '' ) ) ); ?>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					<?php endif; ?>

					<?php if ( $show_socials ) : ?>
						<?php
						$socials = self::get_social_links( $user_id );
						if ( ! empty( $socials ) ) :
						?>
							<div class="rnrd-ab-socials">
								<?php foreach ( $socials as $label => $url ) : ?>
									<a class="rnrd-ab-social rnrd-ab-social-<?php echo esc_attr( sanitize_key( $label ) ); ?>" href="<?php echo esc_url( $url ); ?>" rel="noopener me" target="_blank" aria-label="<?php echo esc_attr( $label ); ?>">
										<?php echo esc_html( $label ); ?>
									</a>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					<?php endif; ?>

					<?php if ( $show_reviewed && ( $reviewed_by_id || $fact_checked_by_id || $last_reviewed ) ) : ?>
						<div class="rnrd-ab-reviewed">
							<?php
							$parts = array();
							if ( $fact_checked_by_id ) {
								$u = get_userdata( $fact_checked_by_id );
								if ( $u ) {
									$parts[] = esc_html__( 'Fact-checked by', 'rankready-ai-llm-seo' ) . ' <a href="' . esc_url( get_author_posts_url( $fact_checked_by_id ) ) . '">' . esc_html( $u->display_name ) . '</a>';
								}
							}
							if ( $reviewed_by_id ) {
								$u = get_userdata( $reviewed_by_id );
								if ( $u ) {
									$parts[] = esc_html__( 'Reviewed by', 'rankready-ai-llm-seo' ) . ' <a href="' . esc_url( get_author_posts_url( $reviewed_by_id ) ) . '">' . esc_html( $u->display_name ) . '</a>';
								}
							}
							if ( $last_reviewed ) {
								$ts = strtotime( $last_reviewed );
								if ( $ts ) {
									$parts[] = esc_html__( 'Last reviewed', 'rankready-ai-llm-seo' ) . ' <time datetime="' . esc_attr( $last_reviewed ) . '">' . esc_html( wp_date( get_option( 'date_format' ), $ts ) ) . '</time>';
								}
							}
							echo implode( ' <span class="rnrd-ab-sep">·</span> ', $parts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
						</div>
					<?php endif; ?>

					<?php
					$factcheck_url = (string) get_option( RNRD_OPT_AUTHOR_FACTCHECK_URL, '' );
					$editorial_url = (string) get_option( RNRD_OPT_AUTHOR_EDITORIAL_URL, '' );
					if ( 'card' === $layout && ( $factcheck_url || $editorial_url ) ) :
					?>
						<div class="rnrd-ab-policy">
							<?php if ( $editorial_url ) : ?>
								<a href="<?php echo esc_url( $editorial_url ); ?>" rel="noopener"><?php esc_html_e( 'Editorial policy', 'rankready-ai-llm-seo' ); ?></a>
							<?php endif; ?>
							<?php if ( $editorial_url && $factcheck_url ) : ?> <span class="rnrd-ab-sep">·</span> <?php endif; ?>
							<?php if ( $factcheck_url ) : ?>
								<a href="<?php echo esc_url( $factcheck_url ); ?>" rel="noopener"><?php esc_html_e( 'How we fact-check', 'rankready-ai-llm-seo' ); ?></a>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private static function get_social_links( int $user_id ): array {
		$m = function ( $key ) use ( $user_id ) {
			return (string) get_user_meta( $user_id, $key, true );
		};

		$links = array();
		$candidates = array(
			'LinkedIn' => 'rnrd_author_linkedin',
			'X'        => 'rnrd_author_twitter',
			'GitHub'   => 'rnrd_author_github',
			'YouTube'  => 'rnrd_author_youtube',
			'Scholar'  => 'rnrd_author_scholar',
			'ORCID'    => 'rnrd_author_orcid',
			'Website'  => 'rnrd_author_website',
		);

		foreach ( $candidates as $label => $key ) {
			$val = $m( $key );
			if ( '' === $val ) continue;
			if ( 'ORCID' === $label && 0 !== strpos( $val, 'http' ) ) {
				$val = 'https://orcid.org/' . $val;
			}
			$links[ $label ] = $val;
		}
		return $links;
	}

	/**
	 * The box's CSS custom properties as raw declarations, with no attribute
	 * wrapper. get_block_wrapper_attributes() wants them raw so it can merge
	 * them with the block supports' own style output instead of putting a
	 * second style attribute on the element.
	 */
	private static function style_declarations( array $attrs ): string {
		$vars = array();

		$map = array(
			'boxBgColor'        => '--rnrd-ab-bg',
			'boxBorderColor'    => '--rnrd-ab-border',
			'boxBorderRadius'   => array( '--rnrd-ab-radius', 'px' ),
			'boxPadding'        => array( '--rnrd-ab-padding', 'px' ),
			'headingColor'      => '--rnrd-ab-heading-color',
			'headingFontSize'   => array( '--rnrd-ab-heading-size', 'px' ),
			'headingFontFamily' => '--rnrd-ab-heading-ff',
			'headingFontWeight' => '--rnrd-ab-heading-fw',
			'nameColor'         => '--rnrd-ab-name-color',
			'nameFontSize'      => array( '--rnrd-ab-name-size', 'px' ),
			'nameFontFamily'    => '--rnrd-ab-name-ff',
			'nameFontWeight'    => '--rnrd-ab-name-fw',
			'metaColor'         => '--rnrd-ab-meta-color',
			'metaFontSize'      => array( '--rnrd-ab-meta-size', 'px' ),
			'metaFontFamily'    => '--rnrd-ab-meta-ff',
			'bioColor'          => '--rnrd-ab-bio-color',
			'bioFontSize'       => array( '--rnrd-ab-bio-size', 'px' ),
			'bioFontFamily'     => '--rnrd-ab-bio-ff',
			'bioLineHeight'     => '--rnrd-ab-bio-lh',
			'imageSize'         => array( '--rnrd-ab-img-size', 'px' ),
			'imageRadius'       => array( '--rnrd-ab-img-radius', 'px' ),
			'socialColor'       => '--rnrd-ab-social-color',
			'socialSize'        => array( '--rnrd-ab-social-size', 'px' ),
		);

		foreach ( $map as $attr => $var ) {
			if ( empty( $attrs[ $attr ] ) ) continue;
			$value = $attrs[ $attr ];
			if ( is_array( $var ) ) {
				// plain_number(), not a float concat: locale-dependent on PHP 7.4.
				$vars[] = $var[0] . ':' . RNRD_Util::plain_number( $value ) . $var[1];
				continue;
			}

			// esc_attr() alone is NOT enough here: it blocks attribute breakout but
			// leaves `;` intact, so a post editor could set a colour to
			// "red;background-image:url(https://attacker.example)" and inject a
			// second declaration — an outbound request on render, or a UI-redress
			// overlay. Validate by value kind: one validator for every block.
			$clean = RNRD_Util::sanitize_style_value( $attr, (string) $value );
			if ( '' === $clean ) {
				continue;
			}
			$vars[] = $var . ':' . $clean;
		}

		return empty( $vars ) ? '' : implode( ';', $vars );
	}

	// ══════════════════════════════════════════════════════════════════════════
	// AUTO-DISPLAY via the_content filter
	// ══════════════════════════════════════════════════════════════════════════

	public static function maybe_auto_display( $content ) {
		if ( ! is_singular() || ! is_main_query() || ! in_the_loop() ) return $content;
		if ( ! self::is_enabled() ) return $content;

		$position = (string) get_option( RNRD_OPT_AUTHOR_AUTO_DISPLAY, 'off' );
		if ( 'off' === $position ) return $content;

		$post_id = get_the_ID();
		if ( ! $post_id ) return $content;

		$post = get_post( $post_id );
		if ( ! $post ) return $content;

		// Don't append the author box onto a non-published or password-protected
		// post — the_content runs on the password-form page too.
		if ( 'publish' !== $post->post_status || ! empty( $post->post_password ) ) return $content;

		// Per-post opt-out.
		if ( get_post_meta( $post_id, RNRD_META_AUTHOR_DISABLE, true ) ) return $content;

		// Post type allowlist.
		if ( ! self::is_post_type_enabled( $post->post_type ) ) return $content;

		// Skip if the Gutenberg block or shortcode already places the author box.
		if ( RNRD_Shortcode::post_has_manual( $post, 'rankready/author-box', RNRD_Shortcode::AUTHOR ) ) {
			return $content;
		}

		$html = self::render_html( (int) $post->post_author, array(), $post_id );
		if ( '' === $html ) return $content;

		if ( 'before' === $position ) return $html . $content;
		if ( 'after' === $position )  return $content . $html;
		if ( 'both' === $position )   return $html . $content . $html;

		return $content;
	}

	// ══════════════════════════════════════════════════════════════════════════
	// BLOCK render callback (registered from class-rnrd-block.php)
	// ══════════════════════════════════════════════════════════════════════════

	public static function render_block( $attrs, $content = '', $block = null ): string {
		if ( ! self::is_enabled() ) {
			return '';
		}
		$post_id = get_the_ID();
		if ( ! $post_id ) return '';

		$post = get_post( $post_id );
		if ( ! $post || ! self::is_post_type_enabled( $post->post_type ) ) {
			return '';
		}
		// Same opt-out and password rules as the automatic the_content path.
		if ( ! RNRD_Util::can_emit( (int) $post_id, RNRD_META_AUTHOR_DISABLE ) ) {
			return '';
		}

		$source  = isset( $attrs['authorSource'] ) ? $attrs['authorSource'] : 'post';
		$user_id = 'specific' === $source && ! empty( $attrs['authorId'] )
			? (int) $attrs['authorId']
			: (int) get_post_field( 'post_author', $post_id );

		if ( $user_id <= 0 ) return '';

		return self::render_html( $user_id, (array) $attrs, (int) $post_id, true );
	}

	/**
	 * Block attributes array — exposed for register_block_type in class-rnrd-block.php.
	 */
	public static function block_attributes(): array {
		return array(
			// Source.
			'authorSource'      => array( 'type' => 'string',  'default' => 'post' ),
			'authorId'          => array( 'type' => 'number',  'default' => 0 ),
			// Layout + content toggles.
			'layout'            => array( 'type' => 'string',  'default' => 'card' ),
			'showHeading'       => array( 'type' => 'boolean', 'default' => true ),
			'headingText'       => array( 'type' => 'string',  'default' => '' ),
			'headingTag'        => array( 'type' => 'string',  'default' => '' ),
			'showHeadshot'      => array( 'type' => 'boolean', 'default' => true ),
			'showJobTitle'      => array( 'type' => 'boolean', 'default' => true ),
			'showEmployer'      => array( 'type' => 'boolean', 'default' => true ),
			'showYearsExp'      => array( 'type' => 'boolean', 'default' => true ),
			'showBio'           => array( 'type' => 'boolean', 'default' => true ),
			'showExpertise'     => array( 'type' => 'boolean', 'default' => true ),
			'showCredentials'   => array( 'type' => 'boolean', 'default' => true ),
			'showSocials'       => array( 'type' => 'boolean', 'default' => true ),
			'showReviewed'      => array( 'type' => 'boolean', 'default' => true ),
			// Box styles.
			'boxBgColor'        => array( 'type' => 'string',  'default' => '' ),
			'boxBorderColor'    => array( 'type' => 'string',  'default' => '' ),
			'boxBorderRadius'   => array( 'type' => 'number',  'default' => 0 ),
			'boxPadding'        => array( 'type' => 'number',  'default' => 0 ),
			// Heading typography.
			'headingColor'      => array( 'type' => 'string',  'default' => '' ),
			'headingFontSize'   => array( 'type' => 'number',  'default' => 0 ),
			'headingFontFamily' => array( 'type' => 'string',  'default' => '' ),
			'headingFontWeight' => array( 'type' => 'string',  'default' => '' ),
			// Name typography.
			'nameColor'         => array( 'type' => 'string',  'default' => '' ),
			'nameFontSize'      => array( 'type' => 'number',  'default' => 0 ),
			'nameFontFamily'    => array( 'type' => 'string',  'default' => '' ),
			'nameFontWeight'    => array( 'type' => 'string',  'default' => '' ),
			// Meta typography.
			'metaColor'         => array( 'type' => 'string',  'default' => '' ),
			'metaFontSize'      => array( 'type' => 'number',  'default' => 0 ),
			'metaFontFamily'    => array( 'type' => 'string',  'default' => '' ),
			// Bio typography.
			'bioColor'          => array( 'type' => 'string',  'default' => '' ),
			'bioFontSize'       => array( 'type' => 'number',  'default' => 0 ),
			'bioFontFamily'     => array( 'type' => 'string',  'default' => '' ),
			'bioLineHeight'     => array( 'type' => 'number',  'default' => 0 ),
			// Image.
			'imageSize'         => array( 'type' => 'number',  'default' => 0 ),
			'imageRadius'       => array( 'type' => 'number',  'default' => 0 ),
			// Social icons.
			'socialColor'       => array( 'type' => 'string',  'default' => '' ),
			'socialSize'        => array( 'type' => 'number',  'default' => 0 ),
		);
	}

	/**
	 * Translatable strings for assets/author-profile.js.
	 *
	 * @return array<string, string>
	 */
	private static function profile_js_i18n(): array {
		return array(
		'selectHeadshot' => __( 'Select Headshot', 'rankready-ai-llm-seo' ),
		'useThisImage'   => __( 'Use this image', 'rankready-ai-llm-seo' ),
	);
	}
}
