<?php

/**
 * Plugin Name: GG - UpdraftPlus Nightly + Throttle
 * Description: Drop-in add-on for UpdraftPlus. Schedules file and database backups for 1:00am (site timezone) and throttles backup resource usage to ~50%.
 * Version:     1.0.0
 * Requires PHP: 7.0
 * License:     GPL-2.0-or-later
 *
 * Works as a normal plugin (wp-content/plugins/gg-updraftplus-nightly-throttle/) or as a
 * must-use plugin (copy just this file into wp-content/mu-plugins/).
 *
 * Optional overrides - define in wp-config.php ABOVE the "That's all, stop editing!" line:
 *   define('UDP_NIGHTLY_HOUR',    1);   // hour of day, 0-23, site timezone
 *   define('UDP_NIGHTLY_MINUTE',  0);   // minute, 0-59
 *   define('UDP_THROTTLE_PERCENT', 50); // 10-100. 100 disables throttling
 *   define('UDP_THROTTLE_MAX_PAUSE', 30); // longest single pause, seconds
 */

if (!defined('ABSPATH')) exit;

final class UDP_Nightly_Throttle {

	/** UpdraftPlus cron hooks whose first-run time we control (files, database). */
	const CRON_HOOKS = array('updraft_backup', 'updraft_backup_database');

	/** Throttle state */
	private static $mark = null;   // microtime of last tick
	private static $owed = 0.0;    // seconds of sleep owed but not yet taken

	public static function init() {
		// ---- Scheduling ----
		add_filter('updraftplus_schedule_firsttime_files', array(__CLASS__, 'first_time'));
		add_filter('updraftplus_schedule_firsttime_db', array(__CLASS__, 'first_time'));
		// Fixes existing schedules, and corrects drift (e.g. at daylight-saving changes).
		add_action('init', array(__CLASS__, 'realign_schedules'), 20);

		// ---- Throttling ----
		if (self::duty() < 1) {
			// Called repeatedly during backups; each is a pass-through that just pauses when needed.
			add_filter('updraftplus_backup_table_results', array(__CLASS__, 'tick_filter'));   // every DB fetch
			add_filter('updraftplus_exclude_file', array(__CLASS__, 'tick_filter'));            // every file considered for the zip
			add_filter('updraftplus_exclude_directory', array(__CLASS__, 'tick_filter'));       // every directory
			add_filter('updraftplus_include_manifest', array(__CLASS__, 'tick_filter'));        // every zip batch open/close
			add_filter('updraftplus_mysqldump_arguments', array(__CLASS__, 'nice_mysqldump'));  // lower CPU priority of mysqldump
		}
	}

	/* ------------------------------------------------------------------ scheduling */

	private static function target_hour() {
		return defined('UDP_NIGHTLY_HOUR') ? max(0, min(23, (int) UDP_NIGHTLY_HOUR)) : 1;
	}
	private static function target_minute() {
		return defined('UDP_NIGHTLY_MINUTE') ? max(0, min(59, (int) UDP_NIGHTLY_MINUTE)) : 0;
	}

	/**
	 * Timestamp of the target time (site timezone) on the local date of $ref (default: now),
	 * moved forward a day if that moment has already passed.
	 */
	public static function target_timestamp($ref = null) {
		$tz   = wp_timezone();
		$base = (null === $ref) ? new DateTimeImmutable('now', $tz) : (new DateTimeImmutable('@' . (int) $ref))->setTimezone($tz);
		$when = $base->setTime(self::target_hour(), self::target_minute(), 0);
		if ($when->getTimestamp() <= time() + 60) {
			$when = $when->modify('+1 day')->setTime(self::target_hour(), self::target_minute(), 0);
		}
		return $when->getTimestamp();
	}

	/** Filter: UpdraftPlus asks when the first run of a (re)scheduled backup should be. */
	public static function first_time($default_time) {
		return self::target_timestamp();
	}

	/** Move any existing daily-or-slower UpdraftPlus schedule onto the target hour. */
	public static function realign_schedules() {
		if (!function_exists('wp_get_scheduled_event')) return;
		$tz = wp_timezone();

		foreach (self::CRON_HOOKS as $hook) {
			$event = wp_get_scheduled_event($hook);
			if (!$event || empty($event->schedule) || (int) $event->interval < DAY_IN_SECONDS) continue; // unscheduled, or hourly-type: leave alone

			$local = (new DateTimeImmutable('@' . $event->timestamp))->setTimezone($tz);
			if ((int) $local->format('G') === self::target_hour() && (int) $local->format('i') === self::target_minute()) continue;

			$new = self::target_timestamp($event->timestamp);
			wp_unschedule_event($event->timestamp, $hook, $event->args);
			wp_schedule_event($new, $event->schedule, $hook, $event->args);
		}
	}

	/* ------------------------------------------------------------------ throttling */

	/** Fraction of wall-clock time the backup may spend working (0.1 - 1.0). */
	private static function duty() {
		$pct = defined('UDP_THROTTLE_PERCENT') ? (int) UDP_THROTTLE_PERCENT : 50;
		return max(10, min(100, $pct)) / 100;
	}

	/**
	 * Duty-cycle throttle: measure how long the backup has worked since the last tick, then
	 * sleep long enough that work / (work + sleep) ~= the configured percentage.
	 */
	private static function tick() {
		$now = microtime(true);

		// First tick of a run/resumption, or a long gap (job was idle/stopped): just start measuring.
		if (null === self::$mark || ($now - self::$mark) > 600) {
			self::$mark = $now;
			self::$owed = 0.0;
			return;
		}

		$duty = self::duty();
		self::$owed += ($now - self::$mark) * (1 / $duty - 1);
		self::$mark  = $now;

		// Batch the pauses so we aren't calling usleep() for every single file.
		if (self::$owed >= 0.05) {
			$max   = defined('UDP_THROTTLE_MAX_PAUSE') ? max(1, (int) UDP_THROTTLE_MAX_PAUSE) : 30;
			$pause = min(self::$owed, $max);
			self::$owed = 0.0;
			usleep((int) ($pause * 1000000));
			self::$mark = microtime(true); // sleeping doesn't count as work
		}
	}

	/** Pass-through filter callback: first argument is returned untouched. */
	public static function tick_filter($value) {
		self::tick();
		return $value;
	}

	/** Prefix the mysqldump command with `nice` where available (it runs outside PHP, so pauses can't reach it). */
	public static function nice_mysqldump($exec) {
		if ('WIN' === strtoupper(substr(PHP_OS, 0, 3)) || !is_string($exec) || 0 === strpos($exec, 'nice ')) return $exec;
		foreach (array('/usr/bin/nice', '/bin/nice') as $nice) {
			if (@is_executable($nice)) return $nice . ' -n 10 ' . $exec; // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		}
		return $exec;
	}
}

UDP_Nightly_Throttle::init();
