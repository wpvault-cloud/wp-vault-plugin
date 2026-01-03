=== WPVault Backup & Optimization ===
Contributors: wpvault
Tags: backup, restore, cloud storage, s3, google drive, optimization, image compression, webp, media optimization
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ultimate WordPress backup and optimization platform with multi-storage support.

== Description ==

WP Vault is the complete backup and optimization solution for WordPress. It typically operates in two modes:

1.  **Free Mode (Standalone):** Fully functional local backup and restore solution. Schedule automated backups, download them manually, and restore with one click. **No registration or cloud connection required.**
2.  **Cloud Mode (Connected):** Connect to offload backups to cloud storage (S3, Google Drive, WP Vault Cloud, etc.) and manage multiple sites from a central dashboard.

**Optimization Features:**

* **Image Compression** - Reduce image file sizes with multiple compression methods (Native PHP, JavaScript, or Server-side)
* **WebP Conversion** - Convert images to modern WebP format for better compression ratios
* **Bulk Optimization** - Optimize multiple images at once to save time
* **Gutenberg Integration** - Optimize images directly from the block editor with one-click optimization modal
* **Optimization Tracking** - Track compression statistics and space saved for each image
* **Smart Compression** - Automatic format selection and quality adjustment based on your settings
* **Optimization Modal** - Configure compression options per image with quality, format, and dimension controls
* **Show Difference** - Compare original and optimized images side-by-side with file size information
* **Separate Settings Tab** - Dedicated Compression Settings tab for configuring default optimization preferences
* **Original File Preservation** - Keep original files by default (optional) with optimized versions saved as `-min.extension`
* **Media Library Integration** - Optimized images appear as separate attachments in WordPress media library

**Free Mode Features (No Connection Required):**

* **Local Backups** - Create full backups of your files and database
* **Automated Scheduling** - Native WordPress cron scheduling (Daily, Weekly, Monthly)
* **One-Click Restore** - Restore your site directly from local backups
* **Downloads** - Download backup archives to your computer
* **Media Optimization** - Optimize images with native PHP compression (GD/Imagick)
* **Image Compression** - Reduce image file sizes and convert to WebP format
* **Bulk Optimization** - Optimize multiple images at once

**Cloud Features (Requires Connection):**

* **Cloud Storage** - Offload backups to S3, Google Drive, Dropbox, etc.
* **WP Vault Cloud** - Get 3GB free cloud storage
* **Central Dashboard** - Manage multiple sites from one place
* **Secure Credentials** - Cloud API keys are stored securely off-site

**Perfect for:**

* **Agencies** managing multiple sites (Cloud Mode)
* **Users** wanting simple, free local backups (Free Mode)
* **Developers** needing a reliable restore tool

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/wp-vault` directory, or install through WordPress plugins screen
2. Activate the plugin through the 'Plugins' screen in WordPress
3. **For Free Mode:** Go to WP Vault → Schedule to set up automated local backups.
4. **For Cloud Mode:** Go to WP Vault → Settings to register and connect your cloud storage.

== Frequently Asked Questions ==

= Is WP Vault free? =

Yes! **Free Mode is truly free and independent.** You can use it indefinitely for local backups and scheduling without ever registering.

= Do I need to register or connect to the cloud? =

No. Registration is **completely optional**. You only need to connect if you want to store backups in the cloud (like S3 or Google Drive) or use our free 3GB cloud storage.

= What features work without a connection? local backup? =

Yes! Local backups, automated scheduling (native WP-Cron), restores, and downloads all work 100% locally without any connection.

= What storage providers are supported? =

(Requires Cloud Connection) WP Vault supports Google Cloud Storage, Amazon S3, MinIO, Wasabi, Backblaze B2, Google Drive, FTP, and SFTP.

= Can I restore to a different server? =

Yes! You can download your backups and restore them anywhere.

= How does incremental backup work? =

Incremental backups only upload files that have changed since the last backup, saving time and storage.

== Screenshots ==

1. Dashboard with backup list
2. Settings page with storage configuration
3. Backup in progress
4. Restore options
5. Media optimization interface with statistics
6. Image optimization modal with options
7. Media gallery showing optimization status
8. Compression Settings tab
9. Image comparison (Show Difference) modal

== Changelog ==

= 1.0.0 =
* Initial release
* Multi-storage support (S3, GCS, Google Drive, FTP, SFTP)
* Site registration and heartbeat
* Manual backup creation
* Backup listing
* Storage connection testing
* Media optimization system
* Image compression with native PHP (GD/Imagick)
* JavaScript client-side compression support
* WebP conversion capabilities
* Bulk image optimization
* Optimization statistics and tracking
* Gutenberg block editor integration for image optimization
* Per-image optimization modal with customizable options
* Show Difference feature for comparing original vs optimized images
* Compression Settings tab for default configuration
* Original file preservation (keep original option enabled by default)
* Optimized files saved as separate attachments (`-min.extension` format)
* Side-by-side image comparison modal

== Upgrade Notice ==

= 1.0.0 =
Initial release of WP Vault.
