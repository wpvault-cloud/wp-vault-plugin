<?php

/**
 * The scheduler class for local backups.
 *
 * Handles native WordPress cron scheduling for backups independent of SaaS.
 *
 * @since      1.0.0
 * @package    WP_Vault
 * @subpackage WP_Vault/includes
 */

namespace WP_Vault;

if (!defined('ABSPATH')) {
    exit;
}

class WP_Vault_Scheduler
{

    /**
     * The option name for storing schedule settings.
     */
    const OPTION_NAME = 'wp_vault_local_schedule';

    /**
     * The hook name for the scheduled backup event.
     */
    const CRON_HOOK = 'wp_vault_scheduled_backup';

    /**
     * Initialize the scheduler.
     */
    public function init()
    {
        add_filter('cron_schedules', array($this, 'add_cron_intervals'));
    }

    /**
     * Add custom cron intervals.
     *
     * @param array $schedules The existing cron schedules.
     * @return array Modified cron schedules.
     */
    public function add_cron_intervals($schedules)
    {
        if (!isset($schedules['wpv_weekly'])) {
            $schedules['wpv_weekly'] = array(
                'interval' => WEEK_IN_SECONDS,
                'display' => __('Weekly', 'wp-vault'),
            );
        }
        if (!isset($schedules['wpv_monthly'])) {
            $schedules['wpv_monthly'] = array(
                'interval' => WEEK_IN_SECONDS * 4, // Approx
                'display' => __('Monthly', 'wp-vault'),
            );
        }
        return $schedules;
    }

    /**
     * Get the current schedule settings.
     *
     * @return array The schedule settings.
     */
    public function get_schedule()
    {
        $defaults = array(
            'enabled' => false,
            'frequency' => 'daily', // hourly, twicedaily, daily, wpv_weekly, wpv_monthly
            'backup_type' => 'full',  // full, db, files
            'next_run' => '',
        );

        $settings = get_option(self::OPTION_NAME, array());
        return wp_parse_args($settings, $defaults);
    }

    /**
     * Update the schedule settings.
     *
     * @param array $settings The new schedule settings.
     * @return bool True on success, false on failure.
     */
    public function update_schedule($settings)
    {
        // 1. Sanitize input
        $clean_settings = array(
            'enabled' => !empty($settings['enabled']),
            'frequency' => sanitize_text_field($settings['frequency']),
            'backup_type' => sanitize_text_field($settings['backup_type']),
        );

        // 2. Validate frequency
        $valid_frequencies = array('hourly', 'twicedaily', 'daily', 'wpv_weekly', 'wpv_monthly');
        if (!in_array($clean_settings['frequency'], $valid_frequencies, true)) {
            $clean_settings['frequency'] = 'daily';
        }

        // 3. Clear existing schedule
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }

        // 4. Schedule new event if enabled
        if ($clean_settings['enabled']) {
            // Calculate next run time (e.g., next occurrence of 02:00 AM for daily/weekly)
            $next_run = $this->calculate_next_run($clean_settings['frequency']);

            // Schedule event
            $result = wp_schedule_event($next_run, $clean_settings['frequency'], self::CRON_HOOK);

            if (is_wp_error($result)) {
                return false;
            }

            $clean_settings['next_run'] = $next_run;
        } else {
            $clean_settings['next_run'] = '';
        }

        // 5. Save settings
        return update_option(self::OPTION_NAME, $clean_settings);
    }

    /**
     * Calculate the next run timestamp.
     *
     * Tries to schedule for a "quiet" time if possible, or next interval.
     *
     * @param string $frequency The frequency key.
     * @return int Unix timestamp.
     */
    private function calculate_next_run($frequency)
    {
        $now = current_time('timestamp');

        // Default to +1 hour from now for quick start, or specific time for daily+
        switch ($frequency) {
            case 'daily':
            case 'twicedaily':
            case 'wpv_weekly':
            case 'wpv_monthly':
                // Schedule for 3 AM server time by default
                $tomorrow_3am = strtotime('tomorrow 03:00:00', $now);
                return $tomorrow_3am;
            case 'hourly':
            default:
                return $now + HOUR_IN_SECONDS;
        }
    }

    /**
     * Get the next scheduled run time formatted for display.
     *
     * @return string Formatted date string or 'Disabled'.
     */
    public function get_formatted_next_run()
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            return get_date_from_gmt(gmdate('Y-m-d H:i:s', $timestamp), 'F j, Y H:i');
        }
        return __('Disabled', 'wp-vault');
    }
}
