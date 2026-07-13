<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrDeductionSetting extends Model
{
    public const MODE_DAILY_FRACTION = 'daily_fraction';
    public const MODE_FIXED          = 'fixed';

    protected $fillable = [
        'code',
        'label',
        'mode',
        'value',
        'is_active',
    ];

    protected $casts = [
        'value'     => 'decimal:4',
        'is_active' => 'boolean',
    ];
}
