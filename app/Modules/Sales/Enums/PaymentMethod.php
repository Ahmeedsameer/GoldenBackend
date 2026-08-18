<?php

namespace App\Modules\Sales\Enums;

/**
 * Supported invoice payment methods.
 *
 * To add a new method (InstaPay, Bank Transfer, Vodafone Cash, …):
 *   1. Add a case here.
 *   2. If it needs a reference/transaction number, list it in
 *      requiresTransactionNumber().
 *   3. Add its Arabic label in label().
 *
 * Nothing else in the backend needs to change — validation, persistence
 * and reporting all read from this enum.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Visa = 'visa';

    /** All method values — used by validation (`in:...`) and reports. */
    public static function values(): array
    {
        return array_map(fn (self $m) => $m->value, self::cases());
    }

    /** Methods that must carry a transaction/reference number. */
    public static function requiresTransactionNumber(): array
    {
        return [self::Visa->value];
    }

    /** Whether this method requires a transaction/reference number. */
    public function needsTransactionNumber(): bool
    {
        return in_array($this->value, self::requiresTransactionNumber(), true);
    }

    /** Human-readable Arabic label. */
    public function label(): string
    {
        return match ($this) {
            self::Cash => 'نقدي',
            self::Visa => 'فيزا',
        };
    }
}
