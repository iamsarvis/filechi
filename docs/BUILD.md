# FileChi — Build, Verification & Release Documentation

This document describes the local build procedures, automated safety net checks, vendor scoping architecture, and release packaging rules for the **FileChi** WordPress plugin on **Windows 10** (PowerShell & CMD).

---

## 1. Prerequisites

- **PHP:** 7.4 through 8.4 (CLI binary in PATH or specified directly, e.g. `C:\wamp64\bin\php\php8.2.30\php.exe`) with `openssl`, `sodium`, and `mbstring` extensions enabled.
- **Composer:** 2.x (installed in PATH or via `composer.bat`).
- **Git:** Version 2.x or later with LF line-endings configured (`* text=auto eol=lf`).

---

## 2. Running Automated Verification Checks

All commands below are provided for both **PowerShell 5.1+** and **Windows CMD**. Execute from the plugin repository root (`filechi/`).

### 2.1 Test Suite (`tests/run.php`)

Runs all isolated test cases in `tests/test_*.php`, testing crypto, media guards, HPOS protected downloads, provider deletion guards, and vendor scoping proofs.

- **PowerShell:**
  ```powershell
  php tests/run.php
  ```
- **CMD:**
  ```cmd
  php tests\run.php
  ```

*Expected result:* `8/8 passed, 0 failed`.

---

### 2.2 PHPStan Static Analysis (Level 5)

Analyzes `filechi.php`, `uninstall.php`, and `includes/` (excluding third-party code in `includes/vendor/`) at Level 5 with WordPress and WooCommerce stubs and Action Scheduler functions registered.

- **PowerShell:**
  ```powershell
  .\vendor\bin\phpstan analyse --memory-limit=1G
  ```
- **CMD:**
  ```cmd
  vendor\bin\phpstan.bat analyse --memory-limit=1G
  ```

*Expected result:* `[OK] No errors`.

---

### 2.3 PHPCS Security & Database Sniffs

Scans codebase against the five critical security and database sniffs (`WordPress.Security.EscapeOutput`, `WordPress.Security.NonceVerification`, `WordPress.Security.ValidatedSanitizedInput`, `WordPress.DB.PreparedSQL`, `WordPress.DB.DirectDatabaseQuery`) configured centrally in `phpcs.xml`.

- **PowerShell:**
  ```powershell
  .\vendor\bin\phpcs
  ```
- **CMD:**
  ```cmd
  vendor\bin\phpcs.bat
  ```

*Expected result:* `0 errors, 0 warnings` (15/15 files clean).

---

### 2.4 PHPCompatibility Scan (PHP 7.4 – 8.4)

Statically validates compatibility across the entire target PHP version range without requiring multiple PHP binaries installed locally.

- **PowerShell:**
  ```powershell
  .\vendor\bin\phpcs -p --standard=PHPCompatibility --runtime-set testVersion 7.4-8.4 --ignore=includes/vendor,vendor filechi.php uninstall.php includes/
  ```
- **CMD:**
  ```cmd
  vendor\bin\phpcs.bat -p --standard=PHPCompatibility --runtime-set testVersion 7.4-8.4 --ignore=includes/vendor,vendor filechi.php uninstall.php includes\
  ```

*Expected result:* `0 errors, 0 warnings` across all target versions (15/15 files clean).

---

## 3. Rebuilding `includes/vendor` From a Clean Clone

FileChi ships its runtime libraries directly inside `includes/vendor/` so end-users never have to run Composer. To regenerate `includes/vendor/` from source:

### 3.1 Install Composer Dev & Runtime Packages
```powershell
composer install
```
This populates the root `vendor/` directory with `phpseclib/phpseclib`, `paragonie/constant_time_encoding`, and `woocommerce/action-scheduler`, along with dev tools (PHPStan, PHPCS).

### 3.2 Execute Vendor Scoping
```powershell
php tools/scope-vendor.php
# Or using the composer shortcut:
composer run-script scope-vendor
```

### 3.3 Scoping Generator Clarification: `tools/scope-vendor.php` vs `Strauss`
- **Active Tool:** `tools/scope-vendor.php` is the **real deterministic generator** used in FileChi. It copies `phpseclib` and `constant_time_encoding` into `includes/vendor/`, replaces namespaces with `FileChi\Vendor\`, preserves third-party licenses, copies `woocommerce/action-scheduler` unscoped, and writes `includes/vendor/autoload.php`.
- **Strauss:** The configuration under `"extra": { "strauss": { ... } }` in `composer.json` is preserved as fallback architectural specification, but the project relies on `tools/scope-vendor.php` to avoid Windows path issues and ensure reproducible autoloader creation.

---

## 4. Release Packaging (Distribution ZIP)

The production distribution zip for zhaket.com must contain only runtime files.

### 4.1 Excluded Files & Folders
The following development artifacts **MUST BE EXCLUDED** from the final zip archive:
- `tests/` — Test runner, bootstrap, fixtures, and unit/integration tests
- `tools/` — Build and vendor scoping scripts
- `docs/` — Internal developer documentation
- `phpstan.neon` — PHPStan configuration
- `composer.json` & `composer.lock` — Composer manifests
- Root `vendor/` — Root developer tools (PHPStan, PHPCS, stubs)
- `.git/`, `.gitignore`, `.gitattributes` — Git version control files
- `.vscode/`, `scratch/`, or any local IDE/temporary artifacts

### 4.2 Included Files & Folders
The shipping plugin folder (`filechi/`) contains:
- `filechi.php` — Main plugin header and bootstrap
- `uninstall.php` — Data cleanup upon deletion
- `includes/` — Core PHP classes and `includes/vendor/` (scoped runtime packages and Action Scheduler)
- `assets/` — Production admin UI assets (scripts, styles)
- `languages/` — Translation template (`.pot`) and translations
- `readme.txt` — WordPress plugin metadata and user documentation
- `LICENSE` files for bundled libraries (`phpseclib`, `constant_time_encoding`, `action-scheduler`)

---

## 5. Plugin Data Inventory & Uninstall Cleanup Specifications

FileChi stores all persistent data using WordPress core conventions with explicit namespacing. The data inventory and deletion behaviors are documented below:

### 5.1 Custom Database Tables
- `{$wpdb->prefix}filechi_providers`: Stores remote storage configurations (SFTP, FTPS, S3/ArvanCloud/ParsPack) with encrypted credentials.
- `{$wpdb->prefix}filechi_logs`: Stores transfer/migration attempt rows and error reasons.

### 5.2 WordPress Options
- `filechi_settings`: Core configuration array (keep local, path format, batch size, etc.).
- `filechi_db_version`: Installed schema version.
- `filechi_migration_status`: Current state of migration (`running`, `paused`, `completed`, `given_up`, `stopped`).
- `filechi_protected_path_missing_notice`: Warning notice data when SFTP/FTPS protected path is unconfigured.

### 5.3 Transients & Lock Keys
- `filechi_lock_{attachment_id}`: Per-attachment execution lock (stored in `wp_options` as `_transient_filechi_lock_*` and `_transient_timeout_filechi_lock_*`, 120s TTL). Cleaned up automatically on uninstall via `_transient_filechi_%` pattern.

### 5.4 Attachment Post Meta (`wp_postmeta`)
- `_filechi_offloaded`: Flag (`1`) indicating all files belonging to the attachment are satisfied on remote storage.
- `_filechi_provider_id`: ID of the remote provider where files were transferred.
- `_filechi_retry_scheduled`: Flag (`1`) indicating an Action Scheduler retry job is pending.
- `_filechi_failure_count`: Integer counter tracking consecutive transfer failures for exponential backoff.
- `_filechi_given_up`: Flag (`1`) indicating max retry limit (5) has been reached or file is unretryable.
- `_filechi_last_error`: String recording the most recent specific failure reason.
- `_filechi_unretryable`: Flag (`1`) set when file is missing both locally and remotely.

### 5.5 Action Scheduler Scheduled Hooks
- `filechi_process_migration_batch`: Group `filechi`.
- `filechi_retry_attachment_offload`: Group `filechi`.

### 5.6 Uninstall Policy
- **Default (`delete_data_on_uninstall = 0`):** Safe uninstall. Cancels all pending Action Scheduler hooks, clears all `filechi_lock_*` transients. Preserves tables, options, and postmeta. Remote storage files are never touched.
- **Opt-in (`delete_data_on_uninstall = 1`):** Complete cleanup. Drops custom tables, deletes all `filechi_%` options, drops all `_transient_filechi_%` transients, and removes all `_filechi_%` postmeta keys. Remote storage files are never touched.
