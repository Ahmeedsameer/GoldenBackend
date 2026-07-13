<?php

namespace App\Console\Commands;

use App\Modules\Hr\Services\TransferService;
use Illuminate\Console\Command;

/**
 * Date-driven transfer transitions. Run daily (scheduler) to flip
 * scheduled → active on start_date and active → completed after end_date.
 *
 * NOTE: correctness of the ACTIVE branch never depends on this command —
 * ActiveBranchService derives it from dates directly. This command only
 * materialises the status column, audit trail and notifications.
 */
class ProcessEmployeeTransfers extends Command
{
    protected $signature = 'hr:process-transfers';
    protected $description = 'Activate/complete employee transfers whose dates are due';

    public function handle(TransferService $transfers): int
    {
        $result = $transfers->processDue();
        $this->info("Transfers processed — activated: {$result['activated']}, completed: {$result['completed']}");

        return self::SUCCESS;
    }
}
