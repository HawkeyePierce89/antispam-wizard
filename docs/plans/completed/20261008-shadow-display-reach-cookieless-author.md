# Shadow display: reach the author who did not consent to cookies

## Overview

When the plugin flags a comment, its author is meant to see it as "awaiting moderation". Today that
only works if the author has a commenter cookie. An author who leaves the "Save my name, email…" box
unticked gets no cookie, so after posting they see nothing.

This plan adds a `comment_post_redirect` callback to `ASW_Shadow_Display`. For a plugin-flagged
`spam` comment it appends the same `unapproved=<ID>&moderation-hash=<wp_hash(comment_date_gmt)>`
pair that core appends for a held (`'0'`) comment. Core's `wp_get_unapproved_comment_author_email()`
then resolves the author's email on the landing page and puts it into `include_unapproved`. From
there the existing widening and `'0'` presentation take over.

Correction to the ticket. The ticket expects no change to `eligible_identity()`, but one is needed.
`eligible_identity()` checks the `include_unapproved` email against `wp_get_current_commenter()`,
which reads only the cookie. A request that carries only the pair would therefore still be rejected.
The landing side has to resolve the identity through `wp_get_unapproved_comment_author_email()`, the
same function core used to fill `include_unapproved`. That function tries the hash pair first and
falls back to the cookie, so the cookie path keeps working. The whole-chain test required by the
ticket would have caught this.

Confirmed against core in the `wordpress:6.4-php8.1-apache` and `wordpress:7.1-php8.3-apache` images:
- `wp-comments-post.php` adds the pair only for `'unapproved'` and runs `comment_post_redirect` after
  that.
- `wp_get_unapproved_comment_author_email()` does not check the comment's status. It only checks the
  hash and the ten-minute window.
- In 7.1, `comments_template()`, the block query vars and `get_page_of_comment()` all call it.

One more detail. When there is no cookie, core's moderation note reads "Your comment is awaiting
moderation. This is a preview; …". Assertions must match the shared prefix
`Your comment is awaiting moderation.`.

## Context

- Files involved:
  - `antispam-wizard/includes/class-shadow-display.php`
  - `tests/bootstrap.php`, `tests/BootstrapStubsTest.php`, `tests/ShadowDisplayTest.php`
  - `tests/smoke/shadow-check.php`, `tests/PluginMetadataTest.php`
  - `site/verify.sh`, `tests/SiteThemeTest.php`
  - `docs/shadow-display.md`, `docs/test-stubs.md`, `docs/site.md`, `CLAUDE.md`,
    `antispam-wizard/readme.txt`
- Related patterns:
  - The class's fail-open callbacks: `try/catch(\Throwable)` that returns the input and logs
    `'ASW: shadow display failed at ' . basename:line`.
  - `test_every_catch_block_logs_only_the_throw_site` (callback list, catch count equals callback
    count).
  - The `WP_Comment_Query` stub and the `run_query()` / `front_end_args()` / `as_cookie_author()`
    helpers in `ShadowDisplayTest`.
  - The `phpcs:ignore <exact sniff> -- reason` convention.
- Dependencies: none new. No new option, meta, transient or translatable string.

## Development Approach

- **Testing approach**: Regular (code first, then tests in the same task)
- Complete each task fully before moving to the next
- Keep the change inside `ASW_Shadow_Display` and the test and doc files it already owns. The
  detector and the moderation class are not touched.
- The redirect path and the query path share one eligibility predicate, so they cannot disagree
  about which comments are shadowed.
- **CRITICAL: every task MUST include new/updated tests**
- **CRITICAL: all tests must pass before starting next task**

## Implementation Steps

### Task 1: Stub layer for the landing request

**Files:**
- Modify: `tests/bootstrap.php`
- Modify: `tests/BootstrapStubsTest.php`
- Modify: `docs/test-stubs.md`

- [x] Add a `wp_hash( $data, $scheme = 'auth' )` stub: `hash_hmac( 'md5', $data, wp_salt( $scheme ) )`,
  as core does.
- [x] Add a `wp_get_unapproved_comment_author_email()` stub that follows core line for line:
  - read `$_GET['unapproved']` and `$_GET['moderation-hash']`, cast the ID to int and call
    `get_comment()`;
  - compare `hash_equals( $_GET['moderation-hash'], wp_hash( comment_date_gmt ) )`;
  - accept only while `time() < strtotime( comment_date_gmt . '+10 minutes' )`;
  - otherwise fall back to `wp_get_current_commenter()['comment_author_email']`.
  - Tests control the window through `comment_date_gmt` relative to `time()`, so no new switch is
    needed.
- [x] Make the `add_query_arg()` stub follow core for a URL that already has a query string (join
  with `&`, replace an existing key) and for a `#fragment` (keep it at the end). Output for a bare
  URL must not change, so existing callers' expectations stay the same.
- [x] Pin each new behaviour in `BootstrapStubsTest`:
  - a valid pair resolves the email;
  - a wrong hash, an expired window, an unknown ID and a missing argument each fall back to the
    cookie email;
  - `add_query_arg()` keeps the fragment and the existing query.
- [x] Catalogue `wp_hash()`, `wp_get_unapproved_comment_author_email()` and the `add_query_arg()`
  change in `docs/test-stubs.md`, in the comment-query layer section.
- [x] Run `docker compose run --rm test`. It must pass before Task 2.

### Task 2: Landing side and shared eligibility

**Files:**
- Modify: `antispam-wizard/includes/class-shadow-display.php`
- Modify: `tests/ShadowDisplayTest.php`

- [x] Extract two private static helpers and use them in place of the inline code:
  - `shadow_cutoff(): ?int` returns the cutoff only when the master `enabled` switch and
    `shadow_display_enabled` are both on and `CUTOFF_OPTION` is numeric; otherwise null.
  - `is_plugin_flagged( object $comment, int $after ): bool` is the PHP mirror of the S fragment:
    `comment_type` is `''` or `'comment'`, the ID is above `$after`, and `_asw_reason` is present.
  - `eligible_identity()` uses `shadow_cutoff()`. `is_viewers_flagged_comment()` keeps its post and
    identity match and calls `is_plugin_flagged()`.
  - The S fragment's docblock names `is_plugin_flagged()` as its mirror.
- [x] In `eligible_identity()`, replace the `wp_get_current_commenter()` read with
  `wp_get_unapproved_comment_author_email()`. This is the function core used to fill
  `include_unapproved`: pair first, then cookie. Keep the `is_email()` check, the verbatim
  `in_array()` check and the existing order, so the "anonymous returns before any option read" test
  still holds.
- [x] `ShadowDisplayTest::setUp()` / `tearDown()` reset `$_GET` and `$_POST`.
- [x] Add tests:
  - a request carrying only a valid pair, with no cookie and `include_unapproved = [ email ]`, gets
    the widened clause and the shadow row presented as `'0'`;
  - a wrong hash, an expired window (`comment_date_gmt` 11 minutes ago) and a pair naming another
    author's comment each leave the WHERE untouched;
  - a pair plus a different cookie email still resolves the pair's email, as core does.
- [x] Existing cookie, user, cutoff and threaded tests still pass unchanged, and so does
  `test_a_throw_inside_the_hooks_leaves_the_core_result_and_logs_only_the_site` (the stub falls back
  to the throwing `wp_get_current_commenter()`).
- [x] Run `docker compose run --rm test`. It must pass before Task 3.

### Task 3: The comment_post_redirect callback

**Files:**
- Modify: `antispam-wizard/includes/class-shadow-display.php`
- Modify: `tests/ShadowDisplayTest.php`

- [x] Add `public function on_comment_post_redirect( $location, $comment = null )` and register it in
  `register_hooks()` as `add_filter( 'comment_post_redirect', …, 100, 2 )`. The late priority means
  filters that replace the target run first.
- [x] The callback returns `$location` unchanged unless all of these hold:
  - `$location` is a non-empty string and `$comment` is an object;
  - `comment_approved === 'spam'`;
  - `! isset( $_POST['wp-comment-cookies-consent'] )`, read as core reads it. If Plugin Check flags
    it, add a `phpcs:ignore` naming the exact NonceVerification sniff with a one-line reason: core's
    own comment form carries no nonce, and only the presence of the key is read;
  - `get_current_user_id() === 0` and the comment's `user_id` is 0;
  - `comment_author_email` is non-empty and passes `is_email()`;
  - `shadow_cutoff()` is not null;
  - `is_plugin_flagged( $comment, $cutoff )` is true;
  - the location's query has neither `unapproved` nor `moderation-hash` (parsed with
    `wp_parse_url()` + `wp_parse_str()`). If either key is present, nothing is added, so the pair is
    never duplicated or mismatched.
- [x] When eligible, return `add_query_arg( array( 'unapproved' => comment_ID, 'moderation-hash' =>
  wp_hash( comment_date_gmt ) ), $location )`. The cookie logic, the row and the status are never
  touched.
- [x] Wrap the body in `try/catch(\Throwable)` that returns `$location` and logs the fixed prefix
  with `basename:line` only.
- [x] Extend `test_register_hooks_adds_the_query_hooks_and_the_transition_hook` with the new filter,
  priority and accepted args.
- [x] Add `on_comment_post_redirect` to the callback list in
  `test_every_catch_block_logs_only_the_throw_site`. Catch count must still equal callback count.
- [x] Add a positive test: an eligible flagged comment without consent gets both arguments, and the
  hash equals `wp_hash( comment_date_gmt )`.
- [x] Add one test per negative case:
  - status `'0'`; status `1`; status `trash`;
  - spam without `_asw_reason`; at or below the cutoff; cutoff unset;
  - no author email;
  - logged-in current user; comment `user_id > 0`;
  - consent given;
  - pingback type;
  - setting off; master switch off.
- [x] Add preservation tests: an existing query argument and a `#comment-<ID>` fragment survive
  (fragment last); a location that already has `unapproved` or `moderation-hash` comes back
  byte-identical.
- [x] Add a throw test: a comment whose every read throws (`ASW_Throwing_Shadow_Input`) returns the
  original location and logs only the throw site.
- [x] Add a whole-chain test:
  1. Run the filter on `http://example.com/post/#comment-<ID>` for an eligible comment.
  2. Parse the resulting query into `$_GET` with no cookie.
  3. Build `include_unapproved` from `wp_get_unapproved_comment_author_email()` the way
     `comments_template()` does.
  4. Run the front-end query and assert the WHERE is widened, the flagged row is returned, and
     `get_comment()` presents it as `'0'` while the stored row stays `spam`.
- [x] Run `docker compose run --rm test`. It must pass before Task 4.

### Task 4: Smoke check, cookie-less pass

**Files:**
- Modify: `tests/smoke/shadow-check.php`
- Modify: `tests/PluginMetadataTest.php`

- [x] After the anonymous pass, add a cookie-less author pass with no commenter cookies:
  - move the flagged comment's `comment_date_gmt` / `comment_date` to now (`$wpdb->update` +
    `clean_comment_cache()`);
  - set `$_GET['unapproved']` and `$_GET['moderation-hash'] = wp_hash( comment_date_gmt )`;
  - render through `comments_template()` and `do_blocks()`;
  - assert the flagged comment and the flagged reply render with two awaiting-moderation notes, the
    moderator and other-email spam stay absent, and the approved comment renders first
    (anti-vacuity).
- [x] Then two negative renders, both on both paths, each rendering no spam text:
  - an invalid hash;
  - an expired window: date moved 11 minutes back, hash recomputed for that date so only the window
    rejects it.
- [x] Unset `$_GET` afterwards and keep the existing anonymous pass and storage assertions. Update
  the file header comment.
- [x] Extend `PluginMetadataTest` (next to `test_shadow_smoke_check_cannot_pass_vacuously`) to pin
  statically that the smoke check sets `moderation-hash` via `wp_hash(`, renders a cookie-less pass,
  and carries the invalid-hash and expired-window cases.
- [x] Run `docker compose run --rm test`. It must pass before Task 5.

### Task 5: Live assertion in site/verify.sh

**Files:**
- Modify: `site/verify.sh`
- Modify: `tests/SiteThemeTest.php`

- [x] After the existing shadow case, post a second flagged comment through `wp-comments-post.php`
  without `wp-comment-cookies-consent`. It uses its own token, its own email and no cookie jar, and
  dumps the headers with `-D`.
- [x] Add these assertions:
  1. 302;
  2. the `Location` header carries `unapproved=<ID>` and `moderation-hash=`;
  3. the hash equals `wpcli eval` of `wp_hash()` on the stored `comment_date_gmt`;
  4. a cookie-less GET of that Location shows the token;
  5. that GET shows the awaiting-moderation note (prefix match);
  6. a plain GET of the post does not show the token.
- [x] Extend `cleanup_shadow()` so the trap deletes both emails' comments.
- [x] Extend `SiteThemeTest::test_verify_carries_the_shadow_display_case_and_its_cleanup`:
  - the no-consent POST carries no `wp-comment-cookies-consent`;
  - the `Location` / `moderation-hash` / `wp_hash` checks are present;
  - the cleanup covers the second email;
  - the trap is armed before the post.
- [x] Run `docker compose run --rm test`. It must pass before Task 6.

### Task 6: Documentation

**Files:**
- Modify: `docs/shadow-display.md`, `docs/site.md`, `CLAUDE.md`, `antispam-wizard/readme.txt`

- [x] `docs/shadow-display.md`:
  - replace the "Cookie consent" known boundary with the new behaviour (redirect pair for
    plugin-flagged spam; identity resolved through `wp_get_unapproved_comment_author_email()`, pair
    first, then cookie);
  - add the ten-minute boundary: after it a cookie-less author sees nothing, as with a core held
    comment, and anyone holding the link during the window sees what core shows for a held comment;
  - add `comment_post_redirect` to the hook list and the new tests to the test map;
  - update the live-checks section (smoke cookie-less pass; verify count).
- [x] `docs/site.md` and `CLAUDE.md`: update the shadow live-assertion count from seven to the new
  total (seven plus the six new lines = thirteen, adjusted to the final count). Final count: fifteen
  (the no-consent case also asserts "stored as spam" and "_asw_reason recorded").
- [x] `CLAUDE.md`: one-sentence update to the `ASW_Shadow_Display` bullet. The identity becomes "the
  `is_email()` email `wp_get_unapproved_comment_author_email()` resolves (moderation-hash pair, else
  commenter cookie)", and `comment_post_redirect` adds that pair for a plugin-flagged spam comment
  posted without cookie consent.
- [x] `antispam-wizard/readme.txt`:
  - rewrite the FAQ sentence about how the author is recognised: by their login, by the comment
    cookie, or, for ten minutes after posting, by the link WordPress sends them back to even if they
    did not tick the box;
  - add a changelog bullet under 1.0.0;
  - no new translatable strings, so the POT and `.po` files are untouched.
- [x] Check that `site/content/` states nothing that contradicts the new readme sentence (it does not
  mention shadow display or author recognition at all).
- [x] Run `docker compose run --rm test`, including `I18nTest`, `SiteThemeTest` and
  `PluginMetadataTest`. It must pass.

### Task 7: Verify acceptance criteria

- [x] Run the full suite: `docker compose run --rm test`
- [x] Confirm `CLAUDE.md` stays under 150 000 characters
- [x] Confirm every new public method and both new stubs are covered by a named test listed in
  `docs/shadow-display.md` / `docs/test-stubs.md`

## Post-Completion

These are run by hand and are not ralphex task items:
- `bash dev/smoke.sh` and `WORDPRESS_IMAGE=6.4-php8.1-apache bash dev/smoke.sh`: SHADOW, including
  the cookie-less pass, must be green.
- `bash dev/plugin-check.sh`: no ERROR rows. If the `$_POST` consent read is flagged, apply the
  exact-sniff `phpcs:ignore`.
- `bash site/build.sh && bash site/verify.sh`: all shadow lines green. Then `bash site/build.sh`
  again before any export.
- After deploying to antispamwizard.com: post a comment that gets flagged with the consent box
  unticked, and confirm it renders as awaiting moderation on the landing page.
