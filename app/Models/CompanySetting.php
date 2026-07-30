<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class CompanySetting extends Model
{
    protected $fillable = [
        'name',
        'logo_path',
        'email',
        'phone',
        'address',
        'website',
        'description',
    ];

    protected $appends = ['logo_url'];

    /**
     * There is only ever one row. Fetch it (creating the default row on first use)
     * so every caller (public API, PDFs, admin form) reads the same singleton.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], ['name' => 'Golden Perfume']);
    }

    public function getLogoUrlAttribute(): ?string
    {
        if (!$this->logo_path) {
            return null;
        }

        return Storage::disk('public')->url($this->logo_path);
    }

    /**
     * Absolute filesystem path to the logo file, for embedding in dompdf-generated
     * PDFs (dompdf reads local paths directly; it does not need the public URL).
     */
    public function logoAbsolutePath(): ?string
    {
        if (!$this->logo_path || !Storage::disk('public')->exists($this->logo_path)) {
            return null;
        }

        return Storage::disk('public')->path($this->logo_path);
    }
}
