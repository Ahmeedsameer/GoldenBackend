<?php

namespace App\Http\Controllers;

use App\Models\SocialChannel;
use Illuminate\Http\Request;

/**
 * Admin-only management of the Landing Page's social/contact channels
 * (Facebook, Instagram, WhatsApp, TikTok, YouTube). The public-facing read
 * path is CompanySettingsController::show(), which only exposes enabled
 * channels with a value — this controller is the admin CRUD surface.
 */
class SocialChannelController extends Controller
{
    public function index()
    {
        return response()->json([
            'message' => 'ok',
            'data'    => SocialChannel::orderBy('id')->get(),
        ]);
    }

    public function update(Request $request, SocialChannel $socialChannel)
    {
        $validated = $request->validate([
            'value'      => ['nullable', 'string', 'max:255'],
            'is_enabled' => ['required', 'boolean'],
        ]);

        $socialChannel->update($validated);

        return response()->json([
            'message' => 'تم تحديث القناة بنجاح',
            'data'    => $socialChannel->fresh(),
        ]);
    }
}
