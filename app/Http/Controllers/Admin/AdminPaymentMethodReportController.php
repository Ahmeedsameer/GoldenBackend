<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InvoicePayment;
use App\Models\PaymentMethod;
use App\Services\Reports\ReportExportService;
use Illuminate\Http\Request;

/**
 * Payment Methods module reports — Payment Method Report, Card Fees Report,
 * Bank Charges Report. All three read live from `invoice_payments` (joined
 * to `payment_methods`/`invoices`/`currencies`) — no separate reporting
 * table, same convention as every other report in this app. Never touches
 * Safe/Sales/Accounting — those already recorded the NET amount at sale
 * time (see SalesService::createInvoice()); this just reads it back.
 */
class AdminPaymentMethodReportController extends Controller
{
    private const TYPE_LABELS = [
        'cash' => 'نقدي', 'visa' => 'فيزا', 'mastercard' => 'ماستركارد',
        'bank_card' => 'بطاقة بنكية', 'mobile_wallet' => 'محفظة إلكترونية',
        'bank_transfer' => 'تحويل بنكي', 'other' => 'أخرى',
    ];

    public function __construct(private ReportExportService $exportService) {}

    private function parseDates(Request $request): array
    {
        if ($request->filled('date_from') && $request->filled('date_to')) {
            return [$request->get('date_from'), $request->get('date_to')];
        }

        return [now()->startOfMonth()->toDateString(), now()->toDateString()];
    }

    /** GET /api/admin/reports/payment-methods — gross/fee/net totals per payment method. */
    public function paymentMethods(Request $request)
    {
        [$from, $to] = $this->parseDates($request);

        $rows = InvoicePayment::query()
            ->join('invoices', 'invoice_payments.invoice_id', '=', 'invoices.id')
            ->join('currencies', 'invoice_payments.currency_id', '=', 'currencies.id')
            ->where('invoices.status', 'approved')
            ->whereBetween('invoices.date', [$from, $to])
            ->whereNotNull('invoice_payments.payment_method_id')
            ->selectRaw('
                invoice_payments.payment_method_id                                             as method_id,
                COUNT(*)                                                                        as payment_count,
                COALESCE(SUM(invoice_payments.amount * currencies.rate), 0)                     as gross_egp,
                COALESCE(SUM(invoice_payments.processing_fee_amount * currencies.rate), 0)      as fee_egp,
                COALESCE(SUM(invoice_payments.net_amount * currencies.rate), 0)                 as net_egp
            ')
            ->groupBy('invoice_payments.payment_method_id')
            ->get()
            ->keyBy('method_id');

        $data = PaymentMethod::orderBy('name')->get()->map(function (PaymentMethod $m) use ($rows) {
            $row = $rows->get($m->id);
            return [
                'id' => $m->id, 'name' => $m->name, 'type' => $m->type, 'type_label' => self::TYPE_LABELS[$m->type] ?? $m->type,
                'is_active' => $m->is_active,
                'payment_count' => (int) ($row->payment_count ?? 0),
                'gross_amount' => round((float) ($row->gross_egp ?? 0), 2),
                'fee_amount' => round((float) ($row->fee_egp ?? 0), 2),
                'net_amount' => round((float) ($row->net_egp ?? 0), 2),
            ];
        });

        if ($request->filled('format')) {
            $columns = ['وسيلة الدفع', 'النوع', 'عدد العمليات', 'إجمالي المدفوع (ج.م)', 'رسوم المعالجة (ج.م)', 'صافي الشركة (ج.م)'];
            $tableRows = $data->map(fn ($r) => [$r['name'], $r['type_label'], $r['payment_count'], $r['gross_amount'], $r['fee_amount'], $r['net_amount']])->all();

            return $request->input('format') === 'excel'
                ? $this->exportService->excel('تقرير وسائل الدفع', $columns, $tableRows, ['من' => $from, 'إلى' => $to], [3, 4, 5])
                : $this->exportService->pdf('تقرير وسائل الدفع', $columns, $tableRows, ['من' => $from, 'إلى' => $to]);
        }

        return response()->json(['message' => 'ok', 'data' => ['period' => ['from' => $from, 'to' => $to], 'rows' => $data->values()]]);
    }

    /** GET /api/admin/reports/payment-methods/card-fees — every fee-bearing payment line, itemized. */
    public function cardFees(Request $request)
    {
        [$from, $to] = $this->parseDates($request);

        $rows = InvoicePayment::with(['paymentMethod:id,name,type', 'invoice:id,date,shop_id', 'invoice.shop:id,name'])
            ->whereHas('invoice', fn ($q) => $q->where('status', 'approved')->whereBetween('date', [$from, $to]))
            ->where('processing_fee_amount', '>', 0)
            ->orderByDesc('id')
            ->get();

        $data = $rows->map(fn (InvoicePayment $p) => [
            'invoice_id' => $p->invoice_id,
            'date' => optional($p->invoice?->date)->toDateString(),
            'branch' => $p->invoice?->shop?->name,
            'method' => $p->paymentMethod?->name,
            'gross_amount' => (float) $p->amount,
            'fee_percent' => (float) $p->processing_fee_percent,
            'fee_amount' => (float) $p->processing_fee_amount,
            'net_amount' => (float) $p->net_amount,
        ]);

        $totalFees = round((float) $rows->sum('processing_fee_amount'), 2);

        if ($request->filled('format')) {
            $columns = ['رقم الفاتورة', 'التاريخ', 'الفرع', 'وسيلة الدفع', 'المبلغ الإجمالي', 'نسبة الرسوم %', 'قيمة الرسوم', 'الصافي'];
            $tableRows = $data->map(fn ($r) => [$r['invoice_id'], $r['date'], $r['branch'], $r['method'], $r['gross_amount'], $r['fee_percent'], $r['fee_amount'], $r['net_amount']])->all();

            return $request->input('format') === 'excel'
                ? $this->exportService->excel('تقرير رسوم البطاقات', $columns, $tableRows, ['من' => $from, 'إلى' => $to], [4, 6, 7])
                : $this->exportService->pdf('تقرير رسوم البطاقات', $columns, $tableRows, ['من' => $from, 'إلى' => $to]);
        }

        return response()->json(['message' => 'ok', 'data' => ['period' => ['from' => $from, 'to' => $to], 'total_fees' => $totalFees, 'rows' => $data->values()]]);
    }

    /** GET /api/admin/reports/payment-methods/bank-charges — grouped by bank (method name) + card type, with average fee. */
    public function bankCharges(Request $request)
    {
        [$from, $to] = $this->parseDates($request);

        $rows = InvoicePayment::query()
            ->join('invoices', 'invoice_payments.invoice_id', '=', 'invoices.id')
            ->join('payment_methods', 'invoice_payments.payment_method_id', '=', 'payment_methods.id')
            ->where('invoices.status', 'approved')
            ->where('invoice_payments.processing_fee_amount', '>', 0)
            ->whereBetween('invoices.date', [$from, $to])
            ->selectRaw('
                payment_methods.name                                     as bank,
                payment_methods.type                                     as card_type,
                COUNT(*)                                                 as charge_count,
                COALESCE(SUM(invoice_payments.amount), 0)                as gross_amount,
                COALESCE(SUM(invoice_payments.processing_fee_amount), 0) as fee_amount,
                COALESCE(SUM(invoice_payments.net_amount), 0)            as net_amount
            ')
            ->groupBy('payment_methods.name', 'payment_methods.type')
            ->orderByDesc('fee_amount')
            ->get();

        $totalFees = round((float) $rows->sum('fee_amount'), 2);

        $data = $rows->map(fn ($r) => [
            'bank' => $r->bank, 'card_type' => $r->card_type, 'card_type_label' => self::TYPE_LABELS[$r->card_type] ?? $r->card_type,
            'charge_count' => (int) $r->charge_count,
            'gross_amount' => round((float) $r->gross_amount, 2), 'fee_amount' => round((float) $r->fee_amount, 2),
            'net_amount' => round((float) $r->net_amount, 2),
            'avg_fee' => $r->charge_count > 0 ? round((float) $r->fee_amount / (int) $r->charge_count, 2) : 0.0,
        ]);

        if ($request->filled('format')) {
            $columns = ['البنك / الوسيلة', 'نوع البطاقة', 'عدد العمليات', 'إجمالي المبلغ', 'إجمالي الرسوم', 'متوسط الرسوم', 'الصافي المودع'];
            $tableRows = $data->map(fn ($r) => [$r['bank'], $r['card_type_label'], $r['charge_count'], $r['gross_amount'], $r['fee_amount'], $r['avg_fee'], $r['net_amount']])->all();

            return $request->input('format') === 'excel'
                ? $this->exportService->excel('تقرير رسوم البنك', $columns, $tableRows, ['من' => $from, 'إلى' => $to], [3, 4, 5, 6])
                : $this->exportService->pdf('تقرير رسوم البنك', $columns, $tableRows, ['من' => $from, 'إلى' => $to]);
        }

        return response()->json(['message' => 'ok', 'data' => ['period' => ['from' => $from, 'to' => $to], 'total_fees' => $totalFees, 'rows' => $data->values()]]);
    }

    /** GET /api/admin/reports/payment-methods/branch-payments — payment distribution grouped by branch, then by method within each branch. */
    public function branchPayments(Request $request)
    {
        [$from, $to] = $this->parseDates($request);

        $rows = InvoicePayment::query()
            ->join('invoices', 'invoice_payments.invoice_id', '=', 'invoices.id')
            ->join('shops', 'invoices.shop_id', '=', 'shops.id')
            ->join('payment_methods', 'invoice_payments.payment_method_id', '=', 'payment_methods.id')
            ->where('invoices.status', 'approved')
            ->whereBetween('invoices.date', [$from, $to])
            ->selectRaw('
                shops.id                                                    as shop_id,
                shops.name                                                  as shop_name,
                payment_methods.name                                        as method,
                COUNT(*)                                                    as payment_count,
                COALESCE(SUM(invoice_payments.amount), 0)                   as gross_amount,
                COALESCE(SUM(invoice_payments.processing_fee_amount), 0)    as fee_amount,
                COALESCE(SUM(invoice_payments.net_amount), 0)               as net_amount
            ')
            ->groupBy('shops.id', 'shops.name', 'payment_methods.name')
            ->orderBy('shops.name')->orderByDesc('gross_amount')
            ->get();

        $byBranch = $rows->groupBy('shop_name')->map(function ($branchRows, $shopName) {
            return [
                'branch' => $shopName,
                'total_gross' => round((float) $branchRows->sum('gross_amount'), 2),
                'total_net' => round((float) $branchRows->sum('net_amount'), 2),
                'methods' => $branchRows->map(fn ($r) => [
                    'method' => $r->method, 'payment_count' => (int) $r->payment_count,
                    'gross_amount' => round((float) $r->gross_amount, 2), 'fee_amount' => round((float) $r->fee_amount, 2),
                    'net_amount' => round((float) $r->net_amount, 2),
                ])->values(),
            ];
        })->values();

        if ($request->filled('format')) {
            $columns = ['الفرع', 'وسيلة الدفع', 'عدد العمليات', 'إجمالي المبلغ', 'الرسوم', 'الصافي'];
            $tableRows = $rows->map(fn ($r) => [$r->shop_name, $r->method, (int) $r->payment_count, round((float) $r->gross_amount, 2), round((float) $r->fee_amount, 2), round((float) $r->net_amount, 2)])->all();

            return $request->input('format') === 'excel'
                ? $this->exportService->excel('تقرير المدفوعات حسب الفرع', $columns, $tableRows, ['من' => $from, 'إلى' => $to], [3, 4, 5])
                : $this->exportService->pdf('تقرير المدفوعات حسب الفرع', $columns, $tableRows, ['من' => $from, 'إلى' => $to]);
        }

        return response()->json(['message' => 'ok', 'data' => ['period' => ['from' => $from, 'to' => $to], 'branches' => $byBranch]]);
    }

    /** GET /api/admin/reports/payment-methods/currency — totals per currency + current wallet/safe balances held in that currency. */
    public function currencyReport(Request $request)
    {
        [$from, $to] = $this->parseDates($request);

        $rows = InvoicePayment::query()
            ->join('invoices', 'invoice_payments.invoice_id', '=', 'invoices.id')
            ->join('currencies', 'invoice_payments.currency_id', '=', 'currencies.id')
            ->where('invoices.status', 'approved')
            ->whereBetween('invoices.date', [$from, $to])
            ->selectRaw('
                currencies.id                                            as currency_id,
                currencies.code                                          as code,
                currencies.symbol                                        as symbol,
                COUNT(*)                                                 as payment_count,
                COALESCE(SUM(invoice_payments.amount), 0)                as gross_amount,
                COALESCE(SUM(invoice_payments.processing_fee_amount), 0) as fee_amount,
                COALESCE(SUM(invoice_payments.net_amount), 0)            as net_amount
            ')
            ->groupBy('currencies.id', 'currencies.code', 'currencies.symbol')
            ->get()
            ->keyBy('currency_id');

        // Current wallet/safe balances per currency — reuses the existing SafeBalance
        // model directly, no new balance-tracking logic.
        $balances = \App\Models\SafeBalance::query()
            ->join('currencies', 'safe_balances.currency_id', '=', 'currencies.id')
            ->selectRaw('currencies.id as currency_id, currencies.code as code, COALESCE(SUM(safe_balances.balance), 0) as total_balance')
            ->groupBy('currencies.id', 'currencies.code')
            ->get()
            ->keyBy('currency_id');

        $currencyIds = $rows->keys()->merge($balances->keys())->unique();
        $currencies = \App\Models\Currency::whereIn('id', $currencyIds)->get()->keyBy('id');

        $data = $currencyIds->map(function ($id) use ($rows, $balances, $currencies) {
            $currency = $currencies->get($id);
            $row = $rows->get($id);
            $balance = $balances->get($id);
            return [
                'currency_id' => $id, 'code' => $currency?->code, 'symbol' => $currency?->symbol,
                'payment_count' => (int) ($row->payment_count ?? 0),
                'gross_amount' => round((float) ($row->gross_amount ?? 0), 2),
                'fee_amount' => round((float) ($row->fee_amount ?? 0), 2),
                'net_amount' => round((float) ($row->net_amount ?? 0), 2),
                'wallet_balance' => round((float) ($balance->total_balance ?? 0), 2),
            ];
        })->sortByDesc('gross_amount')->values();

        if ($request->filled('format')) {
            $columns = ['العملة', 'عدد العمليات', 'إجمالي المدفوع', 'الرسوم', 'الصافي', 'الرصيد الحالي في الخزائن'];
            $tableRows = $data->map(fn ($r) => [$r['code'], $r['payment_count'], $r['gross_amount'], $r['fee_amount'], $r['net_amount'], $r['wallet_balance']])->all();

            return $request->input('format') === 'excel'
                ? $this->exportService->excel('تقرير العملات', $columns, $tableRows, ['من' => $from, 'إلى' => $to], [2, 3, 4, 5])
                : $this->exportService->pdf('تقرير العملات', $columns, $tableRows, ['من' => $from, 'إلى' => $to]);
        }

        return response()->json(['message' => 'ok', 'data' => ['period' => ['from' => $from, 'to' => $to], 'rows' => $data]]);
    }
}
