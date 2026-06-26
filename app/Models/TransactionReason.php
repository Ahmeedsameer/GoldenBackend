<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransactionReason extends Model
{
    protected $fillable = ['name', 'direction', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function transactions(): HasMany
    {
        return $this->hasMany(SafeTransaction::class, 'reason_id');
    }

    /**
     * Check if this reason is valid for a given direction (in / out).
     */
    public function isValidFor(string $direction): bool
    {
        return $this->direction === 'both' || $this->direction === $direction;
    }
}
