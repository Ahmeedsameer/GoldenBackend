<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreShopRequest;
use App\Http\Requests\UpdateShopRequest;
use App\Http\Resources\ShopResource;
use App\Http\Services\ShopService;
use App\Models\Shop;

class ShopController extends Controller
{
    public function __construct(private ShopService $shopService) {}

    public function index()
    {
        $filters = request()->only(['search', 'status']);
        $perPage = request()->integer('per_page', 15);

        $shops = $this->shopService->getAll($filters, $perPage);

        return ShopResource::collection($shops);
    }

    public function show(string $id)
    {
        $shop = $this->shopService->findOrFail((int) $id);

        return new ShopResource($shop);
    }

    public function store(StoreShopRequest $request)
    {
        $shop = $this->shopService->create($request->validated());

        return response()->json([
            'message' => 'تم إنشاء الفرع بنجاح',
            'data'    => new ShopResource($shop),
        ], 201);
    }

    public function update(UpdateShopRequest $request, string $id)
    {
        $shop = Shop::findOrFail($id);
        $updated = $this->shopService->update($shop, $request->validated());

        return response()->json([
            'message' => 'تم تحديث الفرع بنجاح',
            'data'    => new ShopResource($updated),
        ]);
    }

    public function destroy(string $id)
    {
        $shop = Shop::findOrFail($id);
        $this->shopService->delete($shop);

        return response()->json([
            'message' => 'تم حذف الفرع بنجاح',
        ]);
    }

    public function checkUsername(string $username)
    {
        $excludeId = request()->integer('exclude_id') ?: null;
        $exists = $this->shopService->checkUsername($username, $excludeId);

        return response()->json(['exists' => $exists]);
    }
}
