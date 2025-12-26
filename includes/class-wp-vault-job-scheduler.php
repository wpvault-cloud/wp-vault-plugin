<?php
/**
 * Job Scheduler
 * 
 * Cursor-based resumable job scheduler with time-boxing
 *
 * @package WP_Vault
 * @since 1.0.0
 */

namespace WP_Vault;

class WP_Vault_Job_Scheduler
{
    const PHASE_SCAN = 'scan';
    const PHASE_FINGERPRINT = 'fingerprint';
    const PHASE_HASH = 'hash';
    const PHASE_UPLOAD = 'upload';
    const PHASE_RESTORE = 'restore';

    /**
     * Run a job with time budget
     * 
     * @param string $job_id Job ID
     * @param callable $processor Function to process each item
     * @return array Status: ['completed' => bool, 'cursor' => array, 'processed' => int]
     */
    public static function run_job($job_id, $processor)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wp_vault_jobs';

        $start_time = time();
        $budget = WPV_TIME_BUDGET;
        $processed = 0;

        // Load job and cursor
        $job = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE backup_id = %s",
            $job_id
        ));

        if (!$job) {
            return ['completed' => false, 'error' => 'Job not found'];
        }

        // Load cursor (cursor is a reserved keyword, access via array)
        $cursor_json = isset($job->cursor) ? $job->cursor : null;
        $cursor = $cursor_json ? json_decode($cursor_json, true) : null;

        // Run processor within time budget
        while ((time() - $start_time) < $budget) {
            $result = $processor($cursor);

            if ($result === false) {
                // No more items to process
                break;
            }

            if (isset($result['error'])) {
                // Error occurred
                $wpdb->update(
                    $table,
                    [
                        'status' => 'failed',
                        'error_message' => $result['error'],
                        '`cursor`' => json_encode($cursor)
                    ],
                    ['backup_id' => $job_id]
                );
                return ['completed' => false, 'error' => $result['error']];
            }

            // Update cursor
            if (isset($result['cursor'])) {
                $cursor = $result['cursor'];
            }

            $processed++;

            // Update cursor in database after each item (for crash safety)
            $wpdb->update(
                $table,
                [
                    '`cursor`' => json_encode($cursor),
                    'updated_at' => current_time('mysql')
                ],
                ['backup_id' => $job_id]
            );
        }

        // Check if job is complete
        $is_complete = isset($result['completed']) && $result['completed'] === true;

        if ($is_complete) {
            // Mark job as completed
            $wpdb->update(
                $table,
                [
                    'status' => 'completed',
                    '`cursor`' => null,
                    'finished_at' => current_time('mysql')
                ],
                ['backup_id' => $job_id]
            );
        } else {
            // Update status to running (resumable)
            $wpdb->update(
                $table,
                [
                    'status' => 'running',
                    '`cursor`' => json_encode($cursor),
                    'updated_at' => current_time('mysql')
                ],
                ['backup_id' => $job_id]
            );
        }

        return [
            'completed' => $is_complete,
            'cursor' => $cursor,
            'processed' => $processed,
            'time_used' => time() - $start_time
        ];
    }

    /**
     * Create a new job
     * 
     * @param string $job_type Job type ('backup' or 'restore')
     * @param string $backup_id Unique job ID
     * @param string $phase Initial phase
     * @return bool Success
     */
    public static function create_job($job_type, $backup_id, $phase = null)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wp_vault_jobs';

        $data = [
            'job_type' => $job_type,
            'backup_id' => $backup_id,
            'status' => 'pending',
            'progress_percent' => 0,
            'created_at' => current_time('mysql')
        ];

        if ($phase) {
            $data['phase'] = $phase;
        }

        return $wpdb->insert($table, $data) !== false;
    }

    /**
     * Update job phase
     * 
     * @param string $job_id Job ID
     * @param string $phase Phase name
     * @return bool Success
     */
    public static function update_phase($job_id, $phase)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wp_vault_jobs';

        return $wpdb->update(
            $table,
            [
                'phase' => $phase,
                'updated_at' => current_time('mysql')
            ],
            ['backup_id' => $job_id]
        ) !== false;
    }

    /**
     * Get job cursor
     * 
     * @param string $job_id Job ID
     * @return array|null Cursor data or null
     */
    public static function get_cursor($job_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wp_vault_jobs';

        $cursor_json = $wpdb->get_var($wpdb->prepare(
            "SELECT `cursor` FROM $table WHERE backup_id = %s",
            $job_id
        ));

        return $cursor_json ? json_decode($cursor_json, true) : null;
    }

    /**
     * Update job cursor
     * 
     * @param string $job_id Job ID
     * @param array $cursor Cursor data
     * @return bool Success
     */
    public static function update_cursor($job_id, $cursor)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wp_vault_jobs';

        return $wpdb->update(
            $table,
            [
                '`cursor`' => json_encode($cursor),
                'updated_at' => current_time('mysql')
            ],
            ['backup_id' => $job_id]
        ) !== false;
    }

    /**
     * Resume a paused job
     * 
     * @param string $job_id Job ID
     * @return bool Success
     */
    public static function resume_job($job_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wp_vault_jobs';

        return $wpdb->update(
            $table,
            [
                'status' => 'running',
                'updated_at' => current_time('mysql')
            ],
            ['backup_id' => $job_id]
        ) !== false;
    }
}
