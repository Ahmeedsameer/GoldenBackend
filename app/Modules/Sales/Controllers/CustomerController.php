<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerNote;
use App\Models\InvoiceItem;
use App\Models\SalesAuditLog;
use App\Models\Tag;
use App\Modules\Sales\Services\CustomerTagService;
use App\Modules\Sales\Services\SalesAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Customer Management module — list/detail/reports. Reuses Customer::invoices()
 * (Invoice already belongsTo Customer) for every stat; nothing here duplicates
 * invoice data, it only aggregates what already exists.
 *
 * Scoping:
 *  - Admin: full access, every branch.
 *  - Manager: view only — scoped to their own branch (see scopeToManager()).
 *  - Seller: no list access (index() 403s); show() only for a customer they
 *    have personally sold to, matching the same seller_id-ownership rule
 *    InvoiceController already applies to a seller's own invoices.
 */
class CustomerController extends Controller
{
    public function __construct(
        private SalesAuditLogger $salesAuditLogger,
        private CustomerTagService $customerTagService,
    ) {}

    /**
     * Scope a customer LIST to a manager's own branch. Deliberately matches
     * via the customer's INVOICES (same rule show()'s authorizeView() uses),
     * not the stored customers.shop_id column — a customer's shop_id is only
     * ever set at creation time (see SalesService::createCustomer()/
     * createInvoice()), so scoping the list by that column alone would hide
     * every customer created before that column existed, or one who first
     * bought elsewhere but has since bought in this branch too.
     */
    private function scopeToManagerViaInvoices($query, Request $request): void
    {
        if ($request->user()->role === 'manager') {
            $shopId = $request->user()->shop_id;
            $query->whereHas('invoices', fn ($q) => $q->where('shop_id', $shopId));
        }
    }

    /** GET /api/customers?search=&per_page= — Admin: all; Manager: own branch. */
    public function index(Request $request)
    {
        if ($request->user()->role === 'sales') {
            abort(403, 'غير مصرح لك بعرض قائمة العملاء.');
        }

        $query = Customer::query()
            ->withCount(['invoices as total_invoices' => fn ($q) => $q->where('status', 'approved')])
            ->withSum(['invoices as total_purchases' => fn ($q) => $q->where('status', 'approved')], 'total_amount')
            ->withMin(['invoices as first_purchase_date' => fn ($q) => $q->where('status', 'approved')], 'date')
            ->withMax(['invoices as last_purchase_date' => fn ($q) => $q->where('status', 'approved')], 'date')
            ->with('shop:id,name');

        $this->scopeToManagerViaInvoices($query, $request);

        if ($request->filled('search')) {
            $term = trim((string) $request->string('search'));
            $id   = preg_replace('/\D/', '', $term);
            $query->where(function ($q) use ($term, $id) {
                $q->where('name', 'like', "%{$term}%")
                  ->orWhere('phone', 'like', "%{$term}%");
                if ($id !== '') {
                    $q->orWhere('id', $id)
                      ->orWhereHas('invoices', fn ($iq) => $iq->where('id', $id));
                }
            });
        }

        $perPage = min($request->integer('per_page', $request->integer('limit', 20)), 100);

        return response()->json(['message' => 'ok', 'data' => $query->orderByDesc('id')->paginate($perPage)]);
    }

    /**
     * GET /api/customers/{id} — full profile: info, invoice history, totals,
     * average invoice, last purchase, most purchased products, analytics.
     * Every figure is derived from the customer's existing invoices —
     * nothing is stored redundantly, and profit/cost always come from each
     * invoice_item's own frozen snapshot (unit_cost/line_cost/line_profit),
     * never a live product price (see InvoiceItem's snapshot accessors).
     */
    public function show(string $id)
    {
        $customer = Customer::with(['shop:id,name', 'notesUpdatedBy:id,name'])->findOrFail($id);
        $user     = $this->authorizeView($customer);

        // Stats/analytics/favorite-products stay scoped to approved (completed)
        // purchases only — unchanged. The Purchase Timeline itself, however,
        // needs every status (pending/cancelled too) so the admin/manager can
        // actually see a cancelled or still-pending invoice in the customer's
        // history, not just completed ones.
        $invoices = $customer->invoices()
            ->where('status', 'approved')
            ->with(['items.product:id,name,sku,scalar', 'seller:id,name', 'shop:id,name'])
            ->latest('date')
            ->get();

        $timelineInvoices = $customer->invoices()
            ->with(['items.product:id,name,sku,scalar', 'seller:id,name', 'shop:id,name'])
            ->latest('date')
            ->get();

        $totalInvoices  = $invoices->count();
        $totalPurchases = round((float) $invoices->sum('total_amount'), 2);
        $avgInvoice     = $totalInvoices > 0 ? round($totalPurchases / $totalInvoices, 2) : 0.0;
        $totalProducts  = (float) $invoices->sum(fn ($inv) => $inv->items->sum('quantity'));

        // Average days between purchases — gaps between consecutive approved
        // invoice dates, sorted oldest→newest (needs at least 2 invoices).
        $sortedDates = $invoices->pluck('date')->filter()->sort()->values();
        $avgDaysBetweenPurchases = null;
        if ($sortedDates->count() >= 2) {
            $gaps = [];
            for ($i = 1; $i < $sortedDates->count(); $i++) {
                // abs() defensively — diffInDays()'s sign has varied across
                // Carbon versions; the gap between two purchases is never negative.
                $gaps[] = abs($sortedDates[$i]->diffInDays($sortedDates[$i - 1]));
            }
            $avgDaysBetweenPurchases = round(array_sum($gaps) / count($gaps), 1);
        }

        // Total profit — sums each invoice's own line_cost snapshots (the
        // exact same math as Invoice::getGrossProfitAttribute() /
        // AdminAllInvoicesController's total_cost/total_profit), computed
        // here over the already-loaded $invoices instead of re-querying.
        // Admin only — never sent to Manager/Seller.
        $totalProfit = null;
        if ($user->role === 'admin') {
            $totalCost   = (float) $invoices->sum(fn ($inv) => $inv->items->sum('line_cost'));
            $totalProfit = round($totalPurchases - $totalCost, 2);
        }

        // Most purchased products (top 5) — same snapshot-name aggregation
        // shape as ReportsController::topProducts(), scoped to this customer
        // instead of a shop/period (see that method's comment on why
        // product_name is read from invoice_items, never the live product).
        // purchase_count/last_purchase_date are new per-product metrics;
        // product image has no snapshot (see InvoiceItem) so it's read live
        // from the product, same resolution SalesService already uses.
        $base = InvoiceItem::from('invoice_items')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->where('invoices.customer_id', $customer->id)
            ->where('invoices.status', 'approved');

        $rows = (clone $base)
            ->selectRaw('
                invoice_items.product_id as product_id,
                COALESCE(SUM(invoice_items.quantity), 0)                       as total_qty,
                COALESCE(SUM(invoice_items.quantity * invoice_items.price), 0) as total_revenue,
                COUNT(DISTINCT invoice_items.invoice_id)                       as purchase_count,
                MAX(invoices.date)                                             as last_purchase_date
            ')
            ->groupBy('invoice_items.product_id')
            ->orderByDesc('total_qty')
            ->limit(5)
            ->get();

        $productIds = $rows->pluck('product_id');
        $snapshots  = (clone $base)
            ->whereIn('invoice_items.product_id', $productIds)
            ->orderByDesc('invoices.date')->orderByDesc('invoice_items.id')
            ->get(['invoice_items.product_id', 'invoice_items.product_name'])
            ->unique('product_id')->keyBy('product_id');
        $products = \App\Models\Product::whereIn('id', $productIds)->get(['id', 'scalar', 'image'])->keyBy('id');

        $mostPurchased = $rows->map(fn ($r) => [
            'product_id'         => $r->product_id,
            'product_name'       => $snapshots[$r->product_id]->product_name ?? '—',
            'scalar'             => $products[$r->product_id]->scalar ?? null,
            'image'              => ($products[$r->product_id]->image ?? null)
                ? asset('storage/' . $products[$r->product_id]->image)
                : null,
            'total_qty'          => round((float) $r->total_qty, 3),
            'total_revenue'      => round((float) $r->total_revenue, 2),
            'purchase_count'     => (int) $r->purchase_count,
            'last_purchase_date' => $r->last_purchase_date,
        ]);

        $lastPurchaseDate = $invoices->max('date');
        // abs() defensively — diffInDays()'s sign has varied across Carbon
        // versions (same caveat already handled above for avg-gap math).
        $daysSinceLastPurchase = $lastPurchaseDate ? (int) abs(now()->diffInDays($lastPurchaseDate)) : null;

        $stats = [
            'customer_since'            => $customer->created_at?->toDateString(),
            'total_invoices'            => $totalInvoices,
            'total_purchases'           => $totalPurchases,
            'average_invoice'           => $avgInvoice,
            'total_products_purchased'  => $totalProducts,
            'first_purchase_date'       => $invoices->min('date'),
            'last_purchase_date'        => $lastPurchaseDate,
            'days_since_last_purchase'  => $daysSinceLastPurchase,
        ];

        $analytics = [
            'avg_days_between_purchases' => $avgDaysBetweenPurchases,
            'largest_invoice'            => $totalInvoices > 0 ? round((float) $invoices->max('total_amount'), 2) : null,
            'smallest_invoice'           => $totalInvoices > 0 ? round((float) $invoices->min('total_amount'), 2) : null,
            // No discount concept exists anywhere in the Sales domain
            // today (no column on invoices/invoice_items) — always 0
            // rather than fabricated. See Phase 2 summary.
            'total_discounts'            => 0,
            // Admin only; null for Manager/Seller.
            'total_profit'               => $totalProfit,
        ];

        return response()->json([
            'message' => 'ok',
            'data'    => [
                'customer' => $customer,
                'stats'    => $stats,
                'analytics' => $analytics,
                'invoices'                 => $timelineInvoices,
                'most_purchased_products'  => $mostPurchased,
                'activity'                 => $this->buildActivityTimeline($customer),
                'tags'                     => [
                    'auto'   => $this->customerTagService->computeAutoTags($customer, $stats, $analytics, $invoices),
                    'manual' => $customer->tags,
                ],
            ],
        ]);
    }

    /**
     * Customer Activity Timeline — single source: sales_audit_logs rows for
     * this customer (see SalesAuditLogger, called from SalesService and this
     * controller wherever a loggable action happens). Ascending order;
     * frontend renders newest-first. The earliest/latest 'invoice_created'
     * entries are annotated as first/latest-purchase milestones — labels on
     * top of real logged events, not synthesized rows, so every entry here
     * corresponds to something that actually happened and was recorded.
     */
    private function buildActivityTimeline(Customer $customer): array
    {
        $logs = SalesAuditLog::where('customer_id', $customer->id)
            ->with('actor:id,name')
            ->orderBy('created_at')
            ->get();

        $invoiceCreatedIds = $logs->where('action', 'invoice_created')->pluck('id');
        $firstId = $invoiceCreatedIds->first();
        $lastId  = $invoiceCreatedIds->last();

        return $logs->map(function (SalesAuditLog $log) use ($firstId, $lastId) {
            $milestone = null;
            if ($log->id === $firstId) {
                $milestone = 'first_purchase';
            } elseif ($log->id === $lastId && $invoiceCreatedIds->count() > 1) {
                $milestone = 'latest_purchase';
            }

            return [
                'type'        => $log->action,
                'milestone'   => $milestone,
                'date'        => $log->created_at,
                'user'        => $log->actor?->name,
                'subject_id'  => $log->subject_id,
                'subject_type'=> $log->subject_type,
                'description' => $this->describeActivity($log),
            ];
        })->values()->all();
    }

    private function describeActivity(SalesAuditLog $log): string
    {
        $new = $log->new_values ?? [];

        return match ($log->action) {
            'customer_created'       => 'تم إنشاء بيانات العميل',
            'customer_updated'       => 'تم تحديث بيانات العميل',
            'customer_note_added'    => 'تمت إضافة ملاحظة: ' . ($new['excerpt'] ?? ''),
            'customer_tag_added'     => 'تمت إضافة الوسم: ' . ($new['tag'] ?? ''),
            'customer_tag_removed'   => 'تمت إزالة الوسم: ' . ($new['tag'] ?? ''),
            'invoice_created'        => 'تم إنشاء الفاتورة رقم #' . $log->subject_id . ' بقيمة ' . ($new['total_amount'] ?? '—') . ' ج.م',
            'invoice_cancelled'      => 'تم إلغاء الفاتورة رقم #' . $log->subject_id . (! empty($new['reason']) ? ' — ' . $new['reason'] : ''),
            default                  => $log->action,
        };
    }

    /**
     * PUT /api/customers/{id} — edit the customer's own info (name/phone/
     * email/address). Admin only (route-group gated) — Manager's role stays
     * view-only per the original permission model; the notes exception below
     * is a separate, deliberate carve-out, not a general edit grant.
     */
    public function update(Request $request, string $id)
    {
        $customer = Customer::findOrFail($id);

        $data = $request->validate([
            'name'    => 'required|string|max:255',
            'phone'   => 'required|string|max:32|unique:customers,phone,' . $customer->id,
            'email'   => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
        ]);

        $old = $customer->only(array_keys($data));
        $customer->update($data);

        $this->salesAuditLogger->log('customer_updated', $customer, $old, $data, $customer->id);

        return response()->json([
            'message' => 'تم تحديث بيانات العميل بنجاح',
            'data'    => $customer->fresh(['shop:id,name', 'notesUpdatedBy:id,name']),
        ]);
    }

    /**
     * PUT /api/customers/{id}/notes — Admin/Manager only (route-group gated,
     * same as index()/reports above). Sellers still receive `notes` in their
     * show() payload (read-only) since they share that route's group.
     */
    public function updateNotes(Request $request, string $id)
    {
        $data = $request->validate(['notes' => 'nullable|string|max:5000']);

        $customer = Customer::findOrFail($id);
        $customer->update([
            'notes'            => $data['notes'] ?? null,
            'notes_updated_by' => auth()->id(),
            'notes_updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'تم حفظ الملاحظات بنجاح',
            'data'    => $customer->fresh(['notesUpdatedBy:id,name']),
        ]);
    }

    // ── Tags (Task 3) ──────────────────────────────────────────────────────

    /** POST /api/customers/{id}/tags { tag_id } — Admin/Manager only (route-gated). */
    public function attachTag(Request $request, string $id)
    {
        $customer = Customer::findOrFail($id);
        $this->authorizeView($customer);

        $data = $request->validate(['tag_id' => 'required|exists:tags,id']);
        $tag  = Tag::findOrFail($data['tag_id']);

        // Automatic tags are never persisted per-customer (see CustomerTagService) —
        // only 'manual' tags may be attached through this endpoint.
        abort_if($tag->type !== 'manual', 422, 'لا يمكن إضافة وسم تلقائي يدوياً — الوسوم التلقائية تُحسب تلقائياً فقط.');

        if (! $customer->tags()->where('tag_id', $tag->id)->exists()) {
            $customer->tags()->attach($tag->id);
            $this->salesAuditLogger->log('customer_tag_added', $customer, null, ['tag' => $tag->name], $customer->id);
        }

        return response()->json([
            'message' => 'تمت إضافة الوسم بنجاح',
            'data'    => $customer->tags,
        ]);
    }

    /** DELETE /api/customers/{id}/tags/{tagId} — Admin/Manager only (route-gated). */
    public function detachTag(string $id, string $tagId)
    {
        $customer = Customer::findOrFail($id);
        $this->authorizeView($customer);

        $tag = Tag::findOrFail($tagId);
        $customer->tags()->detach($tag->id);
        $this->salesAuditLogger->log('customer_tag_removed', $customer, null, ['tag' => $tag->name], $customer->id);

        return response()->json([
            'message' => 'تمت إزالة الوسم بنجاح',
            'data'    => $customer->tags()->get(),
        ]);
    }

    // ── Notes History (Task 6 — replaces the single-field editor for new UI) ──

    /** GET /api/customers/{id}/notes — full append-only history, oldest→newest. */
    public function notesHistory(string $id)
    {
        $customer = Customer::findOrFail($id);
        $this->authorizeView($customer);

        return response()->json([
            'message' => 'ok',
            'data'    => $customer->noteHistory()->with('author:id,name')->orderBy('created_at')->get(),
        ]);
    }

    /** POST /api/customers/{id}/notes-history — Admin/Manager/Seller (route-gated), append-only. */
    public function addNote(Request $request, string $id)
    {
        $customer = Customer::findOrFail($id);
        $this->authorizeView($customer);

        $data = $request->validate(['note' => 'required|string|max:5000']);

        $note = $customer->noteHistory()->create([
            'author_id' => auth()->id(),
            'note'      => $data['note'],
        ]);

        $this->salesAuditLogger->log(
            'customer_note_added',
            $note,
            null,
            ['excerpt' => Str::limit($note->note, 80)],
            $customer->id,
        );

        return response()->json([
            'message' => 'تمت إضافة الملاحظة بنجاح',
            'data'    => $note->fresh(['author:id,name']),
        ], 201);
    }

    /** PUT /api/customers/{id}/notes-history/{noteId} — only the author's own single latest note. */
    public function editNote(Request $request, string $id, string $noteId)
    {
        $customer = Customer::findOrFail($id);
        $this->authorizeView($customer);

        $note = CustomerNote::where('customer_id', $customer->id)->findOrFail($noteId);

        $latestOwn = CustomerNote::where('customer_id', $customer->id)
            ->where('author_id', auth()->id())
            ->latest('id')
            ->first();

        if (! $latestOwn || $latestOwn->id !== $note->id || $note->author_id !== auth()->id()) {
            abort(403, 'يمكنك تعديل آخر ملاحظة كتبتها أنت فقط.');
        }

        $data = $request->validate(['note' => 'required|string|max:5000']);
        $note->update(['note' => $data['note']]);

        return response()->json([
            'message' => 'تم تحديث الملاحظة بنجاح',
            'data'    => $note->fresh(['author:id,name']),
        ]);
    }

    /** DELETE /api/customers/{id}/notes-history/{noteId} — Admin only. */
    public function deleteNote(string $id, string $noteId)
    {
        $customer = Customer::findOrFail($id);
        $this->authorizeView($customer);

        abort_unless(auth()->user()->role === 'admin', 403, 'حذف الملاحظات متاح للمدير العام فقط.');

        $note = CustomerNote::where('customer_id', $customer->id)->findOrFail($noteId);
        $note->delete();

        return response()->json(['message' => 'تم حذف الملاحظة بنجاح']);
    }

    // ── Similar Customers (Task 4) ─────────────────────────────────────────

    /**
     * GET /api/customers/{id}/similar — rule-based: same branch + spending
     * within ±30% of this customer's total approved purchases. A separate,
     * lazy endpoint (not folded into show()) so the main profile load stays
     * fast — see Task 8 performance notes.
     */
    public function similar(string $id)
    {
        $customer = Customer::with('invoices')->findOrFail($id);
        $this->authorizeView($customer);

        $shopId = $customer->invoices()
            ->select('shop_id')
            ->selectRaw('COUNT(*) as cnt')
            ->groupBy('shop_id')
            ->orderByDesc('cnt')
            ->value('shop_id');

        $totalPurchases = (float) $customer->invoices()->where('status', 'approved')->sum('total_amount');

        if (! $shopId || $totalPurchases <= 0) {
            return response()->json(['message' => 'ok', 'data' => []]);
        }

        $min = $totalPurchases * 0.7;
        $max = $totalPurchases * 1.3;

        $similar = Customer::query()
            ->where('id', '!=', $customer->id)
            ->whereHas('invoices', fn ($q) => $q->where('shop_id', $shopId))
            ->withSum(['invoices as total_purchases' => fn ($q) => $q->where('status', 'approved')], 'total_amount')
            ->withMax(['invoices as last_purchase_date' => fn ($q) => $q->where('status', 'approved')], 'date')
            ->having('total_purchases', '>=', $min)
            ->having('total_purchases', '<=', $max)
            ->orderByRaw('ABS(total_purchases - ?)', [$totalPurchases])
            ->limit(5)
            ->get(['id', 'name', 'phone']);

        return response()->json([
            'message' => 'ok',
            'data'    => $similar->map(fn ($c) => [
                'id'                 => $c->id,
                'name'               => $c->name,
                'phone'              => $c->phone,
                'total_purchases'    => round((float) $c->total_purchases, 2),
                'last_purchase_date' => $c->last_purchase_date,
            ]),
        ]);
    }

    // ── Export (Task 5) ────────────────────────────────────────────────────

    /**
     * GET /api/customers/{id}/export?format=pdf|excel — Admin/Manager only
     * (route-gated). Flattens the whole profile (info, stats, favorite
     * products, purchase history, activity timeline, notes) into ONE
     * two-column table ('القسم'/'التفاصيل') so it can go through the exact
     * same ReportExportService::pdf()/excel() every other report in the ERP
     * already uses — no new export engine. Manager's version omits profit.
     */
    public function export(Request $request, string $id)
    {
        $customer = Customer::findOrFail($id);
        $this->authorizeView($customer);

        $payload = json_decode($this->show($id)->getContent(), true)['data'];
        $isAdmin = auth()->user()->role === 'admin';

        $columns = ['القسم', 'التفاصيل'];
        $rows = [];

        $rows[] = ['بيانات العميل', 'الاسم: ' . $payload['customer']['name']];
        $rows[] = ['بيانات العميل', 'الهاتف: ' . $payload['customer']['phone']];
        $rows[] = ['بيانات العميل', 'البريد الإلكتروني: ' . ($payload['customer']['email'] ?? '—')];
        $rows[] = ['بيانات العميل', 'العنوان: ' . ($payload['customer']['address'] ?? '—')];
        $rows[] = ['بيانات العميل', 'الفرع: ' . ($payload['customer']['shop']['name'] ?? '—')];

        foreach ($payload['stats'] as $key => $value) {
            $rows[] = ['الإحصائيات', "{$key}: " . ($value ?? '—')];
        }
        foreach ($payload['analytics'] as $key => $value) {
            if ($key === 'total_profit' && ! $isAdmin) {
                continue;
            }
            $rows[] = ['التحليلات', "{$key}: " . ($value ?? '—')];
        }

        foreach ($payload['most_purchased_products'] as $p) {
            $rows[] = ['المنتجات المفضّلة', "{$p['product_name']} — كمية: {$p['total_qty']} — إجمالي: {$p['total_revenue']} ج.م"];
        }

        foreach ($payload['invoices'] as $inv) {
            $rows[] = ['سجل المشتريات', "فاتورة #{$inv['id']} — {$inv['date']} — {$inv['status']} — {$inv['total_amount']} ج.م"];
        }

        foreach ($payload['activity'] as $entry) {
            $rows[] = ['سجل النشاط', "{$entry['date']} — {$entry['description']}"];
        }

        $notes = $customer->noteHistory()->with('author:id,name')->orderBy('created_at')->get();
        foreach ($notes as $note) {
            $rows[] = ['الملاحظات', "{$note->created_at} — {$note->author?->name}: {$note->note}"];
        }

        $exportService = app(\App\Services\Reports\ReportExportService::class);
        $title = 'ملف العميل - ' . $customer->name;

        return $request->input('format') === 'excel'
            ? $exportService->excel($title, $columns, $rows, ['رقم العميل' => $id])
            : $exportService->pdf($title, $columns, $rows, ['رقم العميل' => $id]);
    }

    /**
     * GET /api/customers/reports/new?date_from=&date_to= — customers created
     * within the range. Admin: all; Manager: own branch. A brand-new customer
     * may have zero invoices yet, so — unlike index()/inactiveCustomers() —
     * this can only scope by the customer's own registered shop_id (set at
     * creation time), not by invoice history.
     */
    public function newCustomers(Request $request)
    {
        $query = Customer::query();
        if ($request->user()->role === 'manager') {
            $query->where('shop_id', $request->user()->shop_id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $perPage = min($request->integer('per_page', 20), 100);

        return response()->json(['message' => 'ok', 'data' => $query->latest()->paginate($perPage)]);
    }

    /**
     * GET /api/customers/reports/inactive?days=30 — customers with no
     * approved purchase in the last {days} days (never-purchased customers
     * are included too). Admin: all; Manager: own branch.
     */
    public function inactiveCustomers(Request $request)
    {
        $days   = max(1, $request->integer('days', 30));
        $cutoff = now()->subDays($days)->toDateString();

        $query = Customer::query()
            ->withMax(['invoices as last_purchase_date' => fn ($q) => $q->where('status', 'approved')], 'date')
            ->withCount(['invoices as total_invoices' => fn ($q) => $q->where('status', 'approved')])
            ->whereDoesntHave('invoices', function ($q) use ($cutoff) {
                $q->where('status', 'approved')->whereDate('date', '>=', $cutoff);
            });

        $this->scopeToManagerViaInvoices($query, $request);

        $perPage = min($request->integer('per_page', 20), 100);

        return response()->json([
            'message' => 'ok',
            'data'    => ['days' => $days, 'customers' => $query->latest()->paginate($perPage)],
        ]);
    }

    /** Admin: always allowed. Manager: only a customer with an invoice in their branch. Seller: only a customer they personally sold to. */
    private function authorizeView(Customer $customer)
    {
        $user = auth()->user();

        if ($user->role === 'manager' && ! $customer->invoices()->where('shop_id', $user->shop_id)->exists()) {
            abort(403, 'غير مصرح لك بعرض بيانات هذا العميل.');
        }
        if ($user->role === 'sales' && ! $customer->invoices()->where('seller_id', $user->id)->exists()) {
            abort(403, 'غير مصرح لك بعرض بيانات هذا العميل.');
        }

        return $user;
    }
}
