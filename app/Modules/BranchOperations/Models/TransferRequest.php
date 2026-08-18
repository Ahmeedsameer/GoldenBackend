<?php

namespace App\Modules\BranchOperations\Models;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A branch-to-branch stock transfer request, going through
 * draft -> submitted -> approved|rejected -> preparing -> shipped -> received -> closed.
 * Never mutates Goods.current_quantity itself — see TransferRequestService,
 * which is the only place inventory is touched (decrement on ship, increment
 * on receive), keeping this model a pure record of the request/workflow.
 */
class TransferRequest extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_PREPARING = 'preparing';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_CLOSED = 'closed';

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    protected $fillable = [
        'request_number', 'source_shop_id', 'destination_shop_id', 'requested_by',
        'requested_date', 'priority', 'status', 'notes', 'is_emergency',
        'approved_by', 'approved_at', 'shipped_at', 'received_at', 'closed_at',
        'cancelled_at', 'cancelled_by',
    ];

    protected $casts = [
        'requested_date' => 'date',
        'approved_at' => 'datetime',
        'shipped_at' => 'datetime',
        'received_at' => 'datetime',
        'closed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'is_emergency' => 'boolean',
    ];

    public function sourceShop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'source_shop_id');
    }

    public function destinationShop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'destination_shop_id');
    }

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransferRequestItem::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(TransferRequestLog::class)->orderBy('created_at');
    }

    public function internalInvoice()
    {
        return $this->hasOne(InternalTransferInvoice::class);
    }
}
