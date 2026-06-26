<?php

namespace App\Http\Services;

use App\Models\Category;
use Illuminate\Support\Facades\Storage;


class CategorySerivce{

    public function createCategory( $request){
        $data = $request->validated();
        $imagePath = null;
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $imagePath = $image->store('categories', 'public');
        }
        $data['image'] = $imagePath;
        $category = Category::create($data);

        return $category;
    }


    public function update($request){
        $data = $request->validated();
        $category = Category::findOrFail($request->id);
        if ($request->hasFile('image')) {
            #delet the previuse image 
                if ($category->image) {
                $previousImagePath = public_path('storage/' . $category->image);
                if (file_exists($previousImagePath)) {
                   
                    // Storage::delete($previousImagePath);
                        unlink($previousImagePath);
                }
            }
            $image = $request->file('image');
            $imagePath = $image->store('categories', 'public');
            $data['image'] = $imagePath;
        }
        $category->update($data);

        return $category;

    }
}   