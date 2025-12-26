<?php
/**
 * Chunk Processor
 * 
 * Handles chunked processing for backups and restores (AJAX/Cron hybrid)
 *
 * @package WP_Vault
 * @since 1.0.0
 */

namespace WP_Vault;

class WP_Vault_Chunk_Processor
{
    private $job_id;
    private $max_execution_time;
    private $chunk_size;

    public function __construct($job_id, $max_execution_time = 60, $chunk_size = 1000)
    {
        $this->job_id = $job_id;
        $this->max_execution_time = $max_execution_time;
        $this->chunk_size = $chunk_size;
    }

    /**
     * Process files in chunks
     */
    public function process_files_chunked($files, $callback, $start_index = 0)
    {
        $start_time = time();
        $processed = 0;
        $current_index = $start_index;

        while ($current_index < count($files) && (time() - $start_time) < $this->max_execution_time) {
            $chunk = array_slice($files, $current_index, $this->chunk_size);

            if (empty($chunk)) {
                break;
            }

            // Process chunk
            foreach ($chunk as $file) {
                call_user_func($callback, $file);
                $processed++;
            }

            $current_index += count($chunk);

            // Save progress
            $this->save_progress($current_index, count($files));
        }

        return array(
            'processed' => $processed,
            'current_index' => $current_index,
            'total' => count($files),
            'completed' => $current_index >= count($files),
        );
    }

    /**
     * Process SQL in chunks
     */
    public function process_sql_chunked($sql_file, $callback, $offset = 0)
    {
        $start_time = time();
        $chunk_size_bytes = 5 * 1024 * 1024; // 5MB
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- SQL file streaming requires direct file access with fseek() support
        $handle = fopen($sql_file, 'r');

        if (!$handle) {
            throw new \Exception('Could not open SQL file');
        }

        // Seek to offset
        if ($offset > 0) {
            fseek($handle, $offset);
        }

        $buffer = '';
        $queries_processed = 0;

        while (!feof($handle) && (time() - $start_time) < $this->max_execution_time) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- SQL file streaming requires chunked reading
            $chunk = fread($handle, $chunk_size_bytes);
            if ($chunk === false) {
                break;
            }

            $buffer .= $chunk;

            // Extract complete queries
            $queries = $this->extract_complete_queries($buffer);

            // Process queries
            foreach ($queries as $query) {
                call_user_func($callback, $query);
                $queries_processed++;
            }

            // Remove processed queries from buffer
            $buffer = $this->remove_processed_queries($buffer, $queries);
        }

        $current_offset = ftell($handle);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- SQL file streaming requires direct file access
        fclose($handle);

        // Save progress
        $this->save_progress($current_offset, filesize($sql_file), 'sql');

        return array(
            'processed' => $queries_processed,
            'current_offset' => $current_offset,
            'total_size' => filesize($sql_file),
            'completed' => $current_offset >= filesize($sql_file),
        );
    }

    /**
     * Extract complete SQL queries from buffer
     */
    private function extract_complete_queries($buffer)
    {
        $queries = array();
        $current_query = '';
        $in_string = false;
        $string_char = '';

        for ($i = 0; $i < strlen($buffer); $i++) {
            $char = $buffer[$i];

            // Handle strings
            if (($char === '"' || $char === "'") && ($i === 0 || $buffer[$i - 1] !== '\\')) {
                if (!$in_string) {
                    $in_string = true;
                    $string_char = $char;
                } elseif ($char === $string_char) {
                    $in_string = false;
                    $string_char = '';
                }
            }

            $current_query .= $char;

            // Check for query end
            if (!$in_string && $char === ';') {
                $queries[] = trim($current_query);
                $current_query = '';
            }
        }

        return $queries;
    }

    /**
     * Remove processed queries from buffer
     */
    private function remove_processed_queries($buffer, $queries)
    {
        foreach ($queries as $query) {
            $pos = strpos($buffer, $query);
            if ($pos !== false) {
                $buffer = substr($buffer, $pos + strlen($query));
            }
        }

        return trim($buffer);
    }

    /**
     * Save processing progress
     */
    private function save_progress($current, $total, $type = 'files')
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wp_vault_jobs';

        $progress_percent = $total > 0 ? round(($current / $total) * 100) : 0;

        $update_data = array(
            'current_offset' => $current,
            'progress_percent' => $progress_percent,
            'updated_at' => current_time('mysql'),
        );

        if ($type === 'sql') {
            $update_data['current_step'] = 'processing_sql';
        } else {
            $update_data['current_step'] = 'processing_files';
        }

        $wpdb->update(
            $table,
            $update_data,
            array('backup_id' => $this->job_id),
            array('%d', '%d', '%s', '%s'),
            array('%s')
        );
    }

    /**
     * Check if processing should continue
     */
    public function should_continue()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wp_vault_jobs';

        $job = $wpdb->get_row($wpdb->prepare(
            "SELECT status FROM $table WHERE backup_id = %s",
            $this->job_id
        ));

        return $job && $job->status === 'running';
    }
}
