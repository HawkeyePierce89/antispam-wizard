# Shadow display: show a flagged comment to its own author as "awaiting moderation"

## Overview

A comment the plugin flags is stored as `spam`, and today WordPress shows it to nobody. A real
person hit by a false positive sees nothing, so they post again. This change makes the comment's
own author (and nobody else) see it the way core shows a held comment: in its place in the thread,
with core's "Your comment is awaiting moderation." notice. Everyone else, plus feeds, REST, admin
screens and counts, still sees nothing.

How it works:

- A new class `ASW_Shadow_Display` hooks the `WP_Comment_Query` layer, so it covers both the
  block-theme path (`core/comment-template`) and the classic path (`comments_template()` →
  `wp_list_comments()`).
- For a front-end listing that already carries core's own `include_unapproved` identifier for the
  current visitor, it widens the query's existing "approved" condition with one extra OR branch.
  The branch matches this visitor's `spam` comments that carry `_asw_reason`, are of type `''` or
  `comment`, and are on this post.
- It adds a key to the query's `query_var_defaults`, so the `WP_Comment_Query` result-cache key
  changes whenever the widened condition is present.
- It records which returned rows are shadow rows and presents them to rendering as a per-request
  clone with `comment_approved = '0'`.
- The stored row and the comment object cache keep `spam`.
- `ASW_Moderation::append_moderation_link()` decides on the real status through
  `ASW_Shadow_Display::is_shadowed()`.
- A General-tab setting `shadow_display_enabled` (default true) gates it.

## Context

Files involved:

- New: `antispam-wizard/includes/class-shadow-display.php`, `tests/ShadowDisplayTest.php`,
  `tests/smoke/shadow-check.php`, `docs/shadow-display.md`
- Modified:
  - Plugin code: `antispam-wizard/antispam-wizard.php` (require),
    `antispam-wizard/includes/class-antispam-wizard.php` (wiring),
    `antispam-wizard/includes/class-settings.php`, `antispam-wizard/admin/class-admin-page.php`
    (`$bool_keys`), `antispam-wizard/admin/views/settings-general.php`,
    `antispam-wizard/includes/class-moderation.php` (`append_moderation_link()`)
  - Translations and readme: `antispam-wizard/languages/*` (POT, 9 `.po`, 9 `.mo`),
    `antispam-wizard/readme.txt`
  - Test support: `tests/bootstrap.php`, `tests/BootstrapStubsTest.php`,
    `tests/ModerationLinkTest.php`, `tests/SettingsTest.php`, `tests/AdminPageTest.php`,
    `tests/ASWTest.php`
  - Live harnesses: `dev/smoke.sh`, `site/verify.sh`
  - Docs: `docs/test-stubs.md`, `docs/moderation.md`, `docs/site.md`, `CLAUDE.md`

Related patterns:

- Fail-open catch logging only `basename( $e->getFile() ) . ':' . $e->getLine()`, as in
  `ASW_Moderation::on_transition()`. Each class's catch blocks are pinned in that class's own test
  on the source text, by enumerating every use of `$e` and allowing only `getFile()`/`getLine()`
  (`CF7IntegrationTest`, `ModerationTest`).
- The REST case is tested in a child process, as in `ModerationLinkTest::render_in_child_process()`.
- Boolean settings: the `$render_toggle` card plus `$bool_keys`.
- `wp eval-file` scripts carry no `declare( strict_types=1 )`.
- The fresh-per-run identity approach of `site/checks/detect.php`.

Core facts this design relies on (WordPress 6.4 to 7.1):

- `comments_template()` and `build_comment_query_vars_from_block()` both query with
  `status => 'approve'`, `post_id`, and `include_unapproved = [ current user ID ]` for a logged-in
  visitor or `[ commenter email ]` otherwise.
- `WP_Comment_Query::get_comments()` order of operations:
  1. Runs `pre_get_comments`.
  2. Builds the result-cache key from the query vars, limited to the keys of `query_var_defaults`.
  3. Builds `sql_clauses['where']['approved']`.
  4. Passes `&$this` to `comments_clauses`.
  5. Runs `the_comments`.
  6. Converts every row through `get_comment()`.
- `fill_descendants()` runs a nested `get_comments()` with the same query vars plus `parent__in`.
  It then re-fetches every comment by ID through `get_comment()`.
- The block theme's `core/comment-content` also re-fetches by ID with
  `get_comment( $block->context['commentId'] )`, then tests `'0' === $comment->comment_approved`
  before printing the moderation note.
- `render_block()` seeds the `postId`/`postType` block context from the global `$post`, so
  `core/comments` + `core/comment-template` rendered outside the main loop resolve the post only if
  `$GLOBALS['post']` is set.

Dependencies: none new.

## Design decisions

- **The `get_comment` filter is the only way to present `'0'`.** Both the block renderer and
  threaded `fill_descendants()` re-fetch comments by ID, so changing the objects in the query
  result is not enough. A `get_comment` filter is the only hook that reaches both paths. It is
  added lazily, only once this request's shadow query actually returned a shadow row. It returns a
  clone with `'0'` only for those IDs.
  - It does not write to the row, the comment object cache (`wp_cache_get( $id, 'comment' )`
    keeps `spam`) or `$wpdb`.
  - Within that one front-end request, a `get_comment( $id )` for that ID returns the presented
    copy. This is the per-request copy the ticket asks for.
  - The real status stays recoverable through `ASW_Shadow_Display::is_shadowed( $id )`.
- **Identity mirrors core's own `include_unapproved` entry.**
  - For a logged-in visitor, core puts the user ID there. The shadow branch then matches
    `user_id = %d`. In core, that ID is how a logged-in user's email resolves to their comments.
  - For a logged-out visitor, the branch matches `comment_author_email = %s`. The email must come
    from `wp_get_current_commenter()`, pass `is_email()`, and appear verbatim in the query's
    `include_unapproved`.
  - An empty or invalid email, or a mismatch, leaves the query untouched.
- **Widening is done in SQL.**
  - In `comments_clauses`, the hook reads `$query->sql_clauses['where']['approved']` (call it A).
    If A occurs exactly once in `$clauses['where']`, the hook replaces it with `( A OR S )` and
    also writes that back to `sql_clauses['where']['approved']`. Otherwise it changes nothing and
    fails safe.
  - S is built with `$wpdb->prepare()`, with `%i` for table names, and reads:
    `( <identity> AND comment_approved = 'spam' AND comment_type IN ( '', 'comment' ) AND EXISTS
    ( SELECT 1 FROM commentmeta WHERE comment_id = comments.comment_ID AND meta_key = '_asw_reason' ) )`.
  - This adds no extra query, and there is no per-comment meta lookup in PHP.
- **Cache key.**
  - On eligibility, `pre_get_comments` sets `query_var_defaults['asw_shadow'] = ''` and
    `query_vars['asw_shadow'] = 'email:<addr>'` or `'user:<id>'`.
  - A `WeakMap` keyed by the query object records which queries were approved. `comments_clauses`
    and `the_comments` trust only that map, never an incoming `asw_shadow` arg, which is
    overwritten or unset on every run.
- **Inert unless all of the following hold:**
  - both settings are on: the master `enabled` and `shadow_display_enabled`;
  - not `is_admin()`, `wp_doing_ajax()`, `wp_doing_cron()`, `REST_REQUEST` or `is_feed()`;
  - the query has no `count`, `fields` is not `'ids'`, `status` is `'approve'`, `post_id` is
    above 0, and `include_unapproved` contains the current visitor's identifier.
  - Cheap query-var checks run first. An anonymous visitor's query has an empty
    `include_unapproved` and returns before any option read.
- **Counts are untouched.** This covers the `count => true` top-level query used for "newest
  first" pagination offsets. It is a documented boundary: in that edge case a shadow comment can
  shift the computed last page by one, exactly as an extra held comment would not.

## Development Approach

- **Testing approach**: TDD. Write the tests for each task first, see them fail, then implement.
- Complete each task fully before moving to the next.
- `docker compose run --rm test` must stay green after each task.
- Smoke, Plugin Check and `site/verify.sh` runs are Docker-heavy end-to-end runs. The tasks write
  their assertions, but running them is listed under Post-Completion.
- **CRITICAL: every task MUST include new/updated tests**
- **CRITICAL: all tests must pass before starting next task**

## Implementation Steps

### Task 1: Test stubs for the comment-query layer

**Files:**
- Modify: `tests/bootstrap.php`
- Modify: `tests/BootstrapStubsTest.php`
- Modify: `docs/test-stubs.md`

- [x] Add `apply_filters_ref_array()` and `do_action_ref_array()` on the existing `$wp_filters` /
  `$wp_actions` registries.
- [x] Add `wp_get_current_commenter()`, driven by `$GLOBALS['wp_current_commenter']`
  (author/email/url, default empty). Setting `$GLOBALS['wp_current_commenter_throws']` makes it
  throw, which is the seam for the fail-open tests.
- [x] Add `is_user_logged_in()` and `get_current_user_id()` on `$GLOBALS['wp_current_user_id']`,
  and `wp_doing_ajax()` and `wp_doing_cron()` on globals. Reuse any of these that already exist.
- [x] Teach the `get_comment()` stub to accept an object as well as an ID, as core does, and to
  run the result through `apply_filters( 'get_comment', … )`. It must still return a fresh object
  per call, and still never write `$wp_comment_cache`.
- [x] Add a `WP_Comment_Query` stub class modelled on core's order of operations:
  - `query_var_defaults` covers `post_id`, `status`, `include_unapproved`, `count`, `fields`,
    `parent`, `parent__in`, `hierarchical`, `number`, `offset`, `type`.
  - `parse_query()`, then `pre_get_comments` (ref array).
  - The cache key is md5 of the query vars limited to `query_var_defaults`, minus `fields`. It is
    looked up in `$GLOBALS['wp_comment_query_cache']`, and every key used is appended to
    `$GLOBALS['wp_comment_query_cache_keys']`.
  - `sql_clauses['where']['approved']` is built through `$wpdb->prepare()` with core's exact
    `( comment_author_email = %s AND comment_approved = '0' )` and
    `( user_id = %d AND comment_approved = '0' )` fragments.
  - `comments_clauses` (ref array, receives `&$this`), then the final WHERE is recorded in
    `$GLOBALS['wp_comment_query_log']`.
  - Rows are selected from `$wp_comments` by a matcher. The matcher evaluates the post-filter
    approved clause with a balanced-parenthesis OR-splitter. It knows only core's shapes plus the
    plugin's shadow shape (identity, spam, type, `_asw_reason` present in `$wp_comment_meta`). It
    throws `LogicException` on an unknown fragment and when the final WHERE does not contain the
    post-filter approved clause.
  - `the_comments` (ref array), then `array_map( 'get_comment', … )`.
  - `count` returns the count and `fields => 'ids'` returns IDs.
  - `hierarchical => 'threaded'` runs a nested `WP_Comment_Query` with `parent__in` and re-fetches
    every comment by ID through `get_comment()`, as core's `fill_descendants()` does. The result
    is returned flat.
- [x] Reset all new globals per test, the same way the existing switches are reset.
- [x] `BootstrapStubsTest`: pin each new stub — hook order; the cache key changes when a
  `query_var_defaults` key is added; the matcher throws on an unknown fragment and on a
  WHERE/approved mismatch; threaded re-fetch goes through `get_comment()`; `get_comment()` applies
  its filter and passes objects through.
- [x] Document every new stub and switch, and the test it exists for, in `docs/test-stubs.md`.
- [x] Run `docker compose run --rm test`; it must pass.

### Task 2: `shadow_display_enabled` setting on the General tab

**Files:**
- Modify: `antispam-wizard/includes/class-settings.php`
- Modify: `antispam-wizard/admin/class-admin-page.php`
- Modify: `antispam-wizard/admin/views/settings-general.php`
- Modify: `tests/SettingsTest.php`, `tests/AdminPageTest.php`

- [x] Write the tests first: the default is `true`; the General tab renders the toggle checked by
  default; a save without the field stores `false`; a save with it stores `true`.
- [x] Add `'shadow_display_enabled' => true` to `ASW_Settings::get_defaults()`.
- [x] Add the key to `$bool_keys` in `handle_save()`.
- [x] Add one toggle card inside an existing General-tab section, using the existing
  `$render_toggle` helper. No new section title, so the only new msgids are the label ("Show
  flagged comments to their author") and its description.
- [x] Run `docker compose run --rm test`; it must pass. `I18nTest` is expected to fail until
  Task 3.

### Task 3: i18n for the two new strings

**Files:**
- Modify: `antispam-wizard/languages/antispam-wizard.pot`
- Modify: `antispam-wizard/languages/*.po` (9 files), `antispam-wizard/languages/*.mo` (9 files)

- [x] Regenerate the POT from the source; its msgid set must equal the extracted set in both
  directions.
- [x] Run `msgmerge` on all nine `.po` files. Translate both new entries in each language, leaving
  zero untranslated, zero `#, fuzzy` and zero obsolete `#~` entries.
- [x] Recompile all nine `.mo` files with `msgfmt`.
- [x] Run `docker compose run --rm test`; it must pass, including `I18nTest`.

### Task 4: `ASW_Shadow_Display`: eligibility, cache key, SQL widening

**Files:**
- Create: `antispam-wizard/includes/class-shadow-display.php`
- Modify: `antispam-wizard/antispam-wizard.php` (require the file)
- Create: `tests/ShadowDisplayTest.php`

- [x] Write the tests first, all driven through the `WP_Comment_Query` stub:
  - A logged-out author whose cookie email is in `include_unapproved` gets the widened approved
    clause. It contains the escaped email, `comment_approved = 'spam'`, the `comment_type`
    restriction and the `_asw_reason` EXISTS, and keeps core's original clause intact.
  - A logged-in author gets the `user_id = %d` variant.
  - These cases leave the WHERE byte-identical and issue no extra `$wpdb` call:
    - an anonymous visitor (empty `include_unapproved`);
    - a different visitor (the commenter email is not in `include_unapproved`);
    - an empty commenter email, and an invalid one;
    - `is_feed()`, `is_admin()`, `wp_doing_ajax()`, `wp_doing_cron()`;
    - `count => true`, `fields => 'ids'`, `status` other than `'approve'`, no `post_id`;
    - `shadow_display_enabled` off, and master `enabled` off.
  - Under `REST_REQUEST` (child process) the WHERE is unchanged.
  - The cache key differs between the author's widened query and the same query with the setting
    off, and between two visitors.
  - An incoming `asw_shadow` arg passed by a caller is overwritten or unset, never trusted.
  - An approved clause that is absent, or occurs twice, leaves the WHERE untouched.
- [x] Implement `register_hooks()` with `pre_get_comments` (1 arg) and `comments_clauses`
  (2 args).
- [x] Implement the eligibility check, with the cheapest query-var checks first.
- [x] Implement the `WeakMap` of approved queries and the `query_var_defaults` / `query_vars`
  cache-key marker.
- [x] Implement the prepared shadow fragment, using `%i` for `commentmeta` and `comments`. Every
  `phpcs:ignore` carries an exact sniff code and a reason.
- [x] Implement the exactly-once replacement in `$clauses['where']`, with the write-back to
  `sql_clauses['where']['approved']`.
- [x] Run `docker compose run --rm test`; it must pass.

### Task 5: Presentation as status `'0'`, real-status registry, fail-open

**Files:**
- Modify: `antispam-wizard/includes/class-shadow-display.php`
- Modify: `tests/ShadowDisplayTest.php`

- [x] Write the tests first:
  - The author's flat query returns their auto-flagged spam comment with
    `comment_approved === '0'`, in thread order among approved comments.
  - `get_comment( $id )` afterwards also yields `'0'`. This is the block path.
  - A threaded query yields `'0'` on a shadow reply re-fetched by the descendant pass.
  - A moderator-marked spam comment (no `_asw_reason`) by the same author is not returned.
  - A spam pingback carrying `_asw_reason` is not returned.
  - Another visitor and an anonymous visitor get no shadow row.
  - After the render, the stored row in `$wp_comments` and `$wp_comment_cache` still read `spam`,
    and `is_shadowed( $id )` is true.
  - An unrelated comment fetched by `get_comment()` is returned unmodified.
  - With the setting off, no `get_comment` filter is registered.
  - A throw inside the hooks (`wp_current_commenter_throws`) leaves the query result exactly what
    core returns and logs only `basename:line`, never the message.
  - `ShadowDisplayTest` pins every catch block of `ASW_Shadow_Display` on the class's source text,
    the same way `CF7IntegrationTest` and `ModerationTest` pin their own class's catch block:
    enumerate every use of `$e` inside each catch block and allow only `$e->getFile()` (wrapped in
    `basename()`) and `$e->getLine()`, so neither `getMessage()` nor a full path nor a bare `. $e`
    concatenation can slip in. The test also asserts the number of catch blocks it found equals
    the number of callbacks, so a new uncovered catch fails it.
- [x] Add a `the_comments` (2 args) callback: for queries in the `WeakMap`, register the IDs of
  returned rows whose real `comment_approved` is `spam` in a static registry.
- [x] Add the `get_comment` filter lazily, the first time the registry becomes non-empty. It
  returns `clone` with `comment_approved = '0'` for registered IDs and the input unchanged
  otherwise.
- [x] Add a public static `is_shadowed( int $comment_id ): bool`.
- [x] Wrap every callback (`pre_get_comments`, `comments_clauses`, `the_comments`,
  `get_comment`) in `try/catch(\Throwable)`. Each catch returns its input untouched and drops the
  query from the `WeakMap`. It logs
  `'ASW: shadow display failed at ' . basename( $e->getFile() ) . ':' . $e->getLine()`.
- [x] Run `docker compose run --rm test`; it must pass.

### Task 6: Wiring and the "Mark as spam" link

**Files:**
- Modify: `antispam-wizard/includes/class-antispam-wizard.php`
- Modify: `antispam-wizard/includes/class-moderation.php`
- Modify: `tests/ASWTest.php`, `tests/ModerationLinkTest.php`

- [x] Write the tests first:
  - `ASW::init()` registers `pre_get_comments`, `comments_clauses` and `the_comments` with the
    expected callbacks.
  - `append_moderation_link()` returns `$text` byte-identical for a shadow-displayed comment whose
    object carries `'0'`.
  - The link is still rendered for an ordinary approved comment.
- [x] Register `( new ASW_Shadow_Display() )->register_hooks()` in `ASW::init()`, not gated on
  `is_admin()`, because the hooks gate themselves. Add a one-line comment explaining why.
- [x] In `append_moderation_link()`, skip when `ASW_Shadow_Display::is_shadowed( $comment_id )`,
  next to the existing `'spam'` check. It decides on the real status, never on the presented one.
- [x] Reset the static registry via reflection in the setUp of the affected tests.
- [x] Run `docker compose run --rm test`; it must pass.

### Task 7: Live assertions — smoke (both rendering paths) and the site

**Files:**
- Create: `tests/smoke/shadow-check.php`
- Modify: `dev/smoke.sh`
- Modify: `site/verify.sh`
- Modify: `tests/PluginCheckHarnessTest.php` or `tests/SiteThemeTest.php`, whichever already pins
  the harness scripts statically

- [x] Write `tests/smoke/shadow-check.php`, a `wp eval-file` script with no `declare`, logging set
  as in the other smoke scripts:
  - Create a throwaway post. On it, insert an approved comment, an auto-flagged spam comment
    (`_asw_reason` set), a reply to the approved comment that is also auto-flagged, a
    moderator-marked spam comment (no meta) by the same email, and an auto-flagged spam comment by
    another email.
  - Set `$_COOKIE[ 'comment_author_email_' . COOKIEHASH ]` and point `$wp_query` at the post.
  - Set the global `$post` to the throwaway post as well (`$GLOBALS['post'] = get_post( $id )`
    followed by `setup_postdata()`), not only `$wp_query`: `render_block()` seeds the
    `postId`/`postType` context of `core/comments` and `core/comment-template` from the global
    `$post`, and without it the block path renders an empty comment list and every "absent"
    assertion passes vacuously.
  - Classic path: `comments_template()`, which falls back to theme-compat. Block path:
    `do_blocks()` with `comments` / `comment-template` / `comment-content`.
  - Anti-vacuity first, on both paths: assert the output contains the approved comment's text
    before asserting anything about the shadow texts; fail the step if it does not.
  - Assert on both paths: the author's two flagged texts and the awaiting-moderation note are
    present; the moderator-marked and other-email texts are absent.
  - Clear the cookie, re-render, assert the approved comment's text is still present
    (anti-vacuity) and none of the spam texts are.
  - Assert `$wpdb` and `wp_cache_get( $id, 'comment' )` still read `spam`.
  - Delete everything it created.
- [x] Add a SHADOW step to `dev/smoke.sh` after DETECTS, so it runs across the `{6.4, 7.0, 7.1}`
  matrix.
- [x] Add the live block-theme check to `site/verify.sh`:
  - Using a unique run token, email and author, `curl` a POST to `wp-comments-post.php` on the
    announcement post. The body has three links plus the token; send
    `wp-comment-cookies-consent=yes` and keep a cookie jar. The body is unique so neither the
    Duplicate check nor core's duplicate check interferes.
  - Resolve the comment ID with `wp comment list --status=spam --author_email=…`, and assert
    `comment_approved = spam` and `_asw_reason` non-empty.
  - GET the post with the jar: assert the token and `comment-awaiting-moderation` / "Your comment
    is awaiting moderation." are present.
  - GET it without cookies: assert the token is absent.
  - Delete the comment with `--force`, in a trap so a failure still cleans up.
- [x] Add static tests: `smoke.sh` invokes `shadow-check.php`; the new eval-file has no
  `declare( strict_types`; it sets the global `$post` and asserts the approved comment's text on
  the block path; `verify.sh` contains the shadow block and its cleanup.
- [x] Run `docker compose run --rm test`; it must pass.

### Task 8: Documentation

**Files:**
- Create: `docs/shadow-display.md`
- Modify: `antispam-wizard/readme.txt`, `docs/moderation.md`, `docs/site.md`, `CLAUDE.md`

- [x] `docs/shadow-display.md`. Cover:
  - the motivation (comment 5, 2026-10-07);
  - the hook sequence and why it hooks the query layer;
  - the identity rule (user ID or cookie email, mirroring `include_unapproved`);
  - the SQL fragment;
  - the cache-key mechanism;
  - why a `get_comment` filter is unavoidable (block re-fetch, `fill_descendants` re-fetch), and
    its exact scope;
  - `is_shadowed()`;
  - the inert contexts;
  - fail-open behaviour;
  - known boundaries: cookie-consent limitation, page caches, the count query and "newest"
    pagination offset, comment plugins that bypass `WP_Comment_Query`;
  - the test pinning each rule.
- [x] `readme.txt`: a FAQ entry ("Why does a commenter still see a comment you flagged?"): the
  author sees it as awaiting moderation so a false positive is not resent, nobody else sees it,
  and the setting turns it off. Add a 1.0.0 changelog line. The Storage and Privacy sections are
  unchanged (nothing new is stored).
- [x] `docs/moderation.md`: the link decides on the real status via `is_shadowed()`.
  `docs/site.md`: the new live assertion, with the count updated.
- [x] `CLAUDE.md`: a Key classes bullet for `ASW_Shadow_Display` pointing at
  `docs/shadow-display.md`; a Flow line; the smoke SHADOW assertion and the verify.sh assertion
  count; the new stub switches in the Commands list.
- [x] Confirm `wc -c CLAUDE.md` is under 150 000.
- [x] Run `docker compose run --rm test`; it must pass, including `SiteThemeTest`, which compares
  site facts with `readme.txt`.

### Task 9: Verify acceptance criteria

- [x] Run the full suite: `docker compose run --rm test`. (1367 tests, green.)
- [x] Re-read requirements 1–8 against `ShadowDisplayTest` and confirm each one has at least one
  test that fails without the code. (The ticket text is not in the repo, so 1–8 were taken from the
  Overview and Design decisions. Eleven mutations were each caught: the clone left as `spam`, the
  identity dropped, `_asw_reason` dropped, the type filter dropped, no `get_comment` filter, the
  original mutated instead of cloned, the feed gate dropped, the REST gate dropped, the setting gate
  dropped, the cache key moved, and the link ignoring `is_shadowed()`.)
- [x] Confirm that `ASW_Detector`, training, Spam Log, Privacy and `uninstall.php` are unchanged in
  the diff. (No diff vs master in those files; `class-moderation.php` changes only the
  `append_moderation_link()` guard.)

## Post-Completion

These need a Docker stack and network, so they are run by hand:

- `bash dev/smoke.sh`, plus `WORDPRESS_IMAGE=6.4-php8.1-apache bash dev/smoke.sh`: SHADOW green on
  both rendering paths.
- `bash dev/plugin-check.sh`: no ERROR rows.
- `bash site/build.sh && bash site/verify.sh`: the shadow assertion green on the real block theme.
  Rebuild before exporting a `.wpress`.
- CI: unit, smoke matrix, Plugin Check and site jobs green before handing the branch to review.
- After deploy, check the scenario from comment 5 by hand on antispamwizard.com.
