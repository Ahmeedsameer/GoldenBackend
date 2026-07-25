<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'phone',
        'address',
        'bank_account_number',
        'mobile_wallet',
        'instapay',
        'iban',
        'opening_balance',
        'notes',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
    ];

    public function supplies(): HasMany
    {
        return $this->hasMany(Supply::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(SupplierContact::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }
}
