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
            'id'                    => $c->id,
            'component_product_id' => $c->component_product_id,
            'name'                  => $c->component->name  ?? '—',
            'sku'                   => $c->component->sku   ?? null,
            'unit'                  => $c->component->scalar ?? '',
            'quantity'              => (float) $c->quantity,
            'is_variable_quantity'  => (bool) $c->is_variable_quantity,
            'component_group'       => $c->component_group,
        ]);

        return response()->json(['message' => 'ok', 'data' => $data]);
    }

    /** PUT /api/products/{id}/components — replace the whole recipe. */
    public function sync(Request $request, string $id)
    {
        $product = Product::findOrFail($id);

        // Compound Products (perfumes) are never composed of a stored/predefined
        // recipe — the seller freely picks any oil + any bottle fresh on every
        // sale, in the Sales Catalog's Product Builder. Nothing is ever saved
        // on the product itself, so this endpoint must reject them outright.
        if ($product->product_type === Product::TYPE_COMPOUND) {
            abort(422, 'المنتجات المركّبة (العطور) لا تملك تركيبة محفوظة — يختار البائع الزيت والزجاجة عند كل عملية بيع.');
        }

        $validated = $request->validate([
            'components'                           => ['present', 'array'],
            'components.*.component_product_id'    => ['required', 'integer', 'different:__parent', 'exists:products,id'],
            'components.*.quantity'                => ['required', 'numeric', 'min:0.001'],
            // Optional — both default to the original fixed/mandatory behavior
            // when omitted, so existing recipe-save payloads keep working as-is.
            'components.*.is_variable_quantity'    => ['nullable', 'boolean'],
            'components.*.component_group'         => ['nullable', 'string', 'max:100'],
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
                    'is_variable_quantity' => $c['is_variable_quantity'] ?? false,
                    'component_group'      => $c['component_group'] ?? null,
                ]);
            }
        });

        return response()->json(['message' => 'تم حفظ مكوّنات المنتج بنجاح']);
    }
}
