<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ManagerOverrideController extends Controller
{
    /**
     * GET /api/manager/override-requests
     * Lists all override requests for the manager's shop (pending or recent).
     */
    public function index()
    {
        $manager  = auth()->user();
        $indexKey = "override_index:{$manager->shop_id}";
        $index    = Cache::get($indexKey, []);

        $requests = [];
        foreach ($index as $id) {
            $entry = Cache::get("override_request:{$id}");
            if ($entry && (int) $entry['shop_id'] === $manager->shop_id) {
                $requests[] = $entry;
            }
        }

        // Sort: pending first, then by creation time descending
        usort($requests, function ($a, $b) {
            if ($a['status'] === 'pending' && $b['status'] !== 'pending') return -1;
            if ($b['status'] === 'pending' && $a['status'] !== 'pending') return  1;
            return strcmp($b['created_at'], $a['created_at']);
        });

        return response()->json([
            'message' => 'تم جلب طلبات الموافقة بنجاح',
            'data'    => array_values($requests),
        ]);
    }

    /**
     * PUT /api/manager/override-requests/{id}
     * Manager approves or rejects an override request.
     * On approval a short-lived one-time token is generated for the seller.
     */
    public function respond(string $id)
    {
        $manager = auth()->user();

        $validated = request()->validate([
            'action' => ['required', 'in:approved,rejected'],
            'note'   => ['nullable', 'string', 'max:300'],
        ]);

        $entry = Cache::get("override_request:{$id}");

        if (! $entry) {
            return response()->json(['message' => 'الطلب غير موجود أو انتهت صلاحيته'], 404);
        }

        if ((int) $entry['shop_id'] !== $manager->shop_id) {
            return response()->json(['message' => 'غير مصرح لك بإدارة هذا الطلب'], 403);
        }

        if ($entry['status'] !== 'pending') {
            return response()->json(['message' => 'تم البت في هذا الطلب مسبقاً'], 422);
        }

        $token = null;

        if ($validated['action'] === 'approved') {
            // Create a separate one-time token for the seller to include in the invoice POST
            $token = (string) Str::uuid();
            Cache::put("override_token:{$token}", [
                'seller_id'   => (int) $entry['seller_id'],
                'shop_id'     => (int) $entry['shop_id'],
                'approved_by' => $manager->id,
            ], now()->addMinutes(15));
        }

        $entry['status']       = $validated['action'];
        $entry['manager_id']   = $manager->id;
        $entry['manager_note'] = $validated['note'] ?? null;
        $entry['token']        = $token;

        // Persist the updated request so the seller's polling can read it
        Cache::put("override_request:{$id}", $entry, now()->addMinutes(30));

        $message = $validated['action'] === 'approved'
            ? 'تمت الموافقة على طلب البيع'
            : 'تم رفض طلب البيع';

        return response()->json(['message' => $message]);
    }
}
