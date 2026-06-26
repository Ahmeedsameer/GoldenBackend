<?php

namespace App\Modules\Stock\Services;

use App\Models\Supplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class SupplierService
{
    public function getAll(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Supplier::query()->withCount('supplies');

        if (!empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                  ->orWhere('phone', 'like', "%{$term}%");
            });
        }

        return $query->latest()->paginate($perPage);
    }

    public function findOrFail(int $id): Supplier
    {
        return Supplier::withCount('supplies')->findOrFail($id);
    }

    public function create(array $data): Supplier
    {
        return Supplier::create($data);
    }

    public function update(Supplier $supplier, array $data): Supplier
    {
        $supplier->update($data);
        return $supplier->fresh();
    }

    public function delete(Supplier $supplier): void
    {
        if ($supplier->supplies()->exists()) {
            abort(422, 'لا يمكن حذف المورد لوجود توريدات مرتبطة به');
        }

        $supplier->delete();
    }
}
