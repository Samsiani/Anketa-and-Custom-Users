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

Module: `vendor/plugin-update-checker/` — **Plugin Update Checker v5 (PUC)**
by Yahnis Elsts (MIT). Initialized in `ACU_Core::init_update_checker()`.

PUC hooks into the WordPress update pipeline automatically. Site admins see
update notifications in **WP Admin → Plugins** and can install updates exactly
like any wp.org plugin. PUC also adds a native **"Check for updates"** link
on the Plugins page — no custom AJAX needed.

### How It Works

- GitHub Repository: `https://github.com/Samsiani/Anketa-and-Custom-Users/`
- PUC checks the latest GitHub Release and compares its tag against `ACU_VERSION`.
- `enableReleaseAssets()` ensures PUC downloads `arttime-club-member.zip` (the
  compiled release asset that includes `vendor/`) instead of the raw source zipball.

### Release & Update Workflow

To ship a new version, **simply merge a PR to main**. The GitHub Action fires automatically:

1. Reads the current version from `arttime-club-member.php`.
2. Increments the patch segment (e.g. `1.0.2` → `1.0.3`).
3. Writes the new version back to `arttime-club-member.php` (header + `ACU_VERSION` constant), commits `[skip ci]`, and pushes to main.
4. Tags the commit (e.g. `1.0.3`) and pushes the tag.
5. Builds `arttime-club-member.zip` (with `vendor/` included).
6. Publishes the GitHub Release with the zip attached.
7. WordPress sites detect the update within 12 hours, or immediately when an admin visits the Plugins page.

### Verify the update is detected

```bash
wp transient delete acu_github_update_data
wp plugin list --status=active --fields=name,version,update
```

Or in WP Admin: **Dashboard → Updates → Check Again**.

### Rollback

Push a new patch release with the fix. Do not delete and re-push tags.

### Important Rules

- **Never push a tag without the GitHub Action having run.** PUC reads from `/releases/latest`.
- **Tag format:** Any format works (`1.0.4` or `v1.0.4`) — PUC strips the leading `v`.
- **`vendor/` must be committed** to the repo so the Action can include it in the zip.

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

### v1.1.5 — 2026-02-20

- **Refactor: Print buttons moved to Anketa form.** Separated search (navigation) from
  management (printing/editing):
  - `[user_data_check]` result cards no longer show Print buttons. Registered users see
    only the "Edit Anketa" button (cap-gated `edit_users`); unregistered coupon users
    see only "Register (Anketa)".
  - `[club_anketa_form]` in edit mode now renders a management button bar above the form
    with: "Edit Anketa" (reload), "Print Anketa", "Print SMS Terms", "Print Phone Call Terms".
    All buttons use `display:flex;gap:0.75rem;flex-wrap:wrap` for proper spacing and
    responsiveness. Print links use the correct existing URL paths:
    `/print-anketa/?user_id=ID` and `/signature-terms/?user_id=ID&terms_type=sms|call`.

### v1.1.4 — 2026-02-20

- **Edit Anketa button on print page:** `templates/print-anketa.php` now renders an
  "Edit Anketa" button in the print-actions bar alongside the Print and Terms buttons.
  The button auto-discovers the `[club_anketa_form]` page via a `$wpdb` query and links
  to `?edit_user=USER_ID`. Hidden when no anketa page is found.

### v1.1.3 — 2026-02-20

- **Print buttons in search results card:** `render_result_html()` now shows a flex
  action bar with "Print Anketa", "Print SMS Terms", "Print Phone Call Terms", and
  "Edit Anketa" (cap-gated) buttons directly in the result card header. Links point to
  `/print-anketa/?user_id=ID` and `/signature-terms/?user_id=ID&terms_type=sms|call`.
- **SMS consent on unregistered coupon cards:** `render_coupon_result_html()` now
  displays an "SMS თანხმობა: კი" badge row, making it explicit that the phone is on
  the consent whitelist even though the user is not registered.
- **External phone badge updated:** Badge text changed from "თანხმობა" → "კი" to be
  consistent with the coupon card wording.
- **Batch limit 50 → 100:** `ACU_Admin::ajax_bulk_link()` now processes 100 users per
  AJAX batch instead of 50, halving the number of requests needed for a full sync.

### v1.1.2 — 2026-02-20

- **Call consent row in search results:** `render_result_html()` now displays a
  "თანხმობა სატელეფონო ზარზე" row with a `wcu-badge` (ვეთანხმები / არ ვეთანხმები /
  არ არის არჩეული) immediately below the SMS consent row.
- **Consent rows in print-anketa:** `templates/print-anketa.php` now reads `_sms_consent`
  and `_call_consent` user meta and renders them as labeled rows (showing "დიახ" / "არა")
  just above the signature line, but only when the value is set.
- **Button spacing in print-anketa:** `<div class="print-actions">` now has
  `style="display:flex;gap:0.75rem;flex-wrap:wrap;"` so the three print buttons are
  evenly spaced and wrap cleanly on narrow screens.

### v1.1.1 — 2026-02-20

- **Coupon-based search in `[user_data_check]`:** Two new search steps added to
  `ACU_Shortcodes::ajax_udc_search()`:
  - **Step 5 (phone → coupon):** When a phone-like query finds no registered WP user,
    the handler now searches `_erp_sync_allowed_phones` across all `shop_coupon` posts
    via `ACU_Helpers::find_coupon_by_phone()`. If the phone is in a coupon but the user
    is unregistered, a "Found via Coupon: CODE" card is shown.
  - **Step 6 (code → coupon):** When the query string matches a coupon `post_title`
    (case-insensitive), phones are extracted from `_erp_sync_allowed_phones`, normalized,
    and resolved to a WP user. If no user exists, the same "Unregistered User" card is shown.
- **`ACU_Helpers::find_coupon_by_phone()` bug fix:** The confirmation loop previously
  called `normalize_phone()` on the entire comma-separated meta value (e.g.
  `"599111222,599333444"`), which never produced a valid 9-digit match. Fixed to split
  by comma and normalize each entry individually.
- **Registration bridge for unregistered coupon users:** The "Unregistered User" result
  card includes a **"Register (Anketa)"** button (cap-gated `edit_users`) that links to
  the `[club_anketa_form]` page with `?prefill_phone=XXXXXXXXX`. The Anketa form now
  reads this GET param and pre-fills the phone field, with POST values taking priority
  on re-render after a validation error.
- **New private helpers in `ACU_Shortcodes`:**
  - `find_coupon_data_by_code( string $code ): array|null`
  - `render_coupon_result_html( string $phone, string $coupon_code ): string`

### v1.1.0 — 2026-02-20

- **OTP message changed to English:** Replaced the Georgian OTP string
  `'თქვენი ვერიფიკაციის კოდია: %s'` with the plain ASCII `'SMS Code: XXXXXX'`
  in `ACU_OTP::send_otp()`. Eliminates all multi-byte encoding risk at the
  gateway level; message is now unambiguously readable on any handset.

### v1.0.9 — 2026-02-20

- **Fix: Georgian SMS text encoding (question marks):** `add_query_arg()` uses RFC 1738
  `urlencode()` (spaces → `+`) which the MS Group gateway cannot decode as UTF-8, producing
  `??????` on the handset. Removed `text` from the `add_query_arg()` array and appended it
  manually: `$api_url .= '&text=' . rawurlencode($message)`. RFC 3986 `rawurlencode()`
  encodes spaces as `%20` and Georgian multi-byte sequences as `%E1%83...`, which the
  gateway handles correctly.

### v1.0.8 — 2026-02-20

- **Fix: SMS gateway success detection:** The MS Group gateway returns `code: 0` (JSON
  integer) on success, not the string `"0000"`. Updated `ACU_SMS::send()` to treat both
  `'0'` and `'0000'` as success — `(string)$data['code'] === '0' || === '0000'`. OTP
  codes are now delivered correctly end-to-end.

### v1.0.7 — 2026-02-20

- **Fix: SMS OTP not being sent (critical):** Removed `rawurlencode()` from the `text` parameter
  in `ACU_SMS::send()`. `add_query_arg()` URL-encodes values itself; pre-encoding caused
  double-encoding (e.g. `%20` → `%2520`), corrupting the message text and causing the MS Group
  gateway to reject or silently drop the request. Georgian OTP text is now transmitted correctly.
- **Error logging:** Added `error_log()` calls in `ACU_SMS::send()` to capture HTTP errors,
  full gateway response body (with HTTP status code), and gateway error codes for easier
  server-side debugging.
- **OTP module:** Fixed stale `acm_` prefix in `class-acu-otp.php` docblock (should be `acu_`).
  Verified rate-limiter logic — `check_rate_limit()` correctly allows requests when under the
  3-attempt threshold and does not prematurely block.
- **AJAX endpoints:** Verified `acu_send_otp` / `acu_verify_otp` are registered for both
  logged-in (`wp_ajax_`) and anonymous (`wp_ajax_nopriv_`) users, and that nonce action
  `acu_sms_nonce` matches the localized value from `enqueue_scripts()`.

### v1.0.6 — 2026-02-20

- **Clean print output:** `templates/print-anketa.php` now suppresses any row whose value is empty or a `@no-email.local` dummy address, producing a clean printed document.
- **Edit Anketa feature:** `[club_anketa_form]` supports edit mode via `?edit_user=USER_ID`. Pre-fills all fields from existing user data; saves via `wp_update_user()` + `update_user_meta()`. Requires `edit_users` capability.
- **Edit button in search results:** `[user_data_check]` result card shows an "Edit Anketa" button (only for users with `edit_users` cap) linking to `[club_anketa_form]?edit_user=ID`. The anketa page is auto-discovered by searching published pages for the shortcode.
- **Button label spacing (Task 3):** Confirmed "Print SMS Terms" and "Print Phone Call Terms" labels already have correct spacing in `print-anketa.php`. No regression introduced by new code.

### v1.0.5 — 2026-02-20

- **Standardized phone storage:** `billing_phone` is now always written as a strict 9-digit string.
  `ACU_Helpers::normalize_phone()` is applied in `ACU_Registration::maybe_process_submission()`,
  `ACU_Account::created_customer()`, and `ACU_Account::save_account_details()` before any
  `update_user_meta()` call. The old `+995 XXXXXXXXX` format is no longer written to the DB.
- **Format-agnostic phone search:** `[user_data_check]` AJAX handler uses raw `$wpdb` queries
  with SQL `REPLACE()` to strip spaces, dashes, and `+995` from `meta_value` at query time,
  so legacy rows stored in any format are found correctly.
- **External phone table search** updated with the same `REPLACE()` logic.
- **CI fix:** corrected `release.yml` sed regex for `ACU_VERSION` constant (trailing space before
  `);` was preventing the constant from being auto-bumped in previous workflow runs).

### v1.0.3 — 2026-02-20

- **Migrated to Plugin Update Checker v5:** Replaced hand-rolled `ACU_GitHub_Updater`
  with the industry-standard PUC library. Updates now use the compiled release asset
  ZIP (vendor/ included) instead of the raw GitHub source zipball.
- **GitHub Actions CI/CD:** Added `.github/workflows/release.yml` — merging a PR to
  main automatically builds `arttime-club-member.zip` and publishes the GitHub Release.

### v1.0.2 — 2026-02-20

- **CSV export consent filter:** Only users with SMS consent OR call consent set to 'yes' are included.
- **CSV export phone normalization:** Phone column outputs strict 9-digit format via `ACU_Helpers::normalize_phone()`.
- **Static rules in print anketa:** `templates/print-anketa.php` now always outputs the exact hardcoded rules text matching the registration form.
- **CLAUDE.md:** Documented mandatory `gh release create` step in the Release & Update Workflow.

### v1.0.1 — 2026-02-20

- **Optional email in Anketa form:** Email field is no longer required. Label updated to indicate it is optional (needed only for site registration). A dummy `@no-email.local` address is used when no email is provided so `wp_insert_user()` always receives a valid address. The dummy address is hidden on the print-anketa page.
- **Moved signature line:** The "User Signature" row has been removed from `signature-terms.php` and moved to the very bottom of `print-anketa.php` (after the Shop row), so it appears at the end of the printed form.
- **Conditional admin email notifications:** `maybe_send_consent_notification()` now fires only when SMS consent is `'yes'`; a `'no'` selection no longer triggers an admin email.

> Test: Verifying GitHub Actions auto-updater integration.
