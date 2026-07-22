<?php

namespace App\Modules\BranchOperations\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Full audit trail of every transfer-request action — who did what, when,
 * and the status transition it caused. Written by TransferRequestService on
 * every state change; never edited or deleted afterward.
 */
class TransferRequestLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'transfer_request_id', 'user_id', 'action',
        'previous_status', 'new_status', 'notes', 'ip_address', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function transferRequest(): BelongsTo
    {
        return $this->belongsTo(TransferRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
