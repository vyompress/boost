<?php
/**
 * Resumable Media Library migration jobs.
 *
 * @package VyomPress\Boost
 */

declare(strict_types=1);

namespace VyomPress\Boost\Media;

use VyomPress\Boost\Operations\ActivityLog;
use VyomPress\Boost\Settings;
use WP_Error;

/**
 * Offloads, verifies, or restores existing attachments in safe batches.
 */
final class MediaMigrator {
	public const CRON_HOOK     = 'vyompress_boost_media_migration_batch';
	public const STATUS_OPTION = 'vyompress_boost_media_migration_status';

	private const BATCH_SIZE = 5;
	private const LOCK_KEY   = 'vyompress_boost_media_migration_lock';

	/**
	 * Create the media migration worker.
	 *
	 * @param Settings       $settings  Plugin settings.
	 * @param MediaOffloader $offloader Media offloader.
	 * @param S3Client       $client    S3-compatible client.
	 * @param ActivityLog    $log       Local activity log.
	 */
	public function __construct(
		private Settings $settings,
		private MediaOffloader $offloader,
		private S3Client $client,
		private ActivityLog $log
	) {
	}

	/**
	 * Register the worker hook.
	 */
	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'runBatch' ) );
	}

	/**
	 * Start a new migration from the beginning.
	 *
	 * @param string $mode Offload, verify, or restore.
	 */
	public function start( string $mode ): bool {
		if ( ! in_array( $mode, array( 'offload', 'verify', 'restore', 'regenerate' ), true ) || ( 'regenerate' !== $mode && ! $this->offloader->isReady() ) ) {
			return false;
		}

		update_option(
			self::STATUS_OPTION,
			array(
				'state'      => 'running',
				'mode'       => $mode,
				'cursor'     => 0,
				'processed'  => 0,
				'failed'     => 0,
				'started'    => time(),
				'updated'    => time(),
				'last_error' => '',
			),
			false
		);
		$this->schedule();
		$this->log->add( 'media_migration', sprintf( /* translators: %s: migration mode. */ __( 'Media %s job started.', 'vyompress-boost' ), $mode ) );

		return true;
	}

	/**
	 * Pause the active migration after its current item.
	 */
	public function pause(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		$status            = $this->status();
		$status['state']   = 'paused';
		$status['updated'] = time();
		update_option( self::STATUS_OPTION, $status, false );
		$this->log->add( 'media_migration', __( 'Media migration paused.', 'vyompress-boost' ), 'warning' );
	}

	/**
	 * Resume a previously paused migration.
	 */
	public function resume(): bool {
		$status = $this->status();
		if ( 'paused' !== $status['state'] || ! in_array( $status['mode'], array( 'offload', 'verify', 'restore', 'regenerate' ), true ) ) {
			return false;
		}

		$status['state']   = 'running';
		$status['updated'] = time();
		update_option( self::STATUS_OPTION, $status, false );
		$this->schedule();

		return true;
	}

	/**
	 * Process one small attachment batch.
	 */
	public function runBatch(): void {
		if ( get_transient( self::LOCK_KEY ) ) {
			$this->schedule();

			return;
		}

		$status = $this->status();
		if ( 'running' !== $status['state'] ) {
			return;
		}

		set_transient( self::LOCK_KEY, 1, 5 * MINUTE_IN_SECONDS );
		$ids = $this->attachmentIdsAfter( $status['cursor'] );
		if ( array() === $ids ) {
			$status['state']    = 'complete';
			$status['finished'] = time();
			$status['updated']  = time();
			update_option( self::STATUS_OPTION, $status, false );
			delete_transient( self::LOCK_KEY );
			$this->log->add(
				'media_migration',
				sprintf(
					/* translators: 1: processed count, 2: failed count. */
					__( 'Media job completed: %1$d processed, %2$d failed.', 'vyompress-boost' ),
					$status['processed'],
					$status['failed']
				),
				0 === $status['failed'] ? 'success' : 'warning'
			);

			return;
		}

		foreach ( $ids as $attachment_id ) {
			$result           = $this->processAttachment( $attachment_id, $status['mode'] );
			$status['cursor'] = $attachment_id;
			++$status['processed'];
			if ( is_wp_error( $result ) ) {
				++$status['failed'];
				$status['last_error'] = sanitize_text_field( $result->get_error_message() );
			}
		}

		$status['updated'] = time();
		update_option( self::STATUS_OPTION, $status, false );
		delete_transient( self::LOCK_KEY );
		$this->schedule();
	}

	/**
	 * Return normalized migration status.
	 *
	 * @return array{state:string,mode:string,cursor:int,processed:int,failed:int,started:int,updated:int,last_error:string,finished?:int}
	 */
	public function status(): array {
		$status = get_option( self::STATUS_OPTION, array() );
		$status = is_array( $status ) ? $status : array();

		return array_merge(
			array(
				'state'      => 'idle',
				'mode'       => '',
				'cursor'     => 0,
				'processed'  => 0,
				'failed'     => 0,
				'started'    => 0,
				'updated'    => 0,
				'last_error' => '',
			),
			$status
		);
	}

	/**
	 * Process one attachment in the selected mode.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $mode          Migration mode.
	 * @return true|WP_Error
	 */
	private function processAttachment( int $attachment_id, string $mode ): bool|WP_Error {
		if ( 'offload' === $mode ) {
			return $this->offloader->offloadExisting( $attachment_id );
		}

		if ( 'regenerate' === $mode ) {
			$file = (string) get_attached_file( $attachment_id, true );
			if ( '' === $file || ! is_readable( $file ) ) {
				return new WP_Error( 'vyompress_regenerate_missing_file', __( 'The local original is unavailable for image regeneration.', 'vyompress-boost' ) );
			}

			if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}
			$metadata = wp_generate_attachment_metadata( $attachment_id, $file );
			if ( ! is_array( $metadata ) || array() === $metadata ) {
				return new WP_Error( 'vyompress_regenerate_failed', __( 'WordPress could not regenerate this attachment.', 'vyompress-boost' ) );
			}

			wp_update_attachment_metadata( $attachment_id, $metadata );

			return true;
		}

		$record  = $this->offloader->record( $attachment_id );
		$objects = isset( $record['objects'] ) && is_array( $record['objects'] ) ? $record['objects'] : array();
		if ( array() === $objects ) {
			return new WP_Error( 'vyompress_media_not_offloaded', __( 'The attachment has no offload record.', 'vyompress-boost' ) );
		}

		foreach ( $objects as $object_key ) {
			$result = 'verify' === $mode ? $this->client->objectExists( (string) $object_key ) : $this->restoreObject( (string) $object_key );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Restore one object beneath the WordPress uploads directory.
	 *
	 * @param string $object_key Remote object key.
	 * @return true|WP_Error
	 */
	private function restoreObject( string $object_key ): bool|WP_Error {
		$prefix = trim( (string) $this->settings->get( 's3_prefix' ), '/' );
		if ( '' !== $prefix && ! str_starts_with( $object_key, $prefix . '/' ) ) {
			return new WP_Error( 'vyompress_restore_invalid_key', __( 'A remote object is outside the configured media prefix.', 'vyompress-boost' ) );
		}

		$relative = '' === $prefix ? $object_key : substr( $object_key, strlen( $prefix ) + 1 );
		$uploads  = wp_get_upload_dir();
		$base     = trailingslashit( wp_normalize_path( (string) $uploads['basedir'] ) );
		$target   = wp_normalize_path( $base . ltrim( $relative, '/' ) );
		if ( ! str_starts_with( $target, $base ) ) {
			return new WP_Error( 'vyompress_restore_invalid_path', __( 'A restored media path failed validation.', 'vyompress-boost' ) );
		}

		if ( is_file( $target ) ) {
			return true;
		}

		if ( ! wp_mkdir_p( dirname( $target ) ) ) {
			return new WP_Error( 'vyompress_restore_directory', __( 'WordPress could not create the local media directory.', 'vyompress-boost' ) );
		}

		$temporary = wp_tempnam( $target );
		if ( ! is_string( $temporary ) || '' === $temporary ) {
			return new WP_Error( 'vyompress_restore_temporary', __( 'WordPress could not create a temporary media file.', 'vyompress-boost' ) );
		}

		$result = $this->client->downloadObject( $object_key, $temporary );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same-filesystem rename safely completes the streamed download.
		if ( ! rename( $temporary, $target ) ) {
			wp_delete_file( $temporary );

			return new WP_Error( 'vyompress_restore_move', __( 'WordPress could not move a restored media file into place.', 'vyompress-boost' ) );
		}

		return true;
	}

	/**
	 * Return the next attachment IDs without expensive offsets.
	 *
	 * @param int $cursor Last processed attachment ID.
	 * @return list<int>
	 */
	private function attachmentIdsAfter( int $cursor ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded background cursor query cannot use WP_Query without an offset.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND ID > %d ORDER BY ID ASC LIMIT %d",
				'attachment',
				$cursor,
				self::BATCH_SIZE
			)
		);

		return array_values( array_map( 'absint', is_array( $ids ) ? $ids : array() ) );
	}

	/**
	 * Ensure the next batch is scheduled once.
	 */
	private function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		}
	}
}
