<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shop extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'branch_bonus_percent',
        'address',
        'username',
        'password',
        'status',
        'manager_id',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'password'             => 'hashed',
        'status'               => 'string',
        'branch_bonus_percent' => 'decimal:2',
    ];

    // الموظفون المنتسبون للفرع (علاقة واحد لمتعدد)
    public function employees(): HasMany
    {
        return $this->hasMany(User::class, 'shop_id');
    }

    // المدير المسؤول عن الفرع
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    // الخزنة الخاصة بالفرع
    public function safe(): HasOne
    {
        return $this->hasOne(Safe::class);
    }
}