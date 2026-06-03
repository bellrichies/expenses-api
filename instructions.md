# Multi-Tenant SaaS Expense Management API - Implementation Instructions

## Project Overview
Build a secure, multi-tenant SaaS expense management API using Laravel 10+ with advanced features including role-based access control, audit logging, background job processing, and performance optimizations.

## Core Requirements

### 1. Multi-Tenant Architecture
- **Isolation Strategy**: Every table must have `company_id` foreign key (except audit_logs which also tracks company_id)
- **Scoping**: Middleware must automatically filter all queries by authenticated user's company
- **No Shared Data**: Users, expenses, and audit logs must remain isolated per company

### 2. Database Structure

#### Companies Table
```
id (PK)
name (string)
email (string)
created_at
updated_at
```

#### Users Table
```
id (PK)
company_id (FK → companies)
name (string)
email (string, unique per company)
password (string)
role (enum: Admin, Manager, Employee)
created_at
updated_at
```

#### Expenses Table
```
id (PK)
company_id (FK → companies)  [INDEX]
user_id (FK → users)  [INDEX]
title (string)
amount (decimal)
category (string)
created_at
updated_at
```

#### AuditLogs Table
```
id (PK)
user_id (FK → users)
company_id (FK → companies)
action (string: create, update, delete)
model_type (string: Expense, User, etc.)
model_id (integer)
changes (json: {old: {...}, new: {...}})
created_at
```

### 3. Model Relationships

```php
Company hasMany Users
Company hasMany Expenses (through Users)
Company hasMany AuditLogs

User belongsTo Company
User hasMany Expenses
User hasMany AuditLogs

Expense belongsTo Company
Expense belongsTo User
```

### 4. Authentication & Authorization

#### Sanctum Setup
- Use Laravel Sanctum for stateless token-based authentication
- Create tokens on login with appropriate abilities/scopes
- Validate tokens on every protected endpoint

#### Role-Based Middleware
Create `CheckRole` middleware that validates user role:
```php
// Only Admins can access this route
Route::middleware(['auth:sanctum', 'role:Admin'])->...

// Managers and Admins can access this route
Route::middleware(['auth:sanctum', 'role:Manager,Admin'])->...
```

#### Multi-Tenant Middleware
Create `CompanyScope` middleware that:
- Automatically filters all queries to authenticated user's company
- Uses model scopes to enforce filtering
- Throws UnauthorizedException if user attempts cross-company access

### 5. API Endpoints

#### Authentication Endpoints
```
POST /api/register
  - Body: { name, email, password, company_name }
  - Returns: User object + API token
  - Access: Public

POST /api/login
  - Body: { email, password }
  - Returns: User object + API token
  - Access: Public
```

#### Expense Endpoints
```
GET /api/expenses
  - Query params: page, per_page, search (title), category, sort_by
  - Returns: Paginated expenses for authenticated user's company
  - Access: Authenticated (all roles)

POST /api/expenses
  - Body: { title, amount, category }
  - Returns: Created expense
  - Access: Authenticated (Employee, Manager, Admin)

PUT /api/expenses/{id}
  - Body: { title, amount, category }
  - Returns: Updated expense
  - Access: Manager, Admin only
  - Note: Auto-log changes to AuditLog

DELETE /api/expenses/{id}
  - Access: Admin only
  - Note: Auto-log deletion to AuditLog
```

#### User Management Endpoints
```
GET /api/users
  - Query params: page, per_page, role filter
  - Returns: Paginated users for authenticated user's company
  - Access: Admin only

POST /api/users
  - Body: { name, email, password, role }
  - Returns: Created user
  - Access: Admin only
  - Note: Auto-generate initial password or return generated one

PUT /api/users/{id}
  - Body: { name, email, role }
  - Returns: Updated user
  - Access: Admin only
  - Note: Auto-log role changes
```

### 6. Query Optimization

#### Eager Loading Patterns
Always use `with()` to prevent N+1 queries:
```php
// ❌ Bad - N+1 queries
$expenses = Expense::all();
foreach ($expenses as $expense) {
    echo $expense->user->name;  // Extra query per expense
}

// ✅ Good - Single query with eager loading
$expenses = Expense::with('user', 'company')->get();
foreach ($expenses as $expense) {
    echo $expense->user->name;  // No additional queries
}
```

#### Indexing Strategy
Create indexes in migrations:
```php
Schema::table('expenses', function (Blueprint $table) {
    $table->index('company_id');
    $table->index('user_id');
    $table->index('created_at');
});
```

#### Redis Caching
Cache frequently accessed data:
```php
// Cache list of users per company (TTL: 1 hour)
$users = Cache::remember("company.{$companyId}.users", 3600, function () {
    return User::where('company_id', $companyId)->get();
});

// Invalidate on create/update
Cache::forget("company.{$companyId}.users");
```

### 7. Background Jobs & Scheduling

#### Weekly Expense Report Job
```php
// app/Jobs/SendWeeklyExpenseReport.php
- Query all expenses from past 7 days per company
- Group by company and calculate totals
- Send email to all Admin users
- Log job execution
```

#### Scheduler Configuration
```php
// app/Console/Kernel.php
$schedule->job(SendWeeklyExpenseReport::class)
    ->weekly()
    ->mondays()
    ->at('08:00');  // 8 AM Monday
```

#### Job Queue Setup
- Configure queue driver: Redis recommended for production
- Ensure queue worker runs: `php artisan queue:work`
- Implement retry logic with exponential backoff

### 8. Audit Logging

#### Implementation Strategy
Use Laravel Model Observers:
```php
// app/Observers/ExpenseObserver.php
- updated: Log old vs new values
- deleted: Log deletion with expense details
- created: Optional - log expense creation

// Automatically trigger via:
// app/Providers/AppServiceProvider.php
Expense::observe(ExpenseObserver::class);
```

#### Audit Log Structure
```php
$auditLog = AuditLog::create([
    'user_id' => auth()->id(),
    'company_id' => auth()->user()->company_id,
    'action' => 'update',  // create, update, delete
    'model_type' => 'Expense',
    'model_id' => $expense->id,
    'changes' => [
        'old' => ['amount' => 100, 'category' => 'Food'],
        'new' => ['amount' => 150, 'category' => 'Travel'],
    ],
]);
```

### 9. Error Handling & API Responses

#### Response Format
All responses must follow this JSON structure:
```json
{
  "success": true|false,
  "message": "Human-readable message",
  "data": {},
  "errors": []
}
```

#### Exception Handling
Create custom exceptions:
- `UnauthorizedException` → 403 Forbidden
- `ResourceNotFoundException` → 404 Not Found
- `ValidationException` → 422 Unprocessable Entity
- `CompanyAccessException` → 403 Forbidden (cross-company access)

#### HTTP Status Codes
- 200: Success
- 201: Created
- 400: Bad Request
- 401: Unauthorized
- 403: Forbidden
- 404: Not Found
- 422: Validation Error
- 500: Server Error

### 10. Security Best Practices

#### Required Implementation
- ✅ Hash all passwords using bcrypt
- ✅ Use CSRF tokens or validate Bearer tokens
- ✅ Validate all user input
- ✅ Sanitize output in responses
- ✅ Use rate limiting on auth endpoints
- ✅ Implement request validation with FormRequest classes
- ✅ Use Laravel policies for fine-grained authorization

#### Company Isolation Checks
Every query must verify user's company:
```php
// In Controller
$expense = Expense::findOrFail($id);
if ($expense->company_id !== auth()->user()->company_id) {
    throw new CompanyAccessException();
}
```

### 11. Testing Requirements

#### Feature Tests
- User registration and login
- All CRUD operations with role-based access
- Multi-tenant isolation (user cannot access another company's data)
- Audit logging on update/delete

#### Authorization Tests
- Employee cannot update/delete expenses
- Manager cannot delete expenses or manage users
- Admin can perform all operations
- Cross-company access is blocked

#### Performance Tests
- Verify no N+1 queries in list endpoints
- Benchmark query response times
- Validate cache effectiveness

### 12. Code Structure

```
app/
├── Console/
│   └── Commands/
│       └── ScheduleExpenseReports.php
├── Http/
│   ├── Controllers/
│   │   ├── AuthController.php
│   │   ├── ExpenseController.php
│   │   └── UserController.php
│   ├── Middleware/
│   │   ├── CompanyScope.php
│   │   └── CheckRole.php
│   ├── Requests/
│   │   ├── StoreExpenseRequest.php
│   │   ├── UpdateExpenseRequest.php
│   │   └── StoreUserRequest.php
│   └── Resources/
│       ├── ExpenseResource.php
│       └── UserResource.php
├── Models/
│   ├── Company.php
│   ├── User.php
│   ├── Expense.php
│   └── AuditLog.php
├── Jobs/
│   └── SendWeeklyExpenseReport.php
├── Observers/
│   └── ExpenseObserver.php
├── Policies/
│   ├── ExpensePolicy.php
│   └── UserPolicy.php
└── Providers/
    └── AppServiceProvider.php

routes/
├── api.php
└── auth.php

tests/
├── Feature/
│   ├── AuthTest.php
│   ├── ExpenseTest.php
│   └── UserTest.php
└── Unit/
    └── AuditLogTest.php
```

### 13. Development Workflow

1. **Start with Migrations**: Build database schema first
2. **Create Models**: Define relationships and scopes
3. **Implement Middleware**: Set up authentication and company scoping
4. **Build Controllers**: Implement CRUD logic with authorization checks
5. **Add Observers**: Implement audit logging
6. **Configure Jobs**: Set up background processing
7. **Write Tests**: Comprehensive test coverage
8. **Optimize**: Add indexes, caching, eager loading
9. **Document**: API documentation and setup instructions

### 14. Environment Configuration

Required `.env` variables:
```
APP_NAME="Expense Management API"
APP_ENV=production
APP_KEY=base64:...

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=expense_saas
DB_USERNAME=root
DB_PASSWORD=

CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
```

### 15. Bonus Features (Optional)

- Implement API documentation with OpenAPI/Swagger
- Add email notifications for expense approvals
- Implement expense categories management
- Add reporting/analytics endpoints
- Implement rate limiting
- Add API versioning
