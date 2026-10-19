---

# Expand Training Data to 100+ Samples per Script and Add Fallback Tests

## Overview

Populate training data for all 10 script groups to 100+ spam and 100+ ham samples each, balance the combined classifier, and add tests verifying fallback behavior when data is empty or insufficient.

## Context

- Data files: `balrog-blocker/data/{spam,ham}-samples-{script}.txt` for 10 scripts + legacy `{spam,ham}-samples.txt`
- Bayesian check: `balrog-blocker/includes/checks/class-bayesian-check.php` (MIN_TRAINING_SAMPLES=10, fallback chain)
- Training data loader: `balrog-blocker/includes/class-training-data.php`
- Naive Bayes: `balrog-blocker/includes/class-naive-bayes.php`
- Script detector: `balrog-blocker/includes/class-script-detector.php`
- Tests: `tests/BayesianCheckTest.php`, `tests/TrainingDataTest.php`, `tests/NaiveBayesTest.php`
- Current state: 5 scripts empty (greek, hebrew, thai, bengali, indic); existing scripts have 35-117 samples; combined has 20 spam / 51 ham

## Development Approach

- **Testing approach**: Regular (code first, then tests)
- Complete each task fully before moving to the next
- **CRITICAL: every task MUST include new/updated tests**
- **CRITICAL: all tests must pass before starting next task**

## Implementation Steps

### Task 1: Populate 5 empty script groups (greek, hebrew, thai, bengali, indic)

**Files:**
- Modify: `balrog-blocker/data/spam-samples-greek.txt` (currently empty, fill to 100+)
- Modify: `balrog-blocker/data/ham-samples-greek.txt` (currently empty, fill to 100+)
- Modify: `balrog-blocker/data/spam-samples-hebrew.txt` (currently empty, fill to 100+)
- Modify: `balrog-blocker/data/ham-samples-hebrew.txt` (currently empty, fill to 100+)
- Modify: `balrog-blocker/data/spam-samples-thai.txt` (currently empty, fill to 100+)
- Modify: `balrog-blocker/data/ham-samples-thai.txt` (currently empty, fill to 100+)
- Modify: `balrog-blocker/data/spam-samples-bengali.txt` (currently empty, fill to 100+)
- Modify: `balrog-blocker/data/ham-samples-bengali.txt` (currently empty, fill to 100+)
- Modify: `balrog-blocker/data/spam-samples-indic.txt` (currently empty, fill to 100+)
- Modify: `balrog-blocker/data/ham-samples-indic.txt` (currently empty, fill to 100+)

- [x] Generate 100+ realistic spam samples in Greek (gambling, pharma, SEO spam patterns)
- [x] Generate 100+ realistic ham samples in Greek (normal blog comments, questions, opinions)
- [x] Generate 100+ spam and 100+ ham samples in Hebrew
- [x] Generate 100+ spam and 100+ ham samples in Thai
- [x] Generate 100+ spam and 100+ ham samples in Bengali
- [x] Generate 100+ spam and 100+ ham samples in Indic (Hindi/Marathi/Gujarati mix for the indic group)
- [x] Verify each file has 100+ lines with `wc -l`
- [x] Verify script detection: spot-check that samples are detected as the correct script group
- [x] Run test suite

### Task 2: Expand existing script groups to 100+ samples each

**Files:**
- Modify: `balrog-blocker/data/spam-samples-arabic.txt` (35 -> 100+)
- Modify: `balrog-blocker/data/ham-samples-arabic.txt` (36 -> 100+)
- Modify: `balrog-blocker/data/spam-samples-devanagari.txt` (35 -> 100+)
- Modify: `balrog-blocker/data/ham-samples-devanagari.txt` (36 -> 100+)
- Modify: `balrog-blocker/data/spam-samples-cjk.txt` (53 -> 100+)
- Modify: `balrog-blocker/data/ham-samples-cjk.txt` (60 -> 100+)
- Modify: `balrog-blocker/data/spam-samples-cyrillic.txt` (80 -> 100+)
- Modify: `balrog-blocker/data/ham-samples-cyrillic.txt` (82 -> 100+)
- Modify: `balrog-blocker/data/spam-samples-latin.txt` (117 -> keep or expand slightly)
- Modify: `balrog-blocker/data/ham-samples-latin.txt` (117 -> keep or expand slightly)

- [x] Add Arabic spam samples to reach 100+, add Arabic ham samples to reach 100+
- [x] Add Devanagari spam and ham samples to reach 100+
- [x] Add CJK spam and ham samples to reach 100+
- [x] Add Cyrillic spam and ham samples to reach 100+
- [x] Latin already at 117 -- verify balance, top up ham if needed
- [x] Verify all files have 100+ lines and spam/ham counts are balanced (within 10% ratio)
- [x] Run test suite

### Task 3: Balance and expand combined classifier data

**Files:**
- Modify: `balrog-blocker/data/spam-samples.txt` (20 -> 100+)
- Modify: `balrog-blocker/data/ham-samples.txt` (51 -> 100+)

- [x] Add multilingual spam samples to combined spam file to reach 100+ (mix of scripts to represent combined fallback use case)
- [x] Add multilingual ham samples to combined ham file to reach 100+ (balanced with spam count)
- [x] Verify combined files are balanced: spam count roughly equals ham count
- [x] Run test suite

### Task 4: Add fallback behavior tests

**Files:**
- Modify: `tests/BayesianCheckTest.php`
- Modify: `tests/TrainingDataTest.php`

- [x] Test: empty script-specific data falls back to combined classifier (comment in greek with no greek data but combined has data -> uses combined)
- [x] Test: combined classifier with fewer than MIN_TRAINING_SAMPLES returns "insufficient training data"
- [x] Test: combined classifier trained from all script files produces correct doc counts
- [x] Test: heavily imbalanced training data (90% ham / 10% spam) biases classifier toward ham
- [x] Test: per-script classifier with exactly MIN_TRAINING_SAMPLES in each category activates (boundary test)
- [x] Test: script with data below MIN_TRAINING_SAMPLES falls back to combined even when script file is non-empty
- [x] Run test suite

### Task 5: Verify acceptance criteria

- [x] Run full test suite: `docker compose run --rm test`
- [x] Verify all 10 script groups have 100+ spam and 100+ ham samples
- [x] Verify combined files have 100+ balanced samples
- [x] Verify all new fallback tests pass
- [x] Verify no existing tests broken

### Task 6: Update documentation

- [x] Update CLAUDE.md if data volume conventions changed (no update needed - structure unchanged, only sample counts increased)
- [x] Move this plan to `docs/plans/completed/`
