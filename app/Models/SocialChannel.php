<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Admin-managed social/contact channels shown on the public Landing Page
 * (Facebook, Instagram, WhatsApp, TikTok, YouTube). `value` holds a URL for
 * every platform except 'whatsapp', where it holds a local phone number
 * (same format as CompanySetting::phone) that the frontend turns into a
 * wa.me deep link.
 */
class SocialChannel extends Model
{
    protected $fillable = ['platform', 'label', 'value', 'is_enabled'];

    protected $casts = ['is_enabled' => 'boolean'];

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true)->whereNotNull('value')->where('value', '!=', '');
    }
}
