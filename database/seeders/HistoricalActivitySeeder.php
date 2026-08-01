<?php

namespace Database\Seeders;

use App\Models\Bonus;
use App\Models\Convention;
use App\Models\Currency;
use App\Models\EmployeeTransfer;
use App\Models\PaymentMethod;
use App\Models\Payroll;
use App\Models\Product;
use App\Models\Safe;
use App\Models\SalaryAdvance;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\TransactionReason;
use App\Models\User;
use App\Modules\BranchOperations\Services\InventoryCountService;
use App\Modules\BranchOperations\Services\TransferRequestService;
use App\Modules\BranchOperations\Services\WasteService;
use App\Modules\Convention\Services\ConventionService;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\BonusPenaltyService;
use App\Modules\Hr\Services\LeaveService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\SalaryAdvanceService;
use App\Modules\Hr\Services\TransferService as HrTransferService;
use App\Modules\Safe\Services\SafeService;
use App\Modules\Sales\Services\SalesService;
use App\Modules\Stock\Services\SupplierPaymentService;
use App\Modules\Stock\Services\SupplyService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Backfills ~2.5 months of believable pre-existing business history
 * (2026-05-01 → the day before the app's already-real recent activity begins)
 * so the app looks like it has been running in production for a full quarter
 * rather than freshly seeded. Purely additive — never truncates or touches
 * existing rows. Drives the REAL application services (not raw inserts) so
 * every FIFO batch, safe balance, and payroll figure reconciles exactly the
 * way it would if a real admin/manager/seller had done this by hand over
 * three months, just backdated via Carbon::setTestNow().
 *
 * Known pre-existing test/QA noise (e.g. users "ahmeee"/"AAhmed", suppliers
 * "مورد اختبار...") is deliberately never used as an actor or subject here —
 * only the real, production-looking people/entities appear in this history.
 */
class HistoricalActivitySeeder extends Seeder
{
    private User $admin;
    /** @var array<int, User[]> shop_id => employees (managers + sellers) assigned there */
    private array $employeesByShop = [];
    /** @var array<int, User> shop_id => manager */
    private array $managerByShop = [];
    /** @var array<int, Safe> shop_id => physical safe */
    private array $safeByShop = [];
    private int $warehouseShopId;
    /** @var Product[] */
    private array $readyProducts = [];
    /** @var Product[] */
    private array $rawMaterials = [];
    /** @var Product[] */
    private array $packaging = [];
    /** @var Supplier[] */
    private array $suppliers = [];
    private int $egpId;
    /** @var PaymentMethod[] */
    private array $paymentMethods = [];
    private array $reasonIdByName = [];

    private array $customerPool = [
        ['name' => 'محمد عبد الرحمن', 'phone' => '01112223301'],
        ['name' => 'سارة أحمد',        'phone' => '01223334402'],
        ['name' => 'يوسف إبراهيم',     'phone' => '01098765403'],
        ['name' => 'مريم سمير',        'phone' => '01556667704'],
        ['name' => 'خالد فتحي',        'phone' => '01234445505'],
        ['name' => 'نور الهدى',        'phone' => '01011122206'],
        ['name' => 'أحمد جمال',        'phone' => '01523344507'],
        ['name' => 'هبة الله منصور',   'phone' => '01144556608'],
    ];

    public function run(): void
    {
        $this->loadReferenceData();

        $start = Carbon::parse('2026-05-01');
        $end   = Carbon::parse('2026-07-17'); // day before the app's already-real recent data begins

        $this->command?->info("HistoricalActivitySeeder: {$start->toDateString()} → {$end->toDateString()}");

        try {
            $this->seedSupplyAndTransferCycles($start, $end);
            $this->seedDailySales($start, $end);
            $this->seedAttendance($start, $end);
            $this->seedLeaves();
            $this->seedPayroll();
            $this->seedBonusesPenalties();
            $this->seedEmployeeTransfer();
            $this->seedSalaryAdvance();
            $this->seedSafeMisc($start, $end);
            $this->seedConvention();
            $this->seedWaste();
            $this->seedInventoryCount($end);
        } finally {
            // Just drop the impersonated user — Auth::logout() tries to blacklist a real
            // JWT token, which doesn't exist in this console context and throws.
            Carbon::setTestNow();
            Auth::guard('api')->forgetUser();
        }

        $this->command?->info('HistoricalActivitySeeder: done.');
    }

    // ── Setup ──────────────────────────────────────────────────────────────

    private function loadReferenceData(): void
    {
        $this->admin = User::where('role', 'admin')->orderBy('id')->firstOrFail();
        $this->warehouseShopId = Shop::where('is_warehouse', true)->value('id');

        foreach (Shop::where('is_warehouse', false)->get() as $shop) {
            $this->employeesByShop[$shop->id] = User::where('shop_id', $shop->id)
                ->whereIn('role', ['manager', 'sales'])
                ->whereIn('id', [2, 3, 4, 5, 6, 7]) // only the real, named seed employees — never QA test users
                ->get()->all();

            $manager = User::where('shop_id', $shop->id)->where('role', 'manager')->first();
            if ($manager) {
                $this->managerByShop[$shop->id] = $manager;
            }

            $safe = Safe::where('shop_id', $shop->id)
                ->whereHas('safeType', fn ($q) => $q->where('kind', 'physical'))
                ->where('is_active', true)->first();
            if ($safe) {
                $this->safeByShop[$shop->id] = $safe;
            }
        }

        $this->readyProducts = Product::where('product_type', 'ready_product')
            ->where('is_active', true)->where('show_in_catalog', true)
            ->whereNotNull('selling_price')
            ->whereNotIn('id', [107]) // "hakiiiiim" — no price, QA junk
            ->get()->all();

        $this->rawMaterials = Product::where('product_type', 'raw_material')
            ->where('is_active', true)
            ->whereNotIn('id', [103, 104, 106]) // QA junk / unpriced
            ->get()->all();

        $this->packaging = Product::where('product_type', 'packaging')
            ->where('is_active', true)->get()->all();

        $this->suppliers = Supplier::whereIn('id', [1, 2, 3, 4, 5])->get()->all(); // real named suppliers only

        $this->egpId = Currency::where('code', 'EGP')->value('id');

        $this->paymentMethods = PaymentMethod::whereIn('id', [1, 7, 8, 9, 10])->get()->all(); // cash + 4 digital, real ones

        $this->reasonIdByName = TransactionReason::pluck('id', 'name')->all();
    }

    private function actAs(User $user): void
    {
        Auth::guard('api')->setUser($user);
    }

    // ── Weekly supply + branch transfer cycles ──────────────────────────────

    private function seedSupplyAndTransferCycles(Carbon $start, Carbon $end): void
    {
        $supplyService = app(SupplyService::class);
        $paymentService = app(SupplierPaymentService::class);
        $transferService = app(TransferRequestService::class);

        $cursor = $start->copy();
        $supplierCycle = 0;

        while ($cursor->lte($end)) {
            Carbon::setTestNow($cursor->copy()->setTime(9, 0));
            $this->actAs($this->admin);

            // ── Weekly restock supply from a rotating real supplier ──────────
            $supplier = $this->suppliers[$supplierCycle % count($this->suppliers)];
            $supplierCycle++;

            $items = [];
            // 2-3 raw materials + 1-2 packaging per restock, plausible quantities
            foreach ($this->pickRandom($this->rawMaterials, rand(2, 3)) as $rm) {
                $items[] = [
                    'product_id' => $rm->id,
                    'quantity'   => $rm->scalar === 'g' ? rand(500, 2000) : rand(500, 1500),
                    'unit_price' => (float) $rm->price_per_gram,
                    'unit'       => $rm->scalar,
                ];
            }
            foreach ($this->pickRandom($this->packaging, rand(1, 2)) as $pk) {
                $items[] = [
                    'product_id' => $pk->id,
                    'quantity'   => rand(100, 400),
                    'unit_price' => (float) $pk->selling_price * 0.55, // supplier cost well below retail
                    'unit'       => 'pcs',
                ];
            }

            try {
                $paymentMethod = rand(1, 100) <= 60 ? 'immediate' : 'debt';
                $supply = $supplyService->create([
                    'supplier_id'    => $supplier->id,
                    'items'          => $items,
                    'payment_method' => $paymentMethod,
                    'safe_id'        => $paymentMethod === 'immediate' ? ($this->safeByShop[1]->id ?? null) : null,
                    'currency_id'    => $this->egpId,
                ], $this->admin);

                // Debt supplies get paid off (fully or partially) a week or two later — realistic AP aging.
                if ($paymentMethod === 'debt') {
                    Carbon::setTestNow($cursor->copy()->addDays(rand(7, 14))->setTime(11, 0));
                    $portion = rand(1, 100) <= 70 ? 1.0 : 0.6; // most fully settled, some partial
                    $safe = $this->safeByShop[1] ?? array_values($this->safeByShop)[0];
                    $paymentService->pay(
                        $supply, $safe, $this->egpId,
                        round((float) $supply->total_amount * $portion, 2),
                        $this->admin, 'سداد فاتورة توريد رقم ' . $supply->invoice_number
                    );
                }

                // ── Ship a share of every newly-supplied product out to each branch ──
                Carbon::setTestNow($cursor->copy()->addDay()->setTime(10, 0));
                foreach ([1, 2, 3] as $destShopId) {
                    if (! isset($this->safeByShop[$destShopId])) {
                        continue;
                    }
                    $shipItems = [];
                    foreach ($items as $it) {
                        // Sheraton (3) gets lighter, less frequent restocking — smaller branch.
                        if ($destShopId === 3 && rand(1, 100) > 40) {
                            continue;
                        }
                        $share = $destShopId === 1 ? 0.5 : ($destShopId === 2 ? 0.3 : 0.15);
                        $qty = round($it['quantity'] * $share, 3);
                        if ($qty <= 0) {
                            continue;
                        }
                        $shipItems[] = ['product_id' => $it['product_id'], 'requested_quantity' => $qty];
                    }
                    if (empty($shipItems)) {
                        continue;
                    }

                    try {
                        $transfer = $transferService->create([
                            'source_shop_id'      => $this->warehouseShopId,
                            'destination_shop_id' => $destShopId,
                            'items'               => $shipItems,
                            'notes'               => 'تجهيز مخزون دوري للفرع',
                        ], $this->admin, submitImmediately: true);

                        // Receive at the branch a day later — almost always clean, occasional tiny shrinkage.
                        Carbon::setTestNow($cursor->copy()->addDays(2)->setTime(9, 30));
                        $receiver = $this->managerByShop[$destShopId] ?? $this->admin;
                        $this->actAs($receiver);

                        $receipts = [];
                        foreach ($transfer->items as $line) {
                            $shipped = (float) $line->requested_quantity;
                            $damaged = rand(1, 100) <= 8 ? round($shipped * 0.02, 3) : 0.0;
                            $receipts[] = [
                                'item_id'           => $line->id,
                                'received_quantity' => round($shipped - $damaged, 3),
                                'missing_quantity'  => 0,
                                'damaged_quantity'  => $damaged,
                            ];
                        }
                        $transferService->receive($transfer, $receiver, $receipts);
                        $this->actAs($this->admin);
                    } catch (Throwable $e) {
                        $this->actAs($this->admin);
                        continue; // e.g. not enough warehouse stock yet — skip this branch this week
                    }
                }
            } catch (Throwable $e) {
                // Skip this week's supply on any validation edge case, keep the timeline moving.
            }

            $cursor->addWeek();
        }
    }

    // ── Daily sales across branches ──────────────────────────────────────────

    private function seedDailySales(Carbon $start, Carbon $end): void
    {
        $salesService = app(SalesService::class);
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            // Lighter weekend (Friday) activity — believable weekly rhythm.
            $isFriday = $cursor->dayOfWeekIso === 5;

            foreach ($this->employeesByShop as $shopId => $employees) {
                if (empty($employees) || ! isset($this->safeByShop[$shopId])) {
                    continue;
                }

                $baseCount = match ($shopId) {
                    1 => rand(4, 9),
                    2 => rand(2, 6),
                    default => rand(0, 3), // Sheraton — smaller, newer branch
                };
                $invoiceCount = $isFriday ? intdiv($baseCount, 2) : $baseCount;

                for ($i = 0; $i < $invoiceCount; $i++) {
                    $seller = $employees[array_rand($employees)];
                    $hour = rand(10, 21);
                    Carbon::setTestNow($cursor->copy()->setTime($hour, rand(0, 59)));
                    $this->actAs($seller);

                    $lineCount = rand(1, 3);
                    $products = $this->pickRandom($this->readyProducts, $lineCount);
                    $items = [];
                    foreach ($products as $p) {
                        $qty = rand(1, 100) <= 85 ? 1 : rand(2, 3);
                        $price = (float) $p->selling_price;
                        // Rare below-minimum sale (seller-granted discount) — exercises the pending-review workflow.
                        if (rand(1, 100) <= 4) {
                            $price = round($price * 0.5, 2);
                        }
                        $items[] = ['product_id' => $p->id, 'quantity' => $qty, 'price' => $price];
                    }
                    $total = array_sum(array_map(fn ($it) => $it['quantity'] * $it['price'], $items));

                    $walkIn = rand(1, 100) <= 55;
                    $customer = $walkIn ? null : $this->customerPool[array_rand($this->customerPool)];

                    $pm = $this->paymentMethods[array_rand($this->paymentMethods)];

                    try {
                        $result = $salesService->createInvoice([
                            'phone'      => $customer['phone'] ?? null,
                            'name'       => $customer['name'] ?? null,
                            'items'      => $items,
                            'payments'   => [[
                                'payment_method_id' => $pm->id,
                                'currency_id'        => $this->egpId,
                                'amount'             => round($total, 2),
                            ]],
                            'safe_id'    => $this->safeByShop[$shopId]->id,
                            'price_type' => rand(1, 100) <= 90 ? 'retail' : 'wholesale',
                        ]);

                        // Occasionally the customer changes their mind — cancel a small share of approved sales.
                        if (($result['invoice']->status ?? null) === 'approved' && rand(1, 100) <= 3) {
                            Carbon::setTestNow($cursor->copy()->setTime($hour, min(59, rand(0, 59) + 30)));
                            $salesService->cancel($result['invoice'], $seller, 'طلب العميل إلغاء الفاتورة');
                        }

                        // A below-minimum sale lands 'pending' — have the branch manager/admin review it shortly after.
                        if (($result['invoice']->status ?? null) === 'pending') {
                            Carbon::setTestNow($cursor->copy()->addHours(2));
                            $reviewer = $this->managerByShop[$shopId] ?? $this->admin;
                            $decision = rand(1, 100) <= 75 ? 'approved' : 'cancelled';
                            $this->actAs($reviewer);
                            $salesService->updateStatus($result['invoice'], $decision);
                            $this->actAs($seller);
                        }
                    } catch (Throwable $e) {
                        // Out of stock / no price configured for this branch today — perfectly realistic, skip.
                    }
                }
            }

            $cursor->addDay();
        }
    }

    // ── HR: attendance ────────────────────────────────────────────────────

    private function seedAttendance(Carbon $start, Carbon $end): void
    {
        $attendance = app(AttendanceService::class);

        foreach ($this->employeesByShop as $employees) {
            foreach ($employees as $employee) {
                Auth::guard('api')->setUser($this->admin);
                $cursor = $start->copy();
                while ($cursor->lte($end)) {
                    if ($cursor->dayOfWeekIso !== 5) { // Friday off
                        Carbon::setTestNow($cursor->copy()->setTime(8, 30));
                        $roll = rand(1, 100);
                        $status = match (true) {
                            $roll <= 88 => 'present',
                            $roll <= 95 => 'late',
                            default     => 'absent',
                        };
                        try {
                            $attendance->mark($employee->id, $cursor->copy(), $status);
                        } catch (Throwable $e) {
                            // already marked / not their working day per schedule — skip
                        }
                    }
                    $cursor->addDay();
                }
            }
        }
    }

    // ── HR: leave requests ───────────────────────────────────────────────

    private function seedLeaves(): void
    {
        $leaves = app(LeaveService::class);
        $samples = [
            ['user' => 4, 'start' => '2026-05-10', 'end' => '2026-05-12', 'type' => 'annual', 'reason' => 'إجازة عائلية', 'decision' => 'approved'],
            ['user' => 6, 'start' => '2026-05-20', 'end' => '2026-05-20', 'type' => 'sick',   'reason' => 'وعكة صحية',   'decision' => 'approved'],
            ['user' => 5, 'start' => '2026-06-05', 'end' => '2026-06-08', 'type' => 'annual', 'reason' => 'سفر',         'decision' => 'approved'],
            ['user' => 7, 'start' => '2026-06-15', 'end' => '2026-06-16', 'type' => 'unpaid', 'reason' => 'ظرف طارئ',   'decision' => 'rejected'],
            ['user' => 4, 'start' => '2026-07-01', 'end' => '2026-07-03', 'type' => 'annual', 'reason' => 'إجازة صيفية', 'decision' => 'pending'],
        ];

        foreach ($samples as $s) {
            $employee = User::find($s['user']);
            if (! $employee) {
                continue;
            }
            Carbon::setTestNow(Carbon::parse($s['start'])->subDays(3));
            $this->actAs($employee);
            try {
                $leave = $leaves->create($employee, [
                    'start_date' => $s['start'],
                    'end_date'   => $s['end'],
                    'type'       => $s['type'],
                    'reason'     => $s['reason'],
                ]);

                if ($s['decision'] !== 'pending') {
                    Carbon::setTestNow(Carbon::parse($s['start'])->subDay());
                    $manager = $this->managerByShop[$employee->shop_id] ?? $this->admin;
                    $this->actAs($manager);
                    if ($s['decision'] === 'approved') {
                        $leaves->approve($leave, 'موافَق عليها');
                    } else {
                        $leaves->reject($leave, 'ظروف العمل لا تسمح في هذا التوقيت');
                    }
                }
            } catch (Throwable $e) {
                // overlap or balance edge case — skip this sample
            }
        }
    }

    // ── HR: payroll — close out May & June, finalize July ────────────────

    private function seedPayroll(): void
    {
        $payroll = app(PayrollService::class);
        $this->actAs($this->admin);

        foreach ([[2026, 5], [2026, 6]] as [$year, $month]) {
            Carbon::setTestNow(Carbon::create($year, $month, 28));
            try {
                $payroll->generateAll($year, $month);
            } catch (Throwable $e) {
                // no eligible employees that month — skip
            }
        }

        // Lock + mark paid every generated payroll for months now fully in the past.
        Carbon::setTestNow(Carbon::create(2026, 8, 1));
        foreach (Payroll::whereIn('period_month', [5, 6, 7])->where('period_year', 2026)->get() as $p) {
            try {
                if ($p->status === Payroll::GENERATED) {
                    $payroll->lock($p);
                    $payroll->markPaid($p);
                }
            } catch (Throwable $e) {
                continue;
            }
        }
    }

    // ── HR: bonuses & penalties ──────────────────────────────────────────

    private function seedBonusesPenalties(): void
    {
        $service = app(BonusPenaltyService::class);
        $this->actAs($this->admin);

        $bonuses = [
            [4, '2026-05-31', 200, 'تميز في خدمة العملاء خلال الشهر'],
            [6, '2026-06-30', 150, 'تحقيق هدف المبيعات الشهري'],
        ];
        foreach ($bonuses as [$userId, $date, $amount, $reason]) {
            Carbon::setTestNow(Carbon::parse($date));
            $employee = User::find($userId);
            if (! $employee) {
                continue;
            }
            try {
                $service->createBonus($employee, ['amount' => $amount, 'reason' => $reason, 'date' => $date]);
            } catch (Throwable $e) {
                continue;
            }
        }

        $penalties = [
            [5, '2026-06-10', 100, 'تأخير متكرر عن موعد الوردية'],
        ];
        foreach ($penalties as [$userId, $date, $amount, $reason]) {
            Carbon::setTestNow(Carbon::parse($date));
            $employee = User::find($userId);
            if (! $employee) {
                continue;
            }
            try {
                $service->createPenalty($employee, ['amount' => $amount, 'reason' => $reason, 'date' => $date]);
            } catch (Throwable $e) {
                continue;
            }
        }
    }

    // ── HR: one more completed temporary transfer ────────────────────────

    private function seedEmployeeTransfer(): void
    {
        $transfers = app(HrTransferService::class);
        $employee = User::find(5); // بائع 2 - الفرع الرئيسي
        if (! $employee || ! isset($this->safeByShop[3])) {
            return;
        }

        $startDate = '2026-06-01';
        $endDate   = '2026-06-07';

        Carbon::setTestNow(Carbon::parse($startDate)->subDays(2));
        $this->actAs($this->admin);
        try {
            $transfer = $transfers->create([
                'user_id'             => $employee->id,
                'temporary_branch_id' => 3, // Sheraton — gets a temporary hand for its launch week
                'start_date'          => $startDate,
                'end_date'            => $endDate,
                'reason'              => 'دعم فرع شيراتون في أسبوعه الأول',
            ]);

            Carbon::setTestNow(Carbon::parse($startDate)->subDay());
            $transfers->approve($transfer);

            Carbon::setTestNow(Carbon::parse($endDate)->addDay());
            $transfers->processDue();
        } catch (Throwable $e) {
            // overlap with another transfer — skip
        }
    }

    // ── HR: a salary advance with partial early repayment ────────────────

    private function seedSalaryAdvance(): void
    {
        $advances = app(SalaryAdvanceService::class);
        $employee = User::find(7);
        if (! $employee || ! isset($this->safeByShop[$employee->shop_id])) {
            return;
        }

        Carbon::setTestNow(Carbon::parse('2026-05-15'));
        $this->actAs($employee);
        try {
            $advance = $advances->create($employee, [
                'requested_amount' => 1000,
                'reason'           => 'ظرف عائلي طارئ',
            ]);

            Carbon::setTestNow(Carbon::parse('2026-05-16'));
            $this->actAs($this->admin);
            $advance = $advances->approve($advance, [
                'approved_amount' => 1000,
                'safe_id'         => $this->safeByShop[$employee->shop_id]->id,
                'mode'            => SalaryAdvance::MODE_FIXED_MONTHS,
                'months'          => 4,
                'start_year'      => 2026,
                'start_month'     => 6,
            ], $this->admin);

            Carbon::setTestNow(Carbon::parse('2026-06-20'));
            $advances->recordEarlyRepayment($advance, 250, '2026-06-20', $this->safeByShop[$employee->shop_id]->id, $this->admin, 'سداد مبكر جزئي');
        } catch (Throwable $e) {
            // skip on any validation edge case
        }
    }

    // ── Safe: periodic admin deposits/withdrawals for realism ────────────

    private function seedSafeMisc(Carbon $start, Carbon $end): void
    {
        $safeService = app(SafeService::class);
        $this->actAs($this->admin);

        $cursor = $start->copy()->startOfMonth();
        while ($cursor->lte($end)) {
            foreach ($this->safeByShop as $shopId => $safe) {
                Carbon::setTestNow($cursor->copy()->setTime(12, 0));
                try {
                    // Monthly maintenance/admin expense withdrawal
                    $safeService->adminWithdraw(
                        $safe, $this->egpId, rand(300, 900),
                        $this->reasonIdByName['مصاريف صيانة'] ?? $this->reasonIdByName['مصاريف إدارية'] ?? array_values($this->reasonIdByName)[0],
                        'مصاريف تشغيلية شهرية', $this->admin->id
                    );
                } catch (Throwable $e) {
                }

                Carbon::setTestNow($cursor->copy()->addDays(2)->setTime(12, 30));
                try {
                    // Occasional cash top-up / float adjustment deposit
                    if (rand(1, 100) <= 50) {
                        $safeService->adminDeposit(
                            $safe, $this->egpId, rand(500, 1500),
                            $this->reasonIdByName['إيداع نقدي'] ?? array_values($this->reasonIdByName)[0],
                            'تغذية رصيد الخزنة', $this->admin->id
                        );
                    }
                } catch (Throwable $e) {
                }
            }
            $cursor->addMonth();
        }
    }

    // ── Convention: a couple more withdrawals on the existing convention ─

    private function seedConvention(): void
    {
        $convention = Convention::first();
        if (! $convention) {
            return;
        }
        $conventionService = app(ConventionService::class);
        $manager = $this->managerByShop[$convention->shop_id] ?? null;

        $withdrawals = [
            ['2026-05-18', 300, 'مصاريف نقل بضاعة'],
            ['2026-06-25', 450, 'ضيافة عملاء بالجملة'],
        ];
        foreach ($withdrawals as [$date, $amount, $reason]) {
            Carbon::setTestNow(Carbon::parse($date));
            $this->actAs($manager ?? $this->admin);
            try {
                $conventionService->withdraw($convention, [
                    'amount' => $amount,
                    'reason' => $reason,
                ], $manager?->id, $manager ? null : $this->admin->id);
            } catch (Throwable $e) {
                continue;
            }
        }
    }

    // ── Branch ops: a waste record ────────────────────────────────────────

    private function seedWaste(): void
    {
        if (empty($this->rawMaterials) || ! isset($this->employeesByShop[1])) {
            return;
        }
        $waste = app(WasteService::class);
        $product = $this->rawMaterials[array_rand($this->rawMaterials)];
        $reporter = $this->managerByShop[1] ?? $this->admin;

        Carbon::setTestNow(Carbon::parse('2026-06-12 11:00'));
        $this->actAs($reporter);
        try {
            $waste->register([
                'shop_id'    => 1,
                'product_id' => $product->id,
                'quantity'   => 15,
                'reason'     => 'leakage',
                'notes'      => 'تسرب من عبوة أثناء التخزين',
            ], $reporter);
        } catch (Throwable $e) {
        }
    }

    // ── Branch ops: one full inventory count cycle for the main branch ───

    private function seedInventoryCount(Carbon $end): void
    {
        if (! isset($this->employeesByShop[1]) || empty($this->employeesByShop[1])) {
            return;
        }
        $countService = app(InventoryCountService::class);
        $manager = $this->managerByShop[1] ?? $this->admin;
        $employeeIds = array_map(fn ($e) => $e->id, $this->employeesByShop[1]);

        Carbon::setTestNow($end->copy()->subDays(3)->setTime(9, 0));
        $this->actAs($manager);
        try {
            $session = $countService->create(1, $employeeIds, $manager, 'جرد دوري نهاية الفترة');

            $session->load('items');
            $counts = [];
            foreach ($session->items as $item) {
                // Small, believable discrepancies — mostly exact, a few off by a little.
                $drift = rand(1, 100) <= 25 ? (rand(0, 100) <= 50 ? -1 : 1) * round(rand(1, 5) / 10 * max(1, $item->system_quantity), 2) : 0;
                $counts[] = ['item_id' => $item->id, 'physical_quantity' => max(0, round((float) $item->system_quantity + $drift, 3))];
            }
            $session = $countService->recordCounts($session, $counts, $manager);
            $session = $countService->submitForReview($session, $manager);

            Carbon::setTestNow($end->copy()->subDays(2)->setTime(10, 0));
            $this->actAs($this->admin);
            $session = $countService->approve($session, $this->admin);
            $countService->adjustInventory($session, $this->admin);
        } catch (Throwable $e) {
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /** @return array<int, mixed> */
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
