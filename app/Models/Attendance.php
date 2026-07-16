<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    public const PRESENT  = 'present';
    public const LATE     = 'late';
    public const ABSENT   = 'absent';
    public const HALF_DAY = 'half_day';
    public const LEAVE    = 'leave';

    protected $fillable = [
        'user_id',
        'shop_id',
        'date',
        'status',
        'marked_by',
        'note',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }
}
