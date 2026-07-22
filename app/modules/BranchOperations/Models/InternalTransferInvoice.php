<?php

namespace App\Modules\BranchOperations\Models;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Auto-generated the moment a transfer is approved. INTERNAL ONLY — lives in
 * its own table (never `invoices`), so it is structurally impossible for it
 * to appear in Sales, Revenue, Customer, or Financial reports, all of which
 * only ever query the sales `invoices` table.
 */
class InternalTransferInvoice extends Model
{
    protected $fillable = [
        'invoice_number', 'transfer_request_id', 'source_shop_id', 'destination_shop_id',
        'date', 'user_id', 'reference_value', 'status',
    ];

    protected $casts = [
        'date' => 'date',
        'reference_value' => 'decimal:2',
    ];

    public function transferRequest(): BelongsTo
    {
        return $this->belongsTo(TransferRequest::class);
    }

    public function sourceShop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'source_shop_id');
    }

    public function destinationShop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'destination_shop_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
