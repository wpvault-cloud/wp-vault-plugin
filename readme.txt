=== WP Vault ===
Contributors: wpvault
Tags: backup, restore, cloud storage, s3, google drive
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ultimate WordPress backup and optimization platform with multi-storage support.

== Description ==

WP Vault is the complete backup and optimization solution for WordPress. Automatic backups, multi-storage support (S3, Google Cloud, Google Drive, FTP, SFTP), and smart optimization tools.

**Features:**

* **Multi-Storage Support** - Choose from Google Cloud Storage, Amazon S3, MinIO, Google Drive, FTP, or SFTP
* **Automatic Backups** - Schedule daily, weekly, or custom backup intervals
* **Incremental Backups** - Save bandwidth and storage with incremental backups
* **One-Click Restore** - Restore your site with a single click
* **Granular Restore** - Restore only files or database as needed
* **Cloud Storage** - 3GB free storage on WP-Vault Cloud
* **Optimization Tools** - Image optimization, database cleanup (coming soon)
* **Security** - End-to-end encryption for all backups
* **Site Health Monitoring** - Regular heartbeat checks

**Perfect for:**

* WordPress agencies managing multiple sites
* Bloggers and content creators
* E-commerce stores
* Developers and site administrators

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/wp-vault` directory, or install through WordPress plugins screen
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to WP Vault → Settings to configure your storage
4. Register your site with WP Vault
5. Click "Backup Now" to create your first backup

== Frequently Asked Questions ==

= Is WP Vault free? =

Yes! WP Vault offers a free tier with 3GB of cloud storage. You can also bring your own storage (S3, Google Drive, etc.) at no cost.

= What storage providers are supported? =

WP Vault supports Google Cloud Storage, Amazon S3, MinIO, Wasabi, Backblaze B2, Google Drive, FTP, and SFTP.

= Can I restore to a different server? =

Yes! You can download your backups and restore them anywhere.

= How does incremental backup work? =

Incremental backups only upload files that have changed since the last backup, saving time and storage.

== Screenshots ==

1. Dashboard with backup list
2. Settings page with storage configuration
3. Backup in progress
4. Restore options

== Changelog ==

= 1.0.0 =
* Initial release
* Multi-storage support (S3, GCS, Google Drive, FTP, SFTP)
* Site registration and heartbeat
* Manual backup creation
* Backup listing
* Storage connection testing

== Upgrade Notice ==

= 1.0.0 =
Initial release of WP Vault.
