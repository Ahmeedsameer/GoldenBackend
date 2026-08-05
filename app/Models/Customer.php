<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = [
        'name', 'phone', 'email', 'address', 'shop_id',
        'notes', 'notes_updated_by', 'notes_updated_at',
    ];

    protected $casts = [
        'notes_updated_at' => 'datetime',
    ];

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function notesUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'notes_updated_by');
    }

    /** Manual tags only — automatic tags are computed live, never persisted. */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'customer_tag');
    }

    public function noteHistory(): HasMany
    {
        return $this->hasMany(CustomerNote::class);
    }
}
