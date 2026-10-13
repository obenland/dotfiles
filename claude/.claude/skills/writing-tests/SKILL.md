---
name: writing-tests
description: Use before writing, adding, or changing PHPUnit tests, including "add tests for this change" and test-coverage requests. Decides whether a test is warranted, requires each test to name the regression it catches, defaults to no mocks and a happy-path-first test set, runs static checks for low-value test patterns, and self-corrects before reporting.
allowed-tools: Bash Read Grep Glob Edit Write
---

# Writing Tests

Every test runs on every CI run, forever. A low-value test costs queue time on every change and makes the suite harder to read and trust. Most of the waste is duplication and cloned templates, reflection into private methods, trivial existence or constant checks, tests with no real assertion or that only verify mock wiring, swallowed failures, and tests that grep production source.

Your job is to write the **fewest tests that would catch a realistic regression**. Writing zero tests can be the right result.

This skill covers *what* to write. Run tests with the project's own command (check its CLAUDE.md / AGENTS.md, `composer.json` and `package.json` scripts, or `wp-env`).

If a TDD skill is also active, it decides the order (regression test first, watch it fail). This skill still decides whether a test is warranted and what shape it takes.

## 1. Decide whether a test is warranted

**Project rules come first on whether to test.** Read the nearest CLAUDE.md / AGENTS.md / contributing guide first. If it requires tests (a coverage minimum, "always add tests"), that rule decides **whether** you test. This skill still decides **how**: no smells, data providers instead of clones, behavior instead of internals.

**Otherwise, zero new tests is a valid outcome** for:
- wiring (hook registration, loader `require`s, DI bindings) already exercised by a behavioral test;
- config, copy, string, or translation changes;
- registry or data entries (a new row, block, ability, or email definition) when a suite-wide invariant test already covers every row;
- trivial delegation or pass-through with no branching.

When you add no tests, write a one-line reason for the PR description or commit message (see the report template below). If the project expects tests on every change, an explicit, reasoned "no test" line is how you answer that. Don't skip the line, and don't add filler tests to satisfy it.

## 2. Name the regression (required for every test)

Before you keep any test, write one sentence naming **a realistic code change that would make this test fail and that no existing test already catches.**

1. Find the suite: the `<testsuite>` directories in the project's `phpunit.xml(.dist)`.
2. Search what already exists. Grep the suite for the class or function under test, and for the test method names you plan to use:
   ```bash
   grep -rn "<ClassOrFunction>" <suite-dir>/
   grep -rn "function test_<planned_name>" <suite-dir>/
   ```
3. Write the sentence. Good: "If `normalize_x()` stopped casting `null` to `''`, the hook callback would fatal with a `TypeError`." Bad: "Verifies the class works." Also bad: "Ensures the constant equals 8."

**No sentence, no test.** If an existing test already catches the regression, extend that test or leave it alone. Treat a new test as redundant only when another executed test protects the same production path with an equal or stronger assertion.

### Choose what to cover

Cover the **happy path**, plus usually one to three **high-impact flows**. A flow is high impact when it is one of these:
- a failure that loses data, money, access, or security;
- the bug being fixed;
- the main error result a caller actually branches on.

Edge cases add bloat. Skip boundary, empty-input, and odd-format variants unless one of them is the bug being fixed or a project rule requires it.

## 3. Write it well

- **Test public behavior and outputs, not internals.** Don't use `ReflectionMethod`/`setAccessible` to call private methods. Drive the code through its public entry point instead.
- **No mocks by default.** A test double is any of these:
  - `createMock`, `createStub`, `getMockBuilder`, or Mockery;
  - partial mocks;
  - hand-rolled fakes;
  - filters that short-circuit a call.

  Use real objects and real fixtures instead (in WordPress suites, the `factory()` for posts, users, terms and sites), or restructure the test around the public entry point. A test that mocks its dependencies mostly tests the mocks.
  - **If you believe a double is unavoidable, stop before writing it and ask the user.** Unavoidable usually means network or HTTP, a third-party API, the clock, or something that can't run in the test environment. Name the collaborator, why a real one can't be used, and what the test would lose without it.
  - **The default is no.** Without explicit approval, write the test without the double, or drop the test.
  - **In a non-interactive run, don't mock.** List the test under "Not written (needs a mock)" in the report.
  - **`pre_http_request`** (or a plugin's own request filter) is the standard way to fake outgoing HTTP in WordPress. It still needs approval. When you ask, explain two things:
    - why it is useful: tests shouldn't make real external requests, and this filter is the standard seam for avoiding them;
    - your justification: the external call it replaces and what the test asserts with it.

    Proceed only once the user approves. Then note the replaced call in the test's docblock, and give the fake response the real API's shape with specific values.
- **Use a data provider instead of near-identical methods.** One method plus a provider beats 20 `test_accepts_x`/`test_rejects_y` copies.
- **Keep providers short.** Aim for a handful of cases, roughly 3 to 6, with one case per distinct outcome.
  - Fold several edge conditions into one case when they share a branch.
  - Discard the least relevant variants.
  - When you distill a long list, check that every distinct high-impact outcome still has a case. Merging near-duplicates is where real cases get lost.
- **Write one suite-wide test for registry-wide invariants**, such as "no two rows share an id" or "every block is registered". Don't copy the same check into every row's or block's test file.
- **When fixing a bug, write the regression test first** and confirm it fails without the fix.
- **For computed or derived output, assert the exact expected value**, not only shape, type, count, or non-null. For example, use `assertSame( 'settings', $result['page'] )`, not `assertArrayHasKey( 'page', $result )`.
- **Extend an existing test or file before adding a new one.**
- **Never catch and skip real failures.** `markTestSkipped` belongs only in environment guards that run before the code under test.

Detailed smells, examples, and rewrites: [references/anti-patterns.md](references/anti-patterns.md).

## 4. Run the static checks (agent-only, not for CI)

```bash
php ~/.claude/skills/writing-tests/scripts/check-tests.php --repo=<working-copy>            # new/changed test lines
php ~/.claude/skills/writing-tests/scripts/check-tests.php --repo=<working-copy> --pretty   # human-readable JSON
# Options: --base=<ref>  --files=a.php,b.php  --files-from=<list>  --all-lines  --phpcs=<path>
```

Pass the working copy with `--repo` instead of `cd`-ing into it. What counts as "changed":
- **git:** the diff against the merge-base with the default branch (`origin/HEAD`, else `origin/trunk`, `origin/main`, `origin/master`, `trunk`, `main`, `master`), plus uncommitted and untracked files. Pass `--base=<ref>` to override.
- **SVN:** local modifications (`svn diff`) plus unversioned files. `--base` is ignored.

A test file is any PHP file in a `tests/` directory or in a `<testsuite>` directory of the nearest `phpunit.xml(.dist)` that is named like a test (`FooTest.php`, `test-foo.php`, …) or declares test methods.

Requirements: PHP 8.0+, and PHP_CodeSniffer 3.7+ or 4.x. phpcs is taken from the nearest `vendor/bin/phpcs`, then `bin/phpcs` at the repo root, then `phpcs` on PATH. The bundled `TestValue` standard is passed by path, so nothing has to be installed into the project.

The output is JSON: `{base, mode, files, findings: [{file, line, method, check, source, severity, message, related, related_count}], summary, stats: {test_files_changed, new_test_methods, production_lines_changed, new_suites}, errors}`.

Checks (phpcs standard `TestValue`, plus the cross-file duplicate detector):

| Check | Flags |
|---|---|
| `TestValue.PHPUnit.NoMeaningfulAssertion` | No assertions; only trivially true ones like `assertTrue(true)` is `.TrivialAssertion` (hint) |
| `TestValue.PHPUnit.ExistenceAssertion` | `class_exists` / `method_exists` / `function_exists` as the claim |
| `TestValue.PHPUnit.PrivateReflection` | Reflection that calls a private/protected method or reads private state to assert on it |
| `TestValue.PHPUnit.ConstantRestatement` | Asserting that a constant equals its literal value |
| `TestValue.PHPUnit.SourceTextAssertion` | `file_get_contents` on production source and then matching text |
| `TestValue.PHPUnit.SwallowedFailure` | `markTestSkipped`/`markTestIncomplete` inside a `catch`, or as an unconditional first statement |
| `TestValue.PHPUnit.MockOnlyVerification` (hint) | Only claim is `expects()->method()` on a mock |
| `TestValue.PHPUnit.ExcessiveMocks` (hint) | Many collaborators mocked in one test |
| `TestValue.PHPUnit.SnapshotHash` | `md5(serialize(...))`-style fingerprint pins |
| `TestValue.PHPUnit.NearDuplicateMethods` | Near-identical methods in one class (use a data provider) |
| `Duplicates.SameNameInSuite` / `Duplicates.SameBodyInSuite` | Same test name (with a similar body) or same body cloned across files in a suite |

**If `errors` is non-empty, tell the user.** Don't silently skip the check. On an internal error (bad option, missing base ref, not a working copy) the script exits 2 and prints only `{"errors": [...]}`.

## 5. Self-correct before reporting

For **tests you wrote in this session**, act on every finding:

1. **Delete** it if you can't name the regression it catches.
2. **Merge** it: near-duplicates go into a data provider, and per-row or per-block clones become one suite-wide test.
3. **Rewrite** it against public behavior: drop the reflection, assert real values, remove the catch/skip.
4. **Remove** any test double the user did not approve, and trim provider cases and edge-case tests that go beyond "Choose what to cover".
5. **Keep it only with a specific reason**: `// phpcs:ignore TestValue.PHPUnit.<Sniff> -- <specific reason>`. Method-level checks (`NoAssertions`, `MockOnlyVerification`, `ExcessiveMocks`, `NearDuplicateMethods`) report on the `function` line: put the comment directly above `public function test_...()` (after the docblock and attributes) or at the end of that line. Other checks: on or above the flagged line. "Needed" is not a reason. "Regression for #1234: the hook fataled with a TypeError; reaching the end is the assertion" is. `Duplicates.*` findings can't be ignored inline, so justify them in your report.

Re-run the checks until they are clean or every remaining finding is justified. Then check `stats`. If `new_test_methods` is large compared with `production_lines_changed`, you are probably testing one change many times. Re-examine.

**Don't edit pre-existing tests you didn't write** unless the user asks. If you notice something serious nearby, mention it briefly in the report.

## 6. Report

Use this template in your final message:

```
### Tests
**Kept**
- `FooTest::test_bar` — catches: <realistic change that would fail it, not caught elsewhere>

**Dropped / merged**
- `test_x`, `test_y` → merged into `test_z` data provider (near-duplicates)
- `test_class_exists` — dropped: existence check, covered by behavioral tests

**Mocks**
- none | `FooTest` — `pre_http_request` fakes <external call>; approved because <justification the user approved>

**Not written (needs a mock)**
- `FooTest::test_baz` — would need a double for <collaborator>; not approved

**Remaining findings (justified)**
- `FooTest.php:42` TestValue.PHPUnit.SourceTextAssertion — <why no runtime alternative exists>

**Static check errors:** none | <errors[] verbatim>

**Suggested "Testing" line** (when no tests were added):
No unit tests: change is a registry entry; covered by RegistryInvariantsTest, which asserts all rows.
```

Then run the tests with the project's test command.

## Maintaining the checks

The sniffs live in `standards/TestValue/` with a bad (`-1.inc`) and good (`-2.inc`) fixture per sniff. To run their tests (PHPUnit from the global Composer install; `PHPCS_DIR` picks a specific PHP_CodeSniffer checkout, otherwise the global Composer one is used):

```bash
~/.composer/vendor/bin/phpunit -c ~/.claude/skills/writing-tests/standards/phpunit.xml.dist
php ~/.claude/skills/writing-tests/scripts/check-tests.php --self-test
php ~/.claude/skills/writing-tests/scripts/find-duplicate-tests.php --self-test
```

The skill's own PHP follows `phpcs.xml.dist` (WordPress standard): `phpcs --standard="$HOME/.claude/skills/writing-tests/phpcs.xml.dist"`.
