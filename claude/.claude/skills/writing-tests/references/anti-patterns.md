# Test anti-patterns

This is the reference for the `writing-tests` skill. Each section covers one static check: the smell, why it is waste, and what to do instead. The examples are illustrative, modeled on real tests found when the checks were calibrated.

Citing a pattern does not mean existing tests that match it should be edited. Don't touch pre-existing tests unless the user asks.

**Where a `phpcs:ignore` goes.** Method-level checks (`NoMeaningfulAssertion.NoAssertions`, `MockOnlyVerification`, `ExcessiveMocks`, `NearDuplicateMethods`) report on the `function` line. Put `// phpcs:ignore TestValue.PHPUnit.<Sniff> -- <reason>` on the line directly above `public function test_...()`, after the docblock and attributes, or at the end of the `function` line. A comment above the docblock does not suppress anything. Line-level checks report on the flagged statement, so the comment goes on that line or the line above it.

---

## NoMeaningfulAssertion

**Smell:** The test has no assertion, or only `assertTrue( true )`, or a comment claims behavior the assertions never check.

**Why it's waste:** The test only proves the code didn't throw, and it still reads as if it covers behavior. Nothing would fail if the code under test became a no-op.

```php
public function test_record_is_noop_when_overestimated() {
    // Negative delta — the bucket should be left alone, not decremented.
    Rate_Limiter::record( 'user:' . wp_generate_uuid4(), 500, 100 );
    $this->assertTrue( true );
}
```
The comment says the bucket stays untouched, but the test never reads the bucket.

**Do instead:** Assert the observable effect.
```php
public function test_overestimate_leaves_remaining_budget_unchanged() {
    $key    = 'user:' . wp_generate_uuid4();
    $before = Rate_Limiter::remaining( $key );
    Rate_Limiter::record( $key, 500, 100 );
    $this->assertSame( $before, Rate_Limiter::remaining( $key ) );
}
```
If there is no public way to observe the effect, ask whether this test belongs at a higher level, or whether it should exist at all.

Only-`assertTrue( true )` tests report as `.TrivialAssertion` at hint severity: during calibration most of them were deliberate "does not throw" tests of guards, validators and swallow-and-continue code. Act on the hint when the name or a comment claims an effect (no-op, skips, calls X) that is never checked.

**Legitimate exception:** a regression test whose bug was a fatal error or `TypeError`, where reaching the end really is the assertion. Prefer `$this->expectNotToPerformAssertions()` (or `@doesNotPerformAssertions`), which states the contract and is not flagged. Otherwise keep the test with a reasoned ignore, for example `// phpcs:ignore TestValue.PHPUnit.NoMeaningfulAssertion -- #1234 regression: the hook fataled with a TypeError; completion is the assertion.` Place it above the `function` line for `.NoAssertions`, or above the `assertTrue( true )` line for `.TrivialAssertion`.

---

## ExistenceAssertion

**Smell:** `assertTrue( class_exists( ... ) )`, `method_exists`, or `function_exists` used as the test's claim.

**Why it's waste:** Any behavioral test of the class already fails loudly if the class is missing. An existence check catches nothing extra and has to be updated on every rename.

```php
public function test_loader_loads_all_classes(): void {
    $this->assertTrue( class_exists( 'My_Plugin_Exception' ) );
    $this->assertTrue( class_exists( 'My_Plugin_Db' ) );
    $this->assertTrue( method_exists( My_Plugin_Cli::class, 'inspect' ) );
}
```

**Do instead:** Delete the check and rely on the behavioral tests that call those classes. If what matters is that a loader includes a file, test something the loader makes observable, for example that the CLI command is registered and runs a subcommand.

---

## PrivateReflection

**Smell:** `new ReflectionMethod( X::class, 'private_thing' )` followed by `setAccessible( true )` and `invoke`, or a private property read with `getValue()` inside an assertion. Writing a private property with `setValue()` to inject a double or reset a cache is a test seam and is not flagged.

**Why it's waste:** It ties the test to the implementation's layout. Renaming or inlining a private helper breaks the test even though behavior is unchanged, and the public path that real callers use goes untested.

```php
$method = new ReflectionMethod( Styles_Cache::class, 'resolve_style_value' );
$method->setAccessible( true );
$this->assertSame( '#ffffff', $method->invoke( null, '#ffffff', '#000000' ) );
```
Typically repeated across several methods, one per branch of the private helper.

**Do instead:** Drive the public entry point (here, the cache getter) with inputs that reach each branch, and use a data provider for the cases. If a private helper really is complex enough to need direct tests, extract it into its own small class with a public method.

---

## ConstantRestatement

**Smell:** `assertSame( 8, Foo::MAX_ROUNDS )`, which writes the constant's value a second time.

**Why it's waste:** Every intentional change needs two edits, and no unintentional change is caught that a behavioral test wouldn't also catch.

```php
public function test_cap_constants_match_documented_values() {
    $this->assertSame( 8, Agent::MAX_ROUNDS );
    $this->assertSame( 16, Agent::MAX_TOOLS );
}
```

**Do instead:** Test the behavior the constant controls, for example that the agent stops after `MAX_ROUNDS` rounds with stop reason `max_rounds`.

**Nuance:** Constants that form an **external wire contract** (enum strings a client parses) can justify one test. Test that contract where it leaves the system: assert that the serialized response contains `"stop_reason":"max_rounds"`. Don't just compare the constant with itself.

---

## SourceTextAssertion

**Smell:** `file_get_contents()` on a production file, then `assertStringContainsString` or a regex against its text.

**Why it's waste:** The test checks spelling, not behavior. A comment can satisfy it, and a harmless refactor or reformat breaks it.

```php
$source = file_get_contents( WP_PLUGIN_DIR . '/my-plugin/bin/activate.php' );
$this->assertStringContainsString( '$status = Activation_Gate::governing_status(', $source );
```

Sometimes a runtime test really is impractical, for example when the script does its work on include and the shared test process hides a missing `require`. A docblock explaining exactly that is what a justified `phpcs:ignore` looks like.

**Do instead:** Move the logic behind a seam you can call, such as a function the script calls, and test that function. If you can't do that in this change, keep the source pin with `// phpcs:ignore TestValue.PHPUnit.SourceTextAssertion -- <why runtime is impossible>` and scope the match as tightly as possible.

---

## SwallowedFailure

**Smell:** `try { ...assertions... } catch ( \Throwable $e ) { markTestSkipped(...) }`, or skipping because the *code under test* returned an error.

**Why it's waste:** PHPUnit's `AssertionFailedError` is itself a `Throwable`, so a real failure gets reported as a skip. CI stays green even when the code is broken.

```php
try {
    $a = Bootstrap::get_instance();
    $b = Bootstrap::get_instance();
    $this->assertSame( $a, $b );
} catch ( \Throwable $e ) {
    $this->markTestSkipped( 'Bootstrap dependencies unavailable: ' . $e->getMessage() );
}
```
A subtler variant: skipping when `execute()` returns a `WP_Error`, which is exactly the failure the test should catch, often combined with relying on whatever data the environment happens to have.

**Do instead:** Put environment guards *before* the code under test and keep them narrow, for example `if ( ! extension_loaded( 'intl' ) ) { $this->markTestSkipped(...); }`. Let errors from the code under test fail the test. Create the fixture the test needs (posts, users, options, a site) with the factory rather than relying on ambient data. To fake outgoing HTTP, use `pre_http_request`, but explain why to the user and get approval first. Any other double needs approval too.

---

## MockOnlyVerification (hint)

**Smell:** The test's only claim is `$mock->expects( $this->once() )->method( 'x' )->with( ... )`, with nothing asserted about outputs or state.

**Why it's waste:** It restates the implementation's call sequence. Refactors that keep the behavior the same break it, and a wrong result can still pass it if the call still happens.

Typical shape: six methods that each mock a context object, stub its getter, and assert that `add_client_context()` is called (or not) with `[ 'page' => ... ]`.

This is a hint: when the outgoing call *is* the behavior (an HTTP request, an email, a "never called" guard, memoization), an expectation is the right assertion. It is most often waste when the mock is a partial mock of the class under test.

**Do instead:** Use a real (or simple fake) context object and assert its resulting state: `$this->assertSame( 'settings', $context->get_client_context()['page'] )`. Collapse the variants into one short data provider of `input → expected page (or absent)`. Keeping a mock, even when the call is the behavior, needs the user's approval.

---

## ExcessiveMocks (hint)

**Smell:** One test builds many mocked collaborators, often together with reflection to reach a private method.

**Why it's waste:** The test mostly checks how the mocks are wired together. It is brittle when constructors change and says little about real behavior. Treat it as a hint to reconsider the test level or the design, not as an automatic rejection.

Typical shape: a `make_service()` helper that builds the service under test from eight mocked collaborators (storage, an API client, a rule engine, config, stats, …), then calls a private method through `ReflectionMethod`.

**Do instead:** Use real collaborators where they are cheap (value objects, config, pure computers), and call the public method that leads to the private one. Ask the user before doubling any storage or network edge. The default is no double.

---

## SnapshotHash

**Smell:** `assertSame( '<hash>', md5( serialize( SOME_CONSTANT ) ) )`.

**Why it's waste:** It fails on every change, says nothing about *what* changed, and the obvious "fix" is to paste the new hash, so it trains people to ignore it.

```php
$this->assertSame(
    '5290df1b1551e5b6f3af68673e0df5b5',
    md5( serialize( Metric_Catalog::METRICS ) ),
    'The metric catalog changed. If the change is deliberate, update this hash.'
);
```
The intent is often valid, for example "existing metric names must keep producing the same query".

**Do instead:** Assert the actual invariant. For example, a data provider of `name => expected query parameters` for the names that must stay stable, plus a structural check on every entry. Adding an entry then needs no edit, and changing an existing one fails with a readable diff.

---

## NearDuplicateMethods

**Smell:** Many methods in one class with the same body except for the input and expected value.

**Why it's waste:** It inflates the method count and the suite's runtime overhead, hides which cases are actually different, and invites copy-paste drift.

Typical shape: dozens of `test_accepts_*` / `test_rejects_*` methods in one validator test.
```php
public function test_rejects_oversized_rule_id(): void {
    $this->expectException( Validation_Exception::class );
    $this->expectExceptionCode( 400 );
    $this->validator->validate_rule( array( 'rule_id' => str_repeat( 'a', 1025 ) ) );
}
```

**Do instead:** Write two methods with two providers.
```php
#[DataProvider( 'invalid_rules' )]
public function test_rejects_invalid_rule( array $rule ): void {
    $this->expectException( Validation_Exception::class );
    $this->expectExceptionCode( 400 );
    $this->validator->validate_rule( $rule );
}

public static function invalid_rules(): array {
    return array(
        'oversized rule_id' => array( array( 'rule_id' => str_repeat( 'a', 1025 ) ) ),
        'invalid phase'     => array( array( 'phase' => 'nope' ) ),
    );
}
```
Match the suite's PHPUnit version: `#[DataProvider]` needs PHPUnit 10+, the `@dataProvider` docblock tag works everywhere up to PHPUnit 11. Don't carry every case over. Keep a handful per provider, one per distinct rejection rule, and drop variants that hit the same check.

---

## Duplicates.SameNameInSuite / Duplicates.SameBodyInSuite (cross-file)

**Smell:** The same test name or body copied into many files in one suite, usually one copy per registry row, block, or ability. A shared name is only reported when the bodies are also alike (token similarity of at least 0.85, ignoring literals).

**Why it's waste:** N copies cost N times the runtime and give the same protection as one loop over the registry. A new row also gets no coverage unless someone remembers to copy the template again.

Typical shapes:
- `test_block_is_registered` in 20 files, each `assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'my-plugin/<slug>' ) )`.
- `test_no_other_row_claims_these_ids` in 25 files, each looping over the whole registry to check its own ids.

**Do instead:** Write one suite-wide invariant test.
```php
public function test_registry_ids_are_unique(): void {
    $seen = array();
    foreach ( Email_Registry::all() as $name => $def ) {
        $this->assertArrayNotHasKey( $def->id, $seen, "{$name} reuses an id already claimed by another row" );
        $seen[ $def->id ] = $name;
    }
}
```
For blocks or abilities, use one test with a provider listing the expected slugs, or one test that asserts the registered set equals the expected set. These findings can't be suppressed inline. If a duplicate really is needed, justify it in the report.

The suite is the deepest `<testsuite><directory>` from the nearest `phpunit.xml(.dist)` that contains the file; without one, the outermost `tests/` directory above it.

---

## Tautology (judgment only, no sniff)

**Smell:** The expected value is computed with the same helpers or defaulting logic as the production code.

**Why it's waste:** If the production logic is wrong, the expected value is wrong in the same way, so the test passes anyway.

```php
$prefs = get_user_meta( $user->ID, 'preferences', true );
$this->assertSame(
    isset( $prefs['colorScheme'] ) ? $prefs['colorScheme'] : 'fresh',
    $response->data['color_scheme']
);
```

**Do instead:** Set up a known state and assert literals. In one case, set `preferences` to `[ 'colorScheme' => 'midnight' ]` and expect `'midnight'`. In another, leave it unset and expect `'fresh'`.

---

## Good models

- A handful of small tests, each docblock naming the exact failure it prevents, asserting on a builder's output with no mocks.
- A value object tested through its public factory, with assertions on real values and validator behavior, and a docblock explaining why the behavior matters.
- A service tested through its public methods that asserts specific error codes, including precedence between rules, and doubles only the external API (with the user's approval under this skill).
- A repository test that doubles the DB adapter only for query-count contracts the real schema can't observe, and explicitly defers everything else to an integration test (again, only with approval).

---

## Calibration

The checks were calibrated with `check-tests.php --all-lines` over about 3,300 recently added test files (about 39,000 test methods) in a large WordPress codebase. Findings were sampled at random and judged by hand; a true positive is a test that a reviewer trying to shrink the suite would remove, merge or rewrite.

| Check | Precision | Severity |
|---|---|---|
| NoMeaningfulAssertion.NoAssertions | no hits after fixes | warning |
| NoMeaningfulAssertion.TrivialAssertion | ~35% | hint |
| ExistenceAssertion | ~95% | warning |
| PrivateReflection | ~100% | warning |
| ConstantRestatement | ~95% | warning |
| SourceTextAssertion | ~100% | warning |
| SwallowedFailure (InCatch, Unconditional) | ~100% (small sample) | warning |
| MockOnlyVerification | ~10% | hint |
| ExcessiveMocks | ~80% (small sample) | hint |
| SnapshotHash | ~100% (small sample) | warning |
| NearDuplicateMethods | ~100% as merge candidates | warning |
| Duplicates.SameNameInSuite | ~100% | warning |
| Duplicates.SameBodyInSuite | ~95% | warning |

The main false-positive patterns are handled in the checks: private `test_*()` helpers, assertions or mock expectations made inside same-class helpers, Codeception `expect()`, in-house `->expect*()` mocks, subclasses skipping a parent's data-driven test, `property_exists()` on runtime objects, reflection used only to inject a double or reset a cache, served non-PHP assets, and same-named tests with different bodies. `TrivialAssertion` and `MockOnlyVerification` stay hints: most hits are deliberate "does not throw" tests and outgoing-call tests, and no narrow rule separates them.
