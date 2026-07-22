<?php

namespace App\Modules\BranchOperations\Services;

use App\Models\Goods;
use App\Models\Product;
use App\Models\User;
use App\Modules\BranchOperations\Models\TransferRequest;
use App\Modules\BranchOperations\Models\TransferRequestItem;
use App\Modules\BranchOperations\Models\TransferRequestItemBatch;
use App\Modules\BranchOperations\Models\InternalTransferInvoice;
use App\Modules\Convention\Services\NotificationService;
use App\Services\WarehouseResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owns the transfer-request workflow: draft -> submitted -> approved|rejected
 * -> preparing -> (shipped -> received -> closed are added in Part 6/4).
 * Every transition is validated against the current status and written to
 * transfer_request_logs. This service does NOT touch Goods.current_quantity —
 * that only happens at ship/receive time (Part 6), kept separate from this
 * approval workflow on purpose.
 */
class TransferRequestService
{
    /** @var array<string, string[]> Allowed status -> next-status transitions this service grants directly. */
    private const TRANSITIONS = [
        TransferRequest::STATUS_DRAFT => [TransferRequest::STATUS_SUBMITTED],
        TransferRequest::STATUS_SUBMITTED => [TransferRequest::STATUS_APPROVED, TransferRequest::STATUS_REJECTED],
        TransferRequest::STATUS_APPROVED => [TransferRequest::STATUS_PREPARING],
        TransferRequest::STATUS_PREPARING => [TransferRequest::STATUS_SHIPPED],
        TransferRequest::STATUS_SHIPPED => [TransferRequest::STATUS_RECEIVED],
        TransferRequest::STATUS_RECEIVED => [TransferRequest::STATUS_CLOSED],
    ];

    /** Phase 5.1 — admin override: which additional states may be force-cancelled (reuses the existing 'rejected' terminal status, no new state). */
    private const CANCELLABLE_FROM = [
        TransferRequest::STATUS_SUBMITTED, TransferRequest::STATUS_APPROVED, TransferRequest::STATUS_PREPARING,
    ];

    public function __construct(private NotificationService $notifications, private WarehouseResolver $warehouse) {}

    /**
     * Phase 5.4 — routes each event to the specific party who must act or
     * care next, instead of broadcasting every event to both shops' managers
     * every time. Admin always observes (per Part 5.1: "Admin only
     * observes"). Ownership decides who else hears about it, matching the
     * same "source shop owns the stock" rule Part 5.1 uses for authorization:
     *   submitted  -> source manager (must approve/reject) — admin if source is the warehouse
     *   approved/rejected/shipped -> destination manager (their request was actioned / is incoming)
     *   received   -> source manager (their stock arrived safely) — admin if source is the warehouse
     *   cancelled  -> both sides, since admin unilaterally ended it
     */
    private function recipientsForAction(TransferRequest $transfer, string $action): array
    {
        $adminIds = User::where('role', 'admin')->pluck('id')->all();
        $sourceManagerIds = User::where('role', 'manager')->where('shop_id', $transfer->source_shop_id)->pluck('id')->all();
        $destinationManagerIds = User::where('role', 'manager')->where('shop_id', $transfer->destination_shop_id)->pluck('id')->all();

        $roleIds = match ($action) {
            'submitted' => $sourceManagerIds,
            'approved', 'rejected', 'shipped' => $destinationManagerIds,
            'received' => $sourceManagerIds,
            'cancelled' => array_merge($sourceManagerIds, $destinationManagerIds),
            default => array_merge($sourceManagerIds, $destinationManagerIds),
        };

        return array_unique(array_merge($adminIds, $roleIds));
    }

    private function notify(TransferRequest $transfer, string $title, string $message, string $action, bool $silent = false): void
    {
        if ($silent) {
            return;
        }

        $this->notifications->notify($this->recipientsForAction($transfer, $action), 'transfer_request', $title, $message, [
            'transfer_request_id' => $transfer->id,
            'request_number' => $transfer->request_number,
            'status' => $transfer->status,
        ]);
    }

    public function availableStock(int $productId, int $shopId): float
    {
        return (float) Goods::whereHas('supplyItem', fn ($q) => $q->where('product_id', $productId))
            ->where('shop_id', $this->warehouse->goodsShopId($shopId))
            ->sum('current_quantity');
    }

    /**
     * @param array{source_shop_id:int, destination_shop_id:int, requested_date:string, priority:string, notes:?string,
     *              items: array<int, array{product_id:int, requested_quantity:float}>} $data
     */
    /**
     * ONE Transfer Engine, ONE state machine — this is the single entry point every
     * transfer is created through, regardless of who initiates it or why. What differs
     * is how far a given actor fast-forwards through the SAME states, decided entirely
     * by canApproveShop(): whoever already owns approval authority over the source shop
     * (the admin, always — he owns the Main Warehouse and never waits on anyone; or a
     * manager creating on behalf of their own shop) has their request auto-advance
     * through approve()/markPreparing()/ship() immediately, using those exact same
     * methods — never a parallel/duplicated path. Everyone else lands at "submitted"
     * and waits for the source shop's manager to act, exactly as before.
     */
    public function create(array $data, User $user, bool $submitImmediately = false, bool $notify = true): TransferRequest
    {
        // Part 5.8 — a branch manager can never impersonate another branch: they are
        // always the requester acting for THEIR OWN shop (User.shop_id), never a shop
        // they merely pick from a dropdown. This overrides whatever destination_shop_id
        // was submitted — the frontend may display it, but the server is the source of truth.
        if ($user->role === 'manager' && $user->shop_id) {
            $data['destination_shop_id'] = $user->shop_id;
        }

        if ((int) $data['source_shop_id'] === (int) $data['destination_shop_id']) {
            // Covers every disallowed combination structurally: Branch -> same Branch,
            // and Warehouse -> Warehouse (there is only ever one Main Warehouse shop,
            // so that case is already a source==destination match).
            throw ValidationException::withMessages(['destination_shop_id' => 'لا يمكن أن يكون فرع المصدر والوجهة نفس الفرع']);
        }

        if (empty($data['items'])) {
            throw ValidationException::withMessages(['items' => 'يجب إضافة صنف واحد على الأقل']);
        }

        return DB::transaction(function () use ($data, $user, $submitImmediately, $notify) {
            $status = $submitImmediately ? TransferRequest::STATUS_SUBMITTED : TransferRequest::STATUS_DRAFT;
            $sourceShopId = (int) $data['source_shop_id'];

            // Admin is the Main Warehouse's owner, not "another branch manager" — he never
            // waits for anyone's approval, for any transfer he creates. That status is what
            // "is_emergency" records: an owner-initiated transfer that skipped straight
            // through its own approval, not a manual flag the creator chooses.
            $willAutoAdvance = $submitImmediately && $this->canApproveShop($sourceShopId, $user);
            $isEmergency = $willAutoAdvance && $user->role === 'admin';

            $transfer = TransferRequest::create([
                'request_number' => 'TR-' . now()->format('Ymd') . '-000000',
                'source_shop_id' => $sourceShopId,
                'destination_shop_id' => $data['destination_shop_id'],
                'requested_by' => $user->id,
                'requested_date' => $data['requested_date'] ?? now()->toDateString(),
                'priority' => $isEmergency ? 'urgent' : ($data['priority'] ?? 'normal'),
                'status' => $status,
                'notes' => $data['notes'] ?? null,
                'is_emergency' => $isEmergency,
            ]);

            $transfer->update(['request_number' => 'TR-' . now()->format('Ymd') . '-' . str_pad((string) $transfer->id, 4, '0', STR_PAD_LEFT)]);

            foreach ($data['items'] as $itemData) {
                $this->addItem($transfer, $itemData['product_id'], (float) $itemData['requested_quantity']);
            }

            $this->log($transfer, $user, 'created', null, TransferRequest::STATUS_DRAFT);
            $transfer->load(['items.product', 'sourceShop', 'destinationShop', 'requestedByUser']);

            if ($submitImmediately) {
                $this->log($transfer, $user, 'submitted', TransferRequest::STATUS_DRAFT, TransferRequest::STATUS_SUBMITTED);

                if ($willAutoAdvance) {
                    // Same engine, later entry point — silent through the self-approval
                    // (approving your own request isn't a notification-worthy event), then
                    // the normal ship() notification tells the destination a shipment is on the way.
                    $transfer = $this->approve($transfer, $user, null, null, notify: false);
                    $transfer = $this->markPreparing($transfer, $user);
                    $transfer = $this->ship($transfer, $user);
                } else {
                    $this->notify($transfer, 'طلب نقل جديد بانتظار الموافقة', "طلب النقل {$transfer->request_number} من {$transfer->sourceShop->name} إلى {$transfer->destinationShop->name} بانتظار المراجعة", 'submitted', ! $notify);
                }
            }

            return $transfer;
        });
    }

    /**
     * Whoever already owns approval authority over a shop's OUTGOING side doesn't need
     * a separate manual approval click when they're also the one creating the request —
     * this is the single check shared by the controller's action guards (approve/reject/
     * prepare/ship) and by create()'s auto-advance decision, so "who can approve" is
     * defined in exactly one place.
     */
    public function canApproveShop(int $shopId, User $user): bool
    {
        if ($user->role === 'admin') {
            return true;
        }
        if ($this->warehouse->isWarehouse($shopId)) {
            return false;
        }

        return (int) $user->shop_id === $shopId;
    }

    private function addItem(TransferRequest $transfer, int $productId, float $quantity): TransferRequestItem
    {
        $product = Product::findOrFail($productId);

        if ($product->product_type === Product::TYPE_COMPOUND) {
            throw ValidationException::withMessages(['items' => "لا يمكن نقل التركيبات (المنتج: {$product->name}) — التركيبات لا تُنقل بين الفروع"]);
        }

        $available = $this->availableStock($productId, $transfer->source_shop_id);
        if ($quantity > $available) {
            throw ValidationException::withMessages(['items' => "الكمية المطلوبة من {$product->name} ({$quantity}) أكبر من المتاح في فرع المصدر ({$available})"]);
        }

        return TransferRequestItem::create([
            'transfer_request_id' => $transfer->id,
            'product_id' => $productId,
            'unit' => $product->scalar,
            'available_stock_at_request' => $available,
            'requested_quantity' => $quantity,
        ]);
    }

    public function submit(TransferRequest $transfer, User $user, bool $notify = true): TransferRequest
    {
        $this->assertTransition($transfer, TransferRequest::STATUS_SUBMITTED);

        if ($transfer->items()->count() === 0) {
            throw ValidationException::withMessages(['items' => 'لا يمكن إرسال طلب بدون أصناف']);
        }

        $previous = $transfer->status;
        $transfer->update(['status' => TransferRequest::STATUS_SUBMITTED]);
        $this->log($transfer, $user, 'submitted', $previous, TransferRequest::STATUS_SUBMITTED);
        $this->notify($transfer, 'طلب نقل جديد بانتظار الموافقة', "طلب النقل {$transfer->request_number} من {$transfer->sourceShop->name} إلى {$transfer->destinationShop->name} بانتظار المراجعة", 'submitted', ! $notify);

        return $transfer->fresh();
    }

    /**
     * @param array<int, array{item_id:int, approved_quantity:float}>|null $itemApprovals Source-branch
     * manager's per-item decision — omit an item (or the whole array) to approve it as requested.
     * Lets the manager who owns the stock partially approve or reduce a quantity before shipping,
     * without ever exceeding what was requested or what's actually available.
     */
    public function approve(TransferRequest $transfer, User $user, ?string $notes = null, ?array $itemApprovals = null, bool $notify = true): TransferRequest
    {
        $this->assertTransition($transfer, TransferRequest::STATUS_APPROVED);

        return DB::transaction(function () use ($transfer, $user, $notes, $itemApprovals, $notify) {
            if ($itemApprovals) {
                $byItemId = collect($itemApprovals)->keyBy('item_id');
                foreach ($transfer->items as $item) {
                    $decision = $byItemId->get($item->id);
                    if (! $decision) {
                        continue;
                    }
                    $approvedQty = (float) $decision['approved_quantity'];
                    if ($approvedQty > (float) $item->requested_quantity) {
                        throw ValidationException::withMessages(['items' => "الكمية المعتمدة لـ \"{$item->product->name}\" أكبر من الكمية المطلوبة"]);
                    }
                    $item->update(['approved_quantity' => $approvedQty]);
                }
            }

            $previous = $transfer->status;
            $transfer->update([
                'status' => TransferRequest::STATUS_APPROVED,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);
            $this->log($transfer, $user, 'approved', $previous, TransferRequest::STATUS_APPROVED, $notes);
            $this->generateInternalInvoice($transfer, $user);
            $this->notify($transfer, 'تمت الموافقة على طلب النقل', "تمت الموافقة على طلب النقل {$transfer->request_number}", 'approved', ! $notify);

            return $transfer->fresh();
        });
    }

    /** Part 4 — every approved transfer automatically gets an internal-only invoice. Never touches sales `invoices`. */
    private function generateInternalInvoice(TransferRequest $transfer, User $user): InternalTransferInvoice
    {
        $referenceValue = $transfer->items()
            ->with('product:id,purchase_cost')
            ->get()
            ->sum(fn ($item) => (float) $item->requested_quantity * (float) ($item->product->purchase_cost ?? 0));

        $invoice = InternalTransferInvoice::create([
            'invoice_number' => 'ITI-000000',
            'transfer_request_id' => $transfer->id,
            'source_shop_id' => $transfer->source_shop_id,
            'destination_shop_id' => $transfer->destination_shop_id,
            'date' => now()->toDateString(),
            'user_id' => $user->id,
            'reference_value' => round($referenceValue, 2),
            'status' => 'active',
        ]);

        $invoice->update(['invoice_number' => 'ITI-' . now()->format('Ymd') . '-' . str_pad((string) $invoice->id, 4, '0', STR_PAD_LEFT)]);

        return $invoice;
    }

    public function reject(TransferRequest $transfer, User $user, string $reason): TransferRequest
    {
        $this->assertTransition($transfer, TransferRequest::STATUS_REJECTED);

        $previous = $transfer->status;
        $transfer->update(['status' => TransferRequest::STATUS_REJECTED]);
        $this->log($transfer, $user, 'rejected', $previous, TransferRequest::STATUS_REJECTED, $reason);
        $this->notify($transfer, 'تم رفض طلب النقل', "تم رفض طلب النقل {$transfer->request_number}: {$reason}", 'rejected');

        return $transfer->fresh();
    }

    public function markPreparing(TransferRequest $transfer, User $user): TransferRequest
    {
        $this->assertTransition($transfer, TransferRequest::STATUS_PREPARING);

        $previous = $transfer->status;
        $transfer->update(['status' => TransferRequest::STATUS_PREPARING]);
        $this->log($transfer, $user, 'preparing', $previous, TransferRequest::STATUS_PREPARING);

        return $transfer->fresh();
    }

    /**
     * Ships the transfer — this is the ONLY point inventory leaves the source
     * shop. FIFO-deducts each item across the source shop's Goods batches
     * (identical ordering/locking to SalesService::processItem), recording
     * exactly which batches were drawn into transfer_request_item_batches so
     * receive() can credit the destination with the same supply_item_id
     * lineage. Inventory does NOT move to the destination yet — Part 6's
     * explicit rule is "inventory only increases after receiving."
     */
    public function ship(TransferRequest $transfer, User $user): TransferRequest
    {
        $this->assertTransition($transfer, TransferRequest::STATUS_SHIPPED);

        return DB::transaction(function () use ($transfer, $user) {
            foreach ($transfer->items as $item) {
                $needed = (float) ($item->approved_quantity ?? $item->requested_quantity);

                $batches = Goods::whereHas('supplyItem', fn ($q) => $q->where('product_id', $item->product_id))
                    ->where('shop_id', $this->warehouse->goodsShopId($transfer->source_shop_id))
                    ->where('current_quantity', '>', 0)
                    ->orderBy('date')->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $remaining = $needed;
                foreach ($batches as $goods) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $take = min((float) $goods->current_quantity, $remaining);
                    $goods->decrement('current_quantity', $take);

                    TransferRequestItemBatch::create([
                        'transfer_request_item_id' => $item->id,
                        'goods_id' => $goods->id,
                        'quantity_shipped' => $take,
                        // Explicit — see the same fix/comment on the log() helper below;
                        // the column's DB default runs on MySQL's clock, not Laravel's.
                        'created_at' => now(),
                    ]);

                    $remaining = round($remaining - $take, 3);
                }

                if ($remaining > 0) {
                    abort(422, "المخزون المتاح لم يعد كافياً لشحن \"{$item->product->name}\" — تحقق من المخزون الحالي في فرع المصدر");
                }
            }

            $previous = $transfer->status;
            $transfer->update(['status' => TransferRequest::STATUS_SHIPPED, 'shipped_at' => now()]);
            $this->log($transfer, $user, 'shipped', $previous, TransferRequest::STATUS_SHIPPED);
            $this->notify($transfer, 'تم شحن طلب النقل', "تم شحن طلب النقل {$transfer->request_number} — بانتظار الاستلام في {$transfer->destinationShop->name}", 'shipped');

            return $transfer->fresh();
        });
    }

    /**
     * Receives the transfer (fully or partially). This is the ONLY point
     * inventory increases at the destination shop — credited per source
     * batch (same supply_item_id) so FIFO ordering/cost lineage is preserved
     * exactly like TransferService::transfer() does for instant transfers.
     * Missing/damaged quantities are recorded but never added anywhere —
     * they represent real shrinkage in transit, already removed from the
     * source at ship time.
     *
     * @param array<int, array{item_id:int, received_quantity:float, missing_quantity?:float, damaged_quantity?:float, notes?:string}> $itemReceipts
     */
    public function receive(TransferRequest $transfer, User $user, array $itemReceipts, ?string $overallNotes = null): TransferRequest
    {
        $this->assertTransition($transfer, TransferRequest::STATUS_RECEIVED);

        return DB::transaction(function () use ($transfer, $user, $itemReceipts, $overallNotes) {
            $receiptsByItemId = collect($itemReceipts)->keyBy('item_id');

            foreach ($transfer->items as $item) {
                $receipt = $receiptsByItemId->get($item->id);
                $received = (float) ($receipt['received_quantity'] ?? 0);
                $missing = (float) ($receipt['missing_quantity'] ?? 0);
                $damaged = (float) ($receipt['damaged_quantity'] ?? 0);

                $shippedTotal = (float) $item->batches()->sum('quantity_shipped');
                if (round($received + $missing + $damaged, 3) > round($shippedTotal, 3)) {
                    abort(422, "مجموع الكمية المستلمة والمفقودة والتالفة لـ \"{$item->product->name}\" أكبر من الكمية المشحونة ({$shippedTotal})");
                }

                // Credit the destination shop from the same batches this item was shipped from, oldest first.
                $remainingToCredit = $received;
                foreach ($item->batches()->orderBy('id')->lockForUpdate()->get() as $batch) {
                    if ($remainingToCredit <= 0) {
                        break;
                    }
                    $creditable = min((float) $batch->quantity_shipped - (float) $batch->quantity_received, $remainingToCredit);
                    if ($creditable <= 0) {
                        continue;
                    }

                    $destinationGoodsShopId = $this->warehouse->goodsShopId($transfer->destination_shop_id);
                    $sourceGoods = $batch->goods;
                    $destination = Goods::where('supply_item_id', $sourceGoods->supply_item_id)
                        ->where('shop_id', $destinationGoodsShopId)
                        ->lockForUpdate()
                        ->first();

                    if ($destination) {
                        $destination->increment('current_quantity', $creditable);
                    } else {
                        Goods::create([
                            'supply_item_id' => $sourceGoods->supply_item_id,
                            'shop_id' => $destinationGoodsShopId,
                            'current_quantity' => $creditable,
                            'date' => now()->toDateString(),
                        ]);
                    }

                    $batch->increment('quantity_received', $creditable);
                    $remainingToCredit = round($remainingToCredit - $creditable, 3);
                }

                $item->update([
                    'received_quantity' => $received,
                    'missing_quantity' => $missing,
                    'damaged_quantity' => $damaged,
                    'receiving_notes' => $receipt['notes'] ?? null,
                ]);
            }

            $previous = $transfer->status;
            $transfer->update([
                'status' => TransferRequest::STATUS_RECEIVED,
                'received_at' => now(),
                'notes' => $overallNotes ? trim(($transfer->notes ?? '') . "\n" . $overallNotes) : $transfer->notes,
            ]);
            $this->log($transfer, $user, 'received', $previous, TransferRequest::STATUS_RECEIVED, $overallNotes);
            $this->notify($transfer, 'تم استلام طلب النقل', "تم استلام طلب النقل {$transfer->request_number} في {$transfer->destinationShop->name}", 'received');

            return $transfer->fresh();
        });
    }

    public function close(TransferRequest $transfer, User $user): TransferRequest
    {
        $this->assertTransition($transfer, TransferRequest::STATUS_CLOSED);

        $previous = $transfer->status;
        $transfer->update(['status' => TransferRequest::STATUS_CLOSED, 'closed_at' => now()]);
        $this->log($transfer, $user, 'closed', $previous, TransferRequest::STATUS_CLOSED);

        return $transfer->fresh();
    }

    /**
     * Admin-only override (Part 5.1) — cancels a transfer before it ships.
     * Reuses the existing REJECTED terminal status (no new state added),
     * just from more starting states than a normal source-manager rejection
     * (which is only allowed while still SUBMITTED). Once shipped, inventory
     * has already physically left the source — cancellation is no longer
     * offered past that point; the transfer must run its course to received/closed.
     */
    public function cancel(TransferRequest $transfer, User $admin, string $reason): TransferRequest
    {
        if (! in_array($transfer->status, self::CANCELLABLE_FROM, true)) {
            abort(422, "لا يمكن إلغاء طلب النقل في حالته الحالية \"{$transfer->status}\"");
        }

        $previous = $transfer->status;
        $transfer->update([
            'status' => TransferRequest::STATUS_REJECTED,
            'cancelled_at' => now(),
            'cancelled_by' => $admin->id,
        ]);
        $this->log($transfer, $admin, 'cancelled', $previous, TransferRequest::STATUS_REJECTED, $reason);
        $this->notify($transfer, 'تم إلغاء طلب النقل', "قام المدير العام بإلغاء طلب النقل {$transfer->request_number}: {$reason}", 'cancelled');

        return $transfer->fresh();
    }

    private function assertTransition(TransferRequest $transfer, string $to): void
    {
        $allowed = self::TRANSITIONS[$transfer->status] ?? [];
        if (! in_array($to, $allowed, true)) {
            abort(422, "لا يمكن الانتقال من الحالة \"{$transfer->status}\" إلى \"{$to}\"");
        }
    }

    private function log(TransferRequest $transfer, User $user, string $action, ?string $previous, ?string $new, ?string $notes = null): void
    {
        $transfer->logs()->create([
            'user_id' => $user->id,
            'action' => $action,
            'previous_status' => $previous,
            'new_status' => $new,
            'notes' => $notes,
            'ip_address' => request()?->ip(),
            // Explicit, matching every other timestamp in this feature (approved_at,
            // shipped_at, ...) — the column's DB-level CURRENT_TIMESTAMP default runs on
            // the MySQL server's clock, which can differ from Laravel's app.timezone and
            // silently skew every duration computed against it (confirmed: 3h drift here).
            'created_at' => now(),
        ]);
    }
}
