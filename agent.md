# Multi-Tenant SaaS Expense Management API - Agent Configuration

## Purpose
This agent orchestrates the implementation of a secure, high-performance Laravel-based Multi-Tenant SaaS Expense Management API following best practices for enterprise application development.

## Agent Mode & Responsibilities

### Primary Responsibilities
- **Architecture & Database Design**: Ensure proper multi-tenant isolation at the database layer
- **Security Implementation**: Enforce Laravel Sanctum authentication and RBAC throughout
- **Performance Optimization**: Guide eager loading, indexing, and caching strategies
- **Code Quality**: Ensure code follows Laravel conventions and best practices
- **Task Decomposition**: Break down implementation into logical, testable units

### Task Decomposition Rules
Tasks should be organized into these phases:

1. **Database Layer** (Migrations & Models)
   - Company model and migration
   - User model with multi-tenant FK and role enum
   - Expense model with indexes
   - AuditLog model for tracking changes

2. **Authentication & Authorization**
   - Sanctum token setup
   - Role-based middleware
   - Multi-tenant scoping middleware

3. **API Endpoints**
   - Authentication routes
   - Expense CRUD operations
   - User management (admin only)

4. **Performance & Optimization**
   - Eager loading patterns
   - Database indexes
   - Redis caching strategy

5. **Background Processing**
   - Queue job for expense reports
   - Scheduler configuration
   - Audit log tracking

6. **Testing & Validation**
   - Feature tests for endpoints
   - Authorization tests
   - Performance validation

## Implementation Principles

### Multi-Tenant Isolation
- Every query must include `company_id` filtering
- Use middleware to automatically scope queries to the authenticated user's company
- Never allow cross-company data access

### Authentication Flow
- Laravel Sanctum for stateless API authentication
- Token-based authentication with Bearer scheme
- Enforce authentication on all protected routes

### Role-Based Access Control
```
Admin     → Full access (users, expenses, audit logs)
Manager   → Manage expenses only
Employee  → Create & view own expenses
```

### Performance Requirements
- Use `with()` for eager loading (prevent N+1 queries)
- Index `company_id` and `user_id` on expenses table
- Implement Redis caching for frequently queried data
- Paginate list endpoints with configurable limits

### Code Quality Standards
- Follow PSR-12 coding standards
- Use type hints on all methods and properties
- Create custom exceptions for domain errors
- Use model observers for audit logging
- Document complex business logic with comments

## Tool & Framework Preferences

- **Framework**: Laravel 10+
- **Database**: MySQL or PostgreSQL
- **Authentication**: Laravel Sanctum
- **Caching**: Redis
- **Queuing**: Laravel Queues (database or Redis driver)
- **Testing**: PHPUnit with Laravel testing utilities

## Quality Gates

Before marking a task complete:
1. ✅ Code adheres to Laravel conventions
2. ✅ Multi-tenant isolation enforced
3. ✅ Authentication/Authorization working correctly
4. ✅ Database queries optimized (eager loading, indexes)
5. ✅ Error handling with meaningful messages
6. ✅ API responses follow consistent JSON format

## Communication Style
- Be concise and action-oriented
- Provide implementation guidance, not lengthy explanations
- Highlight security implications of design decisions
- Recommend specific Laravel features (e.g., policy classes, scopes)
