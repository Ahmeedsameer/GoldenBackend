# Batch Pricing & Immutable Accounting Architecture

This document explains how selling price, cost, and profit work for
inventory-based products (Ready Products, Packaging, and any future
inventory item priced per batch — see `Product::isBatchPriced()`). It exists
so a future developer can understand *why* the code is shaped this way
before changing it.

## 1. The core problem this solves

A product is never sold at one single price forever. Every time it's
purchased, that purchase (a `Supply` → `SupplyItem` row) may cost a
different amount, and the manager may want to charge a different selling
price for that specific batch. FIFO (`SalesService::fifoBatchesQuery()`)
determines which physical units get sold first — so a single sale can
legitimately draw from two batches with two different costs and two
different prices at once.

The old model stored one flat `selling_price` on `Product`. That's
incompatible with FIFO once purchase cost varies between batches: it can't
express "the first 18 units sold at 150, the next 2 at 190."

## 2. Where prices live: the batch is the unit of pricing

`SupplyItem` **is** the batch. Every batch carries its own:

- `unit_price` — purchase cost (set at supply time, in `Supply`/`SupplyItem`)
- `selling_price` — set **once**, by an admin/manager, in Pricing Management
  (`PricingService::priceBatch()`). Never null → the batch is unsellable
  until priced.
- `priced_at` / `priced_by` — when and who priced it.
- `archived_at` — see §7, Batch Deletion Protection.

A batch's `selling_price` can only be set once. There is no "edit batch
price" endpoint — only `priceBatch()` for a still-`null` price. This is
enforced in code (`PricingService::priceBatch()` aborts 422 if already
priced) — it's not just a UI convention.

## 3. Automatic status flow (Pricing Management)

Computed per-product in `PricingService::batchStatusFor()`:

```
Product Created
      │
      ▼
Waiting For First Supply   (no SupplyItem rows exist yet)
      │  first supply arrives
      ▼
Needs Initial Pricing      (has batches, none priced yet)
      │  admin prices the batch
      ▼
Priced                     (every batch is priced)
      │  a new supply arrives
      ▼
Pricing Update Required    (at least one unpriced batch exists
      │                     alongside already-priced ones)
      │  admin prices the new batch
      ▼
Priced (again)
```

`Inactive` overrides all of the above when `Product.is_active` is false.

## 4. FIFO selling — the cashier never picks a price

`SalesService::fifoBatchesQuery($productId, $shopId, $requirePriced)`:

- Orders `Goods` (physical stock per shop) oldest-first by supply date.
- When `$requirePriced` is true (batch-priced products), it **excludes any
  batch whose `selling_price` is null** — an unpriced arrival is
  structurally invisible to FIFO. Older, already-priced stock keeps selling
  uninterrupted; the business never stops because of an unpriced arrival.
- Also excludes archived batches (see §7) unconditionally.

`SalesService::processItem()` walks this query and, for each batch it
drains, sets the `InvoiceItem.price` to **that batch's own `selling_price`**
— never a client-submitted price, never a flat product price. If a sale
spans a price boundary (18 units left in Batch #1 at 150, then 2 units from
Batch #2 at 190), it produces two separate `InvoiceItem` rows automatically.

`SalesService::priceInvoiceItems()` (called before the invoice is created,
to validate payment totals) runs a read-only "dry run" of the same FIFO walk
(`quoteBatchPriceSplit()`) so the invoice total agrees with what
`processItem()` will actually persist, even across a price boundary.

## 5. Snapshot flow — `InvoiceItem` is a permanent accounting record

Every `InvoiceItem` row stores, **written once at sale time and never
recalculated**:

| Column | Meaning |
|---|---|
| `supply_item_id` | The exact batch this line was drawn from (the "Batch ID") |
| `goods_id` | The specific shop-stock row drained (redundant with `supply_item_id` but kept for stock-reversal on cancel) |
| `quantity` | Units taken from that batch |
| `price` | The batch's `selling_price` at the moment of sale |
| `unit_cost` | The batch's `unit_price` (purchase cost) at the moment of sale |
| `line_cost` | `unit_cost * quantity`, precomputed |
| `line_profit` | `(price * quantity) - line_cost`, precomputed |

`InvoiceItem::getLineCostAttribute()`/`getLineProfitAttribute()` prefer the
stored column and only fall back to a live derivation for legacy rows that
predate these columns (a one-time backfill migration already populated every
existing row, so this fallback is effectively dead code kept only for
safety). **No code path in this system reads these back from `Product` or
recomputes them from current data.**

`Invoice::total_cost` / `gross_profit` / `net_profit` are simple aggregates
over the items' own stored `line_cost`/`line_profit` — never derived from
`Product.purchase_cost` or `Product.selling_price`.

## 6. Profit calculation, step by step

Given: Batch #1 (cost 10, sell 30), Batch #2 (cost 20, sell 40). Customer
buys 10 units, FIFO drains 5 from each.

```
InvoiceItem #1: batch=486, qty=5, unit_cost=10, price=30
  line_cost   = 10 * 5  = 50
  line_profit = (30*5) - 50 = 100

InvoiceItem #2: batch=487, qty=5, unit_cost=20, price=40
  line_cost   = 20 * 5  = 100
  line_profit = (40*5) - 100 = 100

Invoice.total_cost   = 50 + 100 = 150
Invoice.gross_profit = (30*5 + 40*5) - 150 = 350 - 150 = 200
```

Profit is **never averaged across batches** and **never merged into one
line** — each consumed batch produces its own `InvoiceItem` with its own
independent cost/price/profit.

## 7. Batch Deletion Protection

A batch that has ever appeared on an invoice can **never be physically
deleted**. Two independent layers enforce this:

1. **Database**: `invoice_items.supply_item_id` has an `ON DELETE RESTRICT`
   foreign key to `supply_items`. MySQL itself refuses the delete, even if
   application code is bypassed entirely (raw query, different codebase,
   direct DB access).
2. **Application**: `SupplyService::delete()` explicitly checks
   `whereHas('invoiceItems')` on the supply's items and aborts with a clear
   Arabic message before even reaching the database.

The only supported way to retire a batch from future sale is **archiving**
(`SupplyItem.archived_at`, set via `PricingService::archiveBatch()`):
- Archived batches are excluded from `fifoBatchesQuery()` — never sold from
  again, regardless of remaining quantity.
- They remain fully visible in Pricing Management's batch history
  (`status: 'archived'`) and on every past invoice, forever.
- Archiving is one-way (no "un-archive") — matching the same "past state is
  never silently walked back" philosophy as pricing itself.

## 8. What "historical integrity" actually guarantees

Verified by direct testing (see the validation scenarios below): after an
invoice exists, **none** of the following changes anything about that
invoice — neither its financial snapshot (`price`/`unit_cost`/`line_cost`/
`line_profit`, `Invoice.gross_profit`) nor its display identity
(`product_name`/`product_sku`/`product_barcode`):

- The product being renamed.
- The product's SKU or barcode being changed.
- The product being deactivated (`is_active = false`).
- The batch being archived.
- A different, later batch being priced differently.
- The product being deleted — which is itself impossible once sold:
  `invoice_items.product_id` has an `ON DELETE RESTRICT` foreign key, the
  same protection pattern as batch deletion (§7). A sold product can never
  be hard-deleted; `is_active = false` is the only supported way to retire
  it, and old invoices are provably unaffected either way (confirmed by
  loading an `InvoiceItem` without ever touching its `product` relation and
  getting a fully correct render from stored columns alone).

`InvoiceItem.product_name` / `product_sku` / `product_barcode` are written
once, at sale time, in `SalesService::processItem()`, from whatever the
product's identity was at that exact moment — exactly mirroring how the
financial columns are frozen. Every invoice display surface (seller invoice
detail, cashier receipt, admin/manager invoice lists, `invoice-display.util.ts`)
reads `item.product_name`, never `item.product.name`. There is no remaining
path in this system where an old invoice's displayed identity depends on the
current `Product` row.

**Closed**: the *composed/compound* sale display (an oil+bottle assembled
under a catalog "parent" product) previously read that parent's name live
via the `parent_product` relation, with no snapshot. `InvoiceItem` now also
carries `parent_product_name`, frozen at sale time in the same place as
everything else (`SalesService::processItem()`), so a composed sale's group
header is fully immutable too, matching every component line under it.

**Also audited and fixed**: three aggregate historical reports
(`ReportsController::topProducts`, `AdminBranchComparisonController`'s
top-oil/top-bottle, `AdminSalesReportController::byProduct`) grouped and
labeled by the live `products.name`/`sku` instead of `invoice_items`' own
frozen snapshot. Beyond just a stale label, grouping by the live name/sku is
a real correctness bug: renaming a product mid-reporting-period would split
its historical totals into two separate rows (old name, new name) and could
even change which product wins a "top product" ranking. All three now group
strictly by `product_id` (the stable identity) and resolve the display
name/sku from the most recent `invoice_items` snapshot within the reported
period — verified live: renaming a top-selling product and re-running the
report still showed the original name for that period's totals.

## 9. Refunds/returns — architecture readiness

There is no dedicated "partial return" feature in this codebase today (only
whole-invoice cancellation, `SalesService::cancel()`, which already reverses
stock via `Goods::increment()` per `goods_id` and reverses money via the
stored `InvoicePayment` rows — not live product prices).

If a partial-return feature is built later, it should:
- Look up the specific `InvoiceItem` row(s) being returned.
- Reverse exactly `line_cost`/`line_profit`/`quantity` **as stored on that
  row** — never recompute from the product's or batch's current price.
- Increment `Goods.current_quantity` back onto the exact `goods_id`/
  `supply_item_id` the line recorded (the batch it originally came from),
  even if that batch is now archived or otherwise not orderable — receiving
  stock back into an archived batch is a data-integrity operation, not a new
  sale, and must not be blocked by the "no sale from archived batches" rule.

Nothing further needs to change in the schema to support this — the
snapshot columns already contain everything a correct reversal needs.

## 10. Why product-level prices are no longer used for accounting

`Product.selling_price` / `Product.purchase_cost` (and `price_per_gram`,
`default_selling_price` for oils/compounds) still exist and are still
legitimate for:
- Configuring what a **future** sale should charge (Pricing Management).
- Valuing **current** inventory on hand (stock intelligence / inventory
  valuation reports — "what is this shelf worth right now").

They are **never** used to compute or display profit for a sale that
already happened. That would silently rewrite history every time a price
changes — exactly what this architecture exists to prevent. A full backend
audit (grepping every controller/service for `purchase_cost`,
`selling_price`, `profit`, `margin`, `cost`, `revenue`) confirmed only two
reports were still doing this (`AdminMonthlyProfitController`,
`AdminBranchComparisonController`) — both now aggregate
`SUM(invoice_items.line_cost)` / `SUM(invoice_items.quantity * invoice_items.price)`
instead.
