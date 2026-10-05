# Legacy Feature and Behavior Reference (Hacklog Remote Attachment)

> **Document purpose:** Functional specification and vulnerability extraction based on a thorough read of the legacy plugin source code (`Hacklog Remote Attachment` v1.2.8 / v1.3.0, pulled from WordPress.org on 2025-04-23 for security issues).
> Per **AGENTS.md Section 3.1**, this document is used purely as functional reference for the clean-room **FileChi** rewrite. Zero lines of legacy code are reused or refactored.

---

## 1. Functional Analysis

### 1.1 Configuration & Storage of Settings
- **Options Storage:** All options were stored in a single WordPress option `hacklogra_options` (`wp_options` table). Remote storage usage (in bytes) was tracked in `hacklogra_remote_filesize`.
- **Configurable Fields:**
  - `ftp_server`: IP address or domain of the remote server (legacy code defaulted to a private IP `172.30.16.31`).
  - `ftp_port`: Connection port (default `21`).
  - `ftp_user`: FTP account username.
  - `ftp_pwd`: Encrypted password string.
  - `ftp_timeout`: Connection timeout in seconds (default `30`).
  - `remote_baseurl`: Base HTTP/HTTPS URL pointing to the remote server root (e.g. `http://www.your-domain.com`).
  - `ftp_remote_path`: Relative directory path on the FTP filesystem where uploads are stored (e.g. `wp-files` or `.` for root).
  - `http_remote_path`: Corresponding URL path segment mapping to `ftp_remote_path`.

### 1.2 Upload Lifecycle & Transport Logic
- **Hook Strategy:**
  - Hooked `wp_handle_upload` (`upload_and_send`):
    - Differentiated image files (`jpg`, `jpeg`, `png`, `gif`, `bmp`) from non-image files.
    - **Non-image files:** Read entire file contents into memory via `file_get_contents()`, established FTP connection via WordPress's `WP_Filesystem_ftpext` (or `WP_Filesystem_ftpsockets`), ensured remote directory structure existed (recursively creating missing folders and writing empty `index.html` placeholders), uploaded file via `$fs->put_contents()`, incremented size counter in `wp_options`, and immediately deleted the local copy with `unlink()`.
    - **Image files:** Skipped offloading during `wp_handle_upload` so that WordPress core could perform image resizing and intermediate sub-size generation (and compatibility with watermark plugins).
  - Hooked `wp_update_attachment_metadata` (`upload_images`, priority 999):
    - Triggered after WordPress generated intermediate sub-sizes (thumbnails, medium, large, etc.).
    - Uploaded the original full-size image file and unlinked the local file.
    - Extracted all unique sub-size filenames from `$metadata['sizes']`, uploaded each sub-size file to the remote directory, updated the storage counter, and deleted the local copy.
  - Filename Deduplication:
    - Attempted to avoid remote collisions using a custom `unique_filename()` method that called `$fs->is_file()` in a loop to increment suffixes (e.g., `-1.jpg`, `-2.jpg`) before renaming the local file prior to transmission.

### 1.3 URL Rewriting & Delivery
- **Hooked Filters:**
  - `wp_get_attachment_url` (priority -999) and `attachment_link`: Substituted `$local_baseurl` with `$remote_baseurl` using naive `str_replace()`.
  - `media_send_to_editor`: Substituted `$local_url` with `$remote_url` in HTML sent to the post editor.
  - `wp_calculate_image_srcset` (priority -999): Iterated through srcset candidates and replaced `$local_url` with `$remote_url`.

### 1.4 Attachment Deletion Handling
- Hooked `wp_delete_file`:
  - Computed the remote relative path from the local path.
  - Connected to FTP, retrieved file size to decrement `hacklogra_remote_filesize`, and called `$fs->delete($file, false, 'f')`.
  - Did not support any option to preserve the remote file when the local attachment was deleted.

### 1.5 Admin UI & Migration Tools
- Admin menu added under **Settings > Remote Attachment** (`options-general.php`).
- Form displayed current FTP connection settings and remote disk usage.
- Included two naive database string replacement actions under a "Tools" section:
  - "Move": Ran an un-parameterized SQL query `UPDATE wp_posts SET post_content = REPLACE(post_content, '$orig_url', '$new_url')`.
  - "Recovery": Reversed the URL substitution in `post_content`.
- Displayed an admin notice on `plugins.php` if FTP connection failed.

---

## 2. Insecurities, Vulnerabilities & Design Flaws

The legacy plugin was pulled from WordPress.org on 2025-04-23 due to security issues. A technical review of the code reveals several critical vulnerabilities and design flaws:

### 2.1 Complete Lack of CSRF Protection (Missing Nonces)
- **Settings Save:** The options save handler (`if (isset($_POST['submit']))`) contained no `check_admin_referer()` or nonce validation. Any website visited by an authenticated WordPress administrator could execute a cross-site request forgery (CSRF) attack to alter FTP credentials, redirecting media uploads to an attacker-controlled server.
- **Tools URL Replacements:** The database update actions (`?hacklog_do=replace_old_post_attach_url` and `?hacklog_do=recovery_post_attach_url`) were executed on simple GET requests with no nonce verification (`wp_verify_nonce` was completely absent).

### 2.2 Direct SQL Injection (SQLi)
- In the URL replacement tools:
  ```php
  $orig_url = self::$local_baseurl;
  $new_url = self::$remote_baseurl;
  $sql = "UPDATE $wpdb->posts set post_content=replace(post_content,'$orig_url','$new_url')";
  $wpdb->query($sql);
  ```
  `$orig_url` and `$new_url` were concatenated directly into the SQL query without `$wpdb->prepare()`. Because `remote_baseurl` could be manipulated by an attacker (via the aforementioned CSRF or unescaped input), an attacker could execute arbitrary SQL queries against the database.

### 2.3 Broken Cryptography (Trivial XOR Cipher)
- Credential encryption in `includes/crypt.class.php` implemented a primitive single-byte rolling XOR cipher:
  ```php
  for ($i=0;$i<strlen($data);$i++)
      $encrypt .= $data[$i] ^ $this->key[$i % strlen($this->key)];
  ```
- The key was set to `AUTH_KEY`. Simple XOR encryption without a unique initialization vector (IV) or cryptographic authentication (MAC) provides zero semantic security. It is completely trivial to decrypt with basic known-plaintext XOR analysis and allows ciphertext bit-flipping attacks.

### 2.4 Insecure Transport (Plaintext FTP Only)
- The legacy plugin forced plain, unencrypted FTP (port 21, with hardcoded `'ssl' => FALSE`).
- All usernames, passwords, and sensitive attachment files were transmitted over the network in cleartext, exposing credentials to packet sniffing, man-in-the-middle (MITM) attacks, and interception.

### 2.5 Input Sanitization & Output Escaping Deficiencies
- Settings input was handled with PHP's deprecated `addslashes()` rather than WordPress sanitization functions like `sanitize_text_field()` or `esc_url_raw()`.
- Input fields in HTML templates echoed values without escaping (e.g. `value="<?php echo self::get_opt('ftp_server'); ?>"` instead of `esc_attr()`), creating cross-site scripting (XSS) vectors.
- Error messages were output directly without escaping (`echo $html`).

### 2.6 Hardcoded Internal Infrastructure Artifacts
- The source code contained hardcoded internal defaults:
  - Private IP: `172.30.16.31`
  - Default user: `admin`
  - Default XOR ciphertext: `4d4173594c77453d`
  This reflects unsafe development practices and leaky production configurations.

### 2.7 High Memory Consumption & Lack of Streaming
- The plugin used `file_get_contents()` to load entire files into PHP memory before uploading them via `put_contents()`. Uploading large media files (video, audio, high-resolution archives) easily causes fatal out-of-memory (`Allowed memory size exhausted`) errors.

### 2.8 Destructive, Un-batched Database Operations
- The "Move" tool performed a blind MySQL `REPLACE()` across all `post_content` in a single query. On large sites, this causes database timeouts, locks tables, and corrupts serialized strings or block attributes.

---

## 3. Implications for the FileChi Architecture

| Legacy Characteristic | Legacy Implementation | FileChi Clean-Room Architecture (AGENTS.md) |
|---|---|---|
| **Protocol Support** | Plain unencrypted FTP only | **SFTP** (primary, via namespace-scoped `phpseclib 3.x`), **FTPS** (secondary, via `ftp_ssl_connect()`), **S3-compatible REST client** (AWS S3, ArvanCloud, ParsPack with AWS SigV4). Plain FTP strictly prohibited. |
| **Credential Storage** | Custom XOR cipher in `wp_options` | Authenticated encryption using **libsodium** (`sodium_crypto_secretbox`), keyed with key derived from WP salts/constants; stored in custom database tables. |
| **Database Architecture** | Autoloaded `wp_options` | **Custom tables via `dbDelta`** for connection profiles and transfer/migration logs, indexed on query keys (`attachment_id`, `provider_id`, `status`). |
| **Security Controls** | No nonces, no sanitization, raw SQL | Strict nonces, `current_user_can()` capabilities, `sanitize_*()` on all inputs, `esc_*()` on all outputs, `$wpdb->prepare()` for all queries, path traversal defenses. |
| **Migration / Background** | Blind blocking MySQL `REPLACE()` | Reliable background processing via **Action Scheduler** with queueing, retry-on-failure, and live admin progress tracking. |
| **WooCommerce** | No support | Full **WooCommerce 10.x & 11.x** support, explicit **HPOS** compatibility declaration, signed time-limited downloadable URLs. |
| **Admin UI** | Monolithic PHP form with inline HTML | Modern SPA-like admin built with WordPress components (`@wordpress/components`, `@wordpress/data`) and REST API endpoints. |
| **Connection Testing** | Tested on save and on `plugins.php` page load | Dedicated **Test Connection** REST action testing live unsaved form credentials before persistence. |
