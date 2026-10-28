<?php
/**
 * WP-Cron wiring.
 *
 * Three events:
 *  - mojoodyar_process_queue    every 5 minutes (dispatch)
 *  - mojoodyar_reconcile_stock  every 5 minutes, internally throttled to the
 *                              configured interval (default 15 minutes)
 *  - mojoodyar_prune_records    daily (retention + log pruning)
 *
 * The reconcile event runs on a fixed 5-minute schedule so a change of the
 * configured interval never requires rescheduling the event. See
 * docs/SCHEDULER-POLICY.md.
 *
 * @package Mojoodyar
 */

namespace Mojoodyar;

defined( 'ABSPATH' ) || exit;

/**
 * Class Scheduler
 */
final class Scheduler {

	/**
	 * Cron hook: dispatch the queue.
	 *
	 * @var string
	 */
	const HOOK_QUEUE = 'mojoodyar_process_queue';

	/**
	 * Cron hook: reconcile stock snapshots.
	 *
	 * @var string
	 */
	const HOOK_RECONCILE = 'mojoodyar_reconcile_stock';

	/**
	 * Cron hook: prune old rows.
	 *
	 * @var string
	 */
	const HOOK_PRUNE = 'mojoodyar_prune_records';

	/**
	 * Register the custom interval.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function schedules( $schedules ) {
		if ( ! isset( $schedules['mojoodyar_five_minutes'] ) ) {
			$schedules['mojoodyar_five_minutes'] = array(
				'interval' => 300,
				'display'  => __( 'هر ۵ دقیقه (موجودیار)', 'mojoodyar' ),
			);
		}

		return $schedules;
	}

	/**
	 * Register every event that is missing (idempotent, self-healing).
	 *
	 * @return void
	 */
	public static function schedule_all() {
		if ( ! function_exists( 'wp_next_scheduled' ) ) {
			return;
		}

		if ( ! wp_next_scheduled( self::HOOK_QUEUE ) ) {
			wp_schedule_event( time() + 120, 'mojoodyar_five_minutes', self::HOOK_QUEUE );
		}

		if ( ! wp_next_scheduled( self::HOOK_RECONCILE ) ) {
			wp_schedule_event( time() + 300, 'mojoodyar_five_minutes', self::HOOK_RECONCILE );
		}

		if ( ! wp_next_scheduled( self::HOOK_PRUNE ) ) {
			wp_schedule_event( time() + 3600, 'daily', self::HOOK_PRUNE );
		}
	}

	/**
	 * Remove every scheduled event.
	 *
	 * @return void
	 */
	public static function unschedule_all() {
		if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
			return;
		}

		wp_clear_scheduled_hook( self::HOOK_QUEUE );
		wp_clear_scheduled_hook( self::HOOK_RECONCILE );
		wp_clear_scheduled_hook( self::HOOK_PRUNE );
	}

	/**
	 * Runtime self-heal: some hosts truncate the cron option, some plugins clear it.
	 *
	 * @return void
	 */
	public static function maybe_heal() {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! Settings::enabled( 'queue_batch_size' ) ) {
			return;
		}

		if ( wp_next_scheduled( self::HOOK_QUEUE ) && wp_next_scheduled( self::HOOK_RECONCILE ) ) {
			return;
		}

		self::schedule_all();
	}

	/**
	 * Should the reconcile pass run now?
	 *
	 * @param int $last_run Unix timestamp of the previous pass (0 when never).
	 * @param int $now      Current Unix timestamp.
	 * @return bool
	 */
	public static function reconcile_due( $last_run, $now ) {
		$interval = (int) Settings::get( 'reconcile_interval', 15 );
		$interval = max( 5, min( 1440, $interval ) );

		if ( (int) $last_run <= 0 ) {
			return true;
		}

		return ( (int) $now - (int) $last_run ) >= ( $interval * 60 );
	}
}
