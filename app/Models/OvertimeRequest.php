<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OvertimeRequest extends Model
{
    public const ACTIVE    = 'active';
    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'date',
        'start_time',
        'end_time',
        'hourly_rate',
        'hours',
        'pay',
        'reason',
        'created_by',
        'notes',
        'status',
    ];

    protected $casts = [
        'date'        => 'date',
        'hourly_rate' => 'decimal:2',
        'hours'       => 'decimal:2',
        'pay'         => 'decimal:2',
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
