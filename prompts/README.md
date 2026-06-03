# Multi-Tenant SaaS Expense Management API — Implementation Prompts

This folder contains **Copilot-ready implementation prompts**, one per phase. Each file is self-contained: feed it to GitHub Copilot / Claude in your editor and implement that phase end-to-end, then run its **Quality Gates** before moving on.

Source of truth: [`brief.md`](brief.md), [`build_blueprint.md`](build_blueprint.md), `../agent.md`, `../instructions.md`.

## 📚 Execution Order

| # | Prompt | Implements (brief task) |
|---|--------|-------------------------|
| 1 | [Phase 1 — Database Architecture](phase-1-database-architecture.md) | Task 1: Migrations, Models, Relationships, Indexes |
| 2 | [Phase 2 — Authentication Infrastructure](phase-2-authentication-infrastructure.md) | Task 2: Sanctum, RBAC + multi-tenant middleware |
| 3 | [Phase 3 — Expense Management Endpoints](phase-3-expense-management-endpoints.md) | Task 3: Expense CRUD API |
| 4 | [Phase 4 — User Management Endpoints](phase-4-user-management-endpoints.md) | Task 3: User management (Admin only) |
| 5 | [Phase 5 — Authorization & Policies](phase-5-authorization-policies.md) | Task 2: Fine-grained RBAC + company isolation |
| 6 | [Phase 6 — Query Optimization & Redis Caching](phase-6-optimization-and-caching.md) | Task 4: Eager loading, indexes, Redis |
| 7 | [Phase 7 — Background Jobs & Scheduling](phase-7-background-jobs-and-scheduling.md) | Task 5: Queues + weekly report + scheduler |
| 8 | [Phase 8 — Audit Logging](phase-8-audit-logging.md) | Task 6: Observer-driven audit trail |
| 9 | [Phase 9 — API Response Standardization](phase-9-api-response-standardization.md) | Bonus: Consistent JSON envelope |
| 10 | [Phase 10 — Testing & Validation](phase-10-testing-and-validation.md) | Bonus: 80%+ test coverage |
| 11 | [Phase 11 — Documentation & Deployment](phase-11-documentation-and-deployment.md) | Bonus: Docs, deploy, submission |

## 🔗 Dependency Graph
```
1 Database
  └─> 2 Auth
        ├─> 3 Expenses ─┐
        ├─> 4 Users ────┤
        │               ├─> 5 Policies ─> 6 Caching ─> 7 Jobs ─> 8 Audit ─> 9 Responses ─> 10 Tests ─> 11 Deploy
        └───────────────┘
```
Phases 3 and 4 can be built in parallel; everything downstream assumes both exist.

## ✅ How to Use Each Prompt
1. Open the phase file and paste its **Implementation Requirements** into Copilot Chat in the `expense-api/` project.
2. Generate the listed files (paths are given under **Expected File Structure**).
3. Run the phase's **Validation Commands**.
4. Confirm all **Quality Gates** pass — they are the entry condition for the next phase.
5. Commit, then proceed.

## 🛠️ Tech Stack
Laravel 10/11 · MySQL/PostgreSQL · Sanctum · Redis (cache + queue) · Laravel Queues & Scheduler · PHPUnit/Pest · Model Observers · Authorization Policies.

## 🎯 Global Definition of Done
- All 6 brief tasks implemented; multi-tenant isolation proven by tests.
- Sanctum auth + RBAC enforced at middleware **and** policy layers.
- No N+1 queries; Redis caching active with tenant-scoped keys.
- Weekly report job runs on schedule; audit trail complete.
- Consistent JSON responses; 80%+ test coverage; docs + deploy ready.
