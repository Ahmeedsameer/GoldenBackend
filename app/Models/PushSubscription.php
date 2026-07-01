<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushSubscription extends Model
{
    protected $fillable = ['user_id', 'endpoint', 'endpoint_hash', 'public_key', 'auth_token'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Create or update a subscription for a user, keyed by the endpoint. */
    public static function store(int $userId, string $endpoint, string $publicKey, string $authToken): self
    {
        return static::updateOrCreate(
            ['endpoint_hash' => hash('sha256', $endpoint)],
            [
                'user_id'    => $userId,
                'endpoint'   => $endpoint,
                'public_key' => $publicKey,
                'auth_token' => $authToken,
            ]
        );
    }
}
