# Expense Management API — Endpoint Reference

**Base URL:** `http://localhost:8000/api`  
**Authentication:** Bearer token via `Authorization: Bearer <token>` header  
**Response envelope:** All responses follow `{success, message, data[, meta, errors]}`

---

## Authentication

All protected routes require the `Authorization: Bearer <token>` header.  
Tokens are issued by the login and register endpoints.

### `POST /api/auth/register`

Create a new company and its first Admin user.

**Access:** Public · Rate-limited: 6 requests/minute

**Request body:**
```json
{
  "name": "Jane Smith",
  "email": "jane@acme.com",
  "password": "secret123",
  "password_confirmation": "secret123",
  "company_name": "Acme Corp",
  "company_email": "info@acme.com"
}
```

**Success 201:**
```json
{
  "success": true,
  "message": "User registered successfully",
  "data": {
    "user": { "id": 1, "name": "Jane Smith", "email": "jane@acme.com", "role": "Admin", "company": { "id": 1, "name": "Acme Corp" } },
    "token": "1|abc…",
    "token_type": "Bearer"
  }
}
```

**Errors:** `422` validation (duplicate email / company name)

---

### `POST /api/auth/login`

**Access:** Public · Rate-limited: 6 requests/minute

**Request body:** `{ "email": "…", "password": "…" }`

**Success 200:** same envelope as register with `token`  
**Errors:** `422` wrong credentials

---

### `GET /api/auth/user`

Return the authenticated user's profile.

**Access:** Authenticated

**Success 200:**
```json
{ "success": true, "data": { "user": { "id": 1, "role": "Admin", "company": { "id": 1 } } } }
```

---

### `POST /api/auth/logout`

Revoke the current access token.

**Access:** Authenticated  
**Success 200:** `{ "success": true, "message": "Logged out successfully" }`

---

## Expenses

### `GET /api/expenses`

Paginated, company-scoped expense list with search and filtering.

**Access:** Any authenticated role  
**Query params:**

| Param | Type | Description |
|---|---|---|
| `search` | string | Full-text match on `title` OR `category` |
| `category` | string | Exact category filter |
| `from` | date | `created_at` >= this date |
| `to` | date | `created_at` <= this date |
| `sort_by` | `amount\|title\|created_at` | Sort field (default: `created_at`) |
| `direction` | `asc\|desc` | Sort direction (default: `desc`) |
| `per_page` | integer | Items per page 1–100 (default: 15) |

**Success 200:**
```json
{
  "success": true,
  "message": "Expenses retrieved successfully",
  "data": [{ "id": 1, "title": "Taxi", "amount": 20.0, "category": "Travel", "user": { "id": 2, "name": "Bob" }, "created_at": "2026-06-03T10:00:00+00:00" }],
  "meta": { "current_page": 1, "per_page": 15, "total": 42, "last_page": 3 }
}
```

---

### `GET /api/expenses/{id}`

**Access:** Any authenticated role (owner or Manager/Admin only per policy)  
**Errors:** `403` Insufficient permissions · `404` Not found or cross-company

---

### `POST /api/expenses`

Create an expense. `company_id` and `user_id` are auto-derived from the token.

**Access:** Employee | Manager | Admin

**Request body:** `{ "title": "…", "amount": 42.50, "category": "Travel" }`

**Success 201** with expense resource + audit row created automatically.

---

### `PUT /api/expenses/{id}`

Update title, amount, or category. Changes are audit-logged.

**Access:** Manager | Admin  
**Errors:** `403` if Employee or cross-company · `404` cross-company ID

---

### `DELETE /api/expenses/{id}`

**Access:** Admin only  
**Success 200:** `{ "success": true, "message": "Expense deleted successfully", "data": null }`  
**Errors:** `403` non-Admin · `404` cross-company

---

## User Management (Admin only)

All `/api/users` routes require `role: Admin`. Non-admin → `403`.

### `GET /api/users`

Company-scoped user list. Results are cached (Redis TTL: 1 hour).

**Query params:** `role` (Admin|Manager|Employee), `per_page`, `page`

**Success 200:** paginated `UserResource` collection

---

### `POST /api/users`

Create a user in the authenticated Admin's company.  
An auto-generated password is dispatched via async `SendWelcomeEmail` job if no `password` is provided.

**Request body:**
```json
{ "name": "Bob", "email": "bob@acme.com", "role": "Employee", "password": "optional" }
```

**Validation:** `email` must be unique **within the company** (same address is allowed in other companies).  
**Success 201** with user resource.

---

### `PUT /api/users/{id}`

Update name, email, or role. Company-unique email enforced on update.

**Access:** Admin · **Errors:** `404` cross-company user

---

### `DELETE /api/users/{id}`

**Access:** Admin  
**Special:** Admin cannot delete their own account (returns `422`).  
Cache invalidated on success.

---

## Audit Logs (Admin only)

Append-only trail. No write endpoints — `PUT`/`DELETE` return `405`.

### `GET /api/audit-logs`

**Query params:** `action` (create|update|delete), `model_type`, `user_id`, `from`, `to`, `per_page`

**Success 200:**
```json
{
  "data": [{
    "id": 5,
    "action": "update",
    "model_type": "Expense",
    "model_id": 3,
    "changes": { "old": { "amount": "100.00" }, "new": { "amount": 150 } },
    "user": { "id": 1, "name": "Jane Smith" },
    "created_at": "2026-06-03T12:00:00+00:00"
  }],
  "meta": { "current_page": 1, "per_page": 25, "total": 18, "last_page": 1 }
}
```

---

### `GET /api/audit-logs/{id}`

Single audit log entry (company-scoped).  
**Errors:** `404` if cross-company or not found.

---

## Standard Error Responses

| Status | Trigger | Shape |
|---|---|---|
| `401` | Missing / invalid token | `{success:false, message:"Unauthenticated", errors:[]}` |
| `403` | Insufficient role or policy denial | `{success:false, message:"This action is unauthorized.", errors:[]}` |
| `404` | Resource not found or cross-company | `{success:false, message:"Resource not found", errors:[]}` |
| `405` | Method not supported | `{success:false, message:"…", errors:[]}` |
| `422` | Validation failure | `{success:false, message:"Validation failed", errors:{"field":["msg"]}}` |
| `429` | Rate limit exceeded | `{success:false, message:"Too Many Attempts.", errors:[]}` |
| `500` | Server error (production) | `{success:false, message:"Server error", errors:[]}` |

> Stack traces are only included in development (`APP_DEBUG=true`). Production always returns the sanitised `Server error` message.
