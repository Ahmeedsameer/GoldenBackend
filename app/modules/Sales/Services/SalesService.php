<?php

namespace App\Modules\Sales\Services;

use App\Models\Currency;
use App\Models\Customer;
use App\Models\Goods;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\Product;
use App\Models\Safe;
use App\Models\User;
use App\Modules\Safe\Services\SafeService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SalesService
{
    public function __construct(private SafeService $safeService) {}
    // ── Frontend utility: search available goods in seller's shop ─────────────

    public function searchGoods(int $shopId, ?string $search, int $perPage, ?int $categoryId = null): mixed
    {
        $query = Goods::query()
            ->with([
                'supplyItem.product:id,name,sku,scalar,category_id',
                'supplyItem.product.category:id,name,minimum_sell_price,is_fixed,value_percentage',
            ])
            ->where('shop_id', $shopId);

        if ($search) {
            $query->whereHas('supplyItem.product', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku',  'like', "%{$search}%");
            });
        }

        if ($categoryId) {
            $query->whereHas('supplyItem.product', function ($q) use ($categoryId) {
                $q->where('category_id', $categoryId);
            });
        }

        return $query->paginate($perPage);
    }

    // ── Frontend utility: search customers by phone ────────────────────────────

    public function searchCustomers(?string $phone, int $perPage): mixed
    {
        $query = Customer::query();

        if ($phone) {
            $query->where('phone', 'like', "%{$phone}%");
        }

        return $query->latest()->paginate($perPage);
    }

    // ── Frontend utility: search testers in the same shop ─────────────────────

    public function searchTesters(?string $search, int $perPage): mixed
    {
        $query = User::query()
            ->where('role', 'sales')
            ->where('shop_id', auth()->user()->shop_id);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name',  'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return $query->latest()->paginate($perPage);
    }

    // ── Core: create invoice with automatic inventory deduction ───────────────

    /**
     * @return array{invoice: Invoice, violations: array}
     */
    public function createInvoice(array $data): array
    {
        $seller = auth()->user();

        if (! $seller->shop_id) {
            abort(422, 'البائع غير مرتبط بأي فرع، يرجى التواصل مع المدير');
        }

        // ── Validate override token (if provided) ────────────────────────────
        $overrideApproved = false;
        if (! empty($data['override_token'])) {
            $tokenKey  = "override_token:{$data['override_token']}";
            $tokenData = Cache::get($tokenKey);

            if (
                $tokenData &&
                (int) $tokenData['seller_id'] === $seller->id &&
                (int) $tokenData['shop_id']   === $seller->shop_id
            ) {
                $overrideApproved = true;
                Cache::forget($tokenKey); // one-time use
            }
        }

        return DB::transaction(function () use ($data, $seller, $overrideApproved) {
            // ── 1. Find or create customer by phone ──────────────────────────
            $customer = null;
            if (! empty($data['phone']) || ! empty($data['name'])) {
                $customer = Customer::firstOrCreate(
                    ['phone' => $data['phone']],
                    ['name'  => $data['name']]
                );
            }

            // ── 2. Load products with full category data ──────────────────────
            $productIds = array_column($data['items'], 'product_id');
            $products   = Product::with('category:id,name,minimum_sell_price,is_fixed,value_percentage')
                ->whereIn('id', $productIds)
                ->get()
                ->keyBy('id');

            // ── 3. Distribute global total → compute unit price per item ──────
            $items = $this->distributeGlobalTotal(
                (float) $data['total_amount'],
                $data['items'],
                $products
            );

            // ── 4. Validate payment total against total_amount (physical safe) ─
            if (! empty($data['payments'])) {
                $currencyIds      = array_unique(array_column($data['payments'], 'currency_id'));
                $rates            = Currency::whereIn('id', $currencyIds)->pluck('rate', 'id');
                $paymentsEgpTotal = 0.0;

                foreach ($data['payments'] as $payment) {
                    $rate              = (float) ($rates[$payment['currency_id']] ?? 0);
                    $paymentsEgpTotal += (float) $payment['amount'] * $rate;
                }

                if ($paymentsEgpTotal < (float) $data['total_amount']) {
                    abort(422, sprintf(
                        'مجموع المبالغ المدفوعة بعد التحويل إلى الجنيه المصري (%.2f ج.م) أقل من إجمالي الفاتورة (%.2f ج.م). يرجى مراجعة المبالغ المُدخلة.',
                        $paymentsEgpTotal,
                        (float) $data['total_amount']
                    ));
                }
            }

            // ── 5. Check computed prices against category minimums ────────────
            $violations = [];
            foreach ($items as $item) {
                $product  = $products[$item['product_id']] ?? null;
                $category = $product?->category;

                // Fixed-price items are always at the minimum — skip them
                if ($category && ! $category->is_fixed
                    && (float) $item['price'] < (float) $category->minimum_sell_price) {
                    $violations[] = [
                        'product_id'         => $product->id,
                        'product_name'       => $product->name,
                        'category_name'      => $category->name,
                        'sell_price'         => (float) $item['price'],
                        'minimum_sell_price' => (float) $category->minimum_sell_price,
                    ];
                }
            }

            // ── 6. Create invoice header ──────────────────────────────────────
            // Status is 'approved' when: no violations, OR manager pre-approved via token.
            $invoice = Invoice::create([
                'customer_id'  => $customer?->id,
                'shop_id'      => $seller->shop_id,
                'seller_id'    => $seller->id,
                'tester_id'    => $data['tester_id'] ?? null,
                'date'         => $data['date'],
                'price_type'   => $data['price_type'],
                'status'       => ($violations && ! $overrideApproved) ? 'pending' : 'approved',
                'total_amount' => (float) $data['total_amount'],
            ]);

            // ── 7. Process each item — FIFO batch splitting + deduction ───────
            foreach ($items as $item) {
                $this->processItem($invoice, $item);
            }

            // ── 8. Resolve the safe ───────────────────────────────────────────
            if (! empty($data['safe_id'])) {
                $safe = Safe::with('safeType')
                    ->where('id', (int) $data['safe_id'])
                    ->where('shop_id', $seller->shop_id)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->firstOrFail();
            } else {
                $safe = Safe::with('safeType')
                    ->where('shop_id', $seller->shop_id)
                    ->whereHas('safeType', fn($q) => $q->where('kind', 'physical'))
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            // ── 9. Record payments ────────────────────────────────────────────
            if ($safe->safeType->kind === 'physical' && ! empty($data['payments'])) {
                foreach ($data['payments'] as $payment) {
                    InvoicePayment::create([
                        'invoice_id'  => $invoice->id,
                        'safe_id'     => $safe->id,
                        'currency_id' => (int) $payment['currency_id'],
                        'amount'      => (float) $payment['amount'],
                    ]);
                    $this->safeService->recordSaleTransaction(
                        $safe, $invoice, (int) $payment['currency_id'], (float) $payment['amount'], $seller->id
                    );
                }
            } else {
                // Virtual safe → record the entered total directly in EGP
                $egpCurrencyId = Currency::where('code', 'EGP')->value('id');
                if ($egpCurrencyId) {
                    $this->safeService->recordSaleTransaction(
                        $safe, $invoice, (int) $egpCurrencyId, (float) $data['total_amount'], $seller->id
                    );
                }
            }

            return [
                'invoice'    => $invoice->load([
                    'customer',
                    'seller:id,name',
                    'tester:id,name',
                    'shop:id,name',
                    'items.product:id,name,sku,scalar',
                    'items.goods',
                ]),
                'violations' => $violations,
            ];
        });
    }

    // ── Deduct inventory across FIFO batches, split InvoiceItem per batch ─────

    private function processItem(Invoice $invoice, array $item): void
    {
        $productId = (int) $item['product_id'];
        $needed    = (float) $item['quantity'];
        $price     = (float) $item['price'];

        // Fetch all batches with stock for this product in the seller's shop,
        // ordered oldest-first (FIFO) and locked for the transaction.
        $batches = Goods::whereHas('supplyItem', fn($q) => $q->where('product_id', $productId))
            ->where('shop_id', $invoice->shop_id)
            ->where('current_quantity', '>', 0)
            ->orderBy('date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $remaining = $needed;

        // FIFO: drain available batches
        foreach ($batches as $goods) {
            if ($remaining <= 0) {
                break;
            }

            $take = min((float) $goods->current_quantity, $remaining);

            // Deduct — never delete the row even if it reaches zero
            $goods->decrement('current_quantity', $take);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'product_id' => $productId,
                'goods_id'   => $goods->id,
                'quantity'   => $take,
                'price'      => $price,
            ]);

            $remaining = round($remaining - $take, 3);
        }

        // Oversell: if demand exceeds available stock, push the remainder into
        // the most-recent batch (driving its quantity negative).  goods_id is
        // non-nullable, so we must always resolve a batch.
        if ($remaining > 0) {
            // Prefer the last-used batch; fall back to any batch for this product.
            $fallback = $batches->last()
                ?? Goods::whereHas('supplyItem', fn($q) => $q->where('product_id', $productId))
                    ->where('shop_id', $invoice->shop_id)
                    ->orderByDesc('date')
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();

            if ($fallback) {
                $fallback->decrement('current_quantity', $remaining);

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'product_id' => $productId,
                    'goods_id'   => $fallback->id,
                    'quantity'   => $remaining,
                    'price'      => $price,
                ]);
            }
            // If no batch exists at all, the invoice item is simply skipped —
            // this product was never received into this shop.
        }
    }

    // ── Distribute global total across items (A→B→C→D pipeline) ─────────────

    private function distributeGlobalTotal(float $totalAmount, array $items, \Illuminate\Support\Collection $products): array
    {
        // ── Step A: assign fixed prices & accumulate fixed total ──────────────
        $fixedTotal = 0.0;
        foreach ($items as &$item) {
            $category = $products[$item['product_id']]?->category;
            if ($category && $category->is_fixed) {
                $unitPrice     = (float) $category->minimum_sell_price;
                $item['price'] = $unitPrice;
                $fixedTotal   += $unitPrice * (float) $item['quantity'];
            }
        }
        unset($item);

        $remainingPool = round($totalAmount - $fixedTotal, 2);

        if ($remainingPool < 0) {
            abort(422, sprintf(
                'إجمالي المنتجات ذات السعر الثابت (%.2f ج.م) يتجاوز إجمالي الفاتورة المُدخل (%.2f ج.م).',
                $fixedTotal, $totalAmount
            ));
        }

        // ── Collect weighted item indices ─────────────────────────────────────
        $weightedIndices = [];
        $totalRelative   = 0.0;

        foreach ($items as $idx => &$item) {
            $category = $products[$item['product_id']]?->category;
            if ($category && ! $category->is_fixed) {
                $pct              = (float) ($category->value_percentage ?? 0);
                $relative         = (float) $item['quantity'] * ($pct / 100);
                $item['_rel']     = $relative;
                $totalRelative   += $relative;
                $weightedIndices[] = $idx;
            }
        }
        unset($item);

        // ── Guard: remaining pool with no weighted items to absorb it ─────────
        if ($remainingPool > 0 && empty($weightedIndices)) {
            abort(422, sprintf(
                'المبلغ المُدخل (%.2f ج.م) يتجاوز إجمالي الأصناف ذات السعر الثابت (%.2f ج.م) ولا توجد أصناف موزونة لاستيعاب الفارق.',
                $totalAmount, $fixedTotal
            ));
        }

        // ── Guard: weighted items exist but all have value_percentage = 0 ─────
        if (! empty($weightedIndices) && $totalRelative == 0.0) {
            abort(422, 'لا يمكن توزيع القيمة: نسبة القيمة لجميع الأصناف الموزونة تساوي صفر، يرجى مراجعة إعدادات الفئات.');
        }

        // ── Steps C & D: distribute remaining pool, last item absorbs remainder
        $distributed = 0.0;
        $lastIdx     = end($weightedIndices);

        foreach ($weightedIndices as $idx) {
            $qty      = (float) $items[$idx]['quantity'];
            $relative = (float) $items[$idx]['_rel'];

            if ($idx === $lastIdx) {
                $share = round($remainingPool - $distributed, 2);
            } else {
                $share = round(($relative / $totalRelative) * $remainingPool, 2);
            }

            $items[$idx]['price'] = $qty > 0 ? round($share / $qty, 4) : 0;
            $distributed         += $share;
            unset($items[$idx]['_rel']);
        }

        return $items;
    }

    // ── Seller invoice history with filters ───────────────────────────────────

    public function getSellerInvoices(int $sellerId, array $filters, int $perPage): mixed
    {
        $query = Invoice::with([
            'customer',
            'shop:id,name',
            'items.product:id,name,sku',
        ])->where('seller_id', $sellerId);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('date', '<=', $filters['date_to']);
        }

        return $query->latest()->paginate($perPage);
    }

    // ── Update invoice status ─────────────────────────────────────────────────

    public function updateStatus(Invoice $invoice, string $status): Invoice
    {
        if ($invoice->status === 'cancelled') {
            abort(422, 'لا يمكن تعديل فاتورة ملغاة');
        }

        if ($invoice->status === 'approved' && $status === 'cancelled') {
            abort(422, 'لا يمكن إلغاء فاتورة معتمدة بعد الاعتماد');
        }

        $invoice->update(['status' => $status]);

        return $invoice->fresh(['customer', 'items.product:id,name,sku']);
    }
}
