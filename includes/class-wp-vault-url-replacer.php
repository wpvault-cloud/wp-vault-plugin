<?php
/**
 * URL Replacer
 * 
 * Handles URL replacement for site migration (like WPVivid)
 *
 * @package WP_Vault
 * @since 1.0.0
 */

namespace WP_Vault;

class WP_Vault_URL_Replacer
{
    private $old_url;
    private $new_url;
    private $old_domain;
    private $new_domain;

    public function __construct($old_url, $new_url)
    {
        $this->old_url = rtrim($old_url, '/');
        $this->new_url = rtrim($new_url, '/');

        $old_parsed = wp_parse_url($this->old_url);
        $new_parsed = wp_parse_url($this->new_url);

        $this->old_domain = isset($old_parsed['host']) ? $old_parsed['host'] : '';
        $this->new_domain = isset($new_parsed['host']) ? $new_parsed['host'] : '';
    }

    /**
     * Replace URLs in database
     */
    public function replace_in_database()
    {
        global $wpdb;

        $tables = array(
            $wpdb->prefix . 'options',
            $wpdb->prefix . 'posts',
            $wpdb->prefix . 'postmeta',
            $wpdb->prefix . 'comments',
            $wpdb->prefix . 'commentmeta',
        );

        $replaced_count = 0;

        foreach ($tables as $table) {
            $replaced_count += $this->replace_in_table($table);
        }

        return $replaced_count;
    }

    /**
     * Replace URLs in a specific table
     */
    private function replace_in_table($table)
    {
        global $wpdb;

        // Get all rows
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is passed from list
        $rows = $wpdb->get_results("SELECT * FROM `{$table}`", ARRAY_A);

        if (empty($rows)) {
            return 0;
        }

        $replaced = 0;

        foreach ($rows as $row) {
            $updated = false;
            $update_data = array();
            $update_where = array();

            // Build WHERE clause from primary key
            foreach ($row as $key => $value) {
                if (strpos($key, 'ID') !== false || strpos($key, 'id') !== false) {
                    $update_where[$key] = $value;
                }
            }

            // Replace in each column
            foreach ($row as $column => $value) {
                if (is_string($value) && !empty($value)) {
                    $new_value = $this->replace_urls_in_string($value);
                    if ($new_value !== $value) {
                        $update_data[$column] = $new_value;
                        $updated = true;
                    }
                }
            }

            // Update row if changed
            if ($updated && !empty($update_where)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Update safe
                $wpdb->update($table, $update_data, $update_where);
                $replaced++;
            }
        }

        return $replaced;
    }

    /**
     * Replace URLs in a string (handles serialized data safely)
     */
    private function replace_urls_in_string($string)
    {
        // Skip if string doesn't contain old URL
        if (strpos($string, $this->old_url) === false && strpos($string, $this->old_domain) === false) {
            return $string;
        }

        // Check if string is serialized
        if ($this->is_serialized($string)) {
            return $this->replace_in_serialized($string);
        }

        // Simple string replacement
        $string = str_replace($this->old_url, $this->new_url, $string);
        $string = str_replace($this->old_domain, $this->new_domain, $string);

        return $string;
    }

    /**
     * Check if string is serialized
     */
    private function is_serialized($data)
    {
        if (!is_string($data)) {
            return false;
        }

        $data = trim($data);
        if ('N;' == $data) {
            return true;
        }

        if (!preg_match('/^([adObis]):/', $data, $badions)) {
            return false;
        }

        switch ($badions[1]) {
            case 'a':
            case 'O':
            case 's':
                if (preg_match("/^{$badions[1]}:[0-9]+:.*[;}]\$/s", $data)) {
                    return true;
                }
                break;
            case 'b':
            case 'i':
            case 'd':
                if (preg_match("/^{$badions[1]}:[0-9.E-]+;\$/", $data)) {
                    return true;
                }
                break;
        }

        return false;
    }

    /**
     * Replace URLs in serialized data
     */
    private function replace_in_serialized($data)
    {
        // Unserialize, replace, re-serialize
        $unserialized = @unserialize($data);

        if ($unserialized === false && $data !== serialize(false)) {
            // Not valid serialized data, do simple replacement
            return str_replace($this->old_url, $this->new_url, $data);
        }

        // Recursively replace in array/object
        $replaced = $this->replace_recursive($unserialized);

        // Re-serialize
        return serialize($replaced);
    }

    /**
     * Recursively replace URLs in array/object
     */
    private function replace_recursive($data)
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->replace_recursive($value);
            }
        } elseif (is_object($data)) {
            foreach ($data as $key => $value) {
                $data->$key = $this->replace_recursive($value);
            }
        } elseif (is_string($data)) {
            $data = str_replace($this->old_url, $this->new_url, $data);
            $data = str_replace($this->old_domain, $this->new_domain, $data);
        }

        return $data;
    }

    /**
     * Detect old URLs from database
     */
    public static function detect_old_urls()
    {
        global $wpdb;

        // Get site_url and home_url from options
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading raw options
        $site_url = $wpdb->get_var("SELECT option_value FROM {$wpdb->prefix}options WHERE option_name = 'site_url'");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading raw options
        $home_url = $wpdb->get_var("SELECT option_value FROM {$wpdb->prefix}options WHERE option_name = 'home'");

        return array(
            'site_url' => $site_url,
            'home_url' => $home_url,
        );
    }
}
