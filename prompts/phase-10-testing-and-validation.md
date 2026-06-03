# Phase 10: Testing & Validation - Professional Copilot Prompt

## 🎯 Objective
Achieve comprehensive, reliable test coverage (target 80%+) proving authentication, RBAC, multi-tenant isolation, audit logging, and N+1-free queries — plus model factories and a performance baseline.

> **Depends on:** All prior phases. Tests are the final correctness gate before docs/deploy.

## 📋 Implementation Requirements

### 10.1 Test Environment
`phpunit.xml` — use an isolated SQLite (or a dedicated MySQL test DB) and array drivers:
```xml
<php>
    <env name="APP_ENV" value="testing"/>
    <env name="DB_CONNECTION" value="sqlite"/>
    <env name="DB_DATABASE" value=":memory:"/>
    <env name="CACHE_STORE" value="array"/>
    <env name="QUEUE_CONNECTION" value="sync"/>
    <env name="MAIL_MAILER" value="array"/>
</php>
```
> If your migrations use MySQL-only features (e.g. `enum`), run tests against a real MySQL test database instead of SQLite.

### 10.2 Model Factories
`database/factories/CompanyFactory.php`:
```php
public function definition(): array
{
    return [
        'name'  => fake()->unique()->company(),
        'email' => fake()->unique()->companyEmail(),
    ];
}
```
`database/factories/UserFactory.php`:
```php
public function definition(): array
{
    return [
        'company_id' => Company::factory(),
        'name'       => fake()->name(),
        'email'      => fake()->unique()->safeEmail(),
        'password'   => bcrypt('password'),
        'role'       => 'Employee',
    ];
}

public function admin(): static   { return $this->state(fn () => ['role' => 'Admin']); }
public function manager(): static { return $this->state(fn () => ['role' => 'Manager']); }
```
`database/factories/ExpenseFactory.php`:
```php
public function definition(): array
{
    return [
        'company_id' => Company::factory(),
        'user_id'    => User::factory(),
        'title'      => fake()->sentence(3),
        'amount'     => fake()->randomFloat(2, 1, 5000),
        'category'   => fake()->randomElement(['Travel', 'Food', 'Office', 'Software']),
    ];
}
```

### 10.3 AuthTest
`tests/Feature/AuthTest.php`:
```php
<?php

use App\Models\User;
use function Pest\Laravel\postJson;   // or use PHPUnit's $this->postJson

it('registers a company + admin user and returns a token', function () {
    $response = postJson('/api/register', [
        'name' => 'Owner', 'email' => 'owner@acme.test',
        'password' => 'password123', 'password_confirmation' => 'password123',
        'company_name' => 'Acme', 'company_email' => 'hq@acme.test',
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'role']]]);

    expect(User::where('email', 'owner@acme.test')->first()->role)->toBe('Admin');
});

it('logs in with valid credentials', function () {
    $user = User::factory()->create(['password' => bcrypt('secret123')]);
    postJson('/api/login', ['email' => $user->email, 'password' => 'secret123'])
        ->assertOk()->assertJsonPath('success', true);
});

it('rejects invalid credentials with 422', function () {
    $user = User::factory()->create();
    postJson('/api/login', ['email' => $user->email, 'password' => 'wrong'])
        ->assertStatus(422);
});
```
> PHPUnit equivalent: extend `Tests\TestCase`, `use RefreshDatabase;`, and write `public function test_...()` methods calling `$this->postJson(...)`.

### 10.4 ExpenseTest (RBAC + isolation + audit + N+1)
`tests/Feature/ExpenseTest.php`:
```php
<?php

use App\Models\{Company, Expense, User, AuditLog};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('lets an employee create an expense scoped to their company', function () {
    $employee = User::factory()->create(['role' => 'Employee']);
    Sanctum::actingAs($employee);

    $this->postJson('/api/expenses', ['title' => 'Taxi', 'amount' => 20, 'category' => 'Travel'])
        ->assertCreated();

    $this->assertDatabaseHas('expenses', [
        'title' => 'Taxi', 'company_id' => $employee->company_id, 'user_id' => $employee->id,
    ]);
});

it('forbids an employee from updating expenses', function () {
    $employee = User::factory()->create(['role' => 'Employee']);
    $expense  = Expense::factory()->create(['company_id' => $employee->company_id]);
    Sanctum::actingAs($employee);

    $this->putJson("/api/expenses/{$expense->id}", ['amount' => 99])->assertForbidden();
});

it('forbids a manager from deleting expenses', function () {
    $manager = User::factory()->create(['role' => 'Manager']);
    $expense = Expense::factory()->create(['company_id' => $manager->company_id]);
    Sanctum::actingAs($manager);

    $this->deleteJson("/api/expenses/{$expense->id}")->assertForbidden();
});

it('blocks cross-company access (returns 404)', function () {
    $userA   = User::factory()->create();
    $otherCo = Company::factory()->create();
    $foreign = Expense::factory()->create(['company_id' => $otherCo->id]);
    Sanctum::actingAs($userA);

    $this->getJson("/api/expenses/{$foreign->id}")->assertNotFound();
});

it('writes an audit log with old + new values on update', function () {
    $admin   = User::factory()->create(['role' => 'Admin']);
    $expense = Expense::factory()->create(['company_id' => $admin->company_id, 'amount' => 100]);
    Sanctum::actingAs($admin);

    $this->putJson("/api/expenses/{$expense->id}", ['amount' => 150])->assertOk();

    $log = AuditLog::where('model_id', $expense->id)->where('action', 'update')->first();
    expect($log)->not->toBeNull()
        ->and($log->changes['old']['amount'])->toEqual(100)
        ->and($log->changes['new']['amount'])->toEqual(150);
});

it('lists expenses without N+1 queries', function () {
    $user = User::factory()->create();
    Expense::factory()->count(25)->create(['company_id' => $user->company_id, 'user_id' => $user->id]);
    Sanctum::actingAs($user);

    \DB::enableQueryLog();
    $this->getJson('/api/expenses')->assertOk();
    // expenses + users + companies + count = small, constant set (not ~25)
    expect(count(\DB::getQueryLog()))->toBeLessThan(8);
});
```

### 10.5 UserTest (Admin-only)
`tests/Feature/UserTest.php` — assert that:
- Employee/Manager → 403 on `index`, `store`, `update`, `destroy`.
- Admin can create a user (and `SendWelcomeEmail` is queued — `Queue::fake(); Queue::assertPushed(SendWelcomeEmail::class);`).
- Duplicate email within the same company → 422; same email in another company → allowed.
- Updating to an invalid role → 422.

### 10.6 AuditLogTest & Scheduled Job Test
- `AuditLogTest`: deleting an expense records a `delete` action with the prior values; audit API is Admin-only.
- `ReportJobTest`:
```php
Mail::fake();
(new SendWeeklyExpenseReport())->handle();
Mail::assertSent(WeeklyExpenseReportMail::class);   // only to admins, per company
```

### 10.7 Performance Validation
```bash
# Seed a large dataset and confirm constant query count + acceptable latency
php artisan db:seed --class=ExpenseStressSeeder      # 1000+ rows
ab -n 200 -c 10 -H "Authorization: Bearer TOKEN" http://localhost:8000/api/expenses
```
Document results (req/s, p95 latency, query count) in `docs/PERFORMANCE.md`.

## 🔍 Quality Gates

### Before Moving to Phase 11
1. ✅ `php artisan test` — **all green**.
2. ✅ **Coverage ≥ 80%** — `php artisan test --coverage --min=80`.
3. ✅ **Every RBAC rule has a passing 403 test**.
4. ✅ **Cross-company isolation proven** by test, not assumed.
5. ✅ **N+1 assertion passes** on the list endpoint.

## 🚀 Validation Commands
```bash
php artisan test
php artisan test --coverage --min=80
php artisan test --filter=ExpenseTest
php artisan test --parallel        # faster suite
```

## 📝 Expected File Structure
```
tests/Feature/AuthTest.php
tests/Feature/ExpenseTest.php
tests/Feature/UserTest.php
tests/Feature/AuditLogTest.php
tests/Feature/ReportJobTest.php
database/factories/CompanyFactory.php
database/factories/UserFactory.php
database/factories/ExpenseFactory.php
database/seeders/ExpenseStressSeeder.php
phpunit.xml
docs/PERFORMANCE.md
```

## ⚠️ Critical Implementation Notes
1. **`RefreshDatabase`** on every feature test for a clean, isolated DB per test.
2. **`Sanctum::actingAs($user)`** to authenticate without hitting the login endpoint.
3. **Fake side effects** — `Queue::fake()`, `Mail::fake()` — so jobs/mail are asserted, not actually sent.
4. **Test the negative paths** — 401/403/404/422 are where multi-tenant bugs hide.
5. **Assert audit content**, not just row existence — verify `old`/`new` values.
6. **N+1 test via `DB::getQueryLog()`** turns a perf goal into an enforceable assertion.

## 🎯 Success Criteria
✅ Auth, RBAC, isolation, audit, and N+1 all covered by passing tests
✅ 80%+ coverage achieved
✅ Factories + stress seeder in place
✅ Performance baseline documented
✅ Ready for Phase 11: Documentation & Deployment
