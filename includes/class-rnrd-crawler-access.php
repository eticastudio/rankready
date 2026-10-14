<?php
/**
 * Crawler Access — per-crawler Allow/Default/Block policy for robots.txt.
 *
 * Owns the canonical AI/LLM crawler user-agent list and the logic that
 * converts the admin UI's Allow/Default/Block selections into the two
 * legacy option arrays (RNRD_OPT_ROBOTS_CRAWLERS / RNRD_OPT_ROBOTS_BLOCKED)
 * that RNRD_Robots reads when building the robots.txt block.
 *
 * Intentionally separate from RNRD_Crawler_Log::BOTS, which exists for
 * Insights traffic recognition and intent classification — a different job
 * with a deliberately smaller, different UA set.
 *
 * @package RankReady
 * @since   1.2.2
 */

defined( 'ABSPATH' ) || exit;

class RNRD_Crawler_Access {

	public static function init(): void {
		// v1.2.1 — Derive the two legacy crawler arrays whenever the Allow /
		// Default / Block map changes. Registered here rather than in RNRD_Admin
		// so it fires for ANY writer — admin form post, WP-CLI, REST — not only
		// an admin request. Priority 5 so the arrays land before anything at the
		// default priority reads them. Both hooks are required: the first save
		// on an existing install ADDS the option, later saves UPDATE it, and the
		// two fire with different argument signatures.
		add_action(
			'add_option_' . RNRD_OPT_ROBOTS_MODE,
			static function ( $option, $value ): void {
				self::apply_robots_mode( (array) $value );
			},
			5,
			2
		);
		add_action(
			'update_option_' . RNRD_OPT_ROBOTS_MODE,
			static function ( $old_value, $value ): void {
				self::apply_robots_mode( (array) $value );
			},
			5,
			2
		);
	}

	/**
	 * Canonical list of AI/LLM crawler user-agents.
	 *
	 * Single source of truth used by both the robots.txt block builder
	 * (RNRD_Robots) and the AI Crawlers admin UI. Lives in this lightweight
	 * class so the public robots.txt serve path never has to autoload the
	 * large RNRD_Admin class.
	 *
	 * @return array<string,array{0:string,1:string}> user-agent => [vendor, purpose]
	 */
	public static function get_llm_crawlers(): array {
		return array(
			// ── OpenAI ────────────────────────────────────────────────────────
			'GPTBot'              => array( 'OpenAI', 'GPT model training data' ),
			'ChatGPT-User'        => array( 'OpenAI', 'ChatGPT browse mode (user-initiated)' ),
			'OAI-SearchBot'       => array( 'OpenAI', 'ChatGPT search results' ),
			// ── Anthropic ─────────────────────────────────────────────────────
			'ClaudeBot'           => array( 'Anthropic', 'Claude AI retrieval + training' ),
			'anthropic-ai'        => array( 'Anthropic', 'Anthropic training data collection' ),
			'Claude-Web'          => array( 'Anthropic', 'Claude AI (legacy identifier)' ),
			// ── Google ────────────────────────────────────────────────────────
			'Google-Extended'     => array( 'Google', 'Gemini AI training (does NOT affect search ranking)' ),
			'GoogleOther'         => array( 'Google', 'Google R&D crawling (non-search)' ),
			// ── Apple ─────────────────────────────────────────────────────────
			'Applebot-Extended'   => array( 'Apple', 'Apple Intelligence / Siri AI features' ),
			// ── Microsoft ─────────────────────────────────────────────────────
			'Bingbot'             => array( 'Microsoft', 'Bing Search + Copilot (shared UA)' ),
			// ── Perplexity ────────────────────────────────────────────────────
			'PerplexityBot'       => array( 'Perplexity', 'Perplexity AI answer engine' ),
			// ── Meta ──────────────────────────────────────────────────────────
			'Meta-ExternalAgent'  => array( 'Meta', 'Meta AI / Llama training' ),
			'Meta-ExternalFetcher' => array( 'Meta', 'Meta AI real-time retrieval' ),
			'FacebookBot'         => array( 'Meta', 'Facebook/Meta content crawling' ),
			// ── Mistral ───────────────────────────────────────────────────────
			'MistralAI-User'      => array( 'Mistral', 'Le Chat real-time browsing' ),
			// ── ByteDance ─────────────────────────────────────────────────────
			'Bytespider'          => array( 'ByteDance', 'TikTok / ByteDance AI' ),
			// ── Amazon ────────────────────────────────────────────────────────
			'Amazonbot'           => array( 'Amazon', 'Alexa AI / Amazon' ),
			// ── Cohere ────────────────────────────────────────────────────────
			'cohere-ai'           => array( 'Cohere', 'Cohere AI RAG & enterprise' ),
			// ── AI Search Engines ─────────────────────────────────────────────
			'DuckAssistBot'       => array( 'DuckDuckGo', 'DuckDuckGo AI Assist' ),
			'YouBot'              => array( 'You.com', 'You.com AI search' ),
			'PhindBot'            => array( 'Phind', 'Phind AI search for developers' ),
			// ── Training / Dataset Crawlers ────────────────────────────────────
			'CCBot'               => array( 'Common Crawl', 'Open dataset used by many LLMs' ),
			'AI2Bot'              => array( 'Allen Institute', 'AI2 research crawler' ),
			'Diffbot'             => array( 'Diffbot', 'Diffbot AI extraction' ),
			'Omgilibot'           => array( 'Webz.io', 'AI content aggregation' ),
			'PetalBot'            => array( 'Huawei', 'Huawei search & AI data' ),
			'Brightbot'           => array( 'BrightEdge', 'AI SEO data crawling' ),
			'magpie-crawler'      => array( 'Magpie', 'AI data collection' ),
			'DataForSeoBot'       => array( 'DataForSEO', 'SEO data with AI uses' ),
		);
	}

	/**
	 * Search / social crawlers that are NOT AI crawlers.
	 *
	 * These deliberately live outside get_llm_crawlers(): they must never join
	 * the permissive `Allow: /` group, because a crawler obeys only the most
	 * specific matching group (RFC 9309 §2.2.1) — putting Googlebot there would
	 * detach it from the site's own `User-agent: *` rules and let it crawl
	 * /checkout/, /wp-admin/ and search pages.
	 *
	 * Instead they get their own group that MIRRORS the site's `*` rules, so
	 * they are explicitly named (which readiness scanners look for) while their
	 * effective permissions stay exactly what the site already granted them.
	 *
	 * @since 1.2.1
	 */
	public static function get_search_social_crawlers(): array {
		return array(
			'Googlebot'           => array( 'Google', 'Google Search' ),
			'FacebookExternalHit' => array( 'Meta', 'Facebook / WhatsApp link previews' ),
		);
	}

	/**
	 * Build the current per-crawler allow/default/block map from stored options.
	 *
	 * Returns a map of user-agent => 'allow' | 'default' | 'block'.
	 *
	 * @return array<string,string>
	 */
	public static function get_robots_mode(): array {
		$crawlers = array_keys( self::get_llm_crawlers() );
		$allow    = (array) get_option( RNRD_OPT_ROBOTS_CRAWLERS, $crawlers );
		$blocked  = (array) get_option( RNRD_OPT_ROBOTS_BLOCKED, array() );

		$mode = array();
		foreach ( $crawlers as $ua ) {
			if ( in_array( $ua, $blocked, true ) ) {
				$mode[ $ua ] = 'block';
			} elseif ( in_array( $ua, $allow, true ) ) {
				$mode[ $ua ] = 'allow';
			} else {
				$mode[ $ua ] = 'default';
			}
		}
		return $mode;
	}

	/**
	 * Write the two legacy arrays from an Allow/Default/Block mode map.
	 *
	 * RNRD_OPT_ROBOTS_CRAWLERS and RNRD_OPT_ROBOTS_BLOCKED stay the source of
	 * truth for robots.txt output. Writing them here also fires their existing
	 * update_option_ hooks, which re-sync the physical robots.txt exactly as before.
	 *
	 * @since 1.2.1
	 * @param array<string,string> $mode user-agent => 'allow'|'block'|'default'
	 */
	public static function apply_robots_mode( array $mode ): void {
		$allow   = array();
		$blocked = array();
		foreach ( array_keys( self::get_llm_crawlers() ) as $ua ) {
			$state = isset( $mode[ $ua ] ) ? (string) $mode[ $ua ] : 'default';
			if ( 'allow' === $state ) {
				$allow[] = $ua;
			} elseif ( 'block' === $state ) {
				$blocked[] = $ua;
			}
			// 'default' — in neither list; crawler follows existing robots.txt rules.
		}
		update_option( RNRD_OPT_ROBOTS_CRAWLERS, $allow );
		update_option( RNRD_OPT_ROBOTS_BLOCKED, $blocked );
	}
}
