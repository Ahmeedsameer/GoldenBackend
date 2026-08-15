<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;

/**
 * Public (unauthenticated) product catalog — powers the marketing Landing
 * Page's "Products" section. Deliberately separate from ProductResource
 * (used everywhere else in the admin/POS app), which also exposes
 * cost/profit/inventory fields that must never reach an anonymous visitor.
 * Registered outside every CheckRole group, same pattern as the existing
 * public `GET /company-settings` route.
 */
class PublicCatalogController extends Controller
{
    /** GET /api/public/catalog — active, catalog-visible products only, public-safe fields. */
    public function index(Request $request)
    {
        $products = Product::query()
            ->where('is_active', true)
            ->where('show_in_catalog', true)
            ->with('category:id,name')
            ->orderBy('name')
            ->limit(24)
            ->get([
                'id', 'name', 'image', 'description', 'selling_price', 'capacity_ml', 'category_id',
            ])
            ->map(fn (Product $p) => [
                'id'            => $p->id,
                'name'          => $p->name,
                'image'         => $p->image ? asset('storage/'.$p->image) : null,
                'description'   => $p->description,
                'price'         => $p->selling_price,
                'capacity_ml'   => $p->capacity_ml,
                'category_name' => $p->category?->name,
            ]);

        return response()->json(['message' => 'ok', 'data' => $products]);
    }
}
