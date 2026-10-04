<?php
/**
 * The site's ?s= search, answered from the index.
 *
 * On posts_pre_query, for the main front-end search query only, the ranked
 * page of results replaces WordPress's own SQL: $wp_query->posts holds
 * ordinary WP_Post objects in rank order, found_posts and max_num_pages are
 * set, so the theme's loop, pagination and no-results template work as they
 * did. Each post's post_excerpt carries its contextual, highlighted excerpt —
 * what Relevanssi did — so content.php's get_the_excerpt() shows it unchanged.
 *
 * Not intercepted: admin screens, REST, secondary queries, a post_type this
 * index does not hold, or anything mavo_search_search_ok refuses. Nor
 * anything before the first full rebuild has completed — until then WordPress
 * (or Relevanssi) keeps answering, so activating the plugin never empties the
 * results page.
 *
 * Relevanssi may stay active as a fallback: for every query handled here its
 * own relevanssi_search_ok is answered false, so the two never both run.
 * Deactivating this plugin hands search straight back to it.
 */

defined( 'ABSPATH' ) || exit;

class MVS_WP {

	const READY_OPTION = 'mavo_search_ready';

	/** @var array<int,array> post_id => result of the current search */
	private static array $hits  = [];
	private static ?array $last = null;

	public static function init(): void {
		add_filter( 'posts_pre_query', [ __CLASS__, 'pre_query' ], 10, 2 );
		add_filter( 'relevanssi_search_ok', [ __CLASS__, 'relevanssi_search_ok' ], 99, 2 );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
	}

	public static function ready(): bool {
		return (bool) get_option( self::READY_OPTION, false );
	}

	/** Is this query one Mavo Search answers? */
	public static function handles( $query ): bool {
		$ok = $query instanceof WP_Query
			&& ! is_admin()
			&& $query->is_main_query()
			&& $query->is_search()
			&& '' !== trim( (string) $query->get( 's' ) )
			&& self::ready()
			&& self::types_ok( $query->get( 'post_type' ) );

		/** Whether Mavo Search answers this query. */
		return (bool) apply_filters( 'mavo_search_search_ok', $ok, $query );
	}

	public static function relevanssi_search_ok( $ok, $query = null ) {
		return $query instanceof WP_Query && self::handles( $query ) ? false : $ok;
	}

	/**
	 * @param WP_Post[]|int[]|null $posts
	 * @return WP_Post[]|int[]|null
	 */
	public static function pre_query( $posts, $query ) {
		if ( null !== $posts || ! self::handles( $query ) ) {
			return $posts;
		}

		$per_page = (int) $query->get( 'posts_per_page' );
		$per_page = $per_page > 0 ? $per_page : ( -1 === $per_page ? 100 : (int) get_option( 'posts_per_page', 10 ) );
		$page     = max( 1, (int) $query->get( 'paged' ) );
		$type     = $query->get( 'post_type' );

		$result = MVS_Engine::search( (string) $query->get( 's' ), [
			'lang'       => MVS_Lang::current(),
			'page'       => $page,
			'per_page'   => $per_page,
			'post_types' => '' === $type || 'any' === $type ? null : $type,
			/** How many guides (hub and landing pages about the whole query) to show apart from the results; 0 keeps them in the list. */
			'guides'     => max( 0, (int) apply_filters( 'mavo_search_guides', 3 ) ),
		] );

		self::$last = $result;
		self::$hits = array_column( $result['results'], null, 'post_id' );

		$query->found_posts   = $result['total'];
		$query->max_num_pages = $result['pages'];

		if ( 1 === $page && ! current_user_can( 'edit_posts' ) ) {
			MVS_Log::record( $result['query'], $result['lang'], $result['total'], $result['fallback'] );
		}

		$ids = array_map( 'intval', array_column( $result['results'], 'post_id' ) );

		if ( 'ids' === $query->get( 'fields' ) ) {
			return $ids;
		}

		_prime_post_caches( $ids, true, true );

		/** Whether a result's post_excerpt is replaced by its search excerpt. */
		$replace = (bool) apply_filters( 'mavo_search_replace_excerpt', true );
		$out     = [];

		foreach ( $ids as $id ) {
			$post = get_post( $id );

			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			if ( $replace && isset( self::$hits[ $id ]['excerpt'] ) ) {
				$post->post_excerpt = self::$hits[ $id ]['excerpt'];
			}

			$out[] = $post;
		}

		return $out;
	}

	/** The current search's result for one post, or null. */
	public static function hit( int $post_id ): ?array {
		return self::$hits[ $post_id ] ?? null;
	}

	/** The current search's whole result (total, fallback, …) without its results list, or null. */
	public static function last(): ?array {
		if ( null === self::$last ) {
			return null;
		}

		$out = self::$last;
		unset( $out['results'] );

		return $out;
	}

	public static function enqueue(): void {
		if ( ! is_search() || ! self::ready() ) {
			return;
		}

		wp_enqueue_style( 'mavo-search', MVS_PLUGIN_URL . 'assets/search.css', [], MVS_VERSION );

		// Click counting (MVS_Clicks): only on a search answered here, and not
		// for the people who edit the site — the log leaves them out too.
		if ( null !== self::$last && MVS_Log::enabled() && ! current_user_can( 'edit_posts' ) ) {
			wp_enqueue_script( 'mavo-search-clicks', MVS_PLUGIN_URL . 'assets/clicks.js', [], MVS_VERSION, true );
			wp_localize_script( 'mavo-search-clicks', 'MAVO_SEARCH_CLICKS', [
				'endpoint' => rest_url( MVS_Clicks::REST_NS . '/click' ),
				'query'    => self::$last['query'],
				'lang'     => self::$last['lang'],
			] );
		}
	}

	/** For tests. */
	public static function reset(): void {
		self::$hits = [];
		self::$last = null;
	}

	/* -------------------------------------------------------------- private */

	private static function types_ok( $type ): bool {
		if ( '' === $type || null === $type || 'any' === $type || [] === $type ) {
			return true;
		}

		return ! array_diff( (array) $type, MVS_Document::post_types() );
	}
}
