# FileChi (فایل‌چی)

**FileChi** is a high-performance, commercial-grade WordPress plugin designed to seamlessly offload media library attachments and WooCommerce product files to remote storage services.

Author: **Sobhan Askari** — [https://sobhanaskari.ir](https://sobhanaskari.ir)  
License: **GPL-2.0-or-later** — [https://www.gnu.org/licenses/gpl-2.0.htm](https://www.gnu.org/licenses/gpl-2.0.htm)

Succeeding discontinued legacy remote attachment plugins, FileChi is a complete clean-room rewrite built from the ground up for modern WordPress (6.0+) and PHP (7.4–8.4), adhering to strict WordPress Coding Standards (WPCS) and enterprise security requirements.

---

## 🌟 Key Features

### 1. Robust Remote Transport Architecture
- **SFTP (Primary Protocol):** Built with namespace-scoped **phpseclib 3.x** (prefixed to prevent library collisions). Supports both password authentication and RSA/Ed25519 SSH private key authentication.
- **FTPS (Secondary Protocol):** FTP over explicit TLS via native PHP `ftp_ssl_connect()`. Plain unencrypted FTP is strictly forbidden.
- **S3-Compatible Object Storage:** Lightweight, custom REST client using `wp_remote_request()` with manual **AWS Signature Version 4 (SigV4)** signing. Zero heavy dependencies (no AWS SDK).
  - Pre-configured presets for **ArvanCloud Object Storage** (Simin, Shahriar, etc.) and **ParsPack Object Storage**.
  - Standard **Amazon Web Services (AWS S3)**.
  - Custom S3-compatible providers (MinIO, Ceph, Cloudflare R2, DigitalOcean Spaces).
  - Path-style and virtual-hosted addressing support.

### 2. Native WordPress Media Library Integration
- Hooks transparently into `wp_handle_upload` and `wp_generate_attachment_metadata`.
- Generates all WordPress image sub-sizes, thumbnails, and scaled originals (`-scaled.jpg`) before offloading to remote storage.
- Automatically handles non-image files (PDFs, videos, archives, audio).
- Configurable setting to preserve or delete local file copies after offloading (defaults to deleting local copies to save disk space).
- Attachment deletion listener with toggle to keep or delete remote copies.
- Transparent URL rewriting across `wp_get_attachment_url`, `wp_calculate_image_srcset`, and editor insertion.

### 3. WooCommerce 10.x & 11.x Integration
- **High-Performance Order Storage (HPOS):** Explicitly declared and verified custom order tables compatibility.
- **Product Gallery Images:** Processed automatically through the generic Media Library pipeline.
- **Secure Downloadable Products:** Generates time-limited, signed URLs (via AWS SigV4 query authentication or HMAC-verified streaming endpoints) so permanent remote URLs and credentials are never exposed.

### 4. Background Bulk Migration Tool
- Asynchronous batch migration powered by **Action Scheduler** with queueing, retry-on-failure, and backoff.
- Real-time admin progress tracking with live percentage complete, offloaded count, and remote space calculation.

### 5. Enterprise Security Baseline
- **Authenticated Credential Encryption:** Remote credentials (passwords, secret access keys, private key passphrases) are encrypted using **libsodium** (`sodium_crypto_secretbox`) with keys derived from WordPress salts.
- **Custom Database Tables:** Connection profiles and transfer logs are stored in dedicated custom tables via `dbDelta()`, keeping `wp_options` lean and preventing autoload bloat.
- **CSRF & Capability Protection:** Strict nonce checks and `current_user_can('manage_options')` enforcement on all REST and admin endpoints.
- **Path Traversal Defense:** Strict path normalization and sanitization preventing directory traversal attacks.
- **Live Connection Testing:** Pre-save round-trip verification testing latency and credentials against remote servers before persisting settings.

---

## 🛠 Supported Providers

| Provider | Protocol | Notes |
|---|---|---|
| **ArvanCloud Object Storage** | S3 (SigV4) | Tehran Simin (`https://s3.ir-thr-at1.arvanstorage.ir`), Tabriz Shahriar, etc. Path-style enabled. |
| **ParsPack Object Storage** | S3 (SigV4) | Custom per-account endpoint configured in user panel. Path-style enabled. |
| **Amazon S3** | S3 (SigV4) | All AWS global regions supported. |
| **Custom S3 / MinIO** | S3 (SigV4) | Self-hosted MinIO, Ceph, Cloudflare R2, Wasabi, etc. |
| **SFTP Server** | SFTP | Standard SSH port 22; password or SSH private key auth. |
| **FTPS Server** | FTPS | FTP over explicit TLS (port 21). |

---

## 💻 Requirements

- **PHP:** 7.4 to 8.4 (tested and verified)
- **WordPress:** 6.0 or higher
- **WooCommerce (optional):** 10.x / 11.x (HPOS supported)
- **PHP Extensions:** `curl`, `json`, `sodium` (or `openssl`), `ftp` (if using FTPS)

---

## 📦 Third-party libraries

The following third-party libraries are bundled with FileChi under their respective licenses:

| Library | Version | License | License File Location |
|---|---|---|---|
| **phpseclib/phpseclib** | 3.0.57 | MIT | `includes/vendor/phpseclib/LICENSE` |
| **paragonie/constant_time_encoding** | v3.1.3 | MIT | `includes/vendor/paragonie/constant_time_encoding/LICENSE.txt` |
| **paragonie/random_compat** | v9.99.100 | MIT | Bundled with constant_time_encoding |
| **woocommerce/action-scheduler** | 4.2.0 | GPL-3.0-or-later | `includes/vendor/woocommerce/action-scheduler/license.txt` |

---

## 📄 License

FileChi is software created by **Sobhan Askari** and licensed under the [GNU General Public License v2.0 or later](https://www.gnu.org/licenses/gpl-2.0.html).

