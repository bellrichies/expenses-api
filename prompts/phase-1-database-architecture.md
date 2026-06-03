# Phase 1: Database Architecture - Professional Copilot Prompt

## 🎯 Objective
Establish the complete multi-tenant database schema with migrations, models, relationships, and scopes for a secure Laravel-based SaaS expense management system.

## 📋 Implementation Requirements

### 1.1 Database Migrations

#### Companies Table Migration
Create `create_companies_table.php` migration with:
```php
Schema::create('companies', function (Blueprint $table) {
    $table->id();
    $table->string('name')->unique();
    $table->string('email')->unique();
    $table->timestamps();
    
    // Add indexes for performance
    $table->index(['name', 'email']);
});
```

#### Users Table Migration
Create `create_users_table.php` migration with:
```php
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->constrained()->onDelete('cascade');
    $table->string('name');
    $table->string('email')->unique();
    $table->string('password');
    $table->enum('role', ['Admin', 'Manager', 'Employee'])->default('Employee');
    $table->timestamps();
    
    // Add indexes for performance
    $table->index('company_id');
    $table->index('email');
    $table->index('role');
});
```

#### Expenses Table Migration
Create `create_expenses_table.php` migration with:
```php
Schema::create('expenses', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->constrained()->onDelete('cascade');
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->string('title');
    $table->decimal('amount', 10, 2);
    $table->string('category');
    $table->timestamps();
    
    // Critical indexes for multi-tenant performance
    $table->index('company_id');
    $table->index('user_id');
    $table->index('created_at');
    $table->index(['company_id', 'created_at']); // Composite index for company-based queries
});
```

#### AuditLogs Table Migration
Create `create_audit_logs_table.php` migration with:
```php
Schema::create('audit_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->foreignId('company_id')->constrained()->onDelete('cascade');
    $table->string('action'); // create, update, delete
    $table->string('model_type'); // Expense, User, etc.
    $table->unsignedBigInteger('model_id');
    $table->json('changes'); // Store old/new values as JSON
    $table->timestamps();
    
    // Indexes for audit trail queries
    $table->index(['company_id', 'created_at']);
    $table->index(['model_type', 'model_id']);
    $table->index('action');
});
```

### 1.2 Model Layer Implementation

#### Company Model
Create `app/Models/Company.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
    ];

    protected $casts = [
        'email' => 'string',
    ];

    // Company has many users
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    // Company has many expenses (through users)
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    // Company has many audit logs
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
```

#### User Model
Create `app/Models/User.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Eloquent\Builder;

class User extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'email',
        'password',
        'role',
    ];

    protected $casts = [
        'email' => 'string',
        'password' => 'hashed',
        'role' => 'enum:Admin,Manager,Employee',
    ];

    // User belongs to a company
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // User has many expenses
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    // User has many audit logs
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    // Scopes for filtering
    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeAdmins(Builder $query): Builder
    {
        return $query->where('role', 'Admin');
    }

    public function scopeManagers(Builder $query): Builder
    {
        return $query->where('role', 'Manager');
    }

    public function scopeEmployees(Builder $query): Builder
    {
        return $query->where('role', 'Employee');
    }
}
```

#### Expense Model
Create `app/Models/Expense.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Eloquent\Builder;

class Expense extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'user_id',
        'title',
        'amount',
        'category',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'company_id' => 'integer',
        'user_id' => 'integer',
    ];

    // Expense belongs to a company
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // Expense belongs to a user
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Scopes for filtering
    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        return $query->where(function ($q) use ($search) {
            $q->where('title', 'like', "%{$search}%")
              ->orWhere('category', 'like', "%{$search}%");
        });
    }

    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderBy('created_at', 'desc');
    }
}
```

#### AuditLog Model
Create `app/Models/AuditLog.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Eloquent\Builder;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'company_id',
        'action',
        'model_type',
        'model_id',
        'changes',
    ];

    protected $casts = [
        'changes' => 'array',
        'user_id' => 'integer',
        'company_id' => 'integer',
        'model_id' => 'integer',
    ];

    // Audit log belongs to a user
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Audit log belongs to a company
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    // Scopes for filtering
    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForModel(Builder $query, string $modelType, int $modelId): Builder
    {
        return $query->where('model_type', $modelType)
                    ->where('model_id', $modelId);
    }

    public function scopeForAction(Builder $query, string $action): Builder
    {
        return $query->where('action', $action);
    }

    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderBy('created_at', 'desc');
    }
}
```

### 1.3 Database Validation Commands

Create `database-validation.php` artisan command for testing migrations:
```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Company;
use App\Models\User;
use App\Models\Expense;
use App\Models\AuditLog;

class DatabaseValidation extends Command
{
    protected $signature = 'db:validate';
    protected $description = 'Validate database schema and relationships';

    public function handle()
    {
        $this->info('Validating database schema...');

        // Check if all tables exist
        $tables = DB::select("SHOW TABLES");
        $tableNames = array_map(function($table) {
            return reset($table);
        }, $tables);

        $requiredTables = ['companies', 'users', 'expenses', 'audit_logs', 'personal_access_tokens'];
        
        foreach ($requiredTables as $table) {
            if (!in_array($table, $tableNames)) {
                $this->error("Table '{$table}' does not exist!");
                return 1;
            }
            $this->info("✓ Table '{$table}' exists");
        }

        // Test relationships
        $this->info('Testing relationships...');

        try {
            // Test company-user relationship
            $company = Company::factory()->create();
            $user = User::factory()->create(['company_id' => $company->id]);
            
            if ($company->users()->count() !== 1) {
                $this->error('Company-user relationship failed!');
                return 1;
            }
            $this->info('✓ Company-user relationship works');

            // Test user-expense relationship
            $expense = Expense::factory()->create(['user_id' => $user->id, 'company_id' => $company->id]);
            
            if ($user->expenses()->count() !== 1) {
                $this->error('User-expense relationship failed!');
                return 1;
            }
            $this->info('✓ User-expense relationship works');

            // Test company-expense relationship
            if ($company->expenses()->count() !== 1) {
                $this->error('Company-expense relationship failed!');
                return 1;
            }
            $this->info('✓ Company-expense relationship works');

            // Clean up test data
            $company->delete();

            $this->info('✓ All relationships validated successfully!');
            return 0;

        } catch (\Exception $e) {
            $this->error("Validation failed: " . $e->getMessage());
            return 1;
        }
    }
}
```

## 🔍 Quality Gates

### Before Moving to Phase 2

1. ✅ **All migrations run without errors**:
   ```bash
   php artisan migrate:fresh
   ```

2. ✅ **Foreign key constraints work**:
   ```bash
   php artisan tinker
   >>> Company::first()->users()->create(['name' => 'Test', 'email' => 'test@example.com', 'password' => bcrypt('password'), 'role' => 'Admin'])
   ```

3. ✅ **Indexes are created**:
   ```sql
   SHOW INDEX FROM expenses;
   ```

4. ✅ **Model relationships work**:
   ```bash
   php artisan tinker
   >>> Company::with('users', 'expenses')->first()
   ```

5. ✅ **Scopes function correctly**:
   ```bash
   php artisan tinker
   >>> User::forCompany(1)->get()
   >>> Expense::forCompany(1)->recent()->get()
   ```

## 🚀 Validation Commands

### Run Database Validation
```bash
# Test migrations
php artisan migrate:fresh

# Test relationships
php artisan db:validate

# Verify indexes exist
php artisan tinker --execute="DB::select('SHOW INDEX FROM expenses');"
```

### Test Model Relationships
```bash
# In tinker
php artisan tinker

# Create test data
>>> Company::create(['name' => 'Test Company', 'email' => 'test@company.com']);
>>> User::create(['company_id' => 1, 'name' => 'Admin User', 'email' => 'admin@test.com', 'password' => bcrypt('password'), 'role' => 'Admin']);
>>> Expense::create(['company_id' => 1, 'user_id' => 1, 'title' => 'Test Expense', 'amount' => 100.00, 'category' => 'Test']);

# Test relationships
>>> $company = Company::first();
>>> $company->users()->count(); // Should be 1
>>> $company->expenses()->count(); // Should be 1
```

## 📝 Expected File Structure

```
database/migrations/
├── 2024_01_01_000000_create_companies_table.php
├── 2024_01_01_000001_create_users_table.php
├── 2024_01_01_000002_create_expenses_table.php
├── 2024_01_01_000003_create_audit_logs_table.php
└── 2024_01_01_000004_create_personal_access_tokens_table.php

app/Models/
├── Company.php
├── User.php
├── Expense.php
└── AuditLog.php

app/Console/Commands/
└── DatabaseValidation.php
```

## ⚠️ Critical Implementation Notes

1. **Multi-Tenant Foundation**: Every model MUST have `company_id` for proper isolation
2. **Cascade Deletes**: Users cascade delete expenses, Companies cascade delete users and expenses
3. **Indexes are Critical**: `company_id` and `user_id` indexes on expenses table are non-negotiable for performance
4. **Foreign Key Constraints**: All relationships MUST have proper foreign key constraints
5. **JSON Casting**: `changes` field in audit logs MUST be cast to array for proper JSON handling

## 🎯 Success Criteria

✅ All migrations run without errors  
✅ All relationships work correctly  
✅ Multi-tenant isolation enforced at database level  
✅ Performance indexes created  
✅ Validation command passes all tests  
✅ Ready for Phase 2: Authentication Infrastructure