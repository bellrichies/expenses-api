# Phase 2: Authentication Infrastructure - Professional Copilot Prompt

## 🎯 Objective
Implement secure Laravel Sanctum authentication with multi-tenant scoping and role-based access control middleware.

## 📋 Implementation Requirements

### 2.1 Laravel Sanctum Setup

#### Install and Configure Sanctum
```bash
# Install Laravel Sanctum
composer require laravel/sanctum

# Publish Sanctum configuration
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"

# Run Sanctum migrations (creates personal_access_tokens table)
php artisan migrate
```

#### Sanctum Service Provider Configuration
Update `config/auth.php`:
```php
'guards' => [
    'web' => [
        'driver' => 'session',
        'provider' => 'users',
    ],
    'api' => [
        'driver' => 'sanctum',
        'provider' => 'users',
        'hash' => false,
    ],
],
```

### 2.2 Authentication Controller

#### AuthController Implementation
Create `app/Http/Controllers/AuthController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * Register a new user and company
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'company_name' => 'required|string|max:255',
            'company_email' => 'required|email|max:255|unique:companies',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Create company first
            $company = Company::create([
                'name' => $request->company_name,
                'email' => $request->company_email,
            ]);

            // Create admin user
            $user = User::create([
                'company_id' => $company->id,
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => 'Admin', // First user is always admin
            ]);

            // Create API token
            $token = $user->createToken('api-token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'User registered successfully',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                        'company' => [
                            'id' => $company->id,
                            'name' => $company->name,
                            'email' => $company->email,
                        ]
                    ],
                    'token' => $token,
                    'token_type' => 'Bearer'
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Registration failed',
                'errors' => [$e->getMessage()]
            ], 500);
        }
    }

    /**
     * Login user and return API token
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Create API token
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'company' => [
                        'id' => $user->company->id,
                        'name' => $user->company->name,
                        'email' => $user->company->email,
                    ]
                ],
                'token' => $token,
                'token_type' => 'Bearer'
            ]
        ]);
    }

    /**
     * Logout user and revoke token
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully'
        ]);
    }

    /**
     * Get current authenticated user
     */
    public function user(Request $request)
    {
        return response()->json([
            'success' => true,
            'message' => 'User profile retrieved',
            'data' => [
                'user' => [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'role' => $request->user()->role,
                    'company' => [
                        'id' => $request->user()->company->id,
                        'name' => $request->user()->company->name,
                        'email' => $request->user()->company->email,
                    ]
                ]
            ]
        ]);
    }
}
```

### 2.3 Role-Based Access Control Middleware

#### CheckRole Middleware
Create `app/Http/Middleware/CheckRole.php`:
```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class CheckRole
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required',
                'errors' => []
            ], 401);
        }

        // Check if user has any of the required roles
        if (!in_array($user->role, $roles)) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient permissions',
                'errors' => []
            ], 403);
        }

        return $next($request);
    }
}
```

#### CompanyScope Middleware
Create `app/Http/Middleware/CompanyScope.php`:
```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;

class CompanyScope
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required',
                'errors' => []
            ], 401);
        }

        // Check if user has company_id (should always be true for authenticated users)
        if (!$user->company_id) {
            return response()->json([
                'success' => false,
                'message' => 'User not associated with a company',
                'errors' => []
            ], 403);
        }

        // Add company_id to request for easy access in controllers
        $request->merge(['company_id' => $user->company_id]);

        return $next($request);
    }
}
```

### 2.4 Middleware Registration

#### Update Kernel.php
Modify `app/Http/Kernel.php`:
```php
protected $middleware = [
    // ... existing middleware
    \App\Http\Middleware\CompanyScope::class,
];

protected $routeMiddleware = [
    'auth' => \App\Http\Middleware\Authenticate::class,
    'auth:sanctum' => \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
    'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
    'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
    'role' => \App\Http\Middleware\CheckRole::class,
];
```

### 2.5 Authentication Routes

#### Update routes/auth.php
Create or modify `routes/auth.php`:
```php
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;

// Public routes
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

// Protected routes (require authentication)
Route::middleware(['auth:sanctum', 'company:scope'])->group(function () {
    Route::get('/auth/user', [AuthController::class, 'user']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
});
```

#### Update routes/api.php
Add to `routes/api.php`:
```php
// API routes requiring authentication and company scoping
Route::middleware(['auth:sanctum', 'company:scope'])->group(function () {
    // Expense routes (will be added in Phase 3)
    Route::apiResource('expenses', ExpenseController::class);
    
    // User management routes (will be added in Phase 4)
    Route::apiResource('users', UserController::class)->middleware('role:Admin');
});
```

### 2.6 Exception Handling

#### Custom Exceptions
Create `app/Exceptions/CompanyAccessException.php`:
```php
<?php

namespace App\Exceptions;

use Exception;

class CompanyAccessException extends Exception
{
    public function __construct()
    {
        parent::__construct('You do not have access to this company\'s data');
    }
}
```

#### Update Exception Handler
Modify `app/Exceptions/Handler.php`:
```php
public function register()
{
    $this->reportable(function (Exception $e) {
        // ...
    });

    $this->renderable(function (\Illuminate\Validation\ValidationException $e, $request) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $e->errors()
        ], 422);
    });

    $this->renderable(function (\Illuminate\Auth\AuthenticationException $e, $request) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthenticated',
            'errors' => []
        ], 401);
    });

    $this->renderable(function (\App\Exceptions\CompanyAccessException $e, $request) {
        return response()->json([
            'success' => false,
            'message' => 'Company access denied',
            'errors' => []
        ], 403);
    });

    $this->renderable(function (\Illuminate\Database\Eloquent\ModelNotFoundException $e, $request) {
        return response()->json([
            'success' => false,
            'message' => 'Resource not found',
            'errors' => []
        ], 404);
    });
}
```

### 2.7 Helper Functions

Create `app/Helpers/AuthHelper.php`:
```php
<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Auth;

class AuthHelper
{
    /**
     * Get authenticated user's company ID
     */
    public static function getCompanyId()
    {
        return Auth::user()->company_id ?? null;
    }

    /**
     * Check if user has specific role
     */
    public static function hasRole($role)
    {
        return Auth::user()->role === $role;
    }

    /**
     * Check if user has any of the specified roles
     */
    public static function hasAnyRole($roles)
    {
        return in_array(Auth::user()->role, (array)$roles);
    }

    /**
     * Check if user is admin
     */
    public static function isAdmin()
    {
        return self::hasRole('Admin');
    }

    /**
     * Check if user is manager
     */
    public static function isManager()
    {
        return self::hasRole('Manager') || self::isAdmin();
    }

    /**
     * Check if user is employee
     */
    public static function isEmployee()
    {
        return self::hasRole('Employee');
    }
}
```

## 🔍 Quality Gates

### Before Moving to Phase 3

1. ✅ **Sanctum installation and configuration**:
   ```bash
   composer require laravel/sanctum
   php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
   php artisan migrate
   ```

2. ✅ **Registration endpoint works**:
   ```bash
   curl -X POST http://localhost:8000/api/auth/register \
     -H "Content-Type: application/json" \
     -d '{"name":"Test User","email":"test@example.com","password":"password","password_confirmation":"password","company_name":"Test Company","company_email":"company@example.com"}'
   ```

3. ✅ **Login endpoint works**:
   ```bash
   curl -X POST http://localhost:8000/api/auth/login \
     -H "Content-Type: application/json" \
     -d '{"email":"test@example.com","password":"password"}'
   ```

4. ✅ **Authentication middleware works**:
   ```bash
   # Without token
   curl http://localhost:8000/api/auth/user
   
   # With token
   curl http://localhost:8000/api/auth/user \
     -H "Authorization: Bearer YOUR_TOKEN_HERE"
   ```

5. ✅ **Role-based access control works**:
   ```bash
   # Try to access admin-only endpoint without admin role
   curl -X GET http://localhost:8000/api/users \
     -H "Authorization: Bearer YOUR_TOKEN_HERE"
   ```

6. ✅ **Company scoping works**:
   ```bash
   # Verify all queries are scoped to user's company
   # Test in tinker or via API endpoints
   ```

## 🚀 Validation Commands

### Test Authentication Flow
```bash
# Test registration
curl -X POST http://localhost:8000/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{"name":"Test User","email":"test@example.com","password":"password123","password_confirmation":"password123","company_name":"Test Company","company_email":"company@example.com"}'

# Test login
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"password123"}'

# Test user profile
curl http://localhost:8000/api/auth/user \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"

# Test logout
curl -X POST http://localhost:8000/api/auth/logout \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```

### Test Middleware Protection
```bash
# Test unauthenticated access
curl http://localhost:8000/api/expenses

# Test authenticated access
curl http://localhost:8000/api/expenses \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```

### Test Role-Based Access
```bash
# Create users with different roles
# Test that only Admins can access /api/users
# Test that Managers can update expenses
# Test that Employees can only create/view expenses
```

## 📝 Expected File Structure

```
app/Http/Controllers/
└── AuthController.php

app/Http/Middleware/
├── CheckRole.php
└── CompanyScope.php

app/Exceptions/
└── CompanyAccessException.php

app/Helpers/
└── AuthHelper.php

routes/
├── auth.php
└── api.php

config/
└── auth.php
```

## ⚠️ Critical Implementation Notes

1. **Token Security**: Always use Bearer tokens in Authorization header
2. **Company Scoping**: CompanyScope middleware MUST run on all protected routes
3. **Role Hierarchy**: Admin > Manager > Employee (Admin can do everything, Manager can manage expenses, Employee can only create/view)
4. **Error Handling**: Consistent JSON error responses across all authentication endpoints
5. **Password Hashing**: Always use Hash::make() for passwords, never store plain text

## 🎯 Success Criteria

✅ Laravel Sanctum properly installed and configured  
✅ Registration creates company + user + token  
✅ Login returns valid token with user details  
✅ All protected routes require authentication  
✅ Role-based access control functional  
✅ Company scoping enforced on all queries  
✅ Consistent error response format  
✅ Ready for Phase 3: Core API Endpoints