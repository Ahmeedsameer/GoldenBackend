<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SafeType extends Model
{
    protected $fillable = ['name', 'kind', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function safes(): HasMany
    {
        return $this->hasMany(Safe::class);
    }

    public function isPhysical(): bool
    {
        return $this->kind === 'physical';
    }
}
