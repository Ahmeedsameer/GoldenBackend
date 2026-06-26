<?php

namespace App\Http\Services;

use App\Models\Safe;
use App\Models\SafeType;
use App\Models\Shop;
use App\Models\User;

class ShopService
{
    // ─── CRUD ────────────────────────────────────────────────────────────────

    public function getAll(array $filters = [], int $perPage = 15)
    {
        $query = Shop::query()->with(['manager:id,name,email,role']);

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('address', 'like', "%{$search}%")
                  ->orWhere('username', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->withCount('employees')->paginate($perPage);
    }

    public function findOrFail(int $id): Shop
    {
        return Shop::with(['manager:id,name,email,role'])->withCount('employees')->findOrFail($id);
    }

    public function create(array $data): Shop
    {
        $data['password'] = bcrypt($data['password']);
        $shop = Shop::create($data);

        $physicalTypeId = SafeType::where('kind', 'physical')->value('id');

        Safe::create([
            'shop_id'      => $shop->id,
            'safe_type_id' => $physicalTypeId,
            'is_active'    => true,
        ]);

        return $shop;
    }

    public function update(Shop $shop, array $data): Shop
    {
        if (!empty($data['password'])) {
            $data['password'] = bcrypt($data['password']);
        } else {
            unset($data['password']);
        }

        $shop->update($data);
        return $shop->fresh(['manager:id,name,email,role']);
    }

    public function delete(Shop $shop): void
    {
       
        $shop->employees()->update(['shop_id' => null]);
        $shop->delete();
    }

    public function checkUsername(string $username, ?int $excludeId = null): bool
    {
        return Shop::where('username', $username)
            ->when($excludeId, fn($q) => $q->where('id', '!=', $excludeId))
            ->withTrashed()
            ->exists();
    }

    // ─── Employee management ──────────────────────────────────────────────────

    public function listEmployees(Shop $shop, int $perPage = 20)
    {
        return $shop->employees()
            ->select('id', 'name', 'email', 'phone', 'role', 'shift_start', 'shift_end')
            ->paginate($perPage);
    }

    public function addEmployee(Shop $shop, User $user): void
    {
        $user->update(['shop_id' => $shop->id]);
    }

    public function removeEmployee(Shop $shop, User $user): void
    {
        // تأكد أن الموظف فعلاً ينتمي لهذا الفرع
        if ((int) $user->shop_id !== (int) $shop->id) {
            abort(422, 'هذا الموظف لا ينتمي إلى هذا الفرع');
        }

        $user->update(['shop_id' => null]);
    }

    // ─── Manager management ───────────────────────────────────────────────────

    public function assignManager(Shop $shop, User $user): Shop
    {
        $shop->update(['manager_id' => $user->id]);
        return $shop->fresh(['manager:id,name,email,role']);
    }

    public function removeManager(Shop $shop): Shop
    {
        $shop->update(['manager_id' => null]);
        return $shop->fresh();
    }
}
