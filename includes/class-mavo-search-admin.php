<?php
/**
 * Tools → Mavo Search: is the index complete and current, what feeds it, how
 * does a query rank and why, and what do visitors search for. Rebuilds run as
 * a chain of small AJAX requests (MVS_Rebuild::step), never as one long one.
 */

defined( 'ABSPATH' ) || exit;

class MVS_Admin {

	const PAGE_SLUG  = 'mavo-search';
	const CAPABILITY = 'manage_options';
	const AJAX       = 'mvs_rebuild';

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_page' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_action( 'wp_ajax_' . self::AJAX, [ __CLASS__, 'ajax_rebuild' ] );
		add_action( 'admin_post_mvs_reindex_post', [ __CLASS__, 'handle_reindex_post' ] );
		add_action( 'admin_post_mvs_save_settings', [ __CLASS__, 'handle_save_settings' ] );
	}

	public static function add_page(): void {
		add_management_page(
			__( 'Mavo Search', 'mavo-search' ),
			__( 'Mavo Search', 'mavo-search' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function enqueue( string $hook ): void {
		if ( 'tools_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'mvs-admin', MVS_PLUGIN_URL . 'assets/admin.css', [], MVS_VERSION );
		wp_enqueue_style( 'mavo-search', MVS_PLUGIN_URL . 'assets/search.css', [], MVS_VERSION );
		wp_enqueue_script( 'mvs-admin', MVS_PLUGIN_URL . 'assets/admin.js', [], MVS_VERSION, true );
		wp_localize_script( 'mvs-admin', 'MVS_ADMIN', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'action'  => self::AJAX,
			'nonce'   => wp_create_nonce( self::AJAX ),
			'i18n'    => [
				'running' => __( 'Running %1$s: %2$d done', 'mavo-search' ),
				'of'      => __( 'of %d', 'mavo-search' ),
				'done'    => __( 'Finished. %d failed. Reload to see the new counts.', 'mavo-search' ),
				'error'   => __( 'Stopped: %s. Run it again to resume.', 'mavo-search' ),
			],
		] );
	}

	/* ---------------------------------------------------------------- AJAX */

	public static function ajax_rebuild(): void {
		check_ajax_referer( self::AJAX, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}

		$mode   = sanitize_key( wp_unslash( $_POST['mode'] ?? '' ) );
		$cursor = absint( $_POST['cursor'] ?? 0 );

		if ( ! in_array( $mode, MVS_Rebuild::MODES, true ) ) {
			wp_send_json_error( [ 'message' => 'unknown mode' ], 400 );
		}

		wp_send_json_success( MVS_Rebuild::step( $mode, $cursor ) );
	}

	/* -------------------------------------------------------- form handlers */

	public static function handle_reindex_post(): void {
		self::guard( 'mvs_reindex_post' );

		$id     = absint( $_POST['post_id'] ?? 0 );
		$result = $id ? ( MVS_Indexer::index( [ $id ], true )[ $id ] ?? 'removed' ) : 'removed';

		self::back( [ 'mvs_notice' => 'post', 'mvs_id' => $id, 'mvs_result' => $result ] );
	}

	public static function handle_save_settings(): void {
		self::guard( 'mvs_save_settings' );

		update_option( MVS_Log::ENABLED_OPTION, empty( $_POST['log_enabled'] ) ? '0' : '1', false );

		self::back( [ 'mvs_notice' => 'settings' ] );
	}

	/* ---------------------------------------------------------------- page */

	public static function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage the search index.', 'mavo-search' ) );
		}

		$status = MVS_Status::summary();
		?>
		<div class="wrap mvs">
			<h1><?php esc_html_e( 'Mavo Search', 'mavo-search' ); ?></h1>

			<?php self::render_notice(); ?>
			<?php self::render_readiness( $status ); ?>

			<h2><?php esc_html_e( 'Index', 'mavo-search' ); ?></h2>
			<?php self::render_status( $status ); ?>

			<h2><?php esc_html_e( 'Rebuild', 'mavo-search' ); ?></h2>
			<p class="mvs__actions">
				<button type="button" class="button button-primary" data-mvs-modes="all"><?php esc_html_e( 'Rebuild all', 'mavo-search' ); ?></button>
				<button type="button" class="button" data-mvs-modes="stale"><?php esc_html_e( 'Rebuild stale', 'mavo-search' ); ?></button>
			</p>
			<p class="mvs__progress" id="mvs-progress" aria-live="polite"></p>
			<p class="description"><?php esc_html_e( 'Keep this tab open while a rebuild runs. Stopping is safe: running it again resumes. Saves keep the index current on their own; “Rebuild stale” catches up after a deploy that changed the index rules, “Rebuild all” after renaming places in geotag-plus.', 'mavo-search' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mvs__inline">
				<input type="hidden" name="action" value="mvs_reindex_post">
				<?php wp_nonce_field( 'mvs_reindex_post' ); ?>
				<label for="mvs-post"><?php esc_html_e( 'Reindex one post:', 'mavo-search' ); ?></label>
				<input type="number" min="1" id="mvs-post" name="post_id" class="small-text" required>
				<?php submit_button( __( 'Reindex post', 'mavo-search' ), 'secondary', 'submit', false ); ?>
			</form>

			<?php self::render_test(); ?>
			<?php self::render_integrations(); ?>
			<?php self::render_weights(); ?>
			<?php self::render_log(); ?>
		</div>
		<?php
	}

	/* -------------------------------------------------------------- private */

	private static function render_readiness( array $status ): void {
		$background = $status['background'];

		if ( $status['ready'] ) {
			return;
		}
		?>
		<div class="notice notice-warning inline"><p>
			<?php esc_html_e( 'The index is not complete yet, so the site’s searches are still answered by WordPress (or Relevanssi). Mavo Search takes over by itself as soon as one full rebuild has finished.', 'mavo-search' ); ?>
			<?php if ( $background ) : ?>
				<?php
				printf(
					/* translators: 1: posts done, 2: total */
					esc_html__( 'A background rebuild is running: %1$d of %2$s posts. “Rebuild all” here is faster.', 'mavo-search' ),
					(int) $background['done'],
					isset( $background['total'] ) ? esc_html( number_format_i18n( (int) $background['total'] ) ) : '?'
				);
				?>
			<?php endif; ?>
		</p></div>
		<?php
	}

	private static function render_status( array $status ): void {
		?>
		<table class="widefat striped mvs__table">
			<thead><tr>
				<th><?php esc_html_e( 'Should be indexed', 'mavo-search' ); ?></th>
				<th><?php esc_html_e( 'Current', 'mavo-search' ); ?></th>
				<th><?php esc_html_e( 'Stale', 'mavo-search' ); ?></th>
				<th><?php esc_html_e( 'Missing', 'mavo-search' ); ?></th>
				<th><?php esc_html_e( 'Failed', 'mavo-search' ); ?></th>
				<th><?php esc_html_e( 'Orphaned', 'mavo-search' ); ?></th>
			</tr></thead>
			<tbody><tr>
				<td><?php echo esc_html( number_format_i18n( $status['eligible'] ) ); ?></td>
				<td><?php echo esc_html( number_format_i18n( $status['current'] ) ); ?></td>
				<?php foreach ( [ 'stale', 'missing', 'failed', 'orphans' ] as $key ) : ?>
					<td class="<?php echo $status[ $key ] ? 'mvs__warn' : ''; ?>"><?php echo esc_html( number_format_i18n( $status[ $key ] ) ); ?></td>
				<?php endforeach; ?>
			</tr></tbody>
		</table>

		<dl class="mvs__facts">
			<dt><?php esc_html_e( 'Documents', 'mavo-search' ); ?></dt>
			<dd>
				<?php echo esc_html( number_format_i18n( $status['documents'] ) ); ?>
				<?php
				$parts = [];
				foreach ( $status['by_lang'] as $lang => $n ) {
					$parts[] = strtoupper( $lang ) . ' ' . number_format_i18n( $n );
				}
				echo $parts ? esc_html( '(' . implode( ', ', $parts ) . ')' ) : '';
				?>
			</dd>
			<dt><?php esc_html_e( 'Term rows', 'mavo-search' ); ?></dt>
			<dd>
				<?php
				printf(
					/* translators: 1: rows, 2: distinct terms */
					esc_html__( '%1$s rows, %2$s distinct terms', 'mavo-search' ),
					esc_html( number_format_i18n( $status['term_rows'] ) ),
					esc_html( number_format_i18n( $status['distinct_terms'] ) )
				);
				?>
				<br><span class="description">
				<?php
				$parts = [];
				foreach ( $status['rows_by_field'] as $field => $n ) {
					$parts[] = $field . ' ' . number_format_i18n( $n );
				}
				echo esc_html( implode( ' · ', $parts ) );
				?>
				</span>
			</dd>
			<?php if ( null !== $status['size'] ) : ?>
				<dt><?php esc_html_e( 'Size on disk', 'mavo-search' ); ?></dt>
				<dd><?php echo esc_html( size_format( $status['size'] ) ); ?></dd>
			<?php endif; ?>
			<dt><?php esc_html_e( 'Last rebuild', 'mavo-search' ); ?></dt>
			<dd>
				<?php
				$parts = [];
				foreach ( $status['last_rebuild'] as $mode => $time ) {
					$parts[] = $mode . ': ' . wp_date( 'Y-m-d H:i', (int) $time );
				}
				echo $parts ? esc_html( implode( ' · ', $parts ) ) : esc_html__( 'never', 'mavo-search' );
				?>
			</dd>
			<dt><?php esc_html_e( 'Serving searches', 'mavo-search' ); ?></dt>
			<dd><?php echo $status['ready'] ? esc_html__( 'yes', 'mavo-search' ) : '<span class="mvs__warn">' . esc_html__( 'not yet', 'mavo-search' ) . '</span>'; ?></dd>
			<?php if ( $status['queue'] ) : ?>
				<dt><?php esc_html_e( 'Deferred queue', 'mavo-search' ); ?></dt>
				<dd><?php printf( esc_html__( '%d posts waiting for WP-Cron', 'mavo-search' ), (int) $status['queue'] ); ?></dd>
			<?php endif; ?>
			<dt><?php esc_html_e( 'Versions', 'mavo-search' ); ?></dt>
			<dd><?php printf( esc_html__( 'schema %1$d · index rules %2$s', 'mavo-search' ), (int) $status['db_version'], '<code>' . esc_html( substr( $status['rules_version'], 0, 8 ) ) . '</code>' ); ?></dd>
		</dl>

		<?php $failures = $status['failed'] ? MVS_Status::failures( 10 ) : []; ?>
		<?php if ( $failures ) : ?>
			<table class="widefat striped mvs__table">
				<thead><tr><th><?php esc_html_e( 'Failed post', 'mavo-search' ); ?></th><th><?php esc_html_e( 'Error', 'mavo-search' ); ?></th><th><?php esc_html_e( 'When', 'mavo-search' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $failures as $row ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( (string) get_edit_post_link( (int) $row['post_id'] ) ); ?>">#<?php echo (int) $row['post_id']; ?></a></td>
							<td><?php echo esc_html( $row['error'] ); ?></td>
							<td><?php echo esc_html( $row['indexed_at'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	private static function render_test(): void {
		$lang  = MVS_Lang::default_language();
		$query = '';
		$ran   = false;

		if ( isset( $_POST['mvs_test'] ) && check_admin_referer( 'mvs_test' ) ) {
			$lang  = MVS_Lang::normalize( sanitize_key( wp_unslash( $_POST['lang'] ?? '' ) ) ) ?? $lang;
			$query = MVS_Query::clean( sanitize_text_field( wp_unslash( $_POST['query'] ?? '' ) ) );
			$ran   = '' !== $query;
		}
		?>
		<h2 id="mvs-test"><?php esc_html_e( 'Test a search', 'mavo-search' ); ?></h2>
		<form method="post" action="#mvs-test" class="mvs__test">
			<?php wp_nonce_field( 'mvs_test' ); ?>
			<select name="lang">
				<?php foreach ( MVS_Lang::languages() as $option ) : ?>
					<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $option, $lang ); ?>><?php echo esc_html( strtoupper( $option ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="search" name="query" class="large-text" value="<?php echo esc_attr( $query ); ?>" placeholder="<?php esc_attr_e( 'où dormir à Londres', 'mavo-search' ); ?>">
			<?php submit_button( __( 'Search', 'mavo-search' ), 'secondary', 'mvs_test', false ); ?>
		</form>

		<?php if ( ! $ran ) : ?>
			<?php return; ?>
		<?php endif; ?>

		<?php
		$result = mavo_search( $query, [ 'lang' => $lang, 'per_page' => 20, 'explain' => true ] );
		$parsed = $result['parsed'];
		?>
		<p class="description">
			<?php
			$groups = [];
			foreach ( $parsed['groups'] as $group ) {
				$variants = [];
				foreach ( $group['variants'] as $term => $v ) {
					$variants[] = $term . ( 'word' === $v['kind'] ? '' : ' (' . $v['kind'] . ')' );
				}
				$groups[] = '[' . implode( ' | ', $variants ) . ( $group['prefix'] ? ' | ' . $group['token'] . '…' : '' ) . ']';
			}
			printf(
				/* translators: 1: word groups, 2: total, 3: fallback mode */
				esc_html__( 'Read as %1$s — %2$d results, fallback: %3$s', 'mavo-search' ),
				'<code>' . esc_html( implode( ' + ', $groups ) ?: '∅' ) . '</code>',
				(int) $result['total'],
				esc_html( $result['fallback'] )
			);
			if ( $parsed['concepts'] ) {
				echo ' — ' . esc_html__( 'image concepts:', 'mavo-search' ) . ' <code>' . esc_html( implode( ', ', array_keys( $parsed['concepts'] ) ) ) . '</code>';
			}
			if ( $parsed['phrases'] ) {
				echo ' — ' . esc_html__( 'required phrases:', 'mavo-search' ) . ' <code>' . esc_html( implode( ' / ', $parsed['phrases'] ) ) . '</code>';
			}
			?>
		</p>

		<?php if ( ! $result['results'] ) : ?>
			<p><?php esc_html_e( 'No results.', 'mavo-search' ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<table class="widefat striped mvs__table mvs__results">
			<thead><tr>
				<th>#</th>
				<th><?php esc_html_e( 'Result', 'mavo-search' ); ?></th>
				<th><?php esc_html_e( 'Score', 'mavo-search' ); ?></th>
				<th><?php esc_html_e( 'Why', 'mavo-search' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $result['results'] as $i => $hit ) : ?>
					<tr>
						<td><?php echo (int) $i + 1; ?></td>
						<td>
							<strong><a href="<?php echo esc_url( (string) get_permalink( $hit['post_id'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_the_title( $hit['post_id'] ) ); ?></a></strong>
							<span class="description">#<?php echo (int) $hit['post_id']; ?> · <?php echo esc_html( (string) get_post_type( $hit['post_id'] ) ); ?></span>
							<p class="mvs__excerpt"><?php echo wp_kses( $hit['excerpt'] ?? '', [ 'mark' => [ 'class' => true ] ] ); ?></p>
							<?php if ( $hit['matched_image_concepts'] ) : ?>
								<span class="description"><?php echo esc_html( sprintf( __( 'image concepts: %s', 'mavo-search' ), implode( ', ', $hit['matched_image_concepts'] ) ) ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( number_format_i18n( $hit['score'], 2 ) ); ?></td>
						<td>
							<details>
								<summary><?php echo esc_html( implode( ', ', $hit['matched_fields'] ) ); ?></summary>
								<table class="mvs__explain">
									<?php foreach ( $hit['debug'] as $part ) : ?>
										<tr>
											<td><?php echo esc_html( $part['what'] ); ?></td>
											<td class="description"><?php echo esc_html( $part['detail'] ); ?></td>
											<td><?php echo null === $part['points'] ? '' : esc_html( '+' . number_format_i18n( $part['points'], 2 ) ); ?></td>
										</tr>
									<?php endforeach; ?>
								</table>
							</details>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_integrations(): void {
		$rows = [
			[ 'Polylang', function_exists( 'pll_current_language' ), __( 'one index per language; searches stay in the visitor’s language', 'mavo-search' ), __( 'everything is filed under the default language', 'mavo-search' ) ],
			[ 'mavo-geotag-plus', MVS_Geo::available(), __( 'place names and their wider places are indexed', 'mavo-search' ), __( 'no place field', 'mavo-search' ) ],
			[ 'mavo-hubs', MVS_Hubs::available(), __( 'hub titles indexed; hub pages lifted', 'mavo-search' ), __( 'no hub field', 'mavo-search' ) ],
			[ 'mavo-image-index', MVS_Images::available(), __( 'alt text in every language, image concepts', 'mavo-search' ), __( 'default-language WordPress alt text only, no concepts', 'mavo-search' ) ],
			[ __( 'Query concepts (mavo_image_match_concepts)', 'mavo-search' ), function_exists( 'mavo_image_match_concepts' ), __( 'queries read with the full concept dictionary', 'mavo-search' ), __( 'concept labels only', 'mavo-search' ) ],
			[ __( 'Theme landing pages (mavo_search_guide_places)', 'mavo-search' ), has_filter( 'mavo_search_guide_places' ), __( 'a place’s landing page ranks first for that place', 'mavo-search' ), __( 'no guide field', 'mavo-search' ) ],
		];

		$relevanssi = function_exists( 'relevanssi_do_query' );
		?>
		<h2><?php esc_html_e( 'Integrations', 'mavo-search' ); ?></h2>
		<table class="widefat striped mvs__table">
			<tbody>
				<?php foreach ( $rows as [ $name, $on, $yes, $no ] ) : ?>
					<tr>
						<th><?php echo esc_html( $name ); ?></th>
						<td class="<?php echo $on ? '' : 'mvs__warn'; ?>"><?php echo $on ? esc_html__( 'active', 'mavo-search' ) : esc_html__( 'absent', 'mavo-search' ); ?></td>
						<td><?php echo esc_html( $on ? $yes : $no ); ?></td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th>Relevanssi</th>
					<td><?php echo $relevanssi ? esc_html__( 'active', 'mavo-search' ) : esc_html__( 'inactive', 'mavo-search' ); ?></td>
					<td>
						<?php
						if ( ! $relevanssi ) {
							esc_html_e( 'nothing to do; see docs/relevanssi-compat.md', 'mavo-search' );
						} elseif ( MVS_WP::ready() ) {
							esc_html_e( 'still active, but told to stand aside for every search Mavo Search answers. Deactivate it once you are happy; reactivating it and deactivating Mavo Search rolls back.', 'mavo-search' );
						} else {
							esc_html_e( 'answering searches until the index is complete', 'mavo-search' );
						}
						?>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	private static function render_weights(): void {
		?>
		<h2><?php esc_html_e( 'Weights', 'mavo-search' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Points per field, and bonuses. Change them with the mavo_search_field_weights filter; the test search above shows the effect at once.', 'mavo-search' ); ?></p>
		<table class="widefat striped mvs__table mvs__weights">
			<tbody>
				<?php foreach ( MVS_Engine::weights() as $key => $weight ) : ?>
					<tr><th><code><?php echo esc_html( $key ); ?></code></th><td><?php echo esc_html( number_format_i18n( $weight, 1 ) ); ?></td></tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_log(): void {
		$enabled = MVS_Log::enabled();
		$reports = [
			'top'      => __( 'Most searched', 'mavo-search' ),
			'zero'     => __( 'No results', 'mavo-search' ),
			'low'      => __( '1–3 results', 'mavo-search' ),
			'fallback' => __( 'Partial matches only', 'mavo-search' ),
		];
		?>
		<h2 id="mvs-log"><?php esc_html_e( 'What visitors search for (last 30 days)', 'mavo-search' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mvs__inline">
			<input type="hidden" name="action" value="mvs_save_settings">
			<?php wp_nonce_field( 'mvs_save_settings' ); ?>
			<label><input type="checkbox" name="log_enabled" value="1" <?php checked( $enabled ); ?>> <?php esc_html_e( 'Count searches (query, language, day and number of results; nothing about the visitor)', 'mavo-search' ); ?></label>
			<?php submit_button( __( 'Save', 'mavo-search' ), 'secondary', 'submit', false ); ?>
		</form>

		<?php
		$by_lang = MVS_Log::by_language( 30 );
		if ( $by_lang ) {
			$parts = [];
			foreach ( $by_lang as $lang => $row ) {
				$parts[] = sprintf( __( '%1$s: %2$s searches, %3$s without result', 'mavo-search' ), strtoupper( $lang ), number_format_i18n( $row['searches'] ), number_format_i18n( $row['zero'] ) );
			}
			echo '<p>' . esc_html( implode( ' · ', $parts ) ) . '</p>';
		}
		?>

		<div class="mvs__columns">
			<?php foreach ( $reports as $which => $label ) : ?>
				<?php $rows = MVS_Log::report( $which, 30, null, 20 ); ?>
				<table class="widefat striped mvs__table">
					<thead><tr><th colspan="3"><?php echo esc_html( $label ); ?></th></tr></thead>
					<tbody>
						<?php if ( ! $rows ) : ?>
							<tr><td colspan="3"><?php esc_html_e( 'None.', 'mavo-search' ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['query'] ); ?> <span class="description"><?php echo esc_html( strtoupper( $row['lang'] ) ); ?></span></td>
								<td><?php echo esc_html( number_format_i18n( $row['searches'] ) ); ?>×</td>
								<td><?php echo esc_html( number_format_i18n( $row['results'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private static function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$notice = sanitize_key( $_GET['mvs_notice'] ?? '' );

		if ( 'post' === $notice ) {
			printf(
				'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
				esc_html( sprintf(
					/* translators: 1: post ID, 2: result */
					__( 'Post #%1$d: %2$s.', 'mavo-search' ),
					absint( $_GET['mvs_id'] ?? 0 ),
					sanitize_key( $_GET['mvs_result'] ?? '' )
				) )
			);
		} elseif ( 'settings' === $notice ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Saved.', 'mavo-search' ) );
		}
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage the search index.', 'mavo-search' ) );
		}

		check_admin_referer( $action );
	}

	private static function back( array $args ): void {
		wp_safe_redirect( add_query_arg( $args + [ 'page' => self::PAGE_SLUG ], admin_url( 'tools.php' ) ) );
		exit;
	}
}
