---

# Unicode Script-Based Spam Detection Routing

## Overview

Teach the plugin to detect spam in any language without manual user configuration by automatically routing comments to script-specific Bayesian classifiers and similarity models. MVP supports 10 Unicode script groups with training data for 5 primary groups.

## Context

- Files involved:
  - `balrog-blocker/includes/class-balrog-blocker.php` (bootstrap, creates checks)
  - `balrog-blocker/includes/class-tokenizer.php` (word-based tokenization)
  - `balrog-blocker/includes/class-naive-bayes.php` (classifier)
  - `balrog-blocker/includes/class-training-data.php` (loads/saves samples)
  - `balrog-blocker/includes/class-settings.php` (defaults, stop words, excluded tokens)
  - `balrog-blocker/includes/checks/class-bayesian-check.php` (single classifier)
  - `balrog-blocker/includes/checks/class-similarity-check.php` (single sample set)
  - `balrog-blocker/includes/checks/class-stop-words-check.php` (single stop words list)
  - `balrog-blocker/admin/class-admin-page.php` (mark as spam/ham, settings UI)
  - `balrog-blocker/data/spam-samples.txt`, `ham-samples.txt` (current single files)
- Related patterns: strategy pattern for checks, singleton bootstrap, file-based training data with LOCK_EX
- Dependencies: PHP 8.1+ only (no extensions required; script detection uses PCRE `\p{...}` Unicode properties which are built into PHP core)

## Development Approach

- **Testing approach**: TDD where practical, regular for data files and admin UI
- Complete each task fully before moving to the next
- **CRITICAL: every task MUST include new/updated tests**
- **CRITICAL: all tests must pass before starting next task**

## Implementation Steps

### Task 1: Unicode Script Detector utility

**Files:**
- Create: `balrog-blocker/includes/class-script-detector.php`

- [x] Create `Balrog_Blocker_Script_Detector` class with a static method `detect(string $text): string`
- [x] The method analyzes text using `preg_match_all()` with PCRE Unicode property escapes (`\p{Latin}`, `\p{Cyrillic}`, `\p{Han}`, `\p{Hiragana}`, `\p{Katakana}`, `\p{Hangul}`, `\p{Arabic}`, `\p{Devanagari}`, `\p{Greek}`, `\p{Hebrew}`, `\p{Thai}`, `\p{Bengali}`, `\p{Tamil}`, `\p{Telugu}`, `\p{Kannada}`, `\p{Malayalam}`) with the `u` (UTF-8) flag - no PHP extensions required, this is built into PHP's PCRE engine
- [x] Count matches per script pattern, skip characters that dont match any letter pattern (digits, punctuation, whitespace, emoji are naturally excluded)
- [x] Group Han+Hiragana+Katakana+Hangul into "cjk", Tamil+Telugu+Kannada+Malayalam into "indic"
- [x] Return the script group with highest character count: latin, cyrillic, cjk, arabic, devanagari, greek, hebrew, thai, bengali, indic
- [x] Return "unknown" if no letter characters found or text is empty
- [x] Add constant `SCRIPT_GROUPS` array mapping group names to display labels
- [x] Write tests in `tests/ScriptDetectorTest.php`: each of 10 groups detected correctly, mixed text returns dominant script (e.g. Russian with English tech terms -> cyrillic), unknown for empty/numbers-only/emoji-only, short texts
- [x] Run test suite

### Task 2: N-gram tokenization for CJK and Thai

**Files:**
- Modify: `balrog-blocker/includes/class-tokenizer.php`

- [x] Add method `tokenize_ngrams(string $text, int $n = 2): array` that splits text into character n-grams (bigrams by default) after removing whitespace/punctuation
- [x] Add method `tokenize_for_script(string $text, string $script): array` that calls `tokenize_ngrams()` for cjk/thai scripts and `tokenize()` for all others
- [x] Keep existing `tokenize()` method unchanged for backward compatibility
- [x] Write tests in `tests/TokenizerTest.php`: CJK bigram tokenization produces expected tokens, Thai bigram tokenization works, Latin text via tokenize_for_script uses word tokenization, excluded tokens still apply to word-based tokenization
- [x] Run test suite

### Task 3: Per-script training data files and Training Data class updates

**Files:**
- Modify: `balrog-blocker/includes/class-training-data.php`
- Create: per-script data files in `balrog-blocker/data/`

- [x] Add method `get_script_file_path(string $script, string $category): string` that returns path like `data/spam-samples-latin.txt`
- [x] Add method `load_script_samples(string $script, string $category): array` using the above path
- [x] Add method `save_script_sample(string $script, string $category, string $text): bool`
- [x] Add method `remove_script_sample(string $script, string $category, string $text): bool`
- [x] Add method `get_script_sample_counts(): array` that returns `[script => [spam => int, ham => int], ...]` for all scripts that have files
- [x] Add method `train_script_classifier(Balrog_Blocker_Naive_Bayes $classifier, string $script, ?Balrog_Blocker_Tokenizer $tokenizer = null): void` that loads script-specific samples and trains
- [x] Add method `train_combined_classifier(Balrog_Blocker_Naive_Bayes $classifier): void` that loads ALL script samples plus legacy files and trains (for unknown fallback)
- [x] Create empty placeholder files for all 10 script groups (spam + ham = 20 files)
- [x] Keep existing `train_classifier()` working unchanged for backward compatibility during migration
- [x] Write tests in `tests/TrainingDataTest.php`: script file path generation, load/save/remove per-script samples, sample counts, training a script-specific classifier
- [x] Run test suite

### Task 4: Per-script stop words

**Files:**
- Modify: `balrog-blocker/includes/class-settings.php`
- Modify: `balrog-blocker/includes/checks/class-stop-words-check.php`

- [x] Add `get_default_stop_words_for_script(string $script): array` static method to Settings that returns script-specific default stop words (latin: existing English defaults, cyrillic: Russian spam words, cjk/arabic/devanagari: common spam words in those scripts, others: empty arrays initially)
- [x] Update `Balrog_Blocker_Stop_Words_Check` constructor to accept an optional script detector dependency
- [x] Update `check()` method: detect script of incoming comment, merge script-specific default stop words with user's custom stop words, then run the existing matching logic
- [x] Write tests: script-specific stop words applied correctly, user custom stop words still apply to all scripts, fallback when no script-specific words exist
- [x] Run test suite

### Task 5: Route Bayesian and Similarity checks by script

**Files:**
- Modify: `balrog-blocker/includes/checks/class-bayesian-check.php`
- Modify: `balrog-blocker/includes/checks/class-similarity-check.php`

- [x] Update `Balrog_Blocker_Bayesian_Check` constructor to accept `array $classifiers` (keyed by script group) and a `Balrog_Blocker_Naive_Bayes $combined_classifier` for unknown fallback, plus a `Balrog_Blocker_Script_Detector` reference (or use static method directly)
- [x] Update `Bayesian_Check::check()`: detect script, pick matching classifier or combined fallback, run classify on it
- [x] Update `Balrog_Blocker_Similarity_Check` constructor to accept `array $script_samples` (keyed by script, each containing pre-tokenized samples) and `array $combined_samples` for fallback, plus tokenizer-per-script awareness
- [x] Update `Similarity_Check::check()`: detect script, use script-specific samples and appropriate tokenization, fall back to combined for unknown
- [x] Maintain backward compatibility: if constructed with old single-classifier signature, behave as before
- [x] Update existing tests in `tests/BayesianCheckTest.php` and `tests/SimilarityCheckTest.php` for new constructor signatures
- [x] Add new tests: routing to correct script classifier, fallback to combined on unknown, per-script tokenization used for CJK
- [x] Run test suite

### Task 6: Update bootstrap to wire per-script classifiers

**Files:**
- Modify: `balrog-blocker/includes/class-balrog-blocker.php`

- [x] In `init()`, iterate over script groups: for each group with training data, create a script-specific Tokenizer and Naive Bayes, train with script samples
- [x] Create a combined classifier trained on all samples (for unknown fallback)
- [x] For CJK and Thai classifiers, create tokenizers that use n-gram tokenization
- [x] Pass per-script classifiers and combined classifier to Bayesian Check
- [x] For Similarity Check, load per-script spam samples pre-tokenized with appropriate tokenizer, pass keyed arrays
- [x] Include `class-script-detector.php` in autoload
- [x] Update tests in `tests/BalrogBlockerTest.php` and `tests/IntegrationTest.php`
- [x] Run test suite

### Task 7: Admin UI - model coverage display and script group in mark as spam/ham

**Files:**
- Modify: `balrog-blocker/admin/class-admin-page.php`

- [x] In the General or Bayesian settings tab, add a "Loaded Models" section that calls `Training_Data::get_script_sample_counts()` and displays script group name, spam count, ham count for each group that has data
- [x] In `ajax_mark_comment()`, detect script of the comment being marked, save to the appropriate script-specific file instead of the legacy single file
- [x] In the AJAX response for mark_comment, include the detected script group name so the UI can display "Saved to: Cyrillic model"
- [x] Update existing mark comment tests to verify script-aware file routing
- [x] Write tests for sample counts display data
- [x] Run test suite

### Task 8: Populate MVP training data files

**Files:**
- Modify: `balrog-blocker/data/spam-samples-latin.txt`
- Modify: `balrog-blocker/data/ham-samples-latin.txt`
- Modify: `balrog-blocker/data/spam-samples-cyrillic.txt`
- Modify: `balrog-blocker/data/ham-samples-cyrillic.txt`
- Modify: `balrog-blocker/data/spam-samples-cjk.txt`
- Modify: `balrog-blocker/data/ham-samples-cjk.txt`
- Modify: `balrog-blocker/data/spam-samples-arabic.txt`
- Modify: `balrog-blocker/data/ham-samples-arabic.txt`
- Modify: `balrog-blocker/data/spam-samples-devanagari.txt`
- Modify: `balrog-blocker/data/ham-samples-devanagari.txt`

- [x] Latin: 100-150 spam + 100-150 ham samples (EN, ES, PT, DE mixed). Move existing spam-samples.txt/ham-samples.txt content into Latin files
- [x] Cyrillic: 80-100 spam + 80-100 ham samples (RU, some UK)
- [x] CJK: 50-80 spam + 50-80 ham samples (ZH, JA mixed)
- [x] Arabic: 30-50 spam + 30-50 ham samples (AR)
- [x] Devanagari: 30-50 spam + 30-50 ham samples (HI)
- [x] Verify samples are realistic blog comment content (not lorem ipsum)
- [x] Run test suite to ensure trained classifiers work with new data

### Task 9: Integration tests for the full routing pipeline

**Files:**
- Create: `tests/ScriptRoutingIntegrationTest.php`

- [x] Test: Latin comment routed to Latin classifier
- [x] Test: Cyrillic comment routed to Cyrillic classifier
- [x] Test: CJK comment routed to CJK classifier with n-gram tokenization
- [x] Test: Arabic comment routed to Arabic classifier
- [x] Test: Devanagari comment routed to Devanagari classifier
- [x] Test: unknown script (numbers only) falls back to combined model
- [x] Test: mixed Russian+English tech text routes to Cyrillic (dominant script)
- [x] Test: end-to-end through Detector - spam in Cyrillic correctly detected
- [x] Run full test suite

### Task 10: Verify acceptance criteria

- [x] Run full test suite (`docker compose run --rm test`)
- [x] Verify all 10 script groups are detectable
- [x] Verify routing works for all 5 MVP groups with training data
- [x] Verify fallback to combined model for unknown scripts
- [x] Verify CJK/Thai n-gram tokenization
- [x] Verify admin shows model coverage
- [x] Verify mark as spam/ham saves to correct script file
- [x] Move this plan to `docs/plans/completed/`
