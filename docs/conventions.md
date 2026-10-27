# Conventions: the long-form rules

Reference moved out of `CLAUDE.md`. The full reasoning behind the attacker-controlled-text, PCRE Script_Extensions, CJK bucket, fail-open diagnostic, malformed-UTF-8 and `phpcs:ignore` anchoring rules.

- Check code runs on attacker-controlled text bounded only by `ANTISPAMWIZARD_Detector::MAX_COMMENT_LENGTH`
  (65 536 chars; a Contact Form 7 submission joins its fields into the same buffer), and that may
  be a **single unbroken word**. `mb_substr( $s, $i, 1, 'UTF-8' )` in a loop rescans from byte 0,
  so per-character loops are O(n²) and a remote CPU-exhaustion vector — one 65 536-char word cost
  ~0.7 s before `ANTISPAMWIZARD_Multi_Lang_Check` was rewritten. Classify whole strings with character-class
  regexes (`preg_replace`/`preg_match` under `/u`) instead of walking codepoints. The one remaining
  per-character loop, `ANTISPAMWIZARD_Tokenizer`'s CJK/Thai n-gram path, is safe only because its 10 000-token
  cap bounds it.
- From **PCRE2 10.42** on, PCRE resolves `\p{Latin}`, `\p{Devanagari}` etc. against
  **Script_Extensions**, not `Script`, so one character can match several script properties (U+A8F1
  matches both `\p{Devanagari}` and `\p{Bengali}` on 10.44 — but only `\p{Devanagari}` on 10.39, the
  PHP 8.1 floor), and the set widens further with PCRE/Unicode upgrades. Code that buckets
  characters by script must therefore *consume* each script's matches from the subject as it goes
  (first pattern in list order wins) rather than merely testing each pattern against the whole
  subject — testing double-counts and produces false positives.
  `ANTISPAMWIZARD_Multi_Lang_Check::is_multi_script_word()` is the reference implementation, and
  `ANTISPAMWIZARD_Script_Detector::detect()` follows the same rule — it consumes each group's matches from
  the subject rather than summing `preg_match_all()` counts per pattern, which double-counted
  U+30FC (matches both `\p{Hiragana}` and `\p{Katakana}`, and survives the `\p{L}` pre-filter as
  `Lm`) and made `"abcde コーヒー"` — 5 Latin letters against 4 CJK — score `cjk=6` and route to
  the CJK classifier and bigram tokenizer. That also made the verdict depend on the PCRE build
  (the same input routed to `latin` on 10.39 and `cjk` on 10.42+);
  `test_script_extensions_character_is_counted_once` pins it, and
  `test_prolonged_sound_mark_word_still_detects_as_cjk` guards the over-correction. Because the
  divergence is version-dependent, `test_script_extensions_character_counts_as_one_script` asserts
  its own premise and `markTestSkipped()`s on older PCRE instead of passing vacuously.
- `ANTISPAMWIZARD_Multi_Lang_Check::SCRIPT_PATTERNS` keeps `\p{Han}`, `\p{Hiragana}`, `\p{Katakana}` and
  `\p{Hangul}` in **one** `CJK` bucket, matching `ANTISPAMWIZARD_Script_Detector::SCRIPT_PATTERNS`' `cjk`
  group; do not split them apart again. The check splits words on whitespace and hyphens, and
  Japanese has no inter-word spaces, so a whole sentence is a single "word" that routinely mixes
  kanji with both kana — with separate buckets an ordinary three-paragraph Japanese comment scored
  3 mixed-script words and was flagged at the shipped default threshold of 3. Mixing writing
  systems inside CJK is normal orthography, not the Latin/Cyrillic homoglyph spoofing this check
  exists to catch. `test_ordinary_japanese_comment_is_not_flagged` and
  `test_korean_with_hanja_is_not_flagged` pin the *intra*-CJK half of the false positive shut.
- The bucket merge alone did **not** close the whole false positive, because the root cause is the
  whitespace/hyphen split, not the bucket layout: CJK **and Thai** are written without inter-word
  spaces, and embedded Latin (product names, brands, URLs, romaji) is routine in both and written
  without spaces around it, so three ordinary lines like `はじめまして。WordPressでブログを始めました。`
  still scored 3 and were flagged at the shipped default threshold of 3.
  `ANTISPAMWIZARD_Multi_Lang_Check::ORTHOGRAPHIC_PAIRS` therefore exempts the two-bucket combinations
  `Latin+Thai` and `Latin+CJK` (keys sorted in `SCRIPT_PATTERNS` order, joined with `+`) — and
  **only** those, at **exactly** two buckets. Exempting the pair rather than splitting the word at
  the script boundary is what keeps every other verdict intact; the classification loop
  consequently short-circuits at three buckets rather than two, which costs nothing because the
  extra passes run over a residue the matched scripts have already been consumed from.
  `test_ordinary_japanese_comment_with_embedded_latin_is_not_flagged`,
  `…chinese…` and `…thai…` pin the false positive shut;
  `test_space_less_script_with_latin_and_a_third_script_is_flagged`,
  `test_latin_cyrillic_homoglyph_word_is_still_flagged` and
  `test_cjk_mixed_with_another_script_is_still_flagged` guard the over-correction (a third script
  always flags, Latin/Cyrillic homoglyphs are untouched, and a space-less script paired with any
  script other than Latin is still two buckets and still flagged).
- `ANTISPAMWIZARD_Detector::run()`'s fail-open diagnostic logs `get_class( $check )` and the throw site
  **but never `$e->getMessage()`**: a check wrapping `$wpdb`, file I/O or an HTTP client can carry
  a query, an absolute path or a credential in the message, and `debug.log` is web-readable on
  misconfigured hosts. `DetectorTest::test_run_logs_a_diagnostic_naming_the_failing_check` pins
  both halves. Its `ANTISPAMWIZARD_Throwing_Check` fixture must stay a **named** class: an anonymous class
  name embeds a NUL byte, `error_log()` writes a C string, and everything after the NUL — the
  message the negative assertion inspects — is dropped, so an anonymous fixture makes the whole
  test pass vacuously no matter what `run()` logs. `ANTISPAMWIZARD_CF7_Integration::filter_spam()`'s
  catch follows the same rule and logs only `basename( $e->getFile() )`/`getLine()` — its `try` wraps
  `evaluate()`, `ANTISPAMWIZARD_Spam_Log::add_entry()` and `ANTISPAMWIZARD_Stats::record()`, so a `$wpdb` or
  lock-file failure there would otherwise write a query or a filesystem path into
  `debug.log` on an unauthenticated form submission. Forcing a throw past that method's
  inner fail-open catches is not reachable through the CF7 stubs, so
  `CF7IntegrationTest::test_filter_spam_diagnostic_omits_the_exception_message` pins the
  rule on the method's source text — and does so by enumerating every use of the caught
  `$e` and allowing only `getFile()`/`getLine()`, because a name-only grep for
  `getMessage()` is passed unchanged by `. $e` or `"{$e}"`, which stringify the message
  *and* the whole stack trace. The throw site itself is kept in both places, but only as
  `basename( $e->getFile() ) . ':' . $e->getLine()`: the exception need not originate in
  this plugin — a `$wpdb` failure reports `wp-includes/class-wpdb.php`, a throw from
  another plugin's filter reports its own tree — so logging the full path would leak the
  document root into the very log the message was dropped to protect. The file name alone
  still attributes the break. `ANTISPAMWIZARD_Shadow_Display`'s six callbacks (`pre_get_comments`,
  `comments_clauses`, `the_comments`, `get_comment`, `transition_comment_status`,
  `comment_post_redirect`) follow the same
  rule with a fixed `'ANTISPAMWIZARD: shadow display failed at '` prefix instead of a class name, pinned by
  `ShadowDisplayTest::test_every_catch_block_logs_only_the_throw_site`, which also counts the
  catch blocks against the callbacks.
- A `/u` pattern returns `false`/`null` on malformed UTF-8, and comment bodies (and CF7 fields
  joined into them) are attacker-controlled, so "bail out on a PCRE failure" is a one-byte evasion
  of the whole check, not a safe fail-open. Sanitize first — always through
  `ANTISPAMWIZARD_Detector::sanitize_utf8()`, never a bare `mb_convert_encoding( $s, 'UTF-8', 'UTF-8' )` —
  and classify the remainder. That helper exists because the `?` substitute the sanitization
  relies on (Common, and therefore ignored by script bucketing) is **not** guaranteed:
  `mbstring.substitute_character` is a process-wide runtime setting any other plugin can change
  with `mb_substitute_character()`, and given a letter codepoint (e.g. `65`) a stray byte in a
  Cyrillic body comes back as `A`, so `ANTISPAMWIZARD_Multi_Lang_Check` sees Latin + Cyrillic and flags a
  legitimate single-script comment. (The named settings are all safe: `'none'` drops the invalid
  bytes outright, `'long'` and `'entity'` both fall back to `?` for this conversion; only a
  codepoint value is a hazard.) `sanitize_utf8()` pins the substitute to
  `0x3F` and restores the site's setting in a `finally`;
  `test_verdict_does_not_depend_on_mbstring_substitute_character` pins it. Do **not** reach for
  `wp_check_invalid_utf8( $s, true )` here: it strips
  via `iconv( 'utf-8', 'utf-8', … )`, which on PHP 8.1–8.5 emits `Detected an illegal character in
  input string` and returns `false`, so it both fails to sanitize and writes a plugin-attributable
  warning into the `debug.log` that `dev/smoke.sh` scans. That sanitization lives at the single
  choke point every path runs through — `ANTISPAMWIZARD_Detector::evaluate()` sanitizes `comment_content`
  **before** the `MAX_COMMENT_LENGTH` truncation, so `mb_strlen()`/`mb_substr()` and then every
  check see valid UTF-8, on both the `pre_comment_approved` and the CF7 route. Without it one
  stray byte anywhere in the body switched off Emoji, Spacing, Bayesian and Similarity at once,
  blanked the Stop Words obfuscation stripping and made `ANTISPAMWIZARD_Script_Detector::detect()` return
  `'unknown'` (`DetectorTest::test_one_stray_byte_does_not_disable_a_check` and
  `test_evaluate_strips_malformed_utf8_before_checks_run` pin it; both fail if the line is
  removed). The sanitize-**before**-truncate order matters as much as the line's presence, but
  it is only observable at runtime on PHP 8.1/8.2 — from 8.3 on `mb_strlen()` counts each
  invalid byte as one character, so substitution can no longer expand the count past the cap and
  `test_malformed_utf8_is_sanitized_before_the_length_cap_is_applied` holds trivially on the
  `composer:2` image CI runs. `test_evaluate_sanitizes_before_it_truncates_in_source_order`
  therefore asserts the order in `evaluate()`'s source text, so swapping the two statements goes
  red on every runtime instead of only on the floor. `ANTISPAMWIZARD_Multi_Lang_Check` and `ANTISPAMWIZARD_Duplicate_Check` keep their own guards as well,
  because their tests — and any future caller — invoke them directly rather than through
  `evaluate()`; neither guard may fall back to `''`, which would collide every malformed body
  from one IP into a single duplicate-hash bucket.
- Tests reset the singleton via reflection in setUp
- Every plugin-owned identifier carries the full plugin prefix: `ANTISPAMWIZARD_*` for classes and constants,
  `antispamwizard` / `antispamwizard_` / `antispamwizard-` for options, transients, comment meta, the samples table,
  hooks and AJAX/admin-post actions, CSS classes, `data-` attributes, the localized `antispamwizardAdmin` object and
  the `.antispamwizard-locks/` directory. The plugin shipped with a three-letter prefix that the WordPress.org review
  rejected as too short and generic, so the whole tree was renamed in one pass (the 2026-10-27
  prefix-rename plan in `docs/plans/`).
  `PluginMetadataTest::test_no_plugin_file_carries_the_retired_three_letter_prefix()` matches the old token after any
  non-alphanumeric character (so `_x_reason` and `wp_ajax_x_*` forms are caught) in every tracked text file and path
  name of the plugin, `dev/`, `tests/`, `docs/`, `.github/` and the root config. The seed corpus
  (`antispam-wizard/data/`, whose Thai file carries made-up Line IDs that are text, not identifiers) and
  `docs/plans/` are exempt. Because the rename edited the PHP and JS/CSS sides of each shared name independently,
  `PluginMetadataTest::test_admin_js_and_comment_form_css_names_match_the_php_side()` pins them against each other:
  the AJAX actions `admin.js` posts equal the registered `wp_ajax_*` hooks, every `antispamwizardAdmin.*` key it reads
  is localized, every `antispamwizard-*` name it touches exists in the admin views, `class-admin-page.php` or
  `admin.css`, and the comment form's honeypot wrapper class is a selector in `comment-form.css`.
- Every Plugin Check deviation that is not fixed outright is carried as a `phpcs:ignore <exact
  sniff code>` with a one-line reason on the same line — bare suppressions are not allowed
  (`grep -rn "phpcs:ignore" antispam-wizard` should show a reason on every hit)
- A `phpcs:ignore` naming `WordPress.DB.PreparedSQL.NotPrepared` or
  `PluginCheck.Security.DirectDB.UnescapedDBParameter` must sit on the line **directly above a
  single-line statement**: both sniffs report at the `$wpdb->query( … )` call, and a
  previous-line suppression covers exactly one line, so a multi-line
  `$wpdb->query( $wpdb->prepare( … ) )` silences only its opening line while the finding still
  lands on the argument. The shipped shape is `class-sample-repository.php`'s — `prepare()` into
  a `$prepared` variable, then a bare one-line `$wpdb->query( $prepared );` under the
  suppression — with the query string a **literal** and the table name bound through the `%i`
  identifier placeholder (WP 6.2+, under the 6.4 floor; the `$wpdb` stub renders it
  backtick-quoted, so the SQL is byte-identical), since `NotPrepared` reports on any *variable*
  handed to `prepare()`. `PluginMetadataTest::test_not_prepared_suppressions_are_anchored_on_a_single_line_statement()`
  pins it across the shipped tree, requires the covered line to be the literal next one (PHPCS
  does not skip a blank), skips a trailing same-line suppression (which anchors itself), and
  matches the sniff names **exactly** so `PreparedSQL.InterpolatedNotPrepared` and
  `PreparedSQLPlaceholders.*` — which report on an argument line and are correctly anchored
  above a multi-line `prepare()` — are left alone. Both `ANTISPAMWIZARD_Sample_Repository` and
  `ANTISPAMWIZARD_Model_Store` follow it
