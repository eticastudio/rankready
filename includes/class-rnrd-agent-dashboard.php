<?php
/**
 * RankReady — WP dashboard widget.
 *
 * Compact Insights KPIs (same structure as the RankReady Dashboard Insights
 * card) plus one-line links into Insights, AI Visibility, and AI Content.
 *
 * @package RankReady
 * @since   1.2.0-beta.2
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Agent_Dashboard {

	public static function init(): void {
		add_action( 'wp_dashboard_setup', array( self::class, 'register_widget' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
	}

	public static function register_widget(): void {
		// The links in the widget open the RankReady admin screen, which needs
		// manage_options. Show the widget only to people who can follow them.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'rnrd_agent_dashboard',
			__( 'RankReady', 'rankready-ai-llm-seo' ),
			array( self::class, 'render' )
		);
	}

	/**
	 * Lean KPI stylesheet on the main WP dashboard only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( string $hook ): void {
		if ( 'index.php' !== $hook || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$path = RNRD_DIR . 'assets/dashboard-widget.css';
		$ver  = file_exists( $path ) ? RNRD_VERSION . '.' . (string) filemtime( $path ) : RNRD_VERSION;
		wp_enqueue_style(
			'rnrd-dashboard-widget',
			RNRD_URL . 'assets/dashboard-widget.css',
			array(),
			$ver
		);
	}

	/**
	 * Compact Insights summary — same KPI anatomy as Dashboard → Insights.
	 */
	public static function render(): void {
		$stale = 0;
		if ( class_exists( 'RNRD_Freshness' ) && method_exists( 'RNRD_Freshness', 'bucket_counts' ) ) {
			$buckets = RNRD_Freshness::bucket_counts();
			$stale   = (int) ( $buckets['stale'] ?? 0 );
		}

		$insights_url   = admin_url( 'admin.php?page=rankready-ai-llm-seo&tab=insights' );
		$visibility_url = admin_url( 'admin.php?page=rankready-ai-llm-seo&tab=crawlers' );
		$content_url    = admin_url( 'admin.php?page=rankready-ai-llm-seo&tab=content' );

		$training_on     = 'on' === get_option( RNRD_OPT_AI_TRAINING_ENABLE, 'on' );
		$citation_on     = 'on' === get_option( RNRD_OPT_AI_CITATION_ENABLE, 'on' );
		$surfaces_on     = class_exists( 'RNRD_Crawler_Log' ) && RNRD_Crawler_Log::has_loggable_endpoints();
		$training_active = $training_on && $surfaces_on;
		$citation_active = $citation_on && $surfaces_on;
		$training        = $training_active ? self::sum_bot_intent( 'training' ) : 0;
		$citation        = $citation_active ? self::sum_bot_intent( 'citation' ) : 0;
		$referral_on = 'on' === get_option( RNRD_OPT_AI_REFERRAL_ENABLE, 'on' );
		$referrals = ( $referral_on && class_exists( 'RNRD_AI_Referral' ) ) ? (int) RNRD_AI_Referral::total_last_n_days( 30 ) : 0;
		?>
		<div class="rnrd-agent-dashboard">
			<div class="rnrd-kpi-row" role="group" aria-label="<?php esc_attr_e( 'Insights summary', 'rankready-ai-llm-seo' ); ?>">
				<a class="rnrd-kpi rnrd-kpi--link" href="<?php echo esc_url( $insights_url . '&sub=bot-activity' ); ?>" aria-label="<?php esc_attr_e( 'Training Bots — open Insights', 'rankready-ai-llm-seo' ); ?>">
					<div class="rnrd-kpi__title">
						<div class="rnrd-kpi__label"><?php esc_html_e( 'Training Bots', 'rankready-ai-llm-seo' ); ?></div>
						<span class="rnrd-kpi__go" aria-hidden="true"></span>
					</div>
					<?php if ( $training_active ) : ?>
						<div class="rnrd-kpi__period"><?php esc_html_e( 'Last 30 days', 'rankready-ai-llm-seo' ); ?></div>
						<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $training ) ); ?></div>
						<div class="rnrd-kpi__foot"><?php esc_html_e( 'AI crawler hits', 'rankready-ai-llm-seo' ); ?></div>
					<?php else : ?>
						<div class="rnrd-kpi__period"><?php esc_html_e( 'Disabled', 'rankready-ai-llm-seo' ); ?></div>
						<div class="rnrd-kpi__value"><?php esc_html_e( 'Off', 'rankready-ai-llm-seo' ); ?></div>
						<div class="rnrd-kpi__foot"><?php echo esc_html( $training_on ? __( 'llms.txt and Markdown are off', 'rankready-ai-llm-seo' ) : __( 'Training bot logging paused', 'rankready-ai-llm-seo' ) ); ?></div>
					<?php endif; ?>
				</a>
				<a class="rnrd-kpi rnrd-kpi--link" href="<?php echo esc_url( $insights_url . '&sub=citation' ); ?>" aria-label="<?php esc_attr_e( 'Answer-engine bots — open Insights', 'rankready-ai-llm-seo' ); ?>">
					<div class="rnrd-kpi__title">
						<div class="rnrd-kpi__label"><?php esc_html_e( 'Answer-engine bots', 'rankready-ai-llm-seo' ); ?></div>
						<span class="rnrd-kpi__go" aria-hidden="true"></span>
					</div>
					<?php if ( $citation_active ) : ?>
						<div class="rnrd-kpi__period"><?php esc_html_e( 'Last 30 days', 'rankready-ai-llm-seo' ); ?></div>
						<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $citation ) ); ?></div>
						<div class="rnrd-kpi__foot"><?php esc_html_e( 'live answer bots', 'rankready-ai-llm-seo' ); ?></div>
					<?php else : ?>
						<div class="rnrd-kpi__period"><?php esc_html_e( 'Disabled', 'rankready-ai-llm-seo' ); ?></div>
						<div class="rnrd-kpi__value"><?php esc_html_e( 'Off', 'rankready-ai-llm-seo' ); ?></div>
						<div class="rnrd-kpi__foot"><?php echo esc_html( $citation_on ? __( 'llms.txt and Markdown are off', 'rankready-ai-llm-seo' ) : __( 'Answer-engine bot logging paused', 'rankready-ai-llm-seo' ) ); ?></div>
					<?php endif; ?>
				</a>
				<a class="rnrd-kpi rnrd-kpi--link" href="<?php echo esc_url( $insights_url . '&sub=referral' ); ?>" aria-label="<?php esc_attr_e( 'Real AI Referrals — open Insights', 'rankready-ai-llm-seo' ); ?>">
					<div class="rnrd-kpi__title">
						<div class="rnrd-kpi__label"><?php esc_html_e( 'Real AI Referrals', 'rankready-ai-llm-seo' ); ?></div>
						<span class="rnrd-kpi__go" aria-hidden="true"></span>
					</div>
					<?php if ( $referral_on ) : ?>
						<div class="rnrd-kpi__period"><?php esc_html_e( 'Last 30 days', 'rankready-ai-llm-seo' ); ?></div>
						<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $referrals ) ); ?></div>
						<div class="rnrd-kpi__foot"><?php esc_html_e( 'visitors from AI apps', 'rankready-ai-llm-seo' ); ?></div>
					<?php else : ?>
						<div class="rnrd-kpi__period"><?php esc_html_e( 'Disabled', 'rankready-ai-llm-seo' ); ?></div>
						<div class="rnrd-kpi__value"><?php esc_html_e( 'Off', 'rankready-ai-llm-seo' ); ?></div>
						<div class="rnrd-kpi__foot"><?php esc_html_e( 'Referer tracking paused', 'rankready-ai-llm-seo' ); ?></div>
					<?php endif; ?>
				</a>
				<a class="rnrd-kpi rnrd-kpi--link" href="<?php echo esc_url( $insights_url . '&sub=freshness' ); ?>" aria-label="<?php esc_attr_e( 'Content Fresh — open Insights', 'rankready-ai-llm-seo' ); ?>">
					<div class="rnrd-kpi__title">
						<div class="rnrd-kpi__label"><?php esc_html_e( 'Content Fresh', 'rankready-ai-llm-seo' ); ?></div>
						<span class="rnrd-kpi__go" aria-hidden="true"></span>
					</div>
					<div class="rnrd-kpi__period"><?php
					/* translators: %d: number of days after which a post counts as stale */
					echo esc_html( sprintf( __( '%d+ days old', 'rankready-ai-llm-seo' ), ( class_exists( 'RNRD_Freshness' ) ? RNRD_Freshness::stale_days() : 0 ) ) );
					?></div>
					<div class="rnrd-kpi__value"><?php echo esc_html( number_format_i18n( $stale ) ); ?></div>
					<div class="rnrd-kpi__foot"><?php esc_html_e( 'posts going stale', 'rankready-ai-llm-seo' ); ?></div>
				</a>
			</div>
			<p class="rnrd-agent-dashboard__links">
				<a class="rnrd-agent-dashboard__links-primary" href="<?php echo esc_url( $insights_url ); ?>"><?php esc_html_e( 'View Full Insights', 'rankready-ai-llm-seo' ); ?></a>
				<span class="rnrd-agent-dashboard__links-sep" aria-hidden="true">·</span>
				<a href="<?php echo esc_url( $visibility_url ); ?>"><?php esc_html_e( 'AI Visibility Settings', 'rankready-ai-llm-seo' ); ?></a>
				<span class="rnrd-agent-dashboard__links-sep" aria-hidden="true">·</span>
				<a href="<?php echo esc_url( $content_url ); ?>"><?php esc_html_e( 'AI Content Settings', 'rankready-ai-llm-seo' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Sum the last-30-day hit totals for every logged bot whose intent matches
	 * $intent ('training' | 'citation'). Returns 0 when the crawler log is
	 * unavailable.
	 */
	private static function sum_bot_intent( string $intent ): int {
		if ( ! class_exists( 'RNRD_Crawler_Log' ) ) {
			return 0;
		}
		$sum = 0;
		foreach ( (array) RNRD_Crawler_Log::get_bot_stats( 30 ) as $row ) {
			$bot = isset( $row['bot_name'] ) ? (string) $row['bot_name'] : '';
			if ( '' !== $bot && RNRD_Crawler_Log::bot_intent( $bot ) === $intent ) {
				$sum += isset( $row['total'] ) ? (int) $row['total'] : 0;
			}
		}
		return $sum;
	}
}
