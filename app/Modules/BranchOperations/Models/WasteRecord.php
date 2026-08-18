<?php

namespace App\Modules\BranchOperations\Models;

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WasteRecord extends Model
{
    public const REASONS = ['broken', 'expired', 'leakage', 'lost', 'damaged_during_transfer', 'other'];

    protected $fillable = [
        'shop_id', 'product_id', 'quantity', 'reason', 'user_id', 'date', 'estimated_value', 'notes',
    ];

    protected $casts = [
        'date' => 'date',
        'quantity' => 'decimal:3',
        'estimated_value' => 'decimal:2',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(WasteRecordBatch::class);
    }
}
