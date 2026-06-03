# Multi-Tenant SaaS Expense Management API - Build Blueprint

## Project Overview

---

## 🎯 Build Phases & Critical Path

### Phase 1: Foundation (Weeks 1-2)
**Goal**: Establish database schema, models, and authentication infrastructure

#### Phase 1.1: Database Architecture
- [ ] Create `companies` migration
- [ ] Create `users` migration with `company_id` FK and role enum
- [ ] Create `expenses` migration with indexes on `company_id`, `user_id`, `created_at`
- [ ] Create `audit_logs` migration for tracking changes
- [ ] Verify all foreign key relationships and constraints

**Deliverable**: Runnable migrations with proper indexes
**Validation**: `php artisan migrate:fresh` executes without errors

---

#### Phase 1.2: Model Layer
- [ ] Create `Company` model with relationships (`hasMany Users`, `hasMany Expenses`)
- [ ] Create `User` model with relationships (`belongsTo Company`, `hasMany Expenses`)
- [ ] Add `role` enum casting on User model
- [ ] Create `Expense` model with relationships (`belongsTo Company`, `belongsTo User`)
- [ ] Create `AuditLog` model for recording changes
- [ ] Create model scopes:
  - `User::forCompany($companyId)` - scope by company
  - `Expense::forCompany($companyId)` - scope by company
  - `AuditLog::forCompany($companyId)` - scope by company

**Deliverable**: All models with correct relationships and scopes
**Validation**: Tinker test: `Company::with('users', 'expenses')->first()` returns correctly structured data

---

#### Phase 1.3: Authentication Infrastructure
- [ ] Configure Laravel Sanctum (`php artisan vendor:publish --provider=Laravel\\Sanctum\\SanctumServiceProvider`)
- [ ] Add `personal_access_tokens` table migration
- [ ] Create `AuthController` with:
  - `register()` - Create company and admin user
  - `login()` - Issue Sanctum token
  - `logout()` - Revoke token
- [ ] Create middleware:
  - `CompanyScope` - Auto-filter queries by authenticated user's company
  - `CheckRole` - Validate user role matches route requirement
- [ ] Add middleware to `app/Http/Kernel.php`

**Deliverable**: Working authentication flow
**Validation**: 
- POST `/api/register` creates company + user + token
- POST `/api/login` returns token
- Authenticated requests include company_id in Authorization header

---

### Phase 2: Core API Implementation (Weeks 3-4)
**Goal**: Implement all CRUD endpoints with proper authorization and optimization

#### Phase 2.1: Expense Management Endpoints
- [ ] `ExpenseController@index()` - List expenses with:
  - Pagination (default 15 per page)
  - Eager loading: `with('user', 'company')`
  - Search by title/category
  - Filter by date range
  - Respects company scoping
- [ ] `ExpenseController@show()` - Get single expense with relationships
- [ ] `ExpenseController@store()` - Create expense:
  - Validate input (FormRequest)
  - Auto-assign logged-in user
  - Auto-assign company_id from auth user
  - Log to audit_logs
- [ ] `ExpenseController@update()` - Update expense:
  - Manager and Admin only
  - Log changes (old vs new) to audit_logs
  - Validate authorization (policy)
- [ ] `ExpenseController@destroy()` - Delete expense:
  - Admin only
  - Log deletion to audit_logs
  - Soft delete recommended

**Deliverable**: Fully functional expense CRUD with authorization
**Validation**: Feature tests verify all endpoints respect company isolation

---

#### Phase 2.2: User Management Endpoints (Admin Only)
- [ ] `UserController@index()` - List users:
  - Admin only
  - Pagination
  - Filter by role
  - Eager load company
- [ ] `UserController@store()` - Create user:
  - Admin only
  - Validate unique email per company
  - Hash password
  - Send welcome email (async job)
- [ ] `UserController@update()` - Update user role:
  - Admin only
  - Validate role enum
  - Log role changes
- [ ] `UserController@destroy()` - Delete user:
  - Admin only
  - Cascade delete or soft delete

**Deliverable**: User management endpoints with proper authorization
**Validation**: Non-admin users get 403 Forbidden on all endpoints

---

#### Phase 2.3: Authorization & Policies
- [ ] Create `ExpensePolicy`:
  - `view()` - Owner or Manager/Admin in same company
  - `update()` - Manager/Admin in same company
  - `delete()` - Admin only in same company
- [ ] Create `UserPolicy`:
  - `view()` - Same company or Admin
  - `update()` - Admin only
  - `delete()` - Admin only
- [ ] Register policies in `AuthServiceProvider`
- [ ] Add `@can` checks in controllers

**Deliverable**: Fine-grained authorization using Laravel policies
**Validation**: Attempt cross-company access returns 403

---

### Phase 3: Optimization & Caching (Week 5)
**Goal**: Implement query optimization and Redis caching

#### Phase 3.1: Query Optimization
- [ ] Add indexes in expense migration:
  ```php
  $table->index('company_id');
  $table->index('user_id');
  $table->index('created_at');
  ```
- [ ] Implement eager loading in all list endpoints:
  ```php
  Expense::with('user', 'company')->paginate();
  ```
- [ ] Use `select()` to limit columns when appropriate
- [ ] Profile queries with Laravel Debugbar

**Deliverable**: Zero N+1 queries in list endpoints
**Validation**: Debugbar shows single query per endpoint

---

#### Phase 3.2: Redis Caching
- [ ] Configure Redis driver in `.env`
- [ ] Implement cache for frequently accessed data:
  - Company users list: `cache()->remember("company.{$id}.users", 3600, fn() => User::where('company_id', $id)->get())`
  - Expense summary per user: `cache()->remember("user.{$id}.expenses.summary", 1800, ...)`
- [ ] Cache invalidation on create/update:
  ```php
  Cache::forget("company.{$user->company_id}.users");
  ```
- [ ] Add cache headers to responses:
  ```php
  return response()->json($data)->header('Cache-Control', 'max-age=300');
  ```

**Deliverable**: Redis caching configured and active
**Validation**: `redis-cli` shows cached keys; subsequent requests are instant

---

### Phase 4: Background Processing & Scheduling (Week 6)
**Goal**: Implement queues, jobs, and scheduled tasks

#### Phase 4.1: Queue Configuration
- [ ] Set `QUEUE_CONNECTION=redis` in `.env`
- [ ] Create `SendWeeklyExpenseReport` job:
  - Query expenses from last 7 days
  - Group by company
  - Calculate totals
  - Send email to all Admins per company
  - Log job execution
- [ ] Queue the job in `ExpenseController@store()` for async processing

**Deliverable**: Job queue configured and processing
**Validation**: `php artisan queue:work` processes jobs correctly

---

#### Phase 4.2: Scheduler Configuration
- [ ] Register job in `app/Console/Kernel.php`:
  ```php
  $schedule->job(SendWeeklyExpenseReport::class)
      ->weekly()
      ->mondays()
      ->at('08:00');
  ```
- [ ] Test scheduler: `php artisan schedule:run`
- [ ] Add supervisor configuration for production

**Deliverable**: Scheduler ready for production deployment
**Validation**: Manual `schedule:run` triggers job; verify email sent

---

### Phase 5: Audit Logging (Week 6)
**Goal**: Implement comprehensive change tracking

#### Phase 5.1: Model Observers
- [ ] Create `ExpenseObserver`:
  - `updated()` - Log changes (old vs new values)
  - `deleted()` - Log deletion
  - `created()` - Optional - log creation
- [ ] Register observer in `AppServiceProvider`:
  ```php
  Expense::observe(ExpenseObserver::class);
  ```
- [ ] Implement helper to track changes:
  ```php
  private function getChanges($before, $after) {
      return collect($after)->diffAssoc($before)->map(function($value, $key) {
          return ['old' => $before[$key], 'new' => $value];
      })->all();
  }
  ```

**Deliverable**: All expense changes logged to audit_logs table
**Validation**: Query `AuditLog` shows all changes with old/new values

---

#### Phase 5.2: Audit Log Queries
- [ ] Create `AuditLogController` with:
  - `index()` - List audit logs for company (Admin only)
  - `show()` - View single audit log entry
- [ ] Add filtering:
  - By model_type (Expense, User, etc.)
  - By action (create, update, delete)
  - By date range
  - By user

**Deliverable**: Full audit trail queryable and auditable
**Validation**: All expense operations appear in audit logs

---

### Phase 6: API Response Standardization (Week 7)
**Goal**: Consistent, professional JSON responses

#### Phase 6.1: Response Wrapper
- [ ] Create `ApiResponse` class:
  ```php
  ApiResponse::success(data: $data, message: 'Success');
  ApiResponse::error(message: 'Error', errors: []);
  ApiResponse::paginated($paginator);
  ```
- [ ] Create exception handler in `ExceptionHandler`:
  - `ModelNotFoundException` → 404
  - `AuthorizationException` → 403
  - `ValidationException` → 422
  - Consistent error format

**Deliverable**: All endpoints return consistent JSON structure
**Validation**: 
```json
{
  "success": true,
  "message": "Expenses retrieved successfully",
  "data": {...},
  "meta": {"page": 1, "total": 50}
}
```

---

### Phase 7: Testing & Validation (Week 8)
**Goal**: Comprehensive test coverage for reliability

#### Phase 7.1: Feature Tests
- [ ] `AuthTest`:
  - Register creates company + user + token
  - Login returns token
  - Logout revokes token
  - Invalid credentials return 401
- [ ] `ExpenseTest`:
  - Logged-in user can create expense
  - User can view own expenses
  - Manager can update expenses
  - Admin can delete expenses
  - Cross-company access blocked
  - N+1 query detection
- [ ] `UserTest`:
  - Only admin can create users
  - Only admin can update roles
  - Only admin can list users
- [ ] `AuditLogTest`:
  - Changes logged to audit_logs
  - Old values stored correctly

**Deliverable**: 80%+ code coverage
**Validation**: `php artisan test` passes all tests

---

#### Phase 7.2: Performance Tests
- [ ] Load test list endpoints with 1000+ records
- [ ] Verify query count stays constant (eager loading)
- [ ] Benchmark cache hits vs misses
- [ ] Verify queue processes jobs under load

**Deliverable**: Performance baseline established
**Validation**: Load test results documented

---

### Phase 8: Documentation & Deployment (Week 9)
**Goal**: Production-ready deployment package

#### Phase 8.1: API Documentation
- [ ] Generate OpenAPI/Swagger spec
- [ ] Document all endpoints with:
  - Request/response examples
  - Required roles
  - Rate limits
- [ ] Create setup guide: `SETUP.md`
- [ ] Create deployment guide: `DEPLOY.md`

**Deliverable**: Complete API documentation
**Validation**: Documentation matches actual endpoints

---

#### Phase 8.2: Production Deployment
- [ ] Create `.env.production` with production values
- [ ] Configure supervisor for queue worker
- [ ] Set up Redis persistence
- [ ] Enable query logging in production
- [ ] Configure error tracking (Sentry)
- [ ] Set up database backups

**Deliverable**: Production-ready deployment
**Validation**: App runs on production server

---

## 📊 Critical Dependencies & Blockers

| Phase | Depends On | Notes |
|-------|-----------|-------|
| Phase 2 | Phase 1 | Cannot implement endpoints without models |
| Phase 3 | Phase 2 | Cannot optimize queries not yet written |
| Phase 4 | Phase 2 | Queues trigger from controllers |
| Phase 5 | Phase 2 | Observers monitor model changes |
| Phase 7 | All | Tests validate everything works |
| Phase 8 | All | Documentation and deployment come last |

---

## 🔍 Quality Checkpoints

### Before Phase 2
- [ ] Migrations run without errors
- [ ] Models load correctly in Tinker
- [ ] Foreign key relationships work
- [ ] Authentication produces valid tokens

### Before Phase 3
- [ ] All endpoints return 200/201/204 responses
- [ ] Authorization working (403 on unauthorized access)
- [ ] No N+1 queries detected

### Before Phase 4
- [ ] All CRUD operations tested
- [ ] Cross-company access blocked
- [ ] Response format consistent

### Before Phase 5
- [ ] Queue configured and running
- [ ] Audit log table receiving data
- [ ] Cascading updates/deletes work

### Before Phase 7
- [ ] All API response types working
- [ ] Error handling consistent
- [ ] Cache invalidation working

### Before Phase 8
- [ ] Test suite passes
- [ ] Performance benchmarks acceptable
- [ ] No security vulnerabilities

---

## 🛠️ Technology Stack Checklist

- [x] Laravel 10+
- [x] MySQL/PostgreSQL
- [x] Laravel Sanctum (authentication)
- [x] Redis (caching + queues)
- [x] Laravel Queues
- [x] Laravel Scheduler
- [x] PHPUnit (testing)
- [x] Model Observers (audit logging)
- [x] Authorization Policies
- [x] Eager Loading
- [x] Database Indexes

---

## 📈 Success Criteria

✅ All 6 task categories from brief.md implemented
✅ Multi-tenant isolation verified (no cross-company data access)
✅ Security best practices followed (Sanctum, RBAC, input validation)
✅ Performance optimized (eager loading, indexes, caching)
✅ Background jobs processing
✅ Audit trail complete
✅ 80%+ test coverage
✅ All endpoints documented
✅ Deployment ready

---

## ⏱️ Estimated Timeline

- **Week 1**: Database + Authentication (Phase 1)
- **Weeks 2-3**: Core API Endpoints (Phase 2)
- **Week 4**: Optimization (Phase 3)
- **Week 5**: Background Processing (Phase 4)
- **Week 6**: Audit Logging (Phase 5)
- **Week 7**: Response Standardization (Phase 6)
- **Week 8**: Testing (Phase 7)
- **Week 9**: Documentation & Deployment (Phase 8)

**Total: 9 weeks for full implementation**

---

## 🚀 Quick Start Commands

```bash
# Setup
composer create-project laravel/laravel expense-api
cd expense-api

# Configure
cp .env.example .env
php artisan key:generate
composer require laravel/sanctum

# Database
php artisan migrate:fresh

# Development
php artisan serve
php artisan queue:work
php artisan schedule:run

# Testing
php artisan test
php artisan test --coverage

# Production
php artisan config:cache
php artisan route:cache
php artisan view:cache
```
