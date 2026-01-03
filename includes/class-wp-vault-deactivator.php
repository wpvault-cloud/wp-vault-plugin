<?php
/**
 * Plugin Deactivator
 * 
 * @package WP_Vault
 * @since 1.0.0
 */

namespace WP_Vault;

class WP_Vault_Deactivator
{
    /**
     * Run on plugin deactivation
     */
    public static function deactivate()
    {
        // Clear scheduled events
        wp_clear_scheduled_hook('wpv_heartbeat');
        wp_clear_scheduled_hook('wp_vault_scheduled_backup');
    }
}
