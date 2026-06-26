<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Http\Services\ProductService;
use App\Models\Product;
use Illuminate\Http\Request;
use Log;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */




   

    public function __construct(private ProductService $productService) {
        
    }

    public function index()
    {
        
        $query = Product::query();

        if($name = request('name')) {
            $query->where('name', 'like', "%$name%");
        }

        $limit = request('limit', 30);

        $products = $query->with('category')->paginate($limit);
        
        return ProductResource::collection($products);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateProductRequest $request)
    {
        $this->productService->create($request);

        return response()->json(['message'=> 'تم انشاء المنتج بنجاح'], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $product = Product::with('category')->findOrFail($id);

        return new ProductResource($product);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateProductRequest $request, string $id)

    {
        Log::info('Updating product ', ['data' => $request->all(), 'id' => $id]);
        
        $this->productService->update($request,$id);

        return response()->json(['message'=> 'تم تحديث المنتج بنجاح'], 200);   
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
