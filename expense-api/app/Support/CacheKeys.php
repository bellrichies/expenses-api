<?php

namespace App\Support;

class CacheKeys
{
    public const TTL_USERS   = 3600; // 1 hour
    public const TTL_SUMMARY = 1800; // 30 minutes

    public static function companyUsers(int $companyId): string
    {
        return "company.{$companyId}.users";
    }

    public static function userExpenseSummary(int $userId): string
    {
        return "user.{$userId}.expenses.summary";
    }

    public static function companyExpenseStats(int $companyId): string
    {
        return "company.{$companyId}.expenses.stats";
    }
}
