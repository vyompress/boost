<?php
/**
 * Guarded WordPress database maintenance.
 *
 * @package VyomPress\Boost
 */

declare(strict_types=1);

namespace VyomPress\Boost\Optimization;

use VyomPress\Boost\Operations\ActivityLog;
use VyomPress\Boost\Settings;

/**
 * Removes only expired or explicitly disposable core records in small batches.
 */
final class DatabaseOptimizer {
	public const CRON_HOOK = 'vyompress_boost_database_cleanup';

	private const BATCH_SIZE = 100;

	/**
	 * Create the database optimizer.
	 *
	 * @param Settings    $settings Plugin settings.
	 * @param ActivityLog $log      Local activity log.
	 */
	public function __construct( private Settings $settings, private ActivityLog $log ) {
	}

	/**
	 * Register scheduling and execution hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'configureSchedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'run' ) );
	}

	/**
	 * Keep the weekly event aligned with the feature toggle.
	 */
	public function configureSchedule(): void {
		$scheduled = wp_next_scheduled( self::CRON_HOOK );
		if ( $this->settings->get( 'database_cleanup_enabled' ) && ! $scheduled ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', self::CRON_HOOK );
		} elseif ( ! $this->settings->get( 'database_cleanup_enabled' ) && $scheduled ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Run one bounded cleanup pass.
	 *
	 * @return array{revisions:int,trash:int,spam:int,transients:int}
	 */
	public function run(): array {
		$retention = max( 7, min( 365, (int) $this->settings->get( 'database_retention_days' ) ) );
		$before    = gmdate( 'Y-m-d H:i:s', time() - ( $retention * DAY_IN_SECONDS ) );
		$result    = array(
			'revisions'  => 0,
			'trash'      => 0,
			'spam'       => 0,
			'transients' => 0,
		);

		if ( function_exists( 'delete_expired_transients' ) ) {
			$result['transients'] = (int) delete_expired_transients( true );
		}

		if ( $this->settings->get( 'database_cleanup_revisions' ) ) {
			$result['revisions'] = $this->deletePosts( 'revision', 'inherit', $before );
		}

		if ( $this->settings->get( 'database_cleanup_trash' ) ) {
			$result['trash'] = $this->deletePosts( 'any', 'trash', $before );
		}

		if ( $this->settings->get( 'database_cleanup_spam' ) ) {
			$comments = get_comments(
				array(
					'status'     => 'spam',
					'number'     => self::BATCH_SIZE,
					'fields'     => 'ids',
					'date_query' => array( array( 'before' => $before ) ),
				)
			);
			foreach ( $comments as $comment_id ) {
				if ( wp_delete_comment( (int) $comment_id, true ) ) {
					++$result['spam'];
				}
			}
		}

		$total = array_sum( $result );
		$this->log->add(
			'database',
			sprintf( /* translators: %d: number of records removed. */ __( 'Database cleanup removed %d expired or disposable records.', 'vyompress-boost' ), $total ),
			'success'
		);

		return $result;
	}

	/**
	 * Permanently delete a bounded set of old posts.
	 *
	 * @param string $post_type   Post type or any.
	 * @param string $post_status Post status.
	 * @param string $before      UTC cutoff.
	 */
	private function deletePosts( string $post_type, string $post_status, string $before ): int {
		$ids = get_posts(
			array(
				'post_type'              => $post_type,
				'post_status'            => $post_status,
				'posts_per_page'         => self::BATCH_SIZE,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'date_query'             => array( array( 'before' => $before ) ),
			)
		);

		$removed = 0;
		foreach ( $ids as $post_id ) {
			if ( null !== wp_delete_post( (int) $post_id, true ) ) {
				++$removed;
			}
		}

		return $removed;
	}
}
