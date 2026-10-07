# FileChi — Build, Scoping & Release Documentation

This document describes the reproducible build and vendor scoping process for **FileChi**.

---

## 1. Prerequisites

- **PHP:** 7.4 through 8.4 (with `openssl`, `sodium`, and `mbstring` extensions enabled)
- **Composer:** 2.x
- **OpenSSH:** `ssh-keygen` (for generating key pairs during testing)
- **Git**

---

## 2. Directory Layout & Dependency Scoping Architecture

FileChi ships self-contained in the WordPress ecosystem without risking conflicts with other plugins that might bundle differing versions of `phpseclib` or cryptographic libraries.

- **`includes/vendor/phpseclib/`**: Scoped under `FileChi\Vendor\phpseclib3\`
- **`includes/vendor/paragonie/constant_time_encoding/`**: Scoped under `FileChi\Vendor\ParagonIE\ConstantTime\`
- **`includes/vendor/woocommerce/action-scheduler/`**: Bundled **unscoped** (Automattic design: self-orchestrating version-safe loader across all active plugins)
- **`includes/vendor/autoload.php`**: Custom lightweight autoloader mapping `FileChi\Vendor\` to the scoped packages

---

## 3. Rebuild Steps

### Step 3.1: Install Dependencies
Run Composer to install all runtime and development packages:
```bash
composer install
```

### Step 3.2: Run Vendor Scoping
Execute the scoping script:
```bash
php tools/scope-vendor.php
# or via composer shortcut:
composer run-script scope-vendor
```

This automated tool performs the following operations:
1. Copies `phpseclib/phpseclib` and `paragonie/constant_time_encoding` from `vendor/` to `includes/vendor/`.
2. Preserves their original MIT `LICENSE` / `LICENSE.txt` files inside `includes/vendor/`.
3. Transforms all class namespaces:
   - `phpseclib3\` &rarr; `FileChi\Vendor\phpseclib3\`
   - `ParagonIE\ConstantTime\` &rarr; `FileChi\Vendor\ParagonIE\ConstantTime\`
4. Copies `woocommerce/action-scheduler` unscoped into `includes/vendor/woocommerce/action-scheduler/`.
5. Generates the self-contained autoloader in `includes/vendor/autoload.php`.

---

## 4. Verification Procedures

### 4.1 Namespace Cleanliness Verification
Verify that no unscoped vendor namespaces remain in `includes/vendor`:
```bash
# Check phpseclib3: should produce 0 results
git grep -n "namespace phpseclib3" includes/vendor/

# Check ParagonIE\ConstantTime: should produce 0 results
git grep -n "namespace ParagonIE\\ConstantTime" includes/vendor/

# Verify scoped namespaces are present:
git grep -n "namespace FileChi\\Vendor\\phpseclib3" includes/vendor/
git grep -n "namespace FileChi\\Vendor\\ParagonIE\\ConstantTime" includes/vendor/
```

### 4.2 Key Loading Verification
Verify that `PublicKeyLoader::load()` functions with both RSA and Ed25519 keys:
```bash
# Generate test keys:
ssh-keygen -t rsa -b 2048 -f scratch/test_rsa_key -q -N ""
ssh-keygen -t ed25519 -f scratch/test_ed25519_key -q -N ""

# Run PHP verification script:
php scratch/test_scoped_keys.php
```
Expected output:
```
=== TEST 1: Load Scoped RSA Private Key ===
Loaded key class: FileChi\Vendor\phpseclib3\Crypt\RSA\PrivateKey
RSA Key Length: 2048 bits
RSA Key Load: PASS

=== TEST 2: Load Scoped RSA Public Key (.pub) ===
Loaded public key class: FileChi\Vendor\phpseclib3\Crypt\RSA\PublicKey
RSA Public Key Load: PASS

=== TEST 3: Load Scoped Ed25519 Private Key ===
Loaded key class: FileChi\Vendor\phpseclib3\Crypt\EC\PrivateKey
Ed25519 Key Load: PASS

=== TEST 4: Load Scoped Ed25519 Public Key (.pub) ===
Loaded public key class: FileChi\Vendor\phpseclib3\Crypt\EC\PublicKey
Ed25519 Public Key Load: PASS

=== TEST 5: Verify SFTP Driver Class with Scoped phpseclib ===
SFTP Storage Driver initialized with RSA key: PASS

ALL ITEM H KEY LOADING TESTS PASSED!
```

### 4.3 Static Compatibility Analysis (PHP 7.4 - 8.4)
Run PHPCompatibility across the plugin codebase:
```bash
vendor/bin/phpcs --standard=PHPCompatibility --runtime-set testVersion 7.4-8.4 filechi.php includes/
```
Ensure zero syntax or version compatibility errors.
