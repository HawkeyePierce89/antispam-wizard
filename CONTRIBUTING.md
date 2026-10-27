# Contributing

Thanks for helping make AntiSpam Wizard better. Bug reports and feature requests go to
GitHub issues; a vulnerability goes through **Report a vulnerability** on the Security tab,
never through a public issue.

## Running the checks

Everything runs through Docker, nothing else needs to be installed.

```bash
docker compose run --rm test        # unit tests (WordPress stubs, no database)
bash dev/smoke.sh                   # real throwaway WordPress: load, detect, shadow display, schema
bash dev/plugin-check.sh            # the WordPress.org Plugin Check
```

The unit suite must stay green and Plugin Check must report no `ERROR` rows; CI runs the same
three on every push and pull request, the smoke harness across WordPress 6.4, 7.0 and 7.1.

## Pull requests

- Work on a branch and open a pull request against `master`; it is squash-merged once CI is green.
- Read `CLAUDE.md` and the `docs/*.md` file for the subsystem you touch before changing behaviour:
  they record why each rule exists and which test pins it. Update them in the same pull request
  when a rule changes.
- Every rule has a test. A behaviour change comes with the test that would have caught it.
- Translatable strings are a multi-file change (POT, nine `.po` files, compiled `.mo` files);
  `tests/I18nTest.php` explains what it expects.
- Corpus edits (`antispam-wizard/data/*.txt`) must keep `tests/CorpusQualityTest.php` green;
  run `docker compose run --rm test php dev/corpus-eval.php` afterwards.

## Code style

WordPress coding style with the `ANTISPAMWIZARD_` class prefix, no PHP namespaces, PHP 8.1 with strict
types, WordPress 6.4 or newer. Plugin Check deviations are never suppressed silently: each
carries a `phpcs:ignore <sniff>` with a one-line reason.
