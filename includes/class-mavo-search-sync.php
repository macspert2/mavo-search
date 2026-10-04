<?php
/**
 * Keeps the index current as the site changes — one post at a time, never a
 * whole rebuild.
 *
 * Hooks only collect post IDs; the work happens once, at shutdown, so a save
 * that fires save_post, three meta updates and two set_object_terms calls
 * costs one reindex. More than a handful dirtied in one request (a bulk edit,
 * a hub with forty members renamed) and the rest go to a WP-Cron queue.
 *
 *   post saved, trashed, untrashed, status changed     → that post
 *   post deleted                                       → removed at once
 *   its language, tags or indexed taxonomies changed   → that post
 *   Yoast noindex, featured image, indexed custom
 *   fields changed                                     → that post
 *   mavo-hubs membership added / removed               → the member, and
 *                                                        what is below it
 *   a hub marked / unmarked / retitled                 → everything below it
 *   mavo-image-index: a post's image usages changed    → that post
 *   mavo-image-index: an image's alt / concepts changed → the posts using it
 *   theme: a place's landing page changed              → old and new page
 *
 * Not seen by any hook: a place renamed in geotag-plus's place editor. The
 * posts keep the old name until their next save or "Rebuild all".
 *
 * mavo-image-index does its own work at shutdown too and reports what changed
 * through its actions; this flush runs after it (priority 20), and anything
 * reported later still is queued for WP-Cron.
 */

defined( 'ABSPATH' ) || exit;

class MVS_Sync {

	const CRON_HOOK    = 'mavo_search_process_queue';
	const QUEUE_OPTION = 'mavo_search_queue';
	const INLINE_MAX   = 20;
	const CRON_BATCH   = 200;

	private static array $posts   = [];
	private static bool $hooked   = false;
	private static bool $flushed  = false;

	public static function init(): void {
		// After mavo-geotag-plus, which tags places on save_post at 20.
		add_action( 'save_post', [ __CLASS__, 'on_save_post' ], 30, 2 );
		add_action( 'post_updated', [ __CLASS__, 'on_post_updated' ], 10, 3 );
		add_action( 'trashed_post', [ __CLASS__, 'queue_post' ] );
		add_action( 'untrashed_post', [ __CLASS__, 'queue_post' ] );
		add_action( 'deleted_post', [ __CLASS__, 'on_deleted_post' ] );
		add_action( 'set_object_terms', [ __CLASS__, 'on_terms' ], 10, 4 );

		foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $hook ) {
			add_action( $hook, [ __CLASS__, 'on_meta' ], 10, 3 );
		}

		add_action( 'mavo_hub_membership_added', [ __CLASS__, 'on_membership' ], 10, 2 );
		add_action( 'mavo_hub_membership_removed', [ __CLASS__, 'on_membership' ], 10, 2 );
		add_action( 'mavo_hub_marked', [ __CLASS__, 'on_hub' ] );
		add_action( 'mavo_hub_unmarked', [ __CLASS__, 'on_hub' ] );

		add_action( 'mavo_image_usage_updated', [ __CLASS__, 'on_image_usage' ], 10, 2 );
		add_action( 'mavo_image_indexed', [ __CLASS__, 'on_image_indexed' ] );

		add_action( 'mavo_geo_term_url_changed', [ __CLASS__, 'on_landing_page' ], 10, 3 );

		add_action( self::CRON_HOOK, [ __CLASS__, 'process_queue' ] );
	}

	public static function on_save_post( $post_id, $post ): void {
		if ( ! $post instanceof WP_Post || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( in_array( $post->post_type, MVS_Document::post_types(), true ) ) {
			self::queue_post( (int) $post_id );
		}
	}

	/** A hub retitled: every post showing its title in the hub field. */
	public static function on_post_updated( $post_id, $after, $before ): void {
		if ( $after instanceof WP_Post && $before instanceof WP_Post && $after->post_title !== $before->post_title && MVS_Hubs::is_hub( (int) $post_id ) ) {
			self::queue_posts( MVS_Hubs::affected_by( (int) $post_id ) );
		}
	}

	public static function on_deleted_post( $post_id ): void {
		MVS_Indexer::purge( (int) $post_id );
		unset( self::$posts[ (int) $post_id ] );
	}

	public static function on_terms( $object_id, $terms, $tt_ids, $taxonomy ): void {
		if ( in_array( $taxonomy, array_merge( [ 'language', 'post_tag' ], MVS_Document::taxonomies() ), true ) ) {
			self::queue_post( (int) $object_id );
		}
	}

	public static function on_meta( $meta_ids, $object_id, $meta_key ): void {
		$keys = array_merge( [ MVS_Document::NOINDEX_META, '_thumbnail_id' ], MVS_Document::custom_fields() );

		if ( in_array( (string) $meta_key, $keys, true ) ) {
			self::queue_post( (int) $object_id );
		}
	}

	public static function on_membership( $child_id, $hub_id ): void {
		self::queue_post( (int) $child_id );
		self::queue_posts( MVS_Hubs::affected_by( (int) $child_id ) );
	}

	public static function on_hub( $hub_id ): void {
		self::queue_post( (int) $hub_id );
		self::queue_posts( MVS_Hubs::affected_by( (int) $hub_id ) );
	}

	public static function on_image_usage( $attachment_id, $post_id ): void {
		self::queue_post( (int) $post_id );
	}

	public static function on_image_indexed( $attachment_id ): void {
		self::queue_posts( MVS_Images::posts_using( (int) $attachment_id ) );
	}

	public static function on_landing_page( $term_id, $page_id, $old_page_id ): void {
		self::queue_posts( [ (int) $page_id, (int) $old_page_id ] );
	}

	public static function queue_post( $post_id ): void {
		self::queue_posts( [ (int) $post_id ] );
	}

	/** @param int[] $post_ids */
	public static function queue_posts( array $post_ids ): void {
		$post_ids = array_filter( array_map( 'intval', $post_ids ) );

		if ( ! $post_ids ) {
			return;
		}

		// Reported after this request's flush: WP-Cron takes them.
		if ( self::$flushed ) {
			self::defer( $post_ids );
			return;
		}

		foreach ( $post_ids as $id ) {
			self::$posts[ $id ] = true;
		}

		if ( ! self::$hooked ) {
			self::$hooked = true;
			add_action( 'shutdown', [ __CLASS__, 'flush' ], 20 );
		}
	}

	/** Process what this request dirtied. */
	public static function flush(): void {
		$posts         = array_keys( self::$posts );
		self::$posts   = [];
		self::$flushed = true;

		if ( ! $posts ) {
			return;
		}

		self::defer( array_slice( $posts, self::INLINE_MAX ) );
		$now = array_slice( $posts, 0, self::INLINE_MAX );

		try {
			MVS_Indexer::index( $now );
		} catch ( Throwable $e ) {
			// Never break a save over the index. The queue retries it.
			self::defer( $now );
		}
	}

	/** WP-Cron: work through the deferred queue, a batch at a time. */
	public static function process_queue(): void {
		$queue = self::stored_queue();
		$batch = array_splice( $queue, 0, self::CRON_BATCH );

		update_option( self::QUEUE_OPTION, $queue, false );

		if ( $batch ) {
			MVS_Indexer::index( $batch );
		}

		if ( $queue ) {
			wp_schedule_single_event( time() + 30, self::CRON_HOOK );
		}
	}

	/** @return int[] */
	public static function stored_queue(): array {
		return array_values( array_map( 'intval', (array) get_option( self::QUEUE_OPTION, [] ) ) );
	}

	/** For tests. */
	public static function reset(): void {
		self::$posts   = [];
		self::$hooked  = false;
		self::$flushed = false;
	}

	/* -------------------------------------------------------------- private */

	private static function defer( array $post_ids ): void {
		if ( ! $post_ids ) {
			return;
		}

		update_option( self::QUEUE_OPTION, array_values( array_unique( array_merge( self::stored_queue(), $post_ids ) ) ), false );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 10, self::CRON_HOOK );
		}
	}
}
