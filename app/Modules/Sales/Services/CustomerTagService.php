<?php

namespace App\Modules\Sales\Services;

use App\Models\Customer;
use App\Models\Tag;
use Illuminate\Support\Collection;

/**
 * Automatic Customer Tags — pure rule evaluation over data
 * CustomerController::show() has already computed (stats/analytics/the
 * approved-invoices collection). Zero new queries here; auto tags are never
 * persisted per-customer (see the tags migration comment) so they can never
 * go stale — every show() call re-evaluates them live.
 *
 * Definitions are seeded once (see 2026_08_07_000002_create_tags_table
 * migration) and looked up here by slug, so the badge color/name stays a
 * single source of truth in the database rather than duplicated in code.
 */
class CustomerTagService
{
    /** @param Collection $invoices approved invoices for this customer (already loaded by show()) */
    public function computeAutoTags(Customer $customer, array $stats, array $analytics, Collection $invoices): Collection
    {
        $slugs = [];

        $daysSinceLastPurchase = $stats['days_since_last_purchase'] ?? null;
        $totalPurchases        = (float) ($stats['total_purchases'] ?? 0);
        $totalInvoices         = (int) ($stats['total_invoices'] ?? 0);
        $avgDaysBetween        = $analytics['avg_days_between_purchases'] ?? null;
        $customerSince         = $customer->created_at;

        if ($customerSince && $customerSince->diffInDays(now()) <= 30) {
            $slugs[] = 'new-customer';
        }

        if ($daysSinceLastPurchase !== null && $daysSinceLastPurchase > 90) {
            $slugs[] = 'inactive';
        }

        if ($totalPurchases >= 20000) {
            $slugs[] = 'vip';
        } elseif ($totalPurchases >= 5000) {
            $slugs[] = 'high-spender';
        }

        if ($totalInvoices >= 5 && ! in_array('vip', $slugs, true)) {
            $slugs[] = 'regular-customer';
        }

        if ($avgDaysBetween !== null && $avgDaysBetween < 7) {
            $slugs[] = 'frequently-buying';
        }

        if ($invoices->isNotEmpty()) {
            $wholesaleShare = $invoices->where('price_type', 'wholesale')->count() / $invoices->count();
            $slugs[] = $wholesaleShare >= 0.5 ? 'wholesale' : 'retail';
        }

        if (empty($slugs)) {
            return collect();
        }

        return Tag::where('type', 'auto')->whereIn('slug', $slugs)->get();
    }
}
