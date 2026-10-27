# AntiSpam Wizard

Offline-first spam protection for WordPress comments and Contact Form 7 submissions. Ten
configurable checks run on your own server: nothing is sent anywhere, no account and no API key.

## What it does

- **Ten checks, each with its own toggle and thresholds.** Two bot checks run first, a honeypot
  field and submit-time validation. Then stop words, link count, emoji count, abnormal spacing,
  mixed writing systems, duplicate content, a Naive Bayes classifier and cosine similarity against
  known spam samples.
- **Learns from your moderation.** Every Spam / Not Spam decision made anywhere in WordPress
  trains the classifier: a Comments-screen row action, a bulk action, another antispam plugin, or
  the plugin's own Spam Log. Administrators also get a JavaScript-free "Mark as spam" link under
  each comment on the site. A comment is learned once per category, so repeating a decision stores
  one sample and reversing it replaces that sample.
- **Shows a flagged comment to its own author as "awaiting moderation"**, and to nobody else, so
  a real visitor caught by mistake does not post it again. A General tab setting turns it off.
- **Contact Form 7, when it is active.** All enabled checks run on form submissions through
  CF7's own spam handling, and flagged ones land in the same Spam Log with a form marker.
  Without CF7 nothing changes.
- **Ten writing systems**, each with its own seed corpus (Latin, Cyrillic, CJK, Arabic, Devanagari,
  Greek, Hebrew, Thai, Bengali, Indic), so a non-English site starts with a working classifier.
- **WordPress Privacy API**: export and erase per person, see [Privacy](#privacy).
- **Admin interface in ten languages**, see [Supported Languages](#supported-languages).

Requirements: WordPress 6.4 or newer, PHP 8.1 or newer, tested up to WordPress 7.1.

## Notes on detection

- The two bot checks fail open and store no personal data. A missing or stale field never blocks
  anyone, and a bot written for one specific site can bypass them; it is then judged by the
  content checks alone.
- The seed corpus is comment spam. It is expected to transfer reasonably to form submissions,
  but that is unmeasured, and a form-specific corpus is explicit future work.
- Marking a comment the plugin never blocked still records a Spam Log entry, badged
  "Marked by moderator".

## Supported Languages

The admin interface is fully translated into 10 languages:

- English (default)
- Spanish (es_ES)
- German (de_DE)
- French (fr_FR)
- Portuguese - Brazil (pt_BR)
- Italian (it_IT)
- Japanese (ja)
- Russian (ru_RU)
- Dutch (nl_NL)
- Turkish (tr_TR)

Translation files are in `antispam-wizard/languages/`.

## Privacy

AntiSpam Wizard integrates with WordPress's built-in personal-data tools (Tools → Export/Erase Personal Data), so you can honor GDPR access and erasure requests:

- **Export** — for a given email, the report includes the plugin's stored data for that person's flagged comments and, when Contact Form 7 is in use, flagged form submissions: the date, a short comment/submission fragment, the SHA-256 hash of the IP address (the raw IP is never stored), the salted SHA-256 hash of the email for form entries (raw email never stored), the checks that triggered, and the reason the comment/submission was flagged. If a comment was later used to train the spam filter — from the Spam Log's pills or by any Spam / Not Spam moderation decision — the report also carries one item per training sample: the date it was added, the training category (Spam/Ham), and the **full** stored comment text — unlike the spam-log fragment, a training sample keeps the comment verbatim.
- **Erase** — removes the matching spam-log entries (by `comment_id` for comments and by salted email hash for form entries on page 1), the `_antispamwizard_reason` comment meta, and the training samples added from those comments (`{prefix}antispamwizard_samples`, matched by `comment_id` and, on page 1, by the salted email hash — which still reaches a sample whose source comment was hard-deleted); removing samples invalidates the cached models for that writing system (and the combined model), so the classifier is rebuilt without the erased text.

Suggested privacy-policy text describing what the plugin stores and how to remove it — including that it also analyzes Contact Form 7 submissions and stores, for a flagged submission, a short text fragment, a SHA-256 hash of the IP and a salted SHA-256 hash of the email (raw values never stored), and that training the filter on a comment stores that comment's full text as a training sample keyed by its comment ID and by a salted hash of the author's email — is available under **Settings → Privacy** (the WordPress Privacy Policy Guide), ready to insert into your site's policy.

## Website

The marketing site <https://antispamwizard.com> is built and deployed from a separate
repository that mounts this plugin from a sibling checkout; its announcement post's comments
are protected by the plugin itself.

## Local Development

### Prerequisites

- Docker and Docker Compose

### Starting the Test Environment

```bash
docker compose up -d wordpress
```

This starts WordPress (on port 8080) and a MySQL database. The `antispam-wizard/` directory is mounted directly into the WordPress container, so any code changes are reflected immediately.

### Initial Setup

Run the setup script to install WordPress, activate the plugin, and prepare a test post with comments enabled:

```bash
bash dev/setup.sh
```

After setup completes:

- Site: http://localhost:8080
- Admin panel: http://localhost:8080/wp-admin
- Login: `admin` / `admin`

### Resetting the Environment

To wipe all data and return to a clean state (fresh database, one post, one comment, plugin reactivated):

```bash
bash dev/reset.sh
```

### Testing Spam Detection Manually

1. Navigate to the default post on http://localhost:8080 (the setup script enables comments on the first post automatically).
2. Post a normal comment (e.g. "Great article, thanks!") and verify it goes through.
3. Post a spam comment to trigger detection. Examples:
   - **Stop-words**: Add words from the stop-words list configured in AntiSpam Wizard > Stop Words (e.g. "buy now", "free money", "click here").
   - **Excessive links**: Include many URLs in a single comment (e.g. paste 5+ `http://example.com` links).
   - **Emoji abuse**: Post a comment overloaded with emojis.
   - **Multi-language mixing**: Mix scripts from different languages in one comment (e.g. Latin + Cyrillic).
   - **Duplicate content**: Post the exact same comment three times (the default threshold is 3). Matching is per commenter IP within the TTL window, not per post, so the repeats may be on different posts.
   - **Suspicious spacing**: Use unusual whitespace patterns (e.g. extra spaces between every letter).
   - **Bayesian classifier**: Post comments similar to known spam patterns (e.g. "buy cheap pills online free discount"). Admins can refine detection by marking comments as spam or ham in the spam log.
   - **Similarity matching**: Post a comment closely resembling known spam samples (e.g. "buy cheap pills online free discount"). Uses cosine similarity against the shipped spam corpus plus the most recently learned spam samples — the learned half is capped by `ANTISPAMWIZARD_Model_Store::LEARNED_SIMILARITY_BUDGET` (128 KiB of packed tokens per writing system, newest first) so a cached model stays under the object cache's 1 MB per-item limit, while the Bayesian classifier trains on every learned sample (on a site running a persistent object cache the cached model is additionally compressed and, if it would still not fit, its rarest words are dropped until it does); adjustable threshold in settings (default 0.6).
4. Verify the spam comment is blocked and appears in the spam log (AntiSpam Wizard > Spam Log).
5. As an administrator, view a post on the site: each non-spam comment carries a "Mark as spam" link that moves the comment to spam and trains the classifier.
5. Check detection statistics on the Stats tab (AntiSpam Wizard > Statistics).

### Running PHPUnit Tests

```bash
docker compose run --rm test
```

### Runtime Smoke Test

Boot a real, throwaway WordPress, activate the plugin, and verify it **loads**
clean (no plugin-attributable PHP problems in `debug.log`; all 6 admin pages
render) and **detects** spam/ham through `pre_comment_approved` and, when Contact
Form 7 is present, through `wpcf7_spam` via `tests/smoke/cf7-check.php`:

```bash
bash dev/smoke.sh                                   # WP 7.1 (default)
WORDPRESS_IMAGE=6.4-php8.1-apache bash dev/smoke.sh # WP 6.4 floor (control)
ANTISPAMWIZARD_SMOKE_SKIP_CF7=1 bash dev/smoke.sh   # force the CF7 inert path
```

After activation the harness attempts `wp plugin install contact-form-7 --activate`;
if that fails or the classes are not loaded (no network, dead download) it prints
a `SKIP` line and continues — proving the integration degrades to inert rather
than failing the plugin — and `ANTISPAMWIZARD_SMOKE_SKIP_CF7` forces the same skip path.
The CF7 check creates a contact form via the CF7 API, pins deterministic
settings (Links as spam trigger, probabilistic checks off), drives real
submissions through `WPCF7_ContactForm::submit()` with `$_POST` populated
(spam → `spam`, ham → not spam), and asserts the rendered form markup
(`form_elements`) contains both bot-check fields and the token verifies against
the contact-form ID.

Override the WordPress image with `WORDPRESS_IMAGE` (default `7.1-php8.3-apache`;
floor `6.4-php8.1-apache`). Before the admin render the harness gates on a WP
core-version assertion: if the booted core's `major.minor` does not match the
requested image it fails fast with an actionable "stale/mismatched WordPress core
tree" message instead of a raw core fatal. The harness runs fully isolated under
a **per-run, per-image** compose project derived from the sanitized
`WORDPRESS_IMAGE` plus the harness PID (e.g. `antispamwizard-smoke-6-4-php8-1-apache-12345`),
each with ephemeral volumes and a teardown trap, so no two runs — different
images, or two concurrent invocations of the same image — ever share
volumes/containers/network even on the same Docker daemon, and your persistent
dev environment is never touched.

### WordPress.org Plugin Check

Run the same checks WordPress.org runs against a submission:

```bash
bash dev/plugin-check.sh                                   # WP 7.1 (default)
WORDPRESS_IMAGE=6.4-php8.1-apache bash dev/plugin-check.sh # WP 6.4 control
```

The harness boots a throwaway WordPress, installs the official `plugin-check`
plugin from WordPress.org, activates AntiSpam Wizard and runs
`wp plugin check antispam-wizard --categories=plugin_repo`. It checks a
**tracked-files-only export** of the plugin, built from `git ls-files
antispam-wizard`, so untracked junk in the working tree (`.DS_Store`, editor
backups, a locally built zip) can never produce a finding — what is checked is
exactly what would be shipped. Every result row is printed; warnings are
informational, and the run **exits non-zero if any row is an `ERROR`**. Before
counting rows the harness asserts the check actually completed, so a run that
failed to start cannot be mistaken for a clean pass.

The container needs network access to download `plugin-check` from WordPress.org.
Unlike the optional Contact Form 7 step in the smoke harness, a failed install
fails the run — there is no skip path.

Override the WordPress image with `WORDPRESS_IMAGE` (default `7.1-php8.3-apache`;
floor `6.4-php8.1-apache`). Isolation follows the smoke harness model: a per-run,
per-image compose project (`antispamwizard-pc-6-4-php8-1-apache-12345`) with ephemeral
volumes, no published host ports and a teardown trap, plus the same core-version
gate — your persistent dev environment is never touched.

Every deviation the check reports is either fixed or carried in the source as an
annotated `phpcs:ignore` with a one-line reason; there are no bare suppressions.

These checks (the unit suite, a WordPress smoke matrix on 6.4, 7.0 and 7.1, and the
Plugin Check harness) also run in CI on every push and pull request via
`.github/workflows/ci.yml`.

### Stopping the Environment

```bash
docker compose down
```

To remove all data (database, WordPress files) and start fresh:

```bash
docker compose down -v
```

## Contributing and security

See [CONTRIBUTING.md](CONTRIBUTING.md) for how to run the test suite and open a pull request.
Found a vulnerability? Do not open an issue: use **Report a vulnerability** on the repository's
Security tab, so the report stays private until a fix is released.

## License

GPL-2.0-or-later, see [LICENSE](LICENSE).
