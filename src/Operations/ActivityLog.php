<?php
/**
 * Privacy-preserving operational activity log.
 *
 * @package VyomPress\Boost
 */

declare(strict_types=1);

namespace VyomPress\Boost\Operations;

/**
 * Retains a small local history of important background operations.
 */
final class ActivityLog {
	public const OPTION = 'vyompress_boost_activity_log';

	private const MAX_ENTRIES = 100;

	/**
	 * Record a non-sensitive operational event.
	 *
	 * @param string $type    Stable event type.
	 * @param string $message Human-readable summary.
	 * @param string $status  Success, warning, error, or info.
	 */
	public function add( string $type, string $message, string $status = 'info' ): void {
		$entries = $this->all();
		array_unshift(
			$entries,
			array(
				'time'    => time(),
				'type'    => sanitize_key( $type ),
				'status'  => in_array( $status, array( 'success', 'warning', 'error', 'info' ), true ) ? $status : 'info',
				'message' => sanitize_text_field( $message ),
			)
		);

		update_option( self::OPTION, array_slice( $entries, 0, self::MAX_ENTRIES ), false );
	}

	/**
	 * Return newest events first.
	 *
	 * @param int $limit Maximum number of entries.
	 * @return list<array{time:int,type:string,status:string,message:string}>
	 */
	public function all( int $limit = self::MAX_ENTRIES ): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$entries = array();
		foreach ( $stored as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['message'] ) ) {
				continue;
			}

			$entries[] = array(
				'time'    => absint( $entry['time'] ?? 0 ),
				'type'    => sanitize_key( (string) ( $entry['type'] ?? 'event' ) ),
				'status'  => sanitize_key( (string) ( $entry['status'] ?? 'info' ) ),
				'message' => sanitize_text_field( (string) $entry['message'] ),
			);
		}

		return array_slice( $entries, 0, max( 1, min( self::MAX_ENTRIES, $limit ) ) );
	}

	/**
	 * Remove all recorded events.
	 */
	public function clear(): void {
		delete_option( self::OPTION );
	}
}
