# Shadow display (`ANTISPAMWIZARD_Shadow_Display`)

A comment the plugin flags is shown to its own author as "awaiting moderation" and to nobody
else. This file explains why each rule exists and names the test that pins it. The class is
`antispam-wizard/includes/class-shadow-display.php`; its unit tests are `tests/ShadowDisplayTest.php`.

## Motivation

The trigger was comment 5 on the announcement post (2026-10-07). Detection returns `'spam'` from
`pre_comment_approved`, so a flagged comment is *inserted* as `spam`, and WordPress shows a `spam`
comment to nobody, its author included. A real person hit by a false positive saw their comment
vanish and posted it again, which looks exactly like the repeat-posting a spammer does. Core
already has a presentation for "your comment exists but is not public yet": a held (`'0'`)
comment is shown to its author in its place in the thread with "Your comment is awaiting
moderation.". The shadow display gives the plugin's own flagged comments that same
presentation, for that same audience. Everyone else sees nothing, and so do feeds, REST, admin
screens and counts.

The setting is `shadow_display_enabled` (default `true`) on the General tab ("Show flagged
comments to their author"). The master `enabled` switch turns it off too. No personal data is
stored: the feature only changes what one visitor's own page render shows, so `ANTISPAMWIZARD_Privacy`, the
readme's Privacy section and its "What personal data does it store?" FAQ are unchanged. It writes
two things: `_antispamwizard_reason` is dropped when a moderator overrules the verdict (below), and the
cutoff option `antispamwizard_shadow_display_after` (a comment ID, removed by `uninstall.php`) is recorded
once (below).

## Why the query layer

There are two front-end rendering paths. The classic one is `comments_template()` →
`wp_list_comments()`. The block-theme one is `core/comments` + `core/comment-template`
(`build_comment_query_vars_from_block()`). Both query through `WP_Comment_Query` with the same
shape: `status => 'approve'`, `post_id`, and `include_unapproved = [ current user ID ]` for a
logged-in visitor or `[ wp_get_unapproved_comment_author_email() ]` otherwise (the email of the
moderation-hash pair the post-comment redirect carries, else the commenter cookie). That `include_unapproved` entry is how core
shows a held comment to its author. Hooking `WP_Comment_Query` covers both paths with one
mechanism and reuses core's own identification of "this visitor's comments".

Hook sequence, all registered from `ANTISPAMWIZARD::init()` and not gated on `is_admin()` because the
hooks gate themselves (`AntiSpamWizardTest::test_init_registers_the_shadow_display_hooks`,
`ShadowDisplayTest::test_register_hooks_adds_the_query_hooks_and_the_transition_hook`):

1. `pre_get_comments` (10, 1 arg) → `on_pre_get_comments()`: the eligibility decision. When the
   query qualifies, it records the query in a `WeakMap` and puts the identity into the
   result-cache key (below).
2. `comments_clauses` (10, 2 args) → `on_comments_clauses()`: widens the approved condition for
   approved queries only.
3. `the_comments` (10, 2 args) → `on_the_comments()`: records which returned rows are shadow rows
   and, the first time there is one, adds the `get_comment` filter.
4. `get_comment` (10, 1 arg, added lazily) → `on_get_comment()`: presents a shadow row as `'0'`.
5. `transition_comment_status` (10, 3 args) → `on_transition_comment_status()`: drops
   `_antispamwizard_reason` when a moderator overrules the verdict (below).
6. `comment_post_redirect` (100, 2 args) → `on_comment_post_redirect()`: sends a cookie-less
   author of a plugin-flagged comment back with core's moderation-hash pair (below). Priority 100,
   so a filter that replaces the target runs first and the pair lands on the URL the author is
   actually sent to.

## Identity

The identity mirrors core's own `include_unapproved` entry, and the query must already carry it.
The plugin never adds an identifier that core did not put there.

- A logged-in visitor (`get_current_user_id() > 0`) matches only when their user ID is in
  `include_unapproved`. The branch then matches `user_id = %d`, which is how core resolves a
  logged-in user's comments (`test_logged_in_author_gets_the_user_id_variant`,
  `test_a_logged_in_visitor_whose_id_is_not_listed_is_untouched`).
- A logged-out visitor matches on the email `wp_get_unapproved_comment_author_email()` resolves,
  the same function core uses to fill `include_unapproved`: the `unapproved` + `moderation-hash`
  pair of the link core redirected to first, else the commenter cookie. It must pass `is_email()`
  and appear **verbatim** in `include_unapproved`
  (`test_cookie_author_gets_the_widened_approved_clause`, `test_a_different_visitor_is_untouched`,
  `test_an_empty_commenter_email_is_untouched`, `test_an_invalid_commenter_email_is_untouched`).
  Reading `wp_get_current_commenter()` directly, as the first version did, sees only the cookie,
  so a request carrying only the pair would be rejected even though core had already put the
  author's email into `include_unapproved`
  (`test_a_valid_moderation_pair_without_a_cookie_gets_the_widened_clause_and_the_held_row`,
  `test_a_wrong_moderation_hash_is_untouched`, `test_an_expired_moderation_pair_is_untouched`,
  `test_a_moderation_pair_naming_another_authors_comment_is_untouched`,
  `test_a_moderation_pair_wins_over_a_different_cookie_email`).
- `include_unapproved` is read as `wp_parse_list()` does, so a comma string works as well as an
  array (`test_include_unapproved_as_a_comma_string_is_understood`).

## The SQL fragment

`on_comments_clauses()` reads the query's own `sql_clauses['where']['approved']` (call it A).
Core declares `$sql_clauses` **protected**, so it is read, and the widened string written back,
through a `ReflectionProperty` on `WP_Comment_Query`; a plain `$query->sql_clauses` read sees
nothing on a real site, and the whole feature then silently does nothing. The bootstrap stub
declares the property protected too, so a unit test cannot pass where core would fail
(`BootstrapStubsTest::test_comment_query_internals_are_protected_as_in_core`); the smoke SHADOW
step is what caught it. Only when A occurs **exactly once** in `$clauses['where']` does it
replace it with `( A OR S )` and write the widened string back to
`sql_clauses['where']['approved']`. If A is absent,
duplicated or rewritten by another filter, it changes nothing
(`test_an_absent_approved_clause_leaves_the_where_untouched`,
`test_an_approved_clause_that_occurs_twice_leaves_the_where_untouched`). Core's original clause
is kept intact inside the OR, so held comments keep showing as before.

S is built with `$wpdb->prepare()`, binding both table names through `%i`:

```sql
( comment_author_email = %s            -- or: user_id = %d
  AND comment_approved = 'spam'
  AND comment_type IN ( '', 'comment' )
  AND <comments>.comment_ID > %d       -- the cutoff
  AND EXISTS ( SELECT 1 FROM <commentmeta> WHERE comment_id = <comments>.comment_ID
               AND meta_key = '_antispamwizard_reason' ) )
```

- `comment_approved = 'spam'` plus the `_antispamwizard_reason` EXISTS restricts it to comments the
  **plugin** flagged. A comment a moderator marked as spam carries no `_antispamwizard_reason` and stays
  hidden from its author too: that is a human decision, not a possible false positive
  (`test_moderator_marked_spam_and_a_flagged_pingback_are_not_shown`). For that to hold after the
  plugin's verdict has been overruled, `on_transition_comment_status()` deletes `_antispamwizard_reason`
  when a comment leaves `spam` for `approved` or `unapproved` (Not Spam, Approve, the Spam Log
  pill); a moderator who later marks it spam again makes it ordinary moderator-marked spam
  (`test_overruling_the_verdict_drops_the_flag_so_a_later_moderator_spam_stays_hidden`).
  `spam → trash` and every transition into `spam` keep the flag
  (`test_a_flag_survives_every_transition_that_does_not_overrule_it`). The one exception is the
  Spam Log pill: confirming a comment as **Spam** is a moderator's verdict, and the comment it
  confirms is usually already in the spam queue, where no transition fires; a **Ham** verdict on a
  trashed comment restores it `trash → unapproved`, which is not the overruling transition either.
  `ajax_mark_comment()` therefore calls `ANTISPAMWIZARD_Shadow_Display::forget_verdict()` itself after every
  successful verdict, whatever the prior status (`MarkCommentTest::test_confirming_a_flagged_comment_as_spam_stops_its_shadow_display`,
  `…test_a_ham_verdict_on_a_comment_trashed_from_the_spam_queue_does_not_restore_it_as_spam`);
  a refused status change keeps the flag (`test_a_failed_spam_confirmation_keeps_the_flag`,
  `test_a_refused_ham_untrash_keeps_the_recorded_status_and_the_flag`). The Ham verdict cannot use
  `wp_unspam_comment()`/`wp_untrash_comment()` as is on a trashed comment: both restore
  `_wp_trash_meta_status`, which is `'spam'` for a comment trashed from the spam queue, so the
  comment would return to the queue, train spam through `ANTISPAMWIZARD_Moderation` and stay shadow-visible.
  `ANTISPAMWIZARD_Admin_Page::move_out_of_spam()` rewrites that recorded `'spam'` to `'0'` first (putting it
  back if the untrash is refused, reading the restore back and logging one that did not land —
  `MarkCommentTest::test_a_refused_trash_status_rollback_is_logged`) and writes no status for a
  comment already approved or held. A comment with no status at all (deleted after the request
  looked it up) is a failed status change, so the claim goes back and nothing reports success
  (`…test_a_ham_verdict_on_a_comment_deleted_mid_request_is_a_failed_status_change`).
  `forget_verdict()` reads the flag back after `delete_comment_meta()`, whose `false` means both
  "refused" and "already absent", and logs a flag that is still there
  (`ShadowDisplayTest::test_forget_verdict_reads_back_a_refused_delete_and_logs_it`). The hook is registered
  ungated, so the rule holds whatever the setting is when the comment is moderated.
- `comment_type IN ( '', 'comment' )` is the same scope as `ANTISPAMWIZARD_Detector::filter_comment()` and
  `ANTISPAMWIZARD_Moderation`. Pingbacks and trackbacks are never shown, even with `_antispamwizard_reason`.
- The **cutoff** `comment_ID > antispamwizard_shadow_display_after` keeps out every flag written before this
  feature. Earlier versions never deleted `_antispamwizard_reason` when a moderator decided, so an upgraded
  site's spam queue holds flagged comments a moderator already confirmed (the Spam Log pill on an
  already-spam comment) or approved and re-spammed, indistinguishable from unreviewed ones. The
  detector records the cutoff through `ANTISPAMWIZARD_Shadow_Display::record_flag()` the first time it flags a
  comment (`add_option()`, never overwritten: that comment's ID minus one), and while the option
  is unset no query is widened — no flag from this version exists yet. A refused write is retried
  on the next flag; the comments flagged meanwhile stay hidden, the fail-closed direction. The
  threaded `get_comment` recognition applies the same cutoff
  (`test_a_flag_written_before_the_cutoff_is_never_shown`,
  `test_nothing_is_shown_until_the_plugin_has_flagged_a_comment`,
  `test_record_flag_keeps_the_first_cutoff`,
  `DetectorTest::test_the_first_flag_records_the_shadow_display_cutoff`).
- `post_id` already limits the query, so the branch is limited to this post.

The widening adds no query: there is one `WP_Comment_Query`, and no meta lookup per comment in
PHP. Every ineligible case leaves the WHERE byte-identical and issues no extra `$wpdb` call.

## Cache key

`WP_Comment_Query` caches results under a key built from the query vars, limited to the keys of
`query_var_defaults` (minus `fields`). On eligibility, `on_pre_get_comments()` sets
`query_var_defaults['antispamwizard_shadow'] = ''` and `query_vars['antispamwizard_shadow'] = 'email:<addr>'` or
`'user:<id>'`. The widened result therefore never shares a key with the plain query or with
another visitor's widened query (`test_cache_key_differs_from_the_same_query_with_the_setting_off`,
`test_a_cached_widened_result_is_never_served_to_the_plain_query`,
`test_cache_key_differs_between_two_visitors`).

Approval lives in a `WeakMap` keyed by the query object. `comments_clauses` and `the_comments`
trust only that map. Every `pre_get_comments` run first unsets any `antispamwizard_shadow` var and default,
so a value passed in by a caller, or left over from an earlier run of the same query object, is
overwritten or removed and never trusted
(`test_an_incoming_antispamwizard_shadow_arg_is_unset_for_an_ineligible_query`,
`test_an_incoming_antispamwizard_shadow_arg_is_overwritten_for_an_eligible_query`,
`test_a_reused_query_object_does_not_carry_an_earlier_approval`,
`test_comments_clauses_ignores_a_query_pre_get_comments_never_approved`).

## Why a `get_comment` filter, and its exact scope

Changing the objects in the query result is not enough, because both rendering paths re-fetch
comments by ID:

- the block theme's `core/comment-content` calls `get_comment( $block->context['commentId'] )`
  and prints the moderation note only on `'0' === $comment->comment_approved`;
- a threaded query's `fill_descendants()` re-fetches every comment by ID through `get_comment()`
  (`BootstrapStubsTest::test_threaded_query_refetches_every_comment_through_get_comment` pins in
  the stub that `the_comments`' changes do not survive it).

`get_comment` is the only hook that reaches both. Its scope is kept as narrow as possible:

- It is added **lazily**, only once this request's shadow query has actually returned a shadow
  row — or, for an approved threaded (`hierarchical`) query, as soon as its `the_comments` runs
  (child-ID cache, below) — and only once
  (`test_with_the_setting_off_no_get_comment_filter_is_registered`,
  `test_an_eligible_query_with_no_shadow_row_registers_no_filter`,
  `test_the_get_comment_filter_is_added_once`).
- It returns a **clone** with `comment_approved = '0'`, and only for a comment whose real status
  is `spam` (`test_a_shadow_row_whose_real_status_changed_is_no_longer_presented_as_held`) and
  that is either registered or a threaded viewer's own flagged comment. Any other comment passes
  through unmodified (`test_the_presented_copy_is_a_clone_not_the_input`,
  `test_an_unrelated_comment_passes_through_get_comment_unmodified`).
- It never writes to the row, the comment object cache (`wp_cache_get( $id, 'comment' )` keeps
  `spam`) or `$wpdb` (`test_the_stored_row_and_the_object_cache_keep_spam`).
- Within that one front-end request, `get_comment( $id )` for a shadow ID returns the presented
  copy. That per-request copy is the point
  (`test_author_sees_their_flagged_comment_as_awaiting_moderation_in_thread_order`,
  `test_get_comment_after_the_query_presents_the_shadow_row_as_held`,
  `test_a_threaded_query_presents_a_shadow_reply_as_held`).

The rows are registered in `the_comments`: only the shadow branch can return a `spam` row from a
`status => 'approve'` query, so every `spam` row of an approved query is a shadow row. A query the
plugin did not approve registers nothing, even when it returns `spam` rows (the moderator's Spam
screen: `test_a_rejected_spam_listing_registers_and_presents_nothing`). `the_comments` runs on a
result-cache hit too, so the author's later page views under an external object cache still
register their rows (`test_a_cached_widened_result_in_a_new_request_is_still_presented_as_held`).

**The child-ID cache.** Core's `fill_descendants()` caches each parent's child IDs in the
`comment-queries` group (`get_comment_child_ids:<parent>:<key>:<last_changed>`, the key built from
the query vars, `antispamwizard_shadow` included) and on a hit skips the nested per-level query, so a reply
served from it never passes through `the_comments`. Under an external object cache that is the
author's second view of the page. An approved threaded query therefore records its viewer
(identity + `post_id`) in `the_comments`, and `on_get_comment()` presents and registers any
`spam` comment that passes the same test as the SQL branch — that post, that identity (the email
compared case-insensitively, as the column's collation does), type `''`/`comment`, an ID above
the cutoff, an `_antispamwizard_reason` row — without having seen it in a query result
(`test_a_shadow_reply_from_the_child_id_cache_is_still_presented_as_held`,
`test_a_threaded_viewer_is_presented_only_their_own_flagged_comments_on_that_post`).

## The cookie-less author: `comment_post_redirect`

A logged-out author is recognised on the next page view only if core can tell who they are. The
commenter cookie is set only when the "Save my name, email…" consent box is ticked. With the
opt-in turned off (`show_comments_cookies_opt_in` = 0) there is no box, so the consent key is never
posted and `wp_set_comment_cookies()` deletes the cookies instead: on such a site the redirect pair
below is the only way a logged-out author is ever recognised. For an author without the cookie,
`wp-comments-post.php` appends
`unapproved=<ID>&moderation-hash=<wp_hash( comment_date_gmt )>` to the redirect, and on the landing
page `wp_get_unapproved_comment_author_email()` resolves that pair to the comment's email for ten
minutes and puts it into `include_unapproved`. Core adds the pair only for a `'0'` comment, never
for `spam`, so without help the author of a flagged comment lands on a page that knows nothing about
them and sees their comment vanish — the very thing this feature exists to prevent.

`on_comment_post_redirect()` appends the same pair, through `add_query_arg()` (an existing query
argument and a `#comment-<ID>` fragment survive, the fragment last), when all of these hold:

- the location is a non-empty string and the comment is an object;
- `comment_approved === 'spam'` (`test_redirect_ignores_a_held_comment` — core already added the
  pair —, `test_redirect_ignores_an_approved_comment`, `test_redirect_ignores_a_trashed_comment`);
- `$_POST['wp-comment-cookies-consent']` is not set, read as core reads it: an author who consented
  has the cookie (`test_redirect_ignores_a_poster_who_consented_to_cookies`);
- nobody is logged in and the comment's `user_id` is 0 — a logged-in author is matched by user ID
  (`test_redirect_ignores_a_logged_in_poster`, `test_redirect_ignores_a_comment_owned_by_a_user`);
- the author email is non-empty and passes `is_email()`
  (`test_redirect_ignores_a_comment_without_an_author_email`);
- shadow display is on and the cutoff is recorded (`shadow_cutoff()`, shared with
  `eligible_identity()`: `test_redirect_ignores_a_comment_with_the_setting_off`,
  `test_redirect_ignores_a_comment_with_the_master_switch_off`,
  `test_redirect_ignores_everything_until_the_cutoff_is_recorded`);
- the comment is plugin-flagged: type `''`/`comment`, an ID above the cutoff, an `_antispamwizard_reason` row
  (`is_plugin_flagged()`, the same predicate the threaded `get_comment` recognition uses and the PHP
  mirror of the SQL branch, so the redirect and the query cannot disagree about which comments are
  shadowed: `test_redirect_ignores_spam_without_the_plugin_flag`,
  `test_redirect_ignores_a_flag_at_or_below_the_cutoff`, `test_redirect_ignores_a_pingback`);
- the location's query (`wp_parse_url()` + `wp_parse_str()`) carries neither `unapproved` nor
  `moderation-hash`. If either is there the location comes back byte-identical, so the pair is
  never duplicated or mismatched
  (`test_redirect_leaves_a_location_that_already_carries_either_key_byte_identical`).

The callback writes nothing: no cookie, no row, no status. The positive case and the hash value are
pinned by `test_redirect_adds_the_moderation_pair_for_a_flagged_comment_posted_without_consent`,
the preserved query and fragment by `test_redirect_keeps_an_existing_query_argument_and_the_fragment_last`,
odd inputs by `test_redirect_returns_a_non_string_location_and_a_missing_comment_unchanged`.
`test_the_redirect_pair_reaches_the_landing_listing_without_a_cookie` runs the whole chain: the
filter's output parsed into `$_GET` with no cookie, `include_unapproved` built from
`wp_get_unapproved_comment_author_email()` as `comments_template()` builds it, the widened WHERE,
the flagged row returned and presented as `'0'` while the stored row stays `spam`.

Without a cookie core's moderation note reads "Your comment is awaiting moderation. This is a
preview; …", so every assertion on it matches the shared prefix `Your comment is awaiting
moderation.`.

## `is_shadowed()`

`ANTISPAMWIZARD_Shadow_Display::is_shadowed( int $comment_id ): bool` answers from the static registry and
recovers the real status of a comment this request presents as `'0'`. Code that acts on a
comment's status must decide on this, never on the presented `comment_approved`. Its one caller
is `ANTISPAMWIZARD_Moderation::append_moderation_link()`: it skips the "Mark as spam" link when the comment
is `spam` **or** `is_shadowed()`, because the comment is already spam and the link would offer
the author's own page a moderation action on it
(`ModerationLinkTest::test_no_link_under_a_shadow_displayed_comment`,
`…test_the_link_still_renders_under_an_ordinary_comment_beside_a_shadow_one`). Tests that render
comments reset the registry through reflection in `setUp()`.

## Inert contexts

The query hooks (`pre_get_comments`, `comments_clauses`, `the_comments` and the lazy
`get_comment` filter) do nothing unless all of these hold; `transition_comment_status` and
`comment_post_redirect` have their own gates, described in their sections. The cheap query-var checks run first, so an
anonymous visitor's query (empty `include_unapproved`) returns before any option read
(`test_anonymous_visitor_returns_before_any_option_read`).

- The query has no `count`, `fields` is not `'ids'`, `status` is `'approve'`, `post_id > 0`, and
  `include_unapproved` is non-empty (`test_a_count_query_is_untouched`,
  `test_an_ids_query_is_untouched`, `test_a_status_other_than_approve_is_untouched`,
  `test_a_query_without_post_id_is_untouched`, `test_anonymous_visitor_is_untouched`).
- Not `is_admin()`, `wp_doing_ajax()`, `wp_doing_cron()`, `REST_REQUEST` or `is_feed()`
  (`test_an_admin_screen_is_untouched`, `test_an_ajax_request_is_untouched`,
  `test_a_cron_run_is_untouched`, `test_a_feed_is_untouched`; `test_a_rest_request_is_untouched`
  runs in a child process because `REST_REQUEST` is a constant).
- Both `enabled` and `shadow_display_enabled` are on (`test_master_switch_off_is_untouched`,
  `test_shadow_display_setting_off_is_untouched`).
- The visitor's identifier is in `include_unapproved` (Identity above).

Counts are never widened: `get_comments_number()`, the post's `comment_count` and every
`count => true` query see nothing, just as they do not count a held comment.

## Fail-open

Every callback is wrapped in `try/catch(\Throwable)`. Each catch returns its input untouched;
the three query-hook catches also drop the query from the `WeakMap` (`pre_get_comments` writes
its cache-key marker only after everything that can throw, so there is no marker to remove), and
the redirect catch returns the location unchanged
(`test_a_throw_in_the_redirect_returns_the_location_and_logs_only_the_site`).
Each logs `'ANTISPAMWIZARD: shadow display failed at ' . basename( $e->getFile() ) . ':' . $e->getLine()`,
never the message or the full path. A failure therefore leaves the query exactly what core would
return (`test_a_throw_inside_the_hooks_leaves_the_core_result_and_logs_only_the_site`, driven by
the bootstrap's `wp_current_commenter_throws` switch;
`test_a_throw_in_the_later_callbacks_returns_their_input_and_drops_the_approval` drives the
other four through an input whose every read throws).
`test_every_catch_block_logs_only_the_throw_site` pins every catch block on the class's source
text. It enumerates every use of `$e` and allows only `basename( $e->getFile() )` and
`$e->getLine()`, and it asserts the number of catch blocks equals the number of callbacks, so a
new uncovered catch fails it.

## Known boundaries

- **Cookie consent and the ten-minute window.** An author who did not consent to the cookie is
  reached through the moderation-hash pair the redirect carries (above), and core honours that
  pair for ten minutes after `comment_date_gmt`. After that a cookie-less author sees nothing,
  exactly as with a comment core itself holds. During the window anyone holding the link sees
  what core shows behind a held comment's link: that email's held comments on that post, and now
  its plugin-flagged ones, as awaiting moderation. `wp_get_unapproved_comment_author_email()` checks only the
  hash and the window, never the comment's status, which is why the pair works for a `spam`
  comment at all (confirmed in the 6.4 and 7.1 images, where `comments_template()`, the block
  query vars and `get_page_of_comment()` all call it).
- **Page caches.** The widened render exists only for a request carrying the author's cookie,
  login or moderation-hash pair. A full-page cache must bypass visitors with `comment_author_*` cookies and must not cache a
  URL carrying `unapproved` or `moderation-hash`, as it already must for core's own held comments;
  a cache that does not inherits the same exposure core's held comments have, and one that stores
  such a URL serves the held render past the ten-minute window to anyone holding the link.
- **The count query and "newest first" pagination.** The `count => true` top-level query core runs
  to compute page offsets is left untouched. With comment pagination and "newest" as the default
  page, a shadow comment can shift the computed last page by one, a boundary an extra held comment
  does not have.
- **Comment systems that bypass `WP_Comment_Query`.** A plugin or service that renders comments
  from its own queries or a remote store (Disqus, for instance) shows no shadow row.
- **A `comments_pre_query` short-circuit.** A filter that answers the query itself makes core
  return before `comments_clauses` and `the_comments`, so the widening never runs and the flagged
  comment stays hidden, as it was before this feature. That is the fail-closed direction, and it
  is deliberate: rows the plugin's own branch did not select are never relabelled, because only
  that branch establishes that a `spam` row is the viewer's own plugin-flagged comment. A provider
  that replays a result keyed on the query vars (which carry `antispamwizard_shadow`) replays it to the same
  viewer only; such a row then renders with its real `spam` status rather than as held.
- **A logged-in author's logged-out comments.** The `user_id` branch matches only comments posted
  while logged in, the same rule core applies to a logged-in user's held comments.

## Live checks

The unit suite drives the bootstrap's `WP_Comment_Query` stub (`docs/test-stubs.md`). Only a real
core can prove the widening, the cache key and the presentation survive both renderers:

- `dev/smoke.sh` runs a **SHADOW** step after DETECTS on every image of the `{6.4, 7.0, 7.1}`
  matrix: `tests/smoke/shadow-check.php` renders a throwaway post's comments through
  `comments_template()` and `do_blocks()`, first as the author and then without the cookie. It
  asserts the approved comment's text first on every render, so an empty list fails rather than
  passing vacuously, requires exactly one "awaiting moderation" note per flagged comment (so the
  top-level comment's note cannot stand in for the reply's), and checks that `$wpdb` and the object cache still read `spam`
  (`PluginMetadataTest::test_smoke_harness_runs_the_shadow_check_before_the_log_scan`,
  `…test_shadow_smoke_check_cannot_pass_vacuously`). A **cookie-less pass** follows the anonymous
  one: the flagged comment is moved to now, the real `comment_post_redirect` filter runs for it
  (fed `WP_Comment::get_instance()`, the unfiltered `spam` row `wp-comments-post.php` hands it — the
  author pass already registered the ID, so `get_comment()` would present `'0'`)
  with no consent key posted and must return `unapproved` = its ID and `moderation-hash` =
  `wp_hash( comment_date_gmt )` (so the 6.4/7.0/7.1 matrix fails if the callback is unhooked or
  hashes what core does not check), `$_GET` carries that pair, and both renderers must show the flagged comment and the
  flagged reply with two awaiting-moderation notes while the moderator and other-email spam stay
  absent; an invalid hash and an expired window (the date moved 11 minutes back, the hash
  recomputed for it so only the window rejects it) each render no spam text on both paths
  (`…test_shadow_smoke_check_carries_the_cookie_less_pass`).
- The site repository's `verify.sh` posts a real flagged comment to `wp-comments-post.php` on the announcement post
  with a cookie jar, and asserts seven shadow lines: posted (302), found in the spam list, read
  back as `comment_approved = spam`, `_antispamwizard_reason` recorded, visible to the author, shown as
  awaiting moderation, and absent without the cookies. It then posts a second flagged comment
  without `wp-comment-cookies-consent` and without a jar, and asserts eight more: posted (302),
  stored as spam, `_antispamwizard_reason` recorded, the `Location` carrying `unapproved=<ID>` and
  `moderation-hash=`, the hash equal to `wp_hash()` of the stored `comment_date_gmt`, a cookie-less
  GET of that `Location` showing the comment, the same page showing the awaiting-moderation note,
  and a plain GET of the post not showing it — fifteen shadow lines in all. It reads the meta key by
  name (as does the site's `tests/SiteThemeTest.php`), so renaming `_antispamwizard_reason` needs a
  matching change there. Both comments are
  deleted from the exit trap
  (`SiteThemeTest::test_verify_carries_the_shadow_display_case_and_its_cleanup` in that
  repository).
