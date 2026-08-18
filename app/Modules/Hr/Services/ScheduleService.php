<?php

namespace App\Modules\Hr\Services;

use App\Jobs\SendScheduleNotificationJob;
use App\Models\EmployeeTransfer;
use App\Models\ScheduleEntry;
use App\Models\ShiftTemplate;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Weekly schedule — the visual planning grid (rows = employees, columns =
 * Sat→Fri). This is the PLAN, independent of Attendance (the actual record).
 *
 * "Transferred" cells are NEVER stored: they are computed live from
 * employee_transfers on every read, so the transfer record stays the single
 * source of truth and nothing can ever go out of sync. Manual edits on a
 * transferred date are rejected — the transfer itself must be edited instead.
 *
 * Employees are never notified until the Admin explicitly Publishes; draft
 * (unpublished) entries are invisible to the employee-facing endpoints.
 */
class ScheduleService
{
    public function __construct(private HrAuditLogger $audit) {}

    /**
     * The Sat→Fri week (inclusive) containing $anchor.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function weekBounds(?string $anchor = null): array
    {
        $date = $anchor ? Carbon::parse($anchor) : Carbon::today();
        $start = $date->copy()->startOfDay();
        while ((int) $start->dayOfWeek !== Carbon::SATURDAY) {
            $start->subDay();
        }
        return [$start, $start->copy()->addDays(6)];
    }

    /**
     * The schedulable-employee query — extracted verbatim from weekRoster()'s
     * original inline query so the roster grid and the Bulk Shift Assignment
     * employee picker share exactly one source of truth for "who can be
     * scheduled" (role IN manager,sales + shop/role/user/status filters).
     * `search` (name/email/phone) is a new, purely additive filter — every
     * existing caller (weekRoster, publishWeek, cancelWeek) never sets it, so
     * their behavior is byte-for-byte unchanged.
     */
    public function scopedEmployees(array $filters = []): \Illuminate\Support\Collection
    {
        return User::query()
            ->whereIn('role', ['manager', 'sales'])
            ->with('primaryBranch:id,name')
            ->when(! empty($filters['shop_id']), fn ($q) => $q->where('shop_id', (int) $filters['shop_id']))
            ->when(! empty($filters['role']), fn ($q) => $q->where('role', $filters['role']))
            ->when(! empty($filters['user_id']), fn ($q) => $q->where('id', (int) $filters['user_id']))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['search']), function ($q) use ($filters) {
                $term = $filters['search'];
                $q->where(fn ($qq) => $qq->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%"));
            })
            ->orderBy('name')
            ->get();
    }

    /**
     * Build the full weekly roster: one row per employee, one cell per day.
     *
     * @param  bool  $onlyPublished  Employee-facing views must never see drafts.
     */
    public function weekRoster(Carbon $from, Carbon $to, array $filters = [], bool $onlyPublished = false): array
    {
        $employees = $this->scopedEmployees($filters);

        $userIds = $employees->pluck('id')->all();
        $days    = $this->dateRange($from, $to);

        $entries = ScheduleEntry::whereIn('user_id', $userIds)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when($onlyPublished, fn ($q) => $q->where('is_published', true))
            ->with(['shiftTemplate:id,name,color,start_time,end_time', 'shop:id,name'])
            ->get()
            ->groupBy(fn ($e) => $e->user_id . '_' . $e->date->toDateString());

        $transfers = EmployeeTransfer::whereIn('user_id', $userIds)
            ->whereIn('status', EmployeeTransfer::EFFECTIVE_STATUSES)
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->with('temporaryBranch:id,name')
            ->get()
            ->groupBy('user_id');

        $rows = $employees->map(function (User $emp) use ($days, $entries, $transfers) {
            $empTransfers = $transfers->get($emp->id, collect());

            $cells = array_map(function (Carbon $day) use ($emp, $entries, $empTransfers) {
                $dateStr = $day->toDateString();

                $t = $empTransfers->first(fn ($tr) => $day->betweenIncluded($tr->start_date, $tr->end_date));
                if ($t) {
                    return [
                        'date' => $dateStr,
                        'type' => 'transferred',
                        'transfer' => [
                            'id'          => $t->id,
                            'branch_name' => $t->temporaryBranch?->name,
                            'start_date'  => $t->start_date->toDateString(),
                            'end_date'    => $t->end_date->toDateString(),
                        ],
                        'editable' => false,
                    ];
                }

                $entry = $entries->get($emp->id . '_' . $dateStr)?->first();
                if (! $entry) {
                    return ['date' => $dateStr, 'type' => null, 'editable' => true];
                }

                return [
                    'date'    => $dateStr,
                    'type'    => $entry->type,
                    'shift'   => $entry->shiftTemplate ? [
                        'id' => $entry->shiftTemplate->id, 'name' => $entry->shiftTemplate->name,
                        'color'      => $entry->shiftTemplate->color,
                        'start_time' => substr($entry->shiftTemplate->start_time, 0, 5),
                        'end_time'   => substr($entry->shiftTemplate->end_time, 0, 5),
                    ] : null,
                    'start_time'   => $entry->start_time ? substr($entry->start_time, 0, 5) : null,
                    'end_time'     => $entry->end_time ? substr($entry->end_time, 0, 5) : null,
                    'branch_name'  => $entry->shop?->name,
                    'notes'        => $entry->notes,
                    'is_published' => $entry->is_published,
                    'editable'     => true,
                ];
            }, $days);

            return [
                'user_id'       => $emp->id,
                'name'          => $emp->name,
                'role'          => $emp->role,
                'primary_branch'=> $emp->primaryBranch?->name,
                'status'        => $emp->status,
                'cells'         => $cells,
            ];
        });

        return [
            'from'      => $from->toDateString(),
            'to'        => $to->toDateString(),
            'days'      => array_map(fn (Carbon $d) => $d->toDateString(), $days),
            'employees' => $rows->values()->all(),
        ];
    }

    /** Set/replace a single cell. Rejected if the date is covered by a transfer. */
    public function upsertEntry(int $userId, string $date, array $data): ScheduleEntry
    {
        $this->assertNotTransferred($userId, $date);

        return DB::transaction(function () use ($userId, $date, $data) {
            $existing = ScheduleEntry::where('user_id', $userId)->where('date', $date)->first();
            $before   = $existing?->only(['type', 'shift_template_id', 'start_time', 'end_time', 'shop_id', 'notes']);

            $entry = ScheduleEntry::updateOrCreate(
                ['user_id' => $userId, 'date' => $date],
                [
                    'type'              => $data['type'],
                    'shift_template_id' => $data['shift_template_id'] ?? null,
                    'start_time'        => $data['start_time'] ?? null,
                    'end_time'          => $data['end_time'] ?? null,
                    'shop_id'           => $data['shop_id'] ?? null,
                    'notes'             => $data['notes'] ?? null,
                    'created_by'        => auth()->id(),
                ],
            );

            $this->audit->log('schedule.entry_saved', $entry, $before, $entry->only(['type', 'shift_template_id', 'start_time', 'end_time', 'shop_id', 'notes']));

            return $entry->load(['shiftTemplate', 'shop']);
        });
    }

    /**
     * Save the WHOLE week in a single request/transaction. Entries whose date
     * falls inside an active transfer are silently skipped (the transfer stays
     * authoritative) and reported back so the UI can inform the admin.
     *
     * @param  array<int, array{user_id:int, date:string, type:string, shift_template_id?:?int, start_time?:?string, end_time?:?string, shop_id?:?int, notes?:?string}>  $entries
     * @return array{saved:int, skipped:array<int, array{user_id:int,date:string}>}
     */
    public function bulkUpsert(array $entries): array
    {
        $saved   = 0;
        $skipped = [];
        $before  = [];
        $after   = [];

        DB::transaction(function () use ($entries, &$saved, &$skipped, &$before, &$after) {
            foreach ($entries as $row) {
                if ($this->isTransferred($row['user_id'], $row['date'])) {
                    $skipped[] = ['user_id' => $row['user_id'], 'date' => $row['date']];
                    continue;
                }

                $existing = ScheduleEntry::where('user_id', $row['user_id'])->where('date', $row['date'])->first();
                if ($existing) {
                    $before[] = ['user_id' => $row['user_id'], 'date' => $row['date'], ...$existing->only(['type', 'start_time', 'end_time'])];
                }

                $entry = ScheduleEntry::updateOrCreate(
                    ['user_id' => $row['user_id'], 'date' => $row['date']],
                    [
                        'type'              => $row['type'],
                        'shift_template_id' => $row['shift_template_id'] ?? null,
                        'start_time'        => $row['start_time'] ?? null,
                        'end_time'          => $row['end_time'] ?? null,
                        'shop_id'           => $row['shop_id'] ?? null,
                        'notes'             => $row['notes'] ?? null,
                        'created_by'        => auth()->id(),
                    ],
                );
                $after[] = ['user_id' => $row['user_id'], 'date' => $row['date'], 'type' => $entry->type];
                $saved++;
            }
        });

        if ($saved > 0) {
            $this->audit->log('schedule.bulk_saved', null, ['entries' => $before], ['entries' => $after, 'saved' => $saved, 'skipped' => count($skipped)]);
        }

        return ['saved' => $saved, 'skipped' => $skipped];
    }

    /**
     * Bulk Shift Assignment — conflict preview: which of the given employees
     * already have a schedule_entries row for this date, and what it currently
     * holds. Read-only, used by the Bulk Shift Assignment modal to show
     * "N موظفين لديهم شيفت مسجل بالفعل" BEFORE the admin/manager chooses
     * skip-vs-replace, so nothing is ever silently overwritten.
     *
     * @return array<int, array{user_id:int, name:string, date:string, type:string, shift_name:?string}>
     */
    public function findExistingEntriesForDate(array $userIds, string $from, ?string $to = null): array
    {
        $to ??= $from;

        return ScheduleEntry::whereIn('user_id', $userIds)
            ->whereBetween('date', [$from, $to])
            ->with(['user:id,name', 'shiftTemplate:id,name'])
            ->get()
            ->map(fn (ScheduleEntry $e) => [
                'user_id'    => $e->user_id,
                'name'       => $e->user?->name,
                'date'       => $e->date->toDateString(),
                'type'       => $e->type,
                'shift_name' => $e->shiftTemplate?->name,
            ])
            ->values()
            ->all();
    }

    /**
     * Bulk Shift Assignment — assign ONE shift template (type=work, times taken
     * from the template) to MANY employees across EVERY day in an inclusive
     * [from, to] date range (a single date is simply from === to), in one
     * atomic transaction. This is deliberately a SEPARATE method from
     * bulkUpsert(): bulkUpsert() always overwrites (it's "save my staged
     * per-cell edits", where the admin already saw and chose every value)
     * and has no skip-vs-replace concept, which this feature explicitly
     * requires (existing entries — including approved leave, holidays,
     * weekly-off, sick leave, or any other special status — are never
     * silently overwritten; see findExistingEntriesForDate() above).
     * Internally reuses the exact same transfer-conflict guard
     * (isTransferred) and updateOrCreate pattern as every other write path
     * in this service, so a bulk-assigned entry is indistinguishable from
     * one saved through the normal grid editor.
     *
     * @return array{assigned:int, skipped_existing:int[], skipped_transferred:int[]}
     */
    public function bulkAssignShift(array $userIds, ShiftTemplate $template, string $from, bool $replaceExisting, ?string $to = null): array
    {
        $to ??= $from;
        $dates = [];
        $cursor = Carbon::parse($from);
        $end = Carbon::parse($to);
        while ($cursor->lessThanOrEqualTo($end)) {
            $dates[] = $cursor->toDateString();
            $cursor->addDay();
        }

        $assigned = 0;
        $skippedExisting = [];
        $skippedTransferred = [];
        $before = [];
        $after = [];

        DB::transaction(function () use ($userIds, $template, $dates, $replaceExisting, &$assigned, &$skippedExisting, &$skippedTransferred, &$before, &$after) {
            foreach ($userIds as $userId) {
                foreach ($dates as $date) {
                    if ($this->isTransferred($userId, $date)) {
                        $skippedTransferred[] = ['user_id' => $userId, 'date' => $date];
                        continue;
                    }

                    $existing = ScheduleEntry::where('user_id', $userId)->where('date', $date)->first();
                    if ($existing && ! $replaceExisting) {
                        $skippedExisting[] = ['user_id' => $userId, 'date' => $date];
                        continue;
                    }
                    if ($existing) {
                        $before[] = ['user_id' => $userId, 'date' => $date, ...$existing->only(['type', 'shift_template_id', 'start_time', 'end_time'])];
                    }

                    ScheduleEntry::updateOrCreate(
                        ['user_id' => $userId, 'date' => $date],
                        [
                            'type'              => ScheduleEntry::WORK,
                            'shift_template_id' => $template->id,
                            'start_time'        => $template->start_time,
                            'end_time'          => $template->end_time,
                            'created_by'        => auth()->id(),
                        ],
                    );
                    $after[] = ['user_id' => $userId, 'date' => $date, 'shift_template_id' => $template->id];
                    $assigned++;
                }
            }
        });

        if ($assigned > 0) {
            $this->audit->log('schedule.bulk_assigned', null, ['entries' => $before], [
                'entries' => $after, 'assigned' => $assigned, 'shift_template_id' => $template->id,
                'date_from' => $dates[0] ?? null, 'date_to' => end($dates) ?: null,
                'skipped_existing' => count($skippedExisting), 'skipped_transferred' => count($skippedTransferred),
            ]);
        }

        return ['assigned' => $assigned, 'skipped_existing' => $skippedExisting, 'skipped_transferred' => $skippedTransferred];
    }

    /**
     * Publish every persisted entry for the given employees within the week and
     * notify each affected employee exactly once (queued — one job per employee,
     * never one HTTP request per employee).
     */
    public function publishWeek(Carbon $from, Carbon $to, array $filters = []): int
    {
        $userIds = User::query()
            ->whereIn('role', ['manager', 'sales'])
            ->when(! empty($filters['shop_id']), fn ($q) => $q->where('shop_id', (int) $filters['shop_id']))
            ->when(! empty($filters['user_id']), fn ($q) => $q->where('id', (int) $filters['user_id']))
            ->pluck('id');

        $entries = ScheduleEntry::whereIn('user_id', $userIds)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get();

        if ($entries->isEmpty()) {
            return 0;
        }

        $alreadyPublishedUserIds = $entries->where('is_published', true)->pluck('user_id')->unique();

        ScheduleEntry::whereIn('id', $entries->pluck('id'))->update(['is_published' => true, 'published_at' => now()]);

        foreach ($entries->pluck('user_id')->unique() as $userId) {
            $event = $alreadyPublishedUserIds->contains($userId) ? 'republished' : 'published';
            SendScheduleNotificationJob::dispatch($userId, $event, $from->toDateString(), $to->toDateString());
        }

        $this->audit->log('schedule.published', null, null, [
            'from' => $from->toDateString(), 'to' => $to->toDateString(), 'entries' => $entries->count(),
        ]);

        return $entries->count();
    }

    /** Unpublish (retract) a week's schedule and notify affected employees. */
    public function cancelWeek(Carbon $from, Carbon $to, array $filters = []): int
    {
        $userIds = User::query()
            ->whereIn('role', ['manager', 'sales'])
            ->when(! empty($filters['shop_id']), fn ($q) => $q->where('shop_id', (int) $filters['shop_id']))
            ->when(! empty($filters['user_id']), fn ($q) => $q->where('id', (int) $filters['user_id']))
            ->pluck('id');

        $entries = ScheduleEntry::whereIn('user_id', $userIds)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->where('is_published', true)
            ->get();

        if ($entries->isEmpty()) {
            return 0;
        }

        ScheduleEntry::whereIn('id', $entries->pluck('id'))->update(['is_published' => false, 'published_at' => null]);

        foreach ($entries->pluck('user_id')->unique() as $userId) {
            SendScheduleNotificationJob::dispatch($userId, 'cancelled', $from->toDateString(), $to->toDateString());
        }

        $this->audit->log('schedule.cancelled', null, null, [
            'from' => $from->toDateString(), 'to' => $to->toDateString(), 'entries' => $entries->count(),
        ]);

        return $entries->count();
    }

    /**
     * Automatically stamp every day of an approved leave as 'leave' on the
     * schedule — published immediately (leave-driven changes must be visible
     * to the employee right away, unlike normal draft edits). Days covered by
     * an active transfer are skipped (the transfer stays authoritative).
     *
     * @return array{saved:int, skipped:array<int, array{user_id:int,date:string}>}
     */
    public function applyLeaveRange(int $userId, Carbon $from, Carbon $to): array
    {
        return $this->stampRange($userId, $from, $to, ScheduleEntry::LEAVE, 'leave.schedule_applied');
    }

    /**
     * Early leave termination: revert the now-unused tail of the leave range
     * back to a plain 'work' day (no predefined shift — manual hours are the
     * default everywhere else, so the admin/manager assigns real hours after).
     *
     * @return array{saved:int, skipped:array<int, array{user_id:int,date:string}>}
     */
    public function restoreWorkRange(int $userId, Carbon $from, Carbon $to): array
    {
        return $this->stampRange($userId, $from, $to, ScheduleEntry::WORK, 'leave.schedule_restored');
    }

    private function stampRange(int $userId, Carbon $from, Carbon $to, string $type, string $auditAction): array
    {
        $saved   = 0;
        $skipped = [];

        DB::transaction(function () use ($userId, $from, $to, $type, &$saved, &$skipped) {
            foreach ($this->dateRange($from, $to) as $day) {
                $date = $day->toDateString();
                if ($this->isTransferred($userId, $date)) {
                    $skipped[] = ['user_id' => $userId, 'date' => $date];
                    continue;
                }

                ScheduleEntry::updateOrCreate(
                    ['user_id' => $userId, 'date' => $date],
                    [
                        'type'              => $type,
                        'shift_template_id' => null,
                        'start_time'        => null,
                        'end_time'          => null,
                        'is_published'      => true,
                        'published_at'      => now(),
                        'created_by'        => auth()->id(),
                    ],
                );
                $saved++;
            }
        });

        if ($saved > 0) {
            $this->audit->log($auditAction, null, null, [
                'user_id' => $userId, 'from' => $from->toDateString(), 'to' => $to->toDateString(),
                'type' => $type, 'saved' => $saved, 'skipped' => count($skipped),
            ]);
        }

        return ['saved' => $saved, 'skipped' => $skipped];
    }

    private function isTransferred(int $userId, string $date): bool
    {
        return EmployeeTransfer::where('user_id', $userId)
            ->whereIn('status', EmployeeTransfer::EFFECTIVE_STATUSES)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->exists();
    }

    private function assertNotTransferred(int $userId, string $date): void
    {
        if ($this->isTransferred($userId, $date)) {
            throw ValidationException::withMessages([
                'date' => 'هذا اليوم ضمن فترة نقل مؤقت — عدّل سجل النقل بدلاً من الجدول.',
            ]);
        }
    }

    /** @return Carbon[] */
    private function dateRange(Carbon $from, Carbon $to): array
    {
        $days = [];
        $cursor = $from->copy();
        while ($cursor->lessThanOrEqualTo($to)) {
            $days[] = $cursor->copy();
            $cursor->addDay();
        }
        return $days;
    }

    // ── Shift template management ───────────────────────────────────────────
    // Every schedule_entries row snapshots its OWN start_time/end_time at save
    // time (see upsertEntry/bulkUpsert) — a template is only ever a shortcut
    // that prefills those columns client-side. Editing a template therefore
    // never touches historical data unless explicitly re-synced here, and even
    // then a PUBLISHED PAST entry (date < today AND is_published) is the one
    // true historical record and is NEVER touched by any scope.

    public const EDIT_SCOPE_FUTURE      = 'future';      // future dates, still-draft entries only (recommended)
    public const EDIT_SCOPE_UNPUBLISHED = 'unpublished';  // every draft entry, any date
    public const EDIT_SCOPE_ALL         = 'all';          // every entry except published-past

    /**
     * Update a Shift Template's own fields, then optionally re-sync the frozen
     * start_time/end_time of schedule_entries still linked to it, per the
     * admin-chosen propagation scope. Past PUBLISHED entries are always excluded.
     */
    public function updateShiftTemplate(ShiftTemplate $template, array $data, string $scope = self::EDIT_SCOPE_FUTURE): ShiftTemplate
    {
        return DB::transaction(function () use ($template, $data, $scope) {
            $before = $template->only(['name', 'color', 'start_time', 'end_time', 'description', 'is_active']);
            $template->update($data);

            $timeChanged = array_key_exists('start_time', $data) || array_key_exists('end_time', $data);
            $updated = 0;

            if ($timeChanged) {
                $today = Carbon::today()->toDateString();
                $query = ScheduleEntry::where('shift_template_id', $template->id);

                $query = match ($scope) {
                    self::EDIT_SCOPE_FUTURE      => $query->where('date', '>=', $today)->where('is_published', false),
                    self::EDIT_SCOPE_UNPUBLISHED => $query->where('is_published', false),
                    self::EDIT_SCOPE_ALL         => $query->where(fn ($q) => $q->where('is_published', false)->orWhere('date', '>=', $today)),
                    default                       => $query->whereRaw('1 = 0'),
                };

                $updated = $query->update(['start_time' => $template->start_time, 'end_time' => $template->end_time]);
            }

            $this->audit->log('shift_template.updated', $template, $before,
                array_merge($template->only(['name', 'color', 'start_time', 'end_time', 'description', 'is_active']),
                    ['scope' => $scope, 'entries_resynced' => $updated]));

            return $template;
        });
    }

    /** Disable (archive) — hides it from the picker for NEW assignments but never touches entries already using it. */
    public function archiveShiftTemplate(ShiftTemplate $template): ShiftTemplate
    {
        $template->update(['is_active' => false]);
        $this->audit->log('shift_template.archived', $template, ['is_active' => true], ['is_active' => false]);
        return $template;
    }

    public function restoreShiftTemplate(ShiftTemplate $template): ShiftTemplate
    {
        $template->update(['is_active' => true]);
        $this->audit->log('shift_template.restored', $template, ['is_active' => false], ['is_active' => true]);
        return $template;
    }

    /** Hard delete — only allowed when nothing scheduled from today onward still references it. */
    public function deleteShiftTemplate(ShiftTemplate $template): void
    {
        $today = Carbon::today()->toDateString();
        $usedInFuture = ScheduleEntry::where('shift_template_id', $template->id)->where('date', '>=', $today)->exists();

        if ($usedInFuture) {
            throw ValidationException::withMessages([
                'template' => 'لا يمكن حذف هذا الشيفت لأنه مستخدم في جداول مستقبلية — يمكنك تعطيله (أرشفته) بدلاً من ذلك.',
            ]);
        }

        $this->audit->log('shift_template.deleted', $template,
            $template->only(['name', 'color', 'start_time', 'end_time']), null);
        $template->delete(); // nullOnDelete on schedule_entries.shift_template_id — past entries keep their own frozen times
    }
}
