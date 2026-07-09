<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin management of a product's BOM/recipe (its components).
 */
class ProductComponentController extends Controller
{
    /** GET /api/products/{id}/components */
    public function index(string $id)
    {
        $product = Product::with(['components.component:id,name,sku,scalar'])->findOrFail($id);

        $data = $product->components->map(fn ($c) => [
            'id'                   => $c->id,
            'component_product_id' => $c->component_product_id,
            'name'                 => $c->component->name  ?? '—',
            'sku'                  => $c->component->sku   ?? null,
            'unit'                 => $c->component->scalar ?? '',
            'quantity'             => (float) $c->quantity,
        ]);

        return response()->json(['message' => 'ok', 'data' => $data]);
    }

    /** PUT /api/products/{id}/components — replace the whole recipe. */
    public function sync(Request $request, string $id)
    {
        $product = Product::findOrFail($id);

        $validated = $request->validate([
            'components'                        => ['present', 'array'],
            'components.*.component_product_id' => ['required', 'integer', 'different:__parent', 'exists:products,id'],
            'components.*.quantity'             => ['required', 'numeric', 'min:0.001'],
        ]);

        DB::transaction(function () use ($product, $validated) {
            $product->components()->delete();
            foreach ($validated['components'] as $c) {
                // A product cannot be a component of itself.
                if ((int) $c['component_product_id'] === $product->id) {
                    continue;
                }
                $product->components()->create([
                    'component_product_id' => $c['component_product_id'],
                    'quantity'             => $c['quantity'],
                ]);
            }
        });

        return response()->json(['message' => 'تم حفظ مكوّنات المنتج بنجاح']);
    }
}
