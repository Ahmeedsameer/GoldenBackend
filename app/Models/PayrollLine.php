<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollLine extends Model
{
    public const BASE                = 'base';
    public const PERSONAL_COMMISSION = 'personal_commission';
    public const BRANCH_COMMISSION   = 'branch_commission';
    public const DEDUCTION           = 'deduction';

    protected $fillable = [
        'payroll_id',
        'type',
        'label',
        'shop_id',
        'amount',
        'meta',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'meta'   => 'array',
    ];

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
