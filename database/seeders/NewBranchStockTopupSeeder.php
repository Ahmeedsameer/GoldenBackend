<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Safe;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\TransactionReason;
use App\Models\User;
use App\Modules\BranchOperations\Services\TransferRequestService;
use App\Modules\Safe\Services\SafeService;
use App\Modules\Sales\Services\SalesService;
use App\Modules\Stock\Services\SupplyService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * One-off corrective top-up: BranchActivitySeeder's first run funded each new
 * branch's opening stock purchase from that branch's OWN (still-empty) safe,
 * so every supply/transfer/sale failed with "insufficient balance" — while
 * attendance and payroll (which don't touch the safe) succeeded normally.
 * This redoes ONLY the stock + sales portion (now with a proper opening
 * capital float first) without touching attendance/payroll again.
 *
 * BranchActivitySeeder itself has already been fixed with the same float-
 * deposit step for any future fresh-install run — this class exists purely
 * to backfill the already-partially-seeded database in its current state.
 */
class NewBranchStockTopupSeeder extends Seeder
{
    private User $admin;
    private int $egpId = 1;
    private array $readyProducts = [];
    private array $rawMaterials = [];
    private array $packaging = [];
    private array $paymentMethodIds = [1, 7, 8, 9, 10];

    public function run(): void
    {
        $this->admin = User::where('role', 'admin')->orderBy('id')->firstOrFail();
        $this->readyProducts = Product::where('product_type', 'ready_product')
            ->where('is_active', true)->where('show_in_catalog', true)
            ->whereNotIn('id', [107])->whereNotNull('selling_price')->get()->all();
        $this->rawMaterials = Product::where('product_type', 'raw_material')
            ->whereNotIn('id', [103, 104, 106])->get()->all();
        $this->packaging = Product::where('product_type', 'packaging')->get()->all();

        $warehouseShopId = Shop::where('is_warehouse', true)->value('id');
        $supplyService = app(SupplyService::class);
        $transferService = app(TransferRequestService::class);
        $safeService = app(SafeService::class);
        $salesService = app(SalesService::class);
        $reasonId = TransactionReason::where('name', 'إيداع نقدي')->value('id');

        $endOfHistory = Carbon::parse('2026-08-01');
        $suppliers = Supplier::whereIn('id', [1, 2, 3, 4, 5])->get()->all();

        foreach (BranchExpansionSeeder::NEW_BRANCHES as $name => [$address, $openedOn, $tier]) {
            $shop = Shop::where('name', $name)->first();
            $safe = Safe::where('shop_id', $shop?->id)->first();
            $employees = User::where('shop_id', $shop?->id)->whereIn('role', ['manager', 'sales'])->get()->all();
            if (! $shop || ! $safe || empty($employees)) {
                continue;
            }

            $start = Carbon::parse($openedOn);
            Auth::guard('api')->setUser($this->admin);

            Carbon::setTestNow($start->copy()->subDays(3)->setTime(9, 0));
            if ($reasonId) {
                try {
                    $safeService->adminDeposit($safe, $this->egpId, 60000, $reasonId, 'رأس مال افتتاحي للفرع', $this->admin->id);
                } catch (Throwable $e) {
                }
            }

            Carbon::setTestNow($start->copy()->subDays(2)->setTime(10, 0));
            $supplier = $suppliers[array_rand($suppliers)];
            $items = [];
            foreach ($this->pickRandom($this->rawMaterials, 3) as $rm) {
                $items[] = ['product_id' => $rm->id, 'quantity' => rand(1000, 2500), 'unit_price' => (float) $rm->price_per_gram, 'unit' => $rm->scalar];
            }
            foreach ($this->pickRandom($this->packaging, 2) as $pk) {
                $items[] = ['product_id' => $pk->id, 'quantity' => rand(200, 500), 'unit_price' => (float) $pk->selling_price * 0.55, 'unit' => 'pcs'];
            }
            try {
                $supplyService->create([
                    'supplier_id' => $supplier->id, 'items' => $items,
                    'payment_method' => 'immediate', 'safe_id' => $safe->id, 'currency_id' => $this->egpId,
                ], $this->admin);

                Carbon::setTestNow($start->copy()->subDay()->setTime(10, 0));
                $shipItems = array_map(fn ($it) => ['product_id' => $it['product_id'], 'requested_quantity' => round($it['quantity'] * 0.7, 3)], $items);
                $transfer = $transferService->create([
                    'source_shop_id' => $warehouseShopId, 'destination_shop_id' => $shop->id,
                    'items' => $shipItems, 'notes' => 'تجهيز مخزون افتتاح الفرع',
                ], $this->admin, submitImmediately: true);

                Carbon::setTestNow($start->copy()->subHours(6));
                $manager = $employees[0];
                Auth::guard('api')->setUser($manager);
                $receipts = array_map(fn ($l) => ['item_id' => $l->id, 'received_quantity' => (float) $l->requested_quantity], $transfer->items->all());
                $transferService->receive($transfer, $manager, $receipts);
                Auth::guard('api')->setUser($this->admin);
            } catch (Throwable $e) {
                $this->command?->warn("{$name}: opening raw-material stock failed — " . $e->getMessage());
            }

            Carbon::setTestNow($start->copy()->subDay()->setTime(11, 0));
            try {
                $readyItems = [];
                foreach ($this->pickRandom($this->readyProducts, 6) as $rp) {
                    $readyItems[] = ['product_id' => $rp->id, 'quantity' => rand(15, 40), 'unit_price' => round((float) $rp->selling_price * 0.5, 2), 'unit' => 'pcs'];
                }
                $localSupply = $supplyService->create([
                    'supplier_id' => $supplier->id, 'items' => $readyItems,
                    'payment_method' => 'immediate', 'safe_id' => $safe->id, 'currency_id' => $this->egpId,
                ], $this->admin);
                foreach ($localSupply->items as $si) {
                    \App\Models\Goods::where('supply_item_id', $si->id)->whereNull('shop_id')->update(['shop_id' => $shop->id]);
                }
            } catch (Throwable $e) {
                $this->command?->warn("{$name}: opening ready-product stock failed — " . $e->getMessage());
            }

            $dailyRange = match ($tier) {
                'strong' => [5, 10], 'medium' => [3, 7], 'weak' => [1, 4], default => [1, 3],
            };

            $cursor = $start->copy();
            $sold = 0;
            while ($cursor->lte($endOfHistory)) {
                if ($cursor->dayOfWeekIso !== 5) {
                    $count = rand($dailyRange[0], $dailyRange[1]);
                    for ($i = 0; $i < $count; $i++) {
                        $seller = $employees[array_rand($employees)];
                        Carbon::setTestNow($cursor->copy()->setTime(rand(10, 21), rand(0, 59)));
                        Auth::guard('api')->setUser($seller);

                        $products = $this->pickRandom($this->readyProducts, rand(1, 2));
                        $items = [];
                        foreach ($products as $p) {
                            $qty = rand(1, 100) <= 90 ? 1 : 2;
                            $items[] = ['product_id' => $p->id, 'quantity' => $qty, 'price' => (float) $p->selling_price];
                        }
                        $total = array_sum(array_map(fn ($it) => $it['quantity'] * $it['price'], $items));
                        $pm = $this->paymentMethodIds[array_rand($this->paymentMethodIds)];

                        try {
                            $salesService->createInvoice([
                                'phone' => null, 'name' => null,
                                'items' => $items,
                                'payments' => [['payment_method_id' => $pm, 'currency_id' => $this->egpId, 'amount' => round($total, 2)]],
                                'safe_id' => $safe->id,
                                'price_type' => 'retail',
                            ]);
                            $sold++;
                        } catch (Throwable $e) {
                        }
                    }
                }
                $cursor->addDay();
            }
            $this->command?->info("{$name}: {$sold} invoices created");
        }

        Carbon::setTestNow();
        Auth::guard('api')->forgetUser();
        $this->command?->info('NewBranchStockTopupSeeder: done.');
    }

    private function pickRandom(array $pool, int $n): array
    {
        if (empty($pool)) {
            return [];
        }
        $n = min($n, count($pool));
        $keys = array_rand($pool, $n);
        if (! is_array($keys)) {
            $keys = [$keys];
        }
        return array_map(fn ($k) => $pool[$k], $keys);
    }
}
