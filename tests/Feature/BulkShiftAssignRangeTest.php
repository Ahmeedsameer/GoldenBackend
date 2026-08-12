<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Models\ScheduleEntry;
use App\Models\ShiftTemplate;
use App\Models\Shop;
use App\Models\User;
use App\Modules\Hr\Services\LeaveService;
use App\Modules\Hr\Services\ScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Real-MySQL verification of the date-range Bulk Shift Assignment extension
 * — the SAME existing endpoints/service (ScheduleService::bulkAssignShift /
 * findExistingEntriesForDate, POST/GET /hr/schedule/bulk-assign*), no new
 * API, backward compatible with the original single-date form.
 */
class BulkShiftAssignRangeTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;
    private User $admin;
    private ShiftTemplate $morning;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create(['name' => 'QA Shop', 'branch_bonus_percent' => 5, 'status' => 'active', 'address' => 'x']);
        $this->admin = User::create([
            'name' => 'QA Admin', 'email' => 'qa_admin_' . uniqid() . '@test.local',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'active',
        ]);
        $this->morning = ShiftTemplate::create(['name' => 'الشيفت الصباحي', 'start_time' => '09:00', 'end_time' => '17:00', 'is_active' => true]);
        $this->actingAs($this->admin, 'api');
    }

    private function makeEmployee(): User
    {
        return User::create([
            'name' => 'QA Employee ' . uniqid(), 'email' => 'qa_emp_' . uniqid() . '@test.local',
            'password' => bcrypt('x'), 'role' => 'sales', 'status' => 'active', 'shop_id' => $this->shop->id,
        ]);
    }

    // ── Backward compatibility: single-day (no date_to) still works ────────

    public function test_single_day_assignment_without_date_to_still_works(): void
    {
        $employee = $this->makeEmployee();

        $response = $this->postJson('/api/hr/schedule/bulk-assign', [
            'employee_ids' => [$employee->id], 'shift_id' => $this->morning->id, 'date' => '2026-08-15',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('schedule_entries', [
            'user_id' => $employee->id, 'date' => '2026-08-15', 'type' => ScheduleEntry::WORK, 'shift_template_id' => $this->morning->id,
        ]);
        $this->assertEquals(1, ScheduleEntry::where('user_id', $employee->id)->count());
    }

    // ── Multi-day range assigns every day inclusively ───────────────────────

    public function test_multi_day_range_assigns_the_shift_for_every_day_inclusive(): void
    {
        $employee = $this->makeEmployee();

        $response = $this->postJson('/api/hr/schedule/bulk-assign', [
            'employee_ids' => [$employee->id], 'shift_id' => $this->morning->id,
            'date' => '2026-08-15', 'date_to' => '2026-08-20',
        ]);

        $response->assertOk();
        $entries = ScheduleEntry::where('user_id', $employee->id)->orderBy('date')->get();
        $this->assertCount(6, $entries, '15→20 August inclusive = 6 days.');
        $this->assertEquals('2026-08-15', $entries->first()->date->toDateString());
        $this->assertEquals('2026-08-20', $entries->last()->date->toDateString());
        foreach ($entries as $e) {
            $this->assertEquals(ScheduleEntry::WORK, $e->type);
            $this->assertEquals($this->morning->id, $e->shift_template_id);
        }
    }

    public function test_multiple_selected_employees_each_receive_the_shift_for_every_day_in_range(): void
    {
        $ahmed = $this->makeEmployee();
        $saif = $this->makeEmployee();
        $kareem = $this->makeEmployee();

        $response = $this->postJson('/api/hr/schedule/bulk-assign', [
            'employee_ids' => [$ahmed->id, $saif->id, $kareem->id], 'shift_id' => $this->morning->id,
            'date' => '2026-08-15', 'date_to' => '2026-08-20',
        ]);

        $response->assertOk();
        foreach ([$ahmed, $saif, $kareem] as $emp) {
            $this->assertEquals(6, ScheduleEntry::where('user_id', $emp->id)->count(), "Employee {$emp->id} must get all 6 days.");
        }
        $this->assertEquals(18, ScheduleEntry::where('shift_template_id', $this->morning->id)->count());
    }

    // ── Conflict/safety: never blindly overwrite existing entries ──────────

    public function test_conflicts_endpoint_reports_every_conflicting_day_across_the_range(): void
    {
        $employee = $this->makeEmployee();
        ScheduleEntry::create(['user_id' => $employee->id, 'date' => '2026-08-16', 'type' => ScheduleEntry::HOLIDAY, 'is_published' => true, 'created_by' => $this->admin->id]);
        ScheduleEntry::create(['user_id' => $employee->id, 'date' => '2026-08-18', 'type' => ScheduleEntry::OFF_DAY, 'is_published' => true, 'created_by' => $this->admin->id]);

        $response = $this->getJson('/api/hr/schedule/bulk-assign/conflicts?' . http_build_query([
            'employee_ids' => [$employee->id], 'date' => '2026-08-15', 'date_to' => '2026-08-20',
        ]));

        $response->assertOk();
        $this->assertEquals(2, $response->json('data.count'));
        $dates = collect($response->json('data.conflicts'))->pluck('date')->sort()->values()->all();
        $this->assertEquals(['2026-08-16', '2026-08-18'], $dates);
    }

    public function test_range_assignment_skips_days_with_approved_leave_by_default(): void
    {
        $employee = $this->makeEmployee();
        $leave = LeaveRequest::create([
            'user_id' => $employee->id, 'start_date' => '2026-08-17', 'end_date' => '2026-08-17',
            'days' => 1, 'type' => 'annual', 'status' => LeaveRequest::PENDING,
        ]);
        app(LeaveService::class)->approve($leave); // stamps a ScheduleEntry(type=leave) for 2026-08-17

        $response = $this->postJson('/api/hr/schedule/bulk-assign', [
            'employee_ids' => [$employee->id], 'shift_id' => $this->morning->id,
            'date' => '2026-08-15', 'date_to' => '2026-08-20', 'replace_existing' => false,
        ]);

        $response->assertOk();
        $leaveDay = ScheduleEntry::where('user_id', $employee->id)->whereDate('date', '2026-08-17')->first();
        $this->assertEquals(ScheduleEntry::LEAVE, $leaveDay->type, 'Approved leave must never be silently overwritten by a bulk shift assignment.');
        // Every other day in the range still got the shift.
        $this->assertEquals(5, ScheduleEntry::where('user_id', $employee->id)->where('type', ScheduleEntry::WORK)->count());
    }

    public function test_replace_existing_true_explicitly_overwrites_conflicting_days_only_when_chosen(): void
    {
        $employee = $this->makeEmployee();
        ScheduleEntry::create(['user_id' => $employee->id, 'date' => '2026-08-16', 'type' => ScheduleEntry::HOLIDAY, 'is_published' => true, 'created_by' => $this->admin->id]);

        $response = $this->postJson('/api/hr/schedule/bulk-assign', [
            'employee_ids' => [$employee->id], 'shift_id' => $this->morning->id,
            'date' => '2026-08-15', 'date_to' => '2026-08-17', 'replace_existing' => true,
        ]);

        $response->assertOk();
        $day16 = ScheduleEntry::where('user_id', $employee->id)->whereDate('date', '2026-08-16')->first();
        $this->assertEquals(ScheduleEntry::WORK, $day16->type, 'Explicit replace_existing=true must overwrite as chosen.');
    }

    // ── Range validation ─────────────────────────────────────────────────

    public function test_date_to_before_date_is_rejected(): void
    {
        $employee = $this->makeEmployee();

        $response = $this->postJson('/api/hr/schedule/bulk-assign', [
            'employee_ids' => [$employee->id], 'shift_id' => $this->morning->id,
            'date' => '2026-08-20', 'date_to' => '2026-08-15',
        ]);

        $response->assertStatus(422);
    }

    public function test_a_range_spanning_more_than_one_week_does_not_break_assignment(): void
    {
        $employee = $this->makeEmployee();

        $response = $this->postJson('/api/hr/schedule/bulk-assign', [
            'employee_ids' => [$employee->id], 'shift_id' => $this->morning->id,
            'date' => '2026-08-01', 'date_to' => '2026-08-20', // 20 days, ~3 weeks
        ]);

        $response->assertOk();
        $this->assertEquals(20, ScheduleEntry::where('user_id', $employee->id)->count());
    }
}
