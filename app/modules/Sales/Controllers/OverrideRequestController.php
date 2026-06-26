<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class OverrideRequestController extends Controller
{
    /**
     * POST /api/sales/override-requests
     * Seller submits an override request when estimated prices are below minimum.
     * Stored in Laravel cache — no DB write needed.
     */
    public function store()
    {
        $seller = auth()->user();

        $validated = request()->validate([
            'violations'              => ['required', 'array', 'min:1'],
            'violations.*.product_name'   => ['required', 'string'],
            'violations.*.estimated_price'=> ['required', 'numeric'],
            'violations.*.minimum_price'  => ['required', 'numeric'],
            'violations.*.category_name'  => ['required', 'string'],
            'justification'           => ['required', 'string', 'max:500'],
        ]);

        $id = (string) Str::uuid();

        $payload = [
            'id'            => $id,
            'seller_id'     => $seller->id,
            'seller_name'   => $seller->name,
            'shop_id'       => $seller->shop_id,
            'violations'    => $validated['violations'],
            'justification' => $validated['justification'],
            'status'        => 'pending',
            'manager_id'    => null,
            'manager_note'  => null,
            'token'         => null,
            'created_at'    => now()->toISOString(),
        ];

        // Store the request (TTL 30 min)
        Cache::put("override_request:{$id}", $payload, now()->addMinutes(30));

        // Maintain a per-shop index so the manager can list all requests
        $indexKey = "override_index:{$seller->shop_id}";
        $index    = Cache::get($indexKey, []);
        $index[]  = $id;
        $index    = array_slice($index, -100); // cap at 100 entries
        Cache::put($indexKey, $index, now()->addMinutes(35));

        return response()->json([
            'message' => 'تم إرسال طلب الموافقة بنجاح',
            'data'    => ['id' => $id],
        ]);
    }

    /**
     * GET /api/sales/override-requests/{id}
     * Seller polls this to know when the manager has responded.
     * Returns only the fields the seller needs (no internal manager data).
     */
    public function show(string $id)
    {
        $seller  = auth()->user();
        $request = Cache::get("override_request:{$id}");

        if (! $request || (int) $request['seller_id'] !== $seller->id) {
            return response()->json(['message' => 'الطلب غير موجود أو انتهت صلاحيته'], 404);
        }

        return response()->json([
            'message' => 'تم جلب حالة الطلب بنجاح',
            'data'    => [
                'id'           => $request['id'],
                'status'       => $request['status'],
                'manager_note' => $request['manager_note'],
                'token'        => $request['token'],
            ],
        ]);
    }
}
