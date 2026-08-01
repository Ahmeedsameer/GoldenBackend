<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Safe;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\User;
use App\Modules\BranchOperations\Services\TransferRequestService;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Safe\Services\SafeService;
use App\Modules\Sales\Services\SalesService;
use App\Modules\Stock\Services\SupplyService;
use App\Models\TransactionReason;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Transactional history for the 8 branches BranchExpansionSeeder created —
 * initial stock, then daily sales scaled by each branch's performance tier,
 * plus attendance and payroll for their staff. Each branch's activity starts
 * on its own opening date (staggered across the quarter — see
 * BranchExpansionSeeder::NEW_BRANCHES) so newer branches naturally have less
 * history than established ones, without any special-casing here.
 */
class BranchActivitySeeder extends Seeder
{
    private User $admin;
    private int $egpId = 1;
    /** @var Product[] */
    private array $readyProducts = [];
    /** @var Product[] */
    private array $rawMaterials = [];
    /** @var Product[] */
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
        $attendanceService = app(AttendanceService::class);
        $salesService = app(SalesService::class);
        $payrollService = app(PayrollService::class);

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

            // ── Opening capital float — a brand-new branch's safe starts at zero;
            // HQ funds it before day one so the opening stock can actually be paid for. ──
            Carbon::setTestNow($start->copy()->subDays(3)->setTime(9, 0));
            $reasonId = TransactionReason::where('name', 'إيداع نقدي')->value('id');
            if ($reasonId) {
                try {
                    app(SafeService::class)->adminDeposit($safe, $this->egpId, 60000, $reasonId, 'رأس مال افتتاحي للفرع', $this->admin->id);
                } catch (Throwable $e) {
                }
            }

            // ── Opening stock: one supply + one immediate transfer to this branch ──
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
                // opening stock failed for some reason — branch will just look thin, still safe to continue
            }

            // ── Ready-product stock: ready products must exist somewhere sellable —
            // credit branch stock directly via a second, ready-product-only supply
            // routed straight to this branch (small local specialty run). ──────────
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
                // Route straight to the branch (skip an extra transfer hop for this local batch).
                foreach ($localSupply->items as $si) {
                    \App\Models\Goods::where('supply_item_id', $si->id)->whereNull('shop_id')->update(['shop_id' => $shop->id]);
                }
            } catch (Throwable $e) {
            }

            // ── Daily sales, scaled by tier ──────────────────────────────────
            $dailyRange = match ($tier) {
                'strong' => [5, 10], 'medium' => [3, 7], 'weak' => [1, 4], default => [1, 3],
            };

            $cursor = $start->copy();
            while ($cursor->lte($endOfHistory)) {
                if ($cursor->dayOfWeekIso !== 5) {
                    $count = rand($dailyRange[0], $dailyRange[1]);
                    for ($i = 0; $i < $count; $i++) {
                        $seller = $employees[array_rand($employees)];
                        Carbon::setTestNow($cursor->copy()->setTime(rand(10, 21), rand(0, 59)));
                        Auth::guard('api')->setUser($seller);

                        $lineCount = rand(1, 2);
                        $products = $this->pickRandom($this->readyProducts, $lineCount);
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
                        } catch (Throwable $e) {
                        }
                    }

                    // Attendance for everyone assigned here, every working day.
                    foreach ($employees as $employee) {
                        Carbon::setTestNow($cursor->copy()->setTime(8, 30));
                        Auth::guard('api')->setUser($this->admin);
                        $roll = rand(1, 100);
                        $status = $roll <= 90 ? 'present' : ($roll <= 97 ? 'late' : 'absent');
                        try {
                            $attendanceService->mark($employee->id, $cursor->copy(), $status);
                        } catch (Throwable $e) {
                        }
                    }
                }
                $cursor->addDay();
            }

            // ── Payroll for every full month this branch has been open ──────
            Auth::guard('api')->setUser($this->admin);
            foreach ([[2026, 5], [2026, 6], [2026, 7]] as [$year, $month]) {
                $monthEnd = Carbon::create($year, $month, 1)->endOfMonth();
                if ($start->gt($monthEnd)) {
                    continue; // branch didn't exist yet this month
                }
                Carbon::setTestNow(Carbon::create($year, $month, 28));
                foreach ($employees as $employee) {
                    try {
                        $p = $payrollService->generate($employee, $year, $month);
                        $payrollService->lock($p);
                        $payrollService->markPaid($p);
                    } catch (Throwable $e) {
                    }
                }
            }
        }

        Carbon::setTestNow();
        Auth::guard('api')->forgetUser();
        $this->command?->info('BranchActivitySeeder: done.');
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
