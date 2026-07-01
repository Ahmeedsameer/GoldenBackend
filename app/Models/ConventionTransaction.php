<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConventionTransaction extends Model
{
    use SoftDeletes;

    public const TYPE_WITHDRAW = 'WITHDRAW';
    public const TYPE_RECHARGE = 'RECHARGE';

    protected $fillable = [
        'convention_id',
        'type',
        'manager_id',
        'admin_id',
        'amount',
        'reason',
        'notes',
        'date',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'date'   => 'date',
    ];

    // العهدة التي تتبعها الحركة
    public function convention(): BelongsTo
    {
        return $this->belongsTo(Convention::class);
    }

    // المدير الذي سجّل الحركة
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    // الأدمن الذي نفّذ الحركة (إن وجد)
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
