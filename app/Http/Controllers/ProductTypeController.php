<?php

namespace App\Http\Controllers;

use App\Models\ProductType;

/**
 * Read-only listing of Product Types (Oil, Bottle, Accessory, Packaging, …).
 * The backend is the single source of truth for types and their inventory
 * behavior; the frontend only displays them.
 */
class ProductTypeController extends Controller
{
    public function index()
    {
        $types = ProductType::orderBy('name')
            ->get(['id', 'code', 'name', 'is_fixed', 'sold_by', 'pricing_source', 'default_unit', 'default_warning_quantity', 'default_critical_quantity']);

        return response()->json(['message' => 'ok', 'data' => $types]);
    }
}
