<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveCashOut extends Model
{
    protected $fillable = [
        'user_id',
        'date',
        'days',
        'daily_rate',
        'amount',
        'note',
        'created_by',
    ];

    protected $casts = [
        'date'       => 'date',
        'days'       => 'decimal:2',
        'daily_rate' => 'decimal:2',
        'amount'     => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
