<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;

/**
 * QA found a small, fixed set of obvious development/test products still
 * sitting in the catalog (junk names typed while testing the app, never
 * real inventory). This command NEVER deletes anything automatically — it
 * only ever runs when a human explicitly invokes it, is a dry-run (list
 * only) by default, and matches an exact, hand-reviewed name list rather
 * than a fuzzy heuristic that could accidentally catch a real product.
 */
class CleanupTestProducts extends Command
{
    protected $signature = 'products:cleanup-test-data
        {--delete : Actually delete the matched products (default is dry-run/list only)}
        {--force : Skip the confirmation prompt when deleting}';

    protected $description = 'List (or, with --delete, remove) known development/test products left over from QA';

    /** Exact, case-insensitive names confirmed as test/dev junk during QA — never a pattern/heuristic. */
    private const KNOWN_TEST_NAMES = [
        'hakiiiiim',
        'hakim',
        'ezzzzzzzzzzzzzzzz',
        'Ahmeddddddddddd',
    ];

    public function handle(): int
    {
        $matches = Product::whereIn('name', self::KNOWN_TEST_NAMES)->get(['id', 'name', 'sku', 'product_type']);

        if ($matches->isEmpty()) {
            $this->info('No known test products found — nothing to do.');
            return self::SUCCESS;
        }

        $this->info('── Matched test/dev products ─────────────────────');
        foreach ($matches as $p) {
            $this->line("  #{$p->id}  {$p->name}  ({$p->sku}, {$p->product_type})");
        }

        if (! $this->option('delete')) {
            $this->line('');
            $this->comment('Dry run — nothing was deleted. Re-run with --delete to remove these.');
            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Delete these ' . $matches->count() . ' product(s)? This cannot be undone.')) {
            $this->comment('Cancelled — nothing was deleted.');
            return self::SUCCESS;
        }

        $count = 0;
        foreach ($matches as $p) {
            $p->delete();
            $count++;
        }

        $this->info("Deleted {$count} test product(s).");
        return self::SUCCESS;
    }
}
