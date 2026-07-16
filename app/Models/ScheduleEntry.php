<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduleEntry extends Model
{
    public const WORK          = 'work';
    public const LEAVE         = 'leave';
    public const OFF_DAY       = 'off_day';
    public const HOLIDAY       = 'holiday';
    public const SICK_LEAVE    = 'sick_leave';
    public const TRAINING      = 'training';
    public const BUSINESS_TRIP = 'business_trip';
    public const ABSENT        = 'absent';
    public const LATE          = 'late';
    public const HALF_DAY      = 'half_day';

    public const TYPES = [
        self::WORK, self::LEAVE, self::OFF_DAY, self::HOLIDAY, self::SICK_LEAVE,
        self::TRAINING, self::BUSINESS_TRIP, self::ABSENT, self::LATE, self::HALF_DAY,
    ];

    protected $fillable = [
        'user_id', 'date', 'type', 'shift_template_id', 'start_time', 'end_time', 'shop_id',
        'notes', 'is_published', 'published_at', 'created_by',
    ];

    protected $casts = [
        'date'         => 'date',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shiftTemplate(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
