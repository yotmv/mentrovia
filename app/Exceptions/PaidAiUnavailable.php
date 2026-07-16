<?php

namespace App\Exceptions;

use Exception;

class PaidAiUnavailable extends Exception
{
    private const SettingsRequiredCode = 1000;

    private const ConcurrencyExceededCode = 1001;

    private const BudgetExceededCode = 1002;

    private const AuditUnavailableCode = 1003;

    public static function disabled(): self
    {
        return new self('Paid AI is disabled for this account.', self::SettingsRequiredCode);
    }

    public static function routeUnavailable(): self
    {
        return new self('No permitted AI provider is available for this account.', self::SettingsRequiredCode);
    }

    public static function entitlementRequired(): self
    {
        return new self('Paid AI is unavailable for this account entitlement.', self::SettingsRequiredCode);
    }

    public static function budgetExceeded(): self
    {
        return new self('This account has reached its configured AI spending limit.', self::BudgetExceededCode);
    }

    public static function budgetEstimateUnavailable(): self
    {
        return new self('This AI model cannot be used with a spending limit because a safe cost estimate is unavailable.', self::BudgetExceededCode);
    }

    public static function concurrencyExceeded(): self
    {
        return new self('This account has reached its configured concurrent AI operation limit.', self::ConcurrencyExceededCode);
    }

    public static function auditUnavailable(): self
    {
        return new self('The AI operation could not be securely audited.', self::AuditUnavailableCode);
    }

    public function requiresSettings(): bool
    {
        return $this->getCode() === self::SettingsRequiredCode;
    }

    public function isBudgetExceeded(): bool
    {
        return $this->getCode() === self::BudgetExceededCode;
    }

    public function isAuditUnavailable(): bool
    {
        return $this->getCode() === self::AuditUnavailableCode;
    }

    public function isConcurrencyExceeded(): bool
    {
        return $this->getCode() === self::ConcurrencyExceededCode;
    }
}
