# CLAUDE.md — Anketa and Custom Users Plugin

## Plugin Identity

| Key | Value |
|---|---|
| Plugin Name | Anketa and Custom Users |
| Slug | `anketa-and-custom-users` |
| Main File | `arttime-club-member.php` |
| Class Prefix | `ACU_` |
| Hook / Option Prefix | `acu_` |
| Text Domain | `acu` |
| Version Constant | `ACU_VERSION` |
| Min PHP | 8.0 |
| Min WordPress | 6.3 |
| Min WooCommerce | 8.0 |

## Module Map

| File | Class | Responsibility |
|---|---|---|
| `arttime-club-member.php` | — | Constants, bootstrap, activation/deactivation hooks |
| `includes/class-acu-core.php` | `ACU_Core` | Singleton orchestrator; loads all modules; DB table creation |
| `includes/class-acu-migration.php` | `ACU_Migration` | One-time data migration from old plugin option/meta keys |
| `includes/class-acu-helpers.php` | `ACU_Helpers` | Static utility belt — phone normalize, consent, terms, coupon, email |
| `includes/class-acu-sms.php` | `ACU_SMS` | MS Group API wrapper (`send()`) |
| `includes/class-acu-otp.php` | `ACU_OTP` | OTP generation, transient storage, rate limiting, AJAX handlers |
| `includes/class-acu-auth.php` | `ACU_Auth` | Phone-number WooCommerce login (`authenticate` filter) |
| `includes/class-acu-settings.php` | `ACU_Settings` | Unified admin settings page (Settings → Club Member Settings) |
| `includes/class-acu-registration.php` | `ACU_Registration` | `[club_anketa_form]` shortcode + form POST processor |
| `includes/class-acu-account.php` | `ACU_Account` | My Account hooks, WC template override, consent/phone save |
| `includes/class-acu-checkout.php` | `ACU_Checkout` | Checkout consent render, OTP validation, coupon auto-apply |
| `includes/class-acu-print.php` | `ACU_Print` | Rewrite rules + template loader for print pages |
| `includes/class-acu-shortcodes.php` | `ACU_Shortcodes` | `[user_data_check]`, `[acm_print_terms_button]`, `[wcu_print_terms_button]` |
| `includes/class-acu-admin.php` | `ACU_Admin` | User list columns, CSV import/export, bulk link AJAX |

## AJAX Actions

| Action | Nonce Action | Nonce Field | Handler | Auth |
|---|---|---|---|---|
| `acu_send_otp` | `acu_sms_nonce` | `nonce` | `ACU_OTP::ajax_send_otp()` | none |
| `acu_verify_otp` | `acu_sms_nonce` | `nonce` | `ACU_OTP::ajax_verify_otp()` | none |
| `acu_udc_search` | `acu_udc_ajax` | `nonce` | `ACU_Shortcodes::ajax_udc_search()` | none |
| `acu_bulk_link` | `acu_bulk_link` | `_nonce` | `ACU_Admin::ajax_bulk_link()` | manage_options |
| `acu_test_email` | `acu_test_email` | `_nonce` | `ACU_Admin::ajax_test_email()` | manage_options |

### admin-post.php Actions

| Action | Nonce | Handler |
|---|---|---|
| `acu_export_users` | `acu_export_users` (query arg `_wpnonce`) | `ACU_Admin::handle_export_users()` |
| `acu_download_import_example` | `acu_download_import_example` | `ACU_Admin::handle_download_import_example()` |

## WordPress Option Keys

| Option | Default | Description |
|---|---|---|
| `acu_sms_username` | `''` | MS Group SMS API username |
| `acu_sms_password` | `''` | MS Group SMS API password |
| `acu_sms_client_id` | `0` | MS Group client ID |
| `acu_sms_service_id` | `0` | MS Group service ID |
| `acu_admin_email` | `''` | Admin notification email (falls back to site admin email) |
| `acu_enable_email_notification` | `false` | Toggle email on consent change |
| `acu_terms_url` | `''` | External T&C URL |
| `acu_terms_html` | `''` | Default T&C HTML (WP editor) |
| `acu_sms_terms_html` | `''` | SMS-specific T&C HTML |
| `acu_call_terms_html` | `''` | Call-specific T&C HTML |
| `acu_auto_apply_club` | `false` | Auto-apply club card coupon at checkout |
| `acu_db_version` | `''` | DB schema version (current: `'1.0'`) |
| `acu_migration_version` | `''` | Migration version (current: `'1.0'`) |

## User Meta Keys

| Meta Key | Type | Description |
|---|---|---|
| `billing_phone` | string | Phone number (stored as `+995XXXXXXXXX`) |
| `_acu_personal_id` | string | 11-digit personal ID (migrated from `_personal_id`) |
| `_sms_consent` | `'yes'`/`'no'`/`''` | SMS marketing consent |
| `_call_consent` | `'yes'`/`'no'`/`''` | Phone call consent |
| `_acu_club_card_coupon` | string | Linked ERP coupon code (migrated from `_club_card_coupon`) |
| `_acu_terms_accepted` | datetime string | When T&C was accepted (migrated from `_wcu_terms_accepted`) |
| `_acu_verified_phone` | string | Last OTP-verified 9-digit phone (migrated from `_verified_phone_number`) |
| `_acu_dob` | string | Date of birth (migrated from `_anketa_dob`) |
| `_acu_card_no` | string | Physical card number (migrated from `_anketa_card_no`) |
| `_acu_responsible_person` | string | Staff who registered the member |
| `_acu_form_date` | string | Date Anketa form was filled |
| `_acu_shop` | string | Shop/location |

## Transient Keys

| Key Pattern | TTL | Purpose |
|---|---|---|
| `acu_otp_{phone9}` | 300s | OTP code |
| `acu_rate_{md5(phone9+ip)}` | 600s | OTP rate limit counter |
| `acu_vtoken_{phone9}` | 300s | Verification token |
| `acu_udc_rate_{md5(ip)}` | 60s | UDC search rate limit |

## Database Table

**Table:** `{wpdb->prefix}acu_external_phones`

```sql
CREATE TABLE wp_acu_external_phones (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    phone      VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY phone (phone)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Created by `ACU_Core::create_db_table()` on activation (uses `dbDelta()`).

## Asset Loading Conditions

| Asset Handle | File | Loaded When |
|---|---|---|
| `acu-frontend` (CSS) | `assets/css/frontend.css` | checkout, account page, or post with `[club_anketa_form]` |
| `acu-sms-verification` (JS) | `assets/js/sms-verification.js` | same as above |
| `acu-account` (CSS) | `assets/css/account.css` | account pages |
| `acu-account` (JS) | `assets/js/account.js` | account pages |
| `acu-shortcode` (CSS) | `assets/css/shortcode.css` | post with `[user_data_check]` |
| `acu-shortcode` (JS) | `assets/js/shortcode.js` | post with `[user_data_check]` |
| `acu-admin` (JS) | `assets/js/admin.js` | settings page (`settings_page_acu-settings`) |

## OTP Flow (step-by-step)

1. User enters 9-digit phone → JS detects valid phone → shows **Verify** button.
2. User clicks **Verify** → modal opens immediately, OTP send request starts.
3. `acu_send_otp` AJAX → `ACU_OTP::ajax_send_otp()`:
   - `check_ajax_referer('acu_sms_nonce', 'nonce')`
   - Rate check: transient `acu_rate_{md5(phone+ip)}` < 3 within 600s
   - Generate 6-digit code → store in `acu_otp_{phone9}` (300s TTL)
   - `ACU_SMS::send()` → `http://bi.msg.ge/sendsms.php`
   - Increment rate limit counter
4. User enters 6 digits → auto-verify triggered.
5. `acu_verify_otp` AJAX → `ACU_OTP::ajax_verify_otp()`:
   - Compare code against `acu_otp_{phone9}`
   - On match: delete OTP transient, generate token → `acu_vtoken_{phone9}` (300s TTL)
   - Return token to JS → stored in hidden field `.otp-verification-token`
6. On form submit (checkout/account): `otp_verification_token` sent in POST.
7. PHP validates: `ACU_OTP::is_phone_verified($phone9, $token)` checks `acu_vtoken_{phone9}`.

### Checkout "Verification on Demand"

- No visible verify button on checkout page.
- JS intercepts `#place_order` click.
- If phone unverified → opens modal → sends OTP.
- After successful verify → triggers `#place_order` click again (auto-submit).

## Print Pages

| URL | Template |
|---|---|
| `/print-anketa/?user_id=ID` | `templates/print-anketa.php` |
| `/signature-terms/?user_id=ID&terms_type=sms\|call` | `templates/signature-terms.php` |

Rewrite rules registered by `ACU_Print`. Flushed on activation/deactivation.

## Shortcodes

| Shortcode | Handler | Notes |
|---|---|---|
| `[club_anketa_form]` | `ACU_Registration` | Full Anketa registration form |
| `[user_data_check]` | `ACU_Shortcodes` | Staff search form |
| `[acm_print_terms_button label="" class="" type="default\|sms\|call"]` | `ACU_Shortcodes` | Print terms link |
| `[wcu_print_terms_button]` | `ACU_Shortcodes` | Backward-compat alias for acm_print_terms_button |

## Data Migration

`ACU_Migration::run()` executes once on activation (guarded by `acu_migration_version` option).

- Copies options from `club_anketa_*` and `wcu_*` → `acu_*` (does NOT delete old options)
- Renames user meta via `$wpdb->query()` UPDATE (bulk, efficient)
- Migrates DB data from `wp_club_anketa_external_phones` → `wp_acu_external_phones` (INSERT IGNORE)

## Coding Standards

- All `$_POST` / `$_GET` input: `wp_unslash()` then appropriate sanitizer
- Text → `sanitize_text_field()`
- Email → `sanitize_email()` + `is_email()`
- HTML → `ACU_Helpers::sanitize_html()` (allowlist via `wp_kses()`)
- Integers → `absint()`
- DB queries → always `$wpdb->prepare()`
- Output in HTML → `esc_html()` / `esc_attr()` / `esc_url()` / `wp_kses_post()`

## Test Commands

```bash
# Create a test user with phone
wp user create testuser testuser@example.com --user_pass=password123 --first_name=Test --last_name=User
wp user meta update <ID> billing_phone '599123456'

# Manually set OTP transient (simulate sent OTP)
wp eval 'set_transient("acu_otp_599123456", "123456", 300);'

# Check rate limit transient
wp eval 'var_dump(get_transient("acu_rate_" . md5("599123456" . "127.0.0.1")));'

# Test phone normalization
wp eval 'echo ACU_Helpers::normalize_phone("+995 599 123 456");'

# Flush rewrite rules (after adding new print page)
wp rewrite flush

# Check DB table exists
wp db query "SHOW TABLES LIKE '%acu_external_phones%';"

# Run migration manually
wp eval 'ACU_Migration::run();'
```

## GitHub Auto-Updater

Module: `includes/class-acu-github-updater.php` — class `ACU_GitHub_Updater`

The plugin ships with a built-in update mechanism that hooks into the WordPress core update pipeline. Site admins see update notifications and can install updates through **WP Admin → Plugins** exactly like any wp.org plugin.

### How It Works

| Filter | Purpose |
|---|---|
| `pre_set_site_transient_update_plugins` | Injects update data when GitHub has a newer tag |
| `plugins_api` | Populates the "View Details" modal with release notes |
| `upgrader_source_selection` | Renames the GitHub zip folder to match the plugin slug |

- GitHub API endpoint: `https://api.github.com/repos/Samsiani/Anketa-and-Custom-Users/releases/latest`
- API response is cached in a transient (`acu_github_update_data`) for **12 hours** to avoid rate limiting
- Version comparison: `version_compare( ACU_VERSION, $latest_tag, '<' )`
- The download package is the release's `zipball_url`

### Release & Update Workflow

Follow these exact steps every time you ship a new version. There is **no CI/CD pipeline** — the GitHub Release itself is the deployment artifact.

#### Step 1 — Bump the version number

Edit **two** places in `arttime-club-member.php`:

```php
// Plugin header
* Version: X.Y.Z

// Version constant (line ~18)
define( 'ACU_VERSION', 'X.Y.Z' );
```

Use [Semantic Versioning](https://semver.org/):
- `PATCH` (1.0.**1**) — bug fixes only
- `MINOR` (1.**1**.0) — new backwards-compatible features
- `MAJOR` (**2**.0.0) — breaking changes

#### Step 2 — Commit the version bump

```bash
git add arttime-club-member.php
git commit -m "Release vX.Y.Z"
```

#### Step 3 — Create and push a Git tag

The tag **must** match the version number, prefixed with `v`:

```bash
git tag vX.Y.Z
git push origin main
git push origin vX.Y.Z
```

#### Step 4 — Create a GitHub Release

1. Go to `https://github.com/Samsiani/Anketa-and-Custom-Users/releases/new`
2. Select the tag `vX.Y.Z` you just pushed
3. Set **Release title** to `vX.Y.Z`
4. Write release notes in the description (markdown supported — shown in the WP "View Details" modal)
5. Click **Publish release**

WordPress sites will detect the update within 12 hours (or immediately if an admin visits the Plugins page and WordPress forces a recheck).

#### Step 5 — Verify the update is detected

On any WordPress site with the plugin installed:

```bash
# Force WordPress to clear the update transient and recheck
wp transient delete acu_github_update_data
wp plugin list --status=active --fields=name,version,update
```

Or in WP Admin: **Dashboard → Updates → Check Again**.

### Rollback

If a release is broken:
1. Delete the GitHub Release (keeps the tag)
2. The updater will fall back to the previous cached response for up to 12 hours, then see no update
3. If sites already updated, push a new patch release (e.g. `v1.0.2`) with the fix

### Important Rules

- **Never push a tag without a corresponding GitHub Release.** The updater reads from `/releases/latest`, not tags.
- **Tag format must be `vX.Y.Z`** (the updater strips the leading `v` with `ltrim()`).
- **Do not delete and re-push tags.** Create a new patch version instead.
- The transient key `acu_github_update_data` can be manually cleared on any site to force an immediate recheck.

---

## PR Checklist

- [ ] All AJAX handlers use `check_ajax_referer()` or `check_admin_referer()`
- [ ] All admin POST handlers include capability check (`manage_options`)
- [ ] All `$_POST` / `$_GET` values are sanitized before use
- [ ] All HTML output is escaped
- [ ] All DB queries use `$wpdb->prepare()`
- [ ] No duplicate hooks (only one handler per action)
- [ ] Assets only enqueued on relevant pages (not globally)
- [ ] `ACU_Migration::run()` is idempotent (safe to call multiple times)
- [ ] Rewrite rules flushed on activation and deactivation

---

## Changelog

### v1.0.1 — 2026-02-20

- **Optional email in Anketa form:** Email field is no longer required. Label updated to indicate it is optional (needed only for site registration). A dummy `@no-email.local` address is used when no email is provided so `wp_insert_user()` always receives a valid address. The dummy address is hidden on the print-anketa page.
- **Moved signature line:** The "User Signature" row has been removed from `signature-terms.php` and moved to the very bottom of `print-anketa.php` (after the Shop row), so it appears at the end of the printed form.
- **Conditional admin email notifications:** `maybe_send_consent_notification()` now fires only when SMS consent is `'yes'`; a `'no'` selection no longer triggers an admin email.
