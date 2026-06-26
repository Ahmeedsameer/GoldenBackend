<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateCategoryRequest;
use App\Http\Requests\updateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Http\Services\CategorySerivce;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */

    public function __construct(private CategorySerivce $categorySerivce)
    {
      
    }


    public function index()
    {
        $query = Category::query();

        if (request()->has('name')) {
            $query->where('name', 'like', '%' . request('name') . '%');
        }

        if (request()->has('type')) {
            switch (request('type')) {
                case 'sub':
                    $query->whereNotNull('parent_id');
                    break;
                case 'main':
                    $query->whereNull('parent_id');
                    break;
                default:
                  
                    break;
            }
        }
        $categories = $query->with(['parent']);
        
        if (request('page') < 1) {
            $categories = $categories->get();
        }else{
        $limit = request()->get('limit', 10);

            $categories = $categories->paginate($limit);
        }
        
        // return response()->json($categories);
        return CategoryResource::collection($categories);
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
    public function store(CreateCategoryRequest $request)
    {
        
        $category = $this->categorySerivce->createCategory($request);

        return response()->json(['message'=> 'تم انشاء الفئه بنجاح'], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $category = Category::with('parent')->findOrFail($id);

        return new CategoryResource($category);
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
    public function update(updateCategoryRequest $request, string $id)
    {
            $category = $this->categorySerivce->update($request);
    
            return response()->json(['message'=> 'تم تحديث الفئه بنجاح'], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $category = Category::findOrFail($id);
        $category->delete();

        return response()->json(['message'=> 'تم حذف الفئه بنجاح'], 200);
    }
}
