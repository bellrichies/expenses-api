<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Expense Management API',
    description: 'Multi-Tenant SaaS Expense Management API — secure, role-based, company-scoped.',
    contact: new OA\Contact(email: 'gplanet.tech@gmail.com'),
    license: new OA\License(name: 'MIT')
)]
#[OA\Server(url: '/api', description: 'Local / Development')]
#[OA\Server(url: 'https://api.yourdomain.com/api', description: 'Production')]
#[OA\SecurityScheme(
    securityScheme: 'sanctum',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'token',
    description: 'Sanctum token — obtain via /auth/login or /auth/register'
)]
#[OA\Tag(name: 'Auth', description: 'Registration, login, logout, profile')]
#[OA\Tag(name: 'Expenses', description: 'CRUD — company-scoped, role-gated, audit-logged')]
#[OA\Tag(name: 'Users', description: 'Admin-only user management')]
#[OA\Tag(name: 'Audit Logs', description: 'Admin-only, read-only audit trail')]
abstract class Controller
{
    use AuthorizesRequests;
}
