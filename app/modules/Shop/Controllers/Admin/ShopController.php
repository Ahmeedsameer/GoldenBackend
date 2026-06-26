<?php

namespace App\Modules\Shop\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Shop\Requests\StoreShopRequest;
use App\Modules\Shop\Requests\UpdateShopRequest;
use App\Modules\Shop\Services\ShopService;
use Illuminate\Http\Request;

class ShopController extends Controller
{
    /**
     * Display a listing of the resource.
     */

     public function __construct(private ShopService $shopService)
     {
        
     }
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {

      
        
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreShopRequest $request)
    {
        $request->validated();
        $this->shopService->createShop($request->all());

        return response()->json([
            'message' => 'تم انشاء الفرح بنجاح'
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit()
    {
       
    }

    /**
     * Update the specified resource in storage.
     */
    public function update( UpdateShopRequest $request, string $id)
    {
        $request->validated();
        $request['id'] = $id;
        $this->shopService->updateShop($request->all());

        return response()->json([
            'message' => 'تم تحديث الفرح بنجاح'
        ], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
