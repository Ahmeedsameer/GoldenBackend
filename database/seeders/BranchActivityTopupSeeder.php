<?php

namespace Database\Seeders;

use App\Models\EmployeeTransfer;
use App\Models\Product;
use App\Models\Safe;
use App\Models\User;
use App\Modules\Hr\Services\TransferService as HrTransferService;
use App\Modules\Sales\Services\SalesService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Sheraton (شيراتون) has a physical safe and real ready-product stock but no
 * permanently-assigned staff yet — a believable "newly opened branch" story.
 * It only ever gets sales while a Main-Branch/North-Branch seller is on a
 * temporary transfer there (HistoricalActivitySeeder created one such window
 * but never actually sold anything during it). This tops that up with three
 * separate rotating-staff weeks spread across the quarter, each with real
 * sales during the transfer's active window — proving the temporary-transfer
 * → active-branch → invoice pipeline actually works end to end, not just that
 * the transfer row exists.
 */
class BranchActivityTopupSeeder extends Seeder
{
    private const SHERATON_SHOP_ID = 3;

    public function run(): void
    {
        $admin = User::where('role', 'admin')->orderBy('id')->firstOrFail();
        $safe = Safe::where('shop_id', self::SHERATON_SHOP_ID)
            ->whereHas('safeType', fn ($q) => $q->where('kind', 'physical'))
            ->first();
        if (! $safe) {
            $this->command?->warn('BranchActivityTopupSeeder: no Sheraton safe found, skipping.');
            return;
        }

        $readyProducts = Product::where('product_type', 'ready_product')
            ->where('is_active', true)->where('show_in_catalog', true)
            ->whereNotIn('id', [107])
            ->get()->all();

        $transfers = app(HrTransferService::class);
        $sales = app(SalesService::class);

        // (seller_id, start, end) — rotating coverage across the quarter,
        // including the window HistoricalActivitySeeder already opened.
        $windows = [
            [4, '2026-05-05', '2026-05-09'],
            [5, '2026-06-01', '2026-06-07'], // matches the transfer HistoricalActivitySeeder created
            [6, '2026-07-08', '2026-07-12'],
        ];

        foreach ($windows as [$sellerId, $start, $end]) {
            $seller = User::find($sellerId);
            if (! $seller) {
                continue;
            }

            // Reuse an already-existing transfer for this exact window (avoid a duplicate row); otherwise create it.
            $transfer = EmployeeTransfer::where('user_id', $sellerId)
                ->where('temporary_branch_id', self::SHERATON_SHOP_ID)
                ->whereDate('start_date', $start)
                ->first();

            if (! $transfer) {
                Carbon::setTestNow(Carbon::parse($start)->subDays(2));
                Auth::guard('api')->setUser($admin);
                try {
                    $transfer = $transfers->create([
                        'user_id'             => $sellerId,
                        'temporary_branch_id' => self::SHERATON_SHOP_ID,
                        'start_date'          => $start,
                        'end_date'            => $end,
                        'reason'              => 'تغطية مؤقتة لفرع شيراتون',
                    ]);
                    Carbon::setTestNow(Carbon::parse($start)->subDay());
                    $transfers->approve($transfer);
                } catch (Throwable $e) {
                    continue; // overlapping transfer for this employee — skip this window
                }
            }

            // Sell a handful of days across the active window.
            $cursor = Carbon::parse($start);
            $endDate = Carbon::parse($end);
            while ($cursor->lte($endDate)) {
                $count = rand(2, 5);
                for ($i = 0; $i < $count; $i++) {
                    Carbon::setTestNow($cursor->copy()->setTime(rand(11, 20), rand(0, 59)));
                    Auth::guard('api')->setUser($seller);

                    $product = $readyProducts[array_rand($readyProducts)];
                    $qty = 1;
                    $price = (float) $product->selling_price;

                    try {
                        $sales->createInvoice([
                            'phone'      => null,
                            'name'       => null,
                            'items'      => [['product_id' => $product->id, 'quantity' => $qty, 'price' => $price]],
                            'payments'   => [[
                                'payment_method_id' => 1, // نقدي
                                'currency_id'        => 1, // EGP
                                'amount'             => round($qty * $price, 2),
                            ]],
                            'safe_id'    => $safe->id,
                            'price_type' => 'retail',
                        ]);
                    } catch (Throwable $e) {
                        // out of stock for this item at Sheraton today — try again next iteration
                    }
                }
                $cursor->addDay();
            }
        }

        Carbon::setTestNow();
        $this->command?->info('BranchActivityTopupSeeder: done.');
    }
}
