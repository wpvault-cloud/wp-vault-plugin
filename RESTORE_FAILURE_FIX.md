# Restore Failure Fix - Problem Description & Solution

## Problem Statement

The restore operation is failing with two critical issues:

1. **All files skipped during restore**: Log shows "Scanned: 4410, Skipped: 4410, Restored: 0" - no files are being restored
2. **Database import reading binary data**: SQL errors show binary garbage being treated as SQL queries (e.g., "i{Y$cgО7L9X1" instead of SQL statements)

## Root Cause Analysis

### Issue 1: Files Not Being Restored

**Location**: `wp-vault-plugin/includes/class-wp-vault-restore-engine.php` - `restore_files()` method (around line 459)

**Problem**: 
- Files are extracted from tar.gz archives
- The code checks if `$relative_path` starts with component paths like `wp-content/themes/`
- However, extracted files might have a different path structure
- When files are extracted from tar.gz, they might be:
  - Extracted with full path: `/var/www/html/wp-content/themes/...`
  - Extracted with relative path: `wp-content/themes/...`
  - Extracted flat: `themes/...` (no wp-content prefix)
  - Extracted with backup ID prefix: `backup-{id}/wp-content/themes/...`

**Current Code Logic** (line 519-532):
```php
$relative_path = str_replace($extract_dir, '', $file->getPathname());

// Check if this file belongs to a selected component
$should_restore = false;
foreach ($components_to_restore as $component) {
    if (isset($component_paths[$component])) {
        if (strpos($relative_path, $component_paths[$component]) === 0) {
            $should_restore = true;
            break;
        }
    }
}
```

**The Problem**: If extracted files have paths like:
- `themes/twenty-twenty-three/style.css` (no `wp-content/` prefix)
- `backup-EH_X9kShkP6zZ3RuJw0A6/wp-content/themes/...` (with backup ID prefix)
- Absolute paths that don't match

Then `strpos($relative_path, 'wp-content/themes/')` will return `false`, and all files are skipped.

### Issue 2: Database Import Reading Binary Data

**Location**: `wp-vault-plugin/includes/class-wp-vault-restore-engine.php` - `import_to_temp_tables()` method (around line 665)

**Problem**:
- Database file is decompressed using `decompress_file()` (line 426)
- The decompressed file should be plain text SQL
- However, the log shows binary data being read as SQL queries
- This suggests either:
  1. Decompression is failing silently
  2. The decompressed file is still binary/corrupted
  3. The file is being read incorrectly

**Current Decompression Code** (line 1395-1409):
```php
private function decompress_file($source, $destination)
{
    $fp_in = gzopen($source, 'rb');
    $fp_out = fopen($destination, 'wb');
    
    while (!gzeof($fp_in)) {
        fwrite($fp_out, gzread($fp_in, 8192));
    }
    
    gzclose($fp_in);
    fclose($fp_out);
}
```

**The Problem**: 
- If `gzopen()` fails, it returns `false`, but the code doesn't check for this
- If the source file is not actually a gzip file, `gzread()` will return binary data
- The decompressed file might be corrupted if the source was corrupted
- No validation that the decompressed file is actually text/SQL

**Current SQL Reading Code** (line 720-753):
```php
$handle = fopen($sql_file, 'r');
// ...
$chunk = fread($handle, $chunk_size);
$buffer .= $chunk;
$queries = $this->extract_complete_queries($buffer);
```

**The Problem**: 
- If the file contains binary data, `extract_complete_queries()` will try to parse it as SQL
- Binary data will be treated as queries, causing "invalid data" errors
- No validation that the file is actually text before processing

## Solution Approach

### Fix 1: Improve File Path Matching

**Solution**: Make the path matching more flexible to handle different extraction structures.

**Implementation**:
1. Normalize the relative path (remove backup ID prefixes, handle absolute paths)
2. Check multiple path patterns for each component
3. Add logging to see what paths are being checked
4. Handle both `wp-content/themes/` and `themes/` patterns

```php
// Normalize relative path
$relative_path = str_replace($extract_dir, '', $file->getPathname());
// Remove backup ID prefix if present
$relative_path = preg_replace('/^backup-[^\/]+\//', '', $relative_path);
// Remove leading slashes
$relative_path = ltrim($relative_path, '/');

// Check if this file belongs to a selected component
$should_restore = false;
foreach ($components_to_restore as $component) {
    if (isset($component_paths[$component])) {
        $base_path = $component_paths[$component]; // e.g., 'wp-content/themes/'
        $alt_path = str_replace('wp-content/', '', $base_path); // e.g., 'themes/'
        
        // Check both full path and alternative path
        if (strpos($relative_path, $base_path) === 0 || 
            strpos($relative_path, $alt_path) === 0 ||
            strpos($relative_path, '/' . $base_path) !== false ||
            strpos($relative_path, '/' . $alt_path) !== false) {
            $should_restore = true;
            break;
        }
    }
}
```

### Fix 2: Validate Database Decompression

**Solution**: Add validation and error handling for database decompression.

**Implementation**:
1. Check if `gzopen()` succeeds
2. Verify the decompressed file is actually text (not binary)
3. Add fallback decompression methods
4. Validate SQL file format before importing

```php
private function decompress_file($source, $destination)
{
    // Check if source file exists and is readable
    if (!file_exists($source) || !is_readable($source)) {
        throw new \Exception('Database file not found or not readable: ' . $source);
    }
    
    // Check if it's actually a gzip file (check magic number)
    $handle = fopen($source, 'rb');
    $header = fread($handle, 2);
    fclose($handle);
    
    if (strlen($header) !== 2 || ord($header[0]) !== 0x1f || ord($header[1]) !== 0x8b) {
        // Not a gzip file - might already be decompressed
        if (copy($source, $destination)) {
            $this->log_php('[WP Vault] Database file is not gzipped, copied directly');
            return;
        } else {
            throw new \Exception('Failed to copy database file (not gzipped)');
        }
    }
    
    $fp_in = gzopen($source, 'rb');
    if (!$fp_in) {
        // Try alternative decompression method
        $this->log_php('[WP Vault] gzopen failed, trying alternative method...');
        $content = file_get_contents('compress.zlib://' . $source);
        if ($content === false) {
            throw new \Exception('Failed to decompress database file');
        }
        file_put_contents($destination, $content);
        $this->log_php('[WP Vault] Database file decompressed using alternative method');
        return;
    }
    
    $fp_out = fopen($destination, 'wb');
    if (!$fp_out) {
        gzclose($fp_in);
        throw new \Exception('Failed to open destination file for writing');
    }
    
    $bytes_written = 0;
    while (!gzeof($fp_in)) {
        $data = gzread($fp_in, 8192);
        if ($data === false) {
            gzclose($fp_in);
            fclose($fp_out);
            throw new \Exception('Error reading from gzip file');
        }
        $written = fwrite($fp_out, $data);
        if ($written === false) {
            gzclose($fp_in);
            fclose($fp_out);
            throw new \Exception('Error writing decompressed data');
        }
        $bytes_written += $written;
    }
    
    gzclose($fp_in);
    fclose($fp_out);
    
    // Validate decompressed file is text (SQL)
    if ($bytes_written > 0) {
        $this->validate_sql_file($destination);
    }
}

private function validate_sql_file($sql_file)
{
    // Read first 1KB to check if it's text
    $handle = fopen($sql_file, 'r');
    $sample = fread($handle, 1024);
    fclose($handle);
    
    // Check if it contains SQL keywords
    $sql_keywords = array('CREATE', 'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TABLE');
    $has_sql = false;
    foreach ($sql_keywords as $keyword) {
        if (stripos($sample, $keyword) !== false) {
            $has_sql = true;
            break;
        }
    }
    
    // Check if it's mostly printable text (not binary)
    $printable = 0;
    $total = strlen($sample);
    for ($i = 0; $i < $total; $i++) {
        $char = ord($sample[$i]);
        if ($char >= 32 && $char <= 126 || $char === 9 || $char === 10 || $char === 13) {
            $printable++;
        }
    }
    $printable_ratio = $total > 0 ? $printable / $total : 0;
    
    if (!$has_sql || $printable_ratio < 0.8) {
        $this->log_php('[WP Vault] WARNING: Decompressed file may not be valid SQL');
        $this->log_php('[WP Vault] SQL keywords found: ' . ($has_sql ? 'yes' : 'no'));
        $this->log_php('[WP Vault] Printable character ratio: ' . round($printable_ratio * 100, 2) . '%');
        // Don't throw error, but log warning
    }
}
```

### Fix 3: Add Binary Data Detection in SQL Parser

**Solution**: Detect and skip binary data in the SQL import process.

**Implementation**:
1. Check if chunk contains mostly binary data
2. Skip binary chunks and log warnings
3. Only process chunks that look like SQL

```php
// In import_to_temp_tables(), before processing chunk:
$chunk = fread($handle, $chunk_size);
if ($chunk === false) {
    break;
}

// Check if chunk is binary data
$is_binary = $this->is_binary_data($chunk);
if ($is_binary) {
    $this->log_php('[WP Vault] WARNING: Skipping binary data chunk at offset: ' . ftell($handle));
    continue; // Skip this chunk
}

$buffer .= $chunk;

private function is_binary_data($data)
{
    if (empty($data)) {
        return false;
    }
    
    $sample_size = min(512, strlen($data));
    $sample = substr($data, 0, $sample_size);
    
    // Count printable characters
    $printable = 0;
    for ($i = 0; $i < $sample_size; $i++) {
        $char = ord($sample[$i]);
        if ($char >= 32 && $char <= 126 || $char === 9 || $char === 10 || $char === 13) {
            $printable++;
        }
    }
    
    $ratio = $printable / $sample_size;
    
    // If less than 70% printable, consider it binary
    return $ratio < 0.7;
}
```

## Testing Checklist

After implementing fixes, test:

1. ✅ Restore with full backup (all components)
2. ✅ Verify files are actually restored (check file count)
3. ✅ Verify database is restored correctly (check table count, data)
4. ✅ Test with backup that has different path structures
5. ✅ Test with corrupted/invalid database file (should fail gracefully)
6. ✅ Check logs for path matching issues
7. ✅ Verify no binary data errors in SQL import

## Expected Behavior

### File Restore
- Files should be restored to correct locations
- Log should show: "Scanned: X, Skipped: Y, Restored: Z" where Z > 0
- Files should appear in `wp-content/themes/`, `wp-content/plugins/`, etc.

### Database Restore
- Database file should decompress correctly
- SQL file should be validated as text
- Import should process SQL queries, not binary data
- No "invalid data" errors
- Tables should be created and populated

## Files to Modify

1. **`wp-vault-plugin/includes/class-wp-vault-restore-engine.php`**
   - `restore_files()` method (around line 459) - Fix path matching
   - `decompress_file()` method (around line 1395) - Add validation
   - `import_to_temp_tables()` method (around line 665) - Add binary detection
   - Add `validate_sql_file()` helper method
   - Add `is_binary_data()` helper method

## Key Points

- **Path matching**: Must handle multiple extraction structures
- **Decompression validation**: Must verify file is actually text/SQL
- **Binary detection**: Must skip binary data in SQL import
- **Error handling**: Must fail gracefully with clear error messages
- **Logging**: Must log path matching attempts and validation results

## Success Criteria

The fix is successful when:
1. Files are restored (Restored count > 0)
2. Database imports without binary data errors
3. All components restore correctly
4. No "invalid data" SQL errors
5. Website is functional after restore

