# Changelog

All notable changes to WPVault Backup & Optimization will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.2] - 2026-01-04

### Added
- **GitHub Actions Release Workflow**: Automated release creation on tag push
  - Automatic plugin zip file generation
  - Release notes generation with installation instructions
  - Asset upload to GitHub releases
  - Support for version tags (v* format)

### Changed
- Updated release workflow to use `softprops/action-gh-release@v2` for better reliability
- Improved workflow configuration for tag-based releases

## [1.0.1] - 2026-01-04

### Added
- **Media Optimization System**:
  - Native PHP image compression using GD Library or Imagick
  - JavaScript client-side compression using `browser-image-compression`
  - Server-side cloud optimization via WPVault Cloud API
  - WebP format conversion support
  - Bulk image optimization functionality
  - Per-image optimization modal with customizable options
  - Optimization statistics and tracking database
  - Original vs optimized file size comparison
  - "Show Difference" feature for side-by-side image comparison
  - Compression Settings tab for default configuration
  - Original file preservation (enabled by default)
  - Optimized files saved as separate attachments (`-min.extension` format)
  - Media library integration with optimization status badges

- **Gutenberg Block Editor Integration**:
  - WPVault Optimize button in Image block toolbar
  - Custom lightning bolt icon for optimization action
  - Inline optimization modal within the editor
  - Success notifications showing storage saved percentage
  - Automatic image replacement after optimization

- **Community & Support Tab**:
  - Discord community widget integration
  - Discord server iframe with live member count
  - Help & Support section with multiple resources
  - Community statistics display (members, online users)
  - Links to Discord, Documentation, GitHub, and Email support

- **Database Schema**:
  - `wp_vault_media_optimization` table for tracking optimization history
  - Tracks original size, compressed size, compression ratio, space saved
  - Records optimization method, MIME types, and WebP conversion status
  - Optimization status tracking (completed, failed, pending)

- **System Capabilities Detection**:
  - Automatic detection of GD Library availability
  - Imagick extension detection
  - WebP support verification
  - Zlib extension checking
  - User-friendly capability status display

### Changed
- **Plugin Renaming**: Changed from "WPVault Plugin" to "WPVault Backup & Optimization"
- **Admin Menu**: Updated menu label to "WPVault"
- **Settings Tab Redesign**:
  - Renamed "API Configuration" to "WP Vault Configuration"
  - Changed "API Endpoint" to "Cloud URL"
  - Updated default Cloud URL to `https://wpvault.cloud`
  - Removed localhost/docker references
  - Added professional documentation and notes for advanced users
  - Improved form submission handling with proper redirects

- **Form Submission Fixes**:
  - Fixed "Save Settings" button functionality
  - Moved form handling to `admin_init` hook for proper redirects
  - Added comprehensive error logging
  - Fixed nonce verification issues
  - Added success message display after settings save

- **Text Domain Standardization**:
  - Updated all text domains to `wp-vault` (matching plugin folder name)
  - Fixed WordPress.org compliance issues
  - Added proper translator comments for all translatable strings
  - Fixed placeholder ordering in `sprintf()` calls

### Fixed
- Fixed incorrect size calculation for optimized images
- Fixed "Keep Original File" checkbox default value (now checked by default)
- Fixed system information sidebar display issues
- Fixed form submission redirect problems
- Fixed nonce verification errors
- Fixed `.htaccess` issues preventing post publishing
- Fixed class namespace issues in optimization tab
- Fixed Gutenberg editor integration errors
- Fixed database table creation consistency
- Fixed file deletion to use WordPress functions (`wp_delete_file` instead of `unlink`)

### Security
- Added proper nonce verification for all form submissions
- Added `phpcs:ignore` comments with justifications for necessary direct functions
- Improved input sanitization and validation

### WordPress.org Compliance
- Fixed Plugin URI and Author URI mismatch
- Removed compressed files from repository
- Removed hidden files (`.DS_Store`)
- Fixed all text domain mismatches
- Added missing translator comments
- Fixed unordered placeholder text
- Ensured proper internationalization practices

## [1.0.0] - 2025-12-26

### Added
- **Initial Release**: Complete backup and optimization platform

- **Backup Features**:
  - Manual backup creation
  - Automated backup scheduling (Daily, Weekly, Custom cron)
  - Incremental backup support
  - Backup compression (Fast mode: tar/gzip, Legacy mode: PHP ZIP)
  - File splitting for large backups (configurable size limit)
  - Backup listing and management
  - Backup download functionality
  - Backup deletion
  - Backup status tracking

- **Restore Features**:
  - One-click restore functionality
  - Restore history tracking
  - Granular restore options
  - URL replacement for cross-domain restores
  - Restore progress monitoring
  - Restore status notifications

- **Storage Providers**:
  - WP Vault Cloud (3GB free tier)
  - Amazon S3 (full compatibility)
  - Google Cloud Storage (requires service account)
  - Google Drive (15GB free with Google account)
  - FTP (standard protocol)
  - SFTP (secure file transfer)
  - MinIO (self-hosted S3-compatible)
  - Wasabi (S3-compatible API)
  - Backblaze B2 (S3-compatible API)

- **Dashboard Features**:
  - Unified tabbed interface
  - Dashboard overview with backup statistics
  - Connection status display
  - Storage usage information
  - System information sidebar
  - Quick links navigation
  - Help & Support section

- **Site Registration**:
  - Site registration with admin email
  - Site ID and token management
  - Connection to WPVault Cloud
  - Heartbeat monitoring
  - Site disconnection functionality

- **Settings & Configuration**:
  - General settings (site registration, Cloud URL)
  - Backup configuration (compression mode, file split size)
  - Storage settings (multiple provider support)
  - Temporary file management
  - Cleanup utilities

- **Logging & Monitoring**:
  - Detailed backup logs
  - Restore logs
  - Error tracking
  - Job status monitoring
  - Connection testing

- **Database Management**:
  - Custom database tables for jobs, logs, file index
  - Media optimization tracking table
  - Settings storage
  - Automatic table creation on activation

- **API Integration**:
  - REST API endpoints for SaaS integration
  - Backup triggering via API
  - Heartbeat API
  - Site registration API
  - Storage configuration API

- **Host Detection**:
  - Automatic host class detection
  - Capability assessment
  - Performance optimization based on host

- **Security Features**:
  - Nonce verification for all forms
  - Input sanitization
  - Output escaping
  - Secure file operations
  - Permission checks

### Technical Details
- **WordPress Requirements**: 5.8 or higher
- **PHP Requirements**: 7.4 or higher
- **Database**: MySQL/MariaDB
- **Dependencies**: 
  - GD Library or Imagick (for image optimization)
  - Zlib extension (for compression)
  - cURL (for API communication)

### Files Structure
- Admin interface files
- Core plugin classes
- Storage adapters (GCS, S3)
- Compression utilities
- File scanning and fingerprinting
- Job scheduling system
- REST API handlers
- Media optimization engine
- Gutenberg block editor integration

---

## Version History Summary

- **1.0.2** (2026-01-04): Added GitHub Actions release workflow
- **1.0.1** (2026-01-04): Added media optimization system, community support tab, fixed various issues
- **1.0.0** (2025-12-26): Initial release with backup, restore, and storage features

---

## Upgrade Notes

### Upgrading to 1.0.1+
- The media optimization feature requires database table creation. This happens automatically on plugin activation.
- If you're using image optimization, ensure GD Library or Imagick is available on your server.
- The "Keep Original File" option is now enabled by default for all optimizations.

### Upgrading to 1.0.2+
- No special upgrade steps required. The release workflow is for development purposes only.

---

## Support

For issues, questions, or contributions:
- **GitHub Issues**: https://github.com/wpvault-cloud/wp-vault-plugin/issues
- **Documentation**: https://wpvault.cloud/docs
- **Discord Community**: https://discord.gg/3PqKgZQWU3
- **Email Support**: support@wpvault.cloud

---

**Note**: This changelog is maintained based on commit history and feature additions. For the most up-to-date information, please refer to the [GitHub repository](https://github.com/wpvault-cloud/wp-vault-plugin).

