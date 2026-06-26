<?php


namespace App\Http\Services;

use App\Http\Requests\CreateProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Product;
use Log;

class ProductService{

    public function create(CreateProductRequest $request){
        $data = $request->validated();
        $imagePath = null;
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $imagePath = $image->store('products', 'public');
        }
        $data['image'] = $imagePath;
        $data['is_active'] = ($data['is_active'] === 'true' || $data['is_active'] === '1') ? true : false;
        $product = Product::create($data);

        return $product;
    }


        public function update(UpdateProductRequest $request,$id){
            $data = $request->validated();
            $product = Product::findOrFail($id);
            if ($request->hasFile('image')) {
             
                    if ($product->image) {
                    $previousImagePath = public_path('storage/' . $product->image);
                    if (file_exists($previousImagePath)) {
                        
                       
                            unlink($previousImagePath);
                    }
                }
                $image = $request->file('image');
                $imagePath = $image->store('products', 'public');
                $data['image'] = $imagePath;
            }
            $data['is_active'] = ($data['is_active'] === 'true' || $data['is_active'] === '1') ? true : false;

            $product->update($data);
            Log::info('Product updated successfully', ['product_id' => $product->id, 'updated_data' => $data]);
            return $product;
    
        }


}