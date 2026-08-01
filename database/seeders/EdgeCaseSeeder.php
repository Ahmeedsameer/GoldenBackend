<?php

namespace Database\Seeders;

use App\Models\Currency;
use App\Models\Product;
use App\Models\Safe;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\TransactionReason;
use App\Models\User;
use App\Modules\BranchOperations\Services\InventoryAdjustmentService;
use App\Modules\BranchOperations\Services\TransferRequestService;
use App\Modules\Safe\Services\SafeService;
use App\Modules\Sales\Services\SalesService;
use App\Modules\Stock\Services\SupplierPaymentService;
use App\Modules\Stock\Services\SupplyService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Deliberate, business-rule-valid edge cases the rest of the dataset doesn't
 * naturally produce: supplier debt aging into a late payment, a transfer left
 * mid-flight, a rejected inventory adjustment, a bulk wholesale invoice next
 * to a tiny one, and foreign-currency safe activity.
 *
 * NOT included: a negative safe balance — SafeService::guardAgainstOverdraft()
 * actively prevents this on every withdraw/transfer path, so it is not a
 * reachable state under the app's own business rules (see the coverage
 * report for the full explanation).
 */
class EdgeCaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', 'admin')->orderBy('id')->firstOrFail();
        Auth::guard('api')->setUser($admin);

        $this->supplierDebtAging($admin);
        $this->staleUnfinishedTransfer($admin);
        $this->rejectedAdjustment($admin);
        $this->bulkAndTinyInvoices($admin);
        $this->foreignCurrencyActivity($admin);
        $this->ownerWithdrawal($admin);
        $this->nearOutOfStock($admin);

        Carbon::setTestNow();
        Auth::guard('api')->forgetUser();
        $this->command?->info('EdgeCaseSeeder: done.');
    }

    /** A supply bought on credit, left unpaid for well over a month — realistic AP aging / supplier debt. */
    private function supplierDebtAging(User $admin): void
    {
        $supplier = Supplier::where('name', 'شركة الشرق للزيوت العطرية')->first();
        $rawMaterial = Product::where('product_type', 'raw_material')->whereNotIn('id', [103, 104, 106])->inRandomOrder()->first();
        if (! $supplier || ! $rawMaterial) {
            return;
        }

        Carbon::setTestNow(Carbon::parse('2026-06-01 10:00'));
        try {
            app(SupplyService::class)->create([
                'supplier_id' => $supplier->id,
                'items' => [['product_id' => $rawMaterial->id, 'quantity' => 3000, 'unit_price' => (float) $rawMaterial->price_per_gram, 'unit' => $rawMaterial->scalar]],
                'payment_method' => 'debt',
            ], $admin);
            // Deliberately never paid — still outstanding as of "today" (2026-08-01), ~2 months overdue.
        } catch (Throwable $e) {
        }

        // A second debt supply that DOES eventually get paid, but very late (45+ days) — late-payment scenario.
        $supplier2 = Supplier::where('name', 'مؤسسة العود والبخور العربي')->first();
        $rawMaterial2 = Product::where('product_type', 'raw_material')->whereNotIn('id', [103, 104, 106])->inRandomOrder()->first();
        if ($supplier2 && $rawMaterial2) {
            Carbon::setTestNow(Carbon::parse('2026-05-10 10:00'));
            try {
                $supply = app(SupplyService::class)->create([
                    'supplier_id' => $supplier2->id,
                    'items' => [['product_id' => $rawMaterial2->id, 'quantity' => 2000, 'unit_price' => (float) $rawMaterial2->price_per_gram, 'unit' => $rawMaterial2->scalar]],
                    'payment_method' => 'debt',
                ], $admin);

                Carbon::setTestNow(Carbon::parse('2026-06-28 15:00')); // paid ~7 weeks late
                $safe = Safe::where('shop_id', 1)->first();
                app(SupplierPaymentService::class)->pay($supply, $safe, 1, (float) $supply->total_amount, $admin, 'سداد متأخر لفاتورة توريد رقم ' . $supply->invoice_number);
            } catch (Throwable $e) {
            }
        }
    }

    /** A transfer request left mid-flight (shipped, never received) — genuine in-flight/pending state. */
    private function staleUnfinishedTransfer(User $admin): void
    {
        $warehouseShopId = Shop::where('is_warehouse', true)->value('id');
        $product = Product::where('product_type', 'packaging')->inRandomOrder()->first();
        if (! $product) {
            return;
        }

        Carbon::setTestNow(Carbon::parse('2026-07-28 09:00'));
        try {
            app(TransferRequestService::class)->create([
                'source_shop_id' => $warehouseShopId,
                'destination_shop_id' => 2,
                'items' => [['product_id' => $product->id, 'requested_quantity' => 50]],
                'notes' => 'بانتظار الشحن الفعلي',
            ], $admin, submitImmediately: false); // left in draft — a genuinely unresolved request
        } catch (Throwable $e) {
        }

        // A second one that DID get approved+shipped, but never received — sitting "in transit".
        $product2 = Product::where('product_type', 'raw_material')->whereNotIn('id', [103, 104, 106])->inRandomOrder()->first();
        if ($product2) {
            try {
                app(TransferRequestService::class)->create([
                    'source_shop_id' => $warehouseShopId,
                    'destination_shop_id' => 1,
                    'items' => [['product_id' => $product2->id, 'requested_quantity' => 100]],
                    'notes' => 'شحنة متأخرة الاستلام',
                ], $admin, submitImmediately: true); // auto-advances through ship() as admin
            } catch (Throwable $e) {
            }
        }
    }

    /** An inventory adjustment the reviewing admin genuinely rejects (count didn't match a believable cause). */
    private function rejectedAdjustment(User $admin): void
    {
        $product = Product::where('product_type', 'ready_product')->where('is_active', true)->inRandomOrder()->first();
        if (! $product) {
            return;
        }
        $service = app(InventoryAdjustmentService::class);

        Carbon::setTestNow(Carbon::parse('2026-06-18 10:00'));
        try {
            $current = $service->currentQuantity($product->id, 1);
            $req = $service->request([
                'shop_id' => 1, 'product_id' => $product->id,
                'after_quantity' => max(0, $current + 500), // implausibly large unexplained increase
                'reason' => 'فرق جرد غير مبرر — يحتاج مراجعة',
            ], User::find(2) ?? $admin);

            Carbon::setTestNow(Carbon::parse('2026-06-19 11:00'));
            $service->reject($req, $admin);
        } catch (Throwable $e) {
        }

        // ...and one legitimate correction that IS approved+executed (real inventory correction).
        $product2 = Product::where('product_type', 'raw_material')->whereNotIn('id', [103, 104, 106])->inRandomOrder()->first();
        if ($product2) {
            try {
                $current2 = $service->currentQuantity($product2->id, 2);
                Carbon::setTestNow(Carbon::parse('2026-07-05 10:00'));
                $req2 = $service->request([
                    'shop_id' => 2, 'product_id' => $product2->id,
                    'after_quantity' => max(0, $current2 - 30),
                    'reason' => 'تلف جزئي غير مسجل ضمن الهالك — تصحيح بعد الجرد اليدوي',
                ], User::find(3) ?? $admin);
                Carbon::setTestNow(Carbon::parse('2026-07-06 09:00'));
                $service->approve($req2, $admin);
                $service->execute($req2, $admin);
            } catch (Throwable $e) {
            }
        }
    }

    /** A large wholesale bulk order next to a tiny single-item sale — both extremes in the same report. */
    private function bulkAndTinyInvoices(User $admin): void
    {
        $sales = app(SalesService::class);
        $seller = User::find(4);
        $safe = Safe::where('shop_id', 1)->first();
        if (! $seller || ! $safe) {
            return;
        }

        $bulkProduct = Product::where('product_type', 'ready_product')->where('is_active', true)->inRandomOrder()->first();
        if ($bulkProduct) {
            Carbon::setTestNow(Carbon::parse('2026-07-20 13:00'));
            Auth::guard('api')->setUser($seller);
            try {
                $qty = 40;
                $price = round((float) $bulkProduct->selling_price * 0.85, 2); // wholesale discount
                $sales->createInvoice([
                    'phone' => '01500099887', 'name' => 'صالون العروسة للتجميل (عميل جملة)',
                    'items' => [['product_id' => $bulkProduct->id, 'quantity' => $qty, 'price' => $price]],
                    'payments' => [['payment_method_id' => 1, 'currency_id' => 1, 'amount' => round($qty * $price, 2)]],
                    'safe_id' => $safe->id, 'price_type' => 'wholesale',
                ]);
            } catch (Throwable $e) {
            }
        }

        $tinyProduct = Product::where('product_type', 'packaging')->where('selling_price', '>', 0)->inRandomOrder()->first();
        if ($tinyProduct) {
            Carbon::setTestNow(Carbon::parse('2026-07-21 16:00'));
            try {
                $sales->createInvoice([
                    'phone' => null, 'name' => null,
                    'items' => [['product_id' => $tinyProduct->id, 'quantity' => 1, 'price' => (float) $tinyProduct->selling_price]],
                    'payments' => [['payment_method_id' => 1, 'currency_id' => 1, 'amount' => (float) $tinyProduct->selling_price]],
                    'safe_id' => $safe->id, 'price_type' => 'retail',
                ]);
            } catch (Throwable $e) {
            }
        }
        Auth::guard('api')->setUser($admin);
    }

    /** Foreign-currency deposits — a branch accepting/holding USD & EUR alongside EGP. */
    private function foreignCurrencyActivity(User $admin): void
    {
        $safeService = app(SafeService::class);
        $usd = Currency::where('code', 'USD')->value('id');
        $eur = Currency::where('code', 'EUR')->value('id');
        $reasonId = TransactionReason::where('name', 'إيداع نقدي')->value('id');
        $mainSafe = Safe::where('shop_id', 1)->first();
        $northSafe = Safe::where('shop_id', 2)->first();

        if ($usd && $mainSafe && $reasonId) {
            Carbon::setTestNow(Carbon::parse('2026-07-15 12:00'));
            try {
                $safeService->adminDeposit($mainSafe, $usd, 500, $reasonId, 'سائح دفع بالدولار الأمريكي', $admin->id);
            } catch (Throwable $e) {
            }
        }
        if ($eur && $northSafe && $reasonId) {
            Carbon::setTestNow(Carbon::parse('2026-07-16 12:00'));
            try {
                $safeService->adminDeposit($northSafe, $eur, 200, $reasonId, 'عميل دفع باليورو', $admin->id);
            } catch (Throwable $e) {
            }
        }
    }

    /** A large, clearly-labeled owner/company withdrawal from the company-level safe. */
    private function ownerWithdrawal(User $admin): void
    {
        $companySafe = Safe::whereNull('shop_id')->first();
        $reasonId = TransactionReason::where('name', 'سحب نقدي')->value('id');
        if (! $companySafe || ! $reasonId) {
            return;
        }
        Carbon::setTestNow(Carbon::parse('2026-06-30 17:00'));
        try {
            app(SafeService::class)->adminWithdraw($companySafe, 1, 15000, $reasonId, 'سحب أرباح لصاحب المنشأة — نهاية الربع', $admin->id);
        } catch (Throwable $e) {
        }
    }

    /** Deliberately drain a popular product's branch stock close to its critical threshold. */
    private function nearOutOfStock(User $admin): void
    {
        $product = Product::where('product_type', 'ready_product')->where('is_active', true)
            ->whereNotNull('critical_quantity')->where('critical_quantity', '>', 0)->inRandomOrder()->first();
        $safe = Safe::where('shop_id', 1)->first();
        $seller = User::find(4);
        if (! $product || ! $safe || ! $seller) {
            return;
        }

        $available = \App\Models\Goods::whereHas('supplyItem', fn ($q) => $q->where('product_id', $product->id))
            ->where('shop_id', 1)->sum('current_quantity');
        $target = (float) $product->critical_quantity + 1; // leave it just barely above critical
        $toSell = max(0, floor($available - $target));
        if ($toSell < 1) {
            return;
        }

        Carbon::setTestNow(Carbon::parse('2026-07-25 14:00'));
        Auth::guard('api')->setUser($seller);
        try {
            app(SalesService::class)->createInvoice([
                'phone' => null, 'name' => null,
                'items' => [['product_id' => $product->id, 'quantity' => $toSell, 'price' => (float) $product->selling_price]],
                'payments' => [['payment_method_id' => 1, 'currency_id' => 1, 'amount' => round($toSell * (float) $product->selling_price, 2)]],
                'safe_id' => $safe->id, 'price_type' => 'retail',
            ]);
        } catch (Throwable $e) {
        }
        Auth::guard('api')->setUser($admin);
    }
}
