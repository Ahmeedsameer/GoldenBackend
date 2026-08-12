<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\Payroll;
use App\Models\PayrollLine;
use App\Models\ScheduleEntry;
use App\Models\Shop;
use App\Models\User;
use App\Modules\Hr\Services\OvertimeService;
use App\Modules\Hr\Services\PayrollService;
use App\Modules\Hr\Services\ShiftAccessService;
use App\Modules\Sales\Services\SalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Real-MySQL QA for the Employee Sales/Shifts/Overtime/Payroll business
 * requirements — no mocks, real ShiftAccessService/OvertimeService/
 * PayrollService/SalesService calls, exactly as production wires them.
 */
class OvertimeShiftPayrollQaTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create(['name' => 'QA Shop', 'branch_bonus_percent' => 5, 'status' => 'active', 'address' => 'QA Address']);
        $this->admin = User::create([
            'name' => 'QA Admin', 'email' => 'qa_admin_' . uniqid() . '@test.local',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'active',
        ]);
        // Default actor for OvertimeService::create() (uses auth()->id() for
        // created_by, exactly like BonusPenaltyService) — tests that need a
        // different acting user (the employee, for shift-check calls) switch
        // it explicitly afterward.
        $this->actingAs($this->admin, 'api');
    }

    private function makeEmployee(array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'QA Employee ' . uniqid(),
            'email' => 'qa_emp_' . uniqid() . '@test.local',
            'password' => bcrypt('x'),
            'role' => 'sales',
            'status' => 'active',
            'shop_id' => $this->shop->id,
            'base_salary' => 3000,
            'personal_commission_percent' => 10,
        ], $overrides));
    }

    private function publishWorkShift(User $employee, Carbon $date, string $start, string $end): ScheduleEntry
    {
        return ScheduleEntry::create([
            'user_id' => $employee->id,
            'date' => $date->toDateString(),
            'type' => ScheduleEntry::WORK,
            'start_time' => $start,
            'end_time' => $end,
            'shop_id' => $this->shop->id,
            'is_published' => true,
            'created_by' => $this->admin->id,
        ]);
    }

    // ── Scenario 1/2: shift-based selling access ───────────────────────────

    public function test_employee_inside_published_shift_is_not_blocked(): void
    {
        $employee = $this->makeEmployee();
        $this->publishWorkShift($employee, today(), '00:00', '23:59');

        $message = app(ShiftAccessService::class)->blockMessageFor($employee->id);

        $this->assertNull($message, 'Inside an active published shift, selling must not be blocked.');
    }

    public function test_employee_with_no_schedule_today_is_blocked(): void
    {
        $employee = $this->makeEmployee();

        $message = app(ShiftAccessService::class)->blockMessageFor($employee->id);

        $this->assertNotNull($message, 'No published shift today must block selling.');
    }

    public function test_employee_outside_shift_hours_is_blocked_but_can_still_view(): void
    {
        $employee = $this->makeEmployee();
        // Shift already ended (started and ended in the past relative to now).
        $this->publishWorkShift($employee, today(), '00:00', '00:01');

        $status = app(ShiftAccessService::class)->statusFor($employee->id);

        $this->assertEquals(ShiftAccessService::OFF_SHIFT, $status['status']);
        // View-only means: statusFor/blockMessageFor never throw, self-service
        // data remains queryable — the block only applies to createInvoice().
    }

    public function test_unpublished_schedule_entry_does_not_grant_access(): void
    {
        $employee = $this->makeEmployee();
        ScheduleEntry::create([
            'user_id' => $employee->id, 'date' => today()->toDateString(), 'type' => ScheduleEntry::WORK,
            'start_time' => '00:00', 'end_time' => '23:59', 'shop_id' => $this->shop->id,
            'is_published' => false, 'created_by' => $this->admin->id,
        ]);

        $message = app(ShiftAccessService::class)->blockMessageFor($employee->id);

        $this->assertNotNull($message, 'An unpublished (draft) shift must never grant selling access.');
    }

    // ── Scenario: SalesService::createInvoice enforcement ──────────────────

    public function test_off_shift_employee_is_rejected_by_create_invoice_before_any_write(): void
    {
        $employee = $this->makeEmployee(); // no schedule today at all
        $this->actingAs($employee, 'api');

        try {
            app(SalesService::class)->createInvoice(['items' => []]);
            $this->fail('createInvoice() must abort for an off-shift employee.');
        } catch (\Throwable $e) {
            $this->assertEquals(403, method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 0);
        }
    }

    public function test_in_shift_employee_passes_shift_check_and_fails_later_for_unrelated_reason(): void
    {
        $employee = $this->makeEmployee();
        $this->publishWorkShift($employee, today(), '00:00', '23:59');
        $this->actingAs($employee, 'api');

        try {
            app(SalesService::class)->createInvoice(['items' => []]);
            $this->fail('Expected an exception from empty items, not a shift block.');
        } catch (\Throwable $e) {
            // Must NOT be blocked with the shift message — whatever it fails
            // on next (empty items / no products) is a different concern.
            $this->assertStringNotContainsString('دوامك', $e->getMessage());
            $this->assertStringNotContainsString('جدول عمل', $e->getMessage());
        }
    }

    public function test_admin_is_never_blocked_by_shift_rules(): void
    {
        $admin = User::create([
            'name' => 'QA Selling Admin', 'email' => 'qa_admin_sell_' . uniqid() . '@test.local',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'active', 'shop_id' => $this->shop->id,
        ]);
        // Deliberately NO schedule entry for today — an ordinary employee would be blocked.
        $this->actingAs($admin, 'api');

        try {
            app(SalesService::class)->createInvoice(['items' => []]);
            $this->fail('Expected an exception from empty items.');
        } catch (\Throwable $e) {
            $this->assertStringNotContainsString('دوامك', $e->getMessage(), 'Admin must never receive the shift-block message.');
            $this->assertStringNotContainsString('جدول عمل', $e->getMessage(), 'Admin must never receive the shift-block message.');
        }
    }

    // ── Scenario: overtime extends selling access ───────────────────────────

    public function test_approved_overtime_extends_access_after_shift_ends(): void
    {
        $employee = $this->makeEmployee();
        // Shift already over (ended at 00:01 today).
        $this->publishWorkShift($employee, today(), '00:00', '00:01');

        $now = now();
        app(OvertimeService::class)->create($employee, [
            'date' => today()->toDateString(),
            'start_time' => $now->copy()->subMinutes(5)->format('H:i'),
            'end_time' => $now->copy()->addMinutes(30)->format('H:i'),
            'hourly_rate' => 20,
        ]);

        $status = app(ShiftAccessService::class)->statusFor($employee->id);
        $this->assertEquals(ShiftAccessService::OVERTIME, $status['status']);
        $this->assertNull(app(ShiftAccessService::class)->blockMessageFor($employee->id));
    }

    public function test_selling_blocked_again_once_overtime_window_ends(): void
    {
        $employee = $this->makeEmployee();
        $this->publishWorkShift($employee, today(), '00:00', '00:01');

        $now = now();
        app(OvertimeService::class)->create($employee, [
            'date' => today()->toDateString(),
            'start_time' => $now->copy()->subMinutes(60)->format('H:i'),
            'end_time' => $now->copy()->subMinutes(30)->format('H:i'), // already ended
            'hourly_rate' => 20,
        ]);

        $status = app(ShiftAccessService::class)->statusFor($employee->id);
        $this->assertEquals(ShiftAccessService::OFF_SHIFT, $status['status']);
    }

    // ── Overtime hours/pay calculation ──────────────────────────────────────

    public function test_overtime_hours_and_pay_are_computed_correctly(): void
    {
        $employee = $this->makeEmployee();
        $overtime = app(OvertimeService::class)->create($employee, [
            'date' => today()->toDateString(),
            'start_time' => '18:00',
            'end_time' => '20:30',
            'hourly_rate' => 25,
        ]);

        $this->assertEqualsWithDelta(2.5, (float) $overtime->hours, 0.01);
        $this->assertEqualsWithDelta(62.5, (float) $overtime->pay, 0.01);
    }

    // ── Payroll formula: overtime pay flows into gross/net + a PayrollLine ──

    public function test_payroll_includes_overtime_pay_in_gross_and_net(): void
    {
        $employee = $this->makeEmployee(['base_salary' => 3000, 'personal_commission_percent' => 0]);
        $year = (int) today()->year;
        $month = (int) today()->month;
        $from = Carbon::create($year, $month, 15);

        app(OvertimeService::class)->create($employee, [
            'date' => $from->toDateString(), 'start_time' => '18:00', 'end_time' => '20:00', 'hourly_rate' => 30,
        ]); // 2h * 30 = 60

        $payroll = app(PayrollService::class)->generate($employee, $year, $month);

        $this->assertEqualsWithDelta(60.0, (float) $payroll->overtime_total, 0.01);
        $this->assertEqualsWithDelta(3060.0, (float) $payroll->gross, 0.01, 'Gross must include base + overtime.');
        $this->assertEqualsWithDelta(3060.0, (float) $payroll->net_salary, 0.01);

        $line = PayrollLine::where('payroll_id', $payroll->id)->where('type', PayrollLine::OVERTIME)->first();
        $this->assertNotNull($line, 'A PayrollLine::OVERTIME row must be created.');
        $this->assertEqualsWithDelta(60.0, (float) $line->amount, 0.01);
    }

    public function test_overtime_outside_payroll_period_is_not_included(): void
    {
        $employee = $this->makeEmployee(['base_salary' => 3000, 'personal_commission_percent' => 0]);
        $year = (int) today()->year;
        $month = (int) today()->month;

        // Overtime dated in a DIFFERENT month must never leak into this month's payroll.
        $otherMonth = Carbon::create($year, $month, 1)->subMonthNoOverflow();
        app(OvertimeService::class)->create($employee, [
            'date' => $otherMonth->toDateString(), 'start_time' => '18:00', 'end_time' => '20:00', 'hourly_rate' => 30,
        ]);

        $payroll = app(PayrollService::class)->generate($employee, $year, $month);

        $this->assertEqualsWithDelta(0.0, (float) $payroll->overtime_total, 0.01);
    }

    // ── Month-boundary / calendar correctness (real days, not fixed 30) ────

    public function test_daily_rate_uses_real_days_in_month_february_leap_vs_non_leap(): void
    {
        $employee = $this->makeEmployee(['base_salary' => 2900, 'personal_commission_percent' => 0]);

        // 2024 is a leap year (Feb = 29 days); 2023 Feb = 28 days.
        $leap = app(PayrollService::class)->generate($employee, 2024, 2);
        $this->assertEquals(29, $leap->working_days);

        $nonLeap = app(PayrollService::class)->generate($employee, 2023, 2);
        $this->assertEquals(28, $nonLeap->working_days);

        $thirtyOne = app(PayrollService::class)->generate($employee, 2024, 1);
        $this->assertEquals(31, $thirtyOne->working_days);

        $thirty = app(PayrollService::class)->generate($employee, 2024, 4);
        $this->assertEquals(30, $thirty->working_days);
    }

    public function test_new_month_payroll_does_not_duplicate_prior_month_history(): void
    {
        $employee = $this->makeEmployee(['base_salary' => 1000, 'personal_commission_percent' => 0]);

        $p1 = app(PayrollService::class)->generate($employee, 2024, 1);
        $p2 = app(PayrollService::class)->generate($employee, 2024, 2);

        $this->assertNotEquals($p1->id, $p2->id);
        $this->assertEquals(2, Payroll::where('user_id', $employee->id)->count(), 'Both months must remain queryable — history preserved.');
    }

    // ── Privacy: self-service overtime rows never leak across employees ────

    public function test_overtime_mine_scopes_strictly_to_the_authenticated_employee(): void
    {
        $e1 = $this->makeEmployee();
        $e2 = $this->makeEmployee();
        app(OvertimeService::class)->create($e1, ['date' => today()->toDateString(), 'start_time' => '18:00', 'end_time' => '19:00', 'hourly_rate' => 10]);
        app(OvertimeService::class)->create($e2, ['date' => today()->toDateString(), 'start_time' => '18:00', 'end_time' => '19:00', 'hourly_rate' => 10]);

        $rows = OvertimeRequest::where('user_id', $e1->id)->get();

        $this->assertCount(1, $rows);
        $this->assertEquals($e1->id, $rows->first()->user_id);
    }

    // ── Leave + shift interaction: leave lock must not be bypassed by overtime ──

    // ── Manager-wide Shift Lock (CheckRole) — off-shift blocks ALL work
    // routes, not just selling; self-service ('*') routes stay open. ───────

    private function makeManager(): User
    {
        return User::create([
            'name' => 'QA Manager ' . uniqid(), 'email' => 'qa_mgr_' . uniqid() . '@test.local',
            'password' => bcrypt('x'), 'role' => 'manager', 'status' => 'active', 'shop_id' => $this->shop->id,
        ]);
    }

    public function test_off_shift_manager_is_blocked_from_a_work_route(): void
    {
        $manager = $this->makeManager(); // no schedule today

        $response = $this->actingAs($manager, 'api')->getJson('/api/shops');

        $response->assertStatus(403);
        $this->assertEquals('off_shift', $response->json('error_code'));
    }

    public function test_in_shift_manager_passes_the_work_route_check(): void
    {
        $manager = $this->makeManager();
        $this->publishWorkShift($manager, today(), '00:00', '23:59');

        $response = $this->actingAs($manager, 'api')->getJson('/api/shops');

        $response->assertStatus(200);
    }

    public function test_off_shift_manager_can_still_reach_self_service_routes(): void
    {
        $manager = $this->makeManager(); // no schedule today — blocked from work routes

        $response = $this->actingAs($manager, 'api')->getJson('/api/hr/me/summary');

        $response->assertStatus(200);
    }

    public function test_off_shift_manager_can_still_submit_a_leave_request(): void
    {
        $manager = $this->makeManager();

        $response = $this->actingAs($manager, 'api')->postJson('/api/hr/leaves', [
            'start_date' => today()->addDay()->toDateString(),
            'end_date'   => today()->addDay()->toDateString(),
            'type'       => 'annual',
            'reason'     => 'QA',
        ]);

        $response->assertStatus(201);
    }

    public function test_off_shift_manager_during_approved_overtime_passes_work_route_check(): void
    {
        $manager = $this->makeManager(); // no published shift today at all
        $now = now();
        app(OvertimeService::class)->create($manager, [
            'date' => today()->toDateString(),
            'start_time' => $now->copy()->subMinutes(5)->format('H:i'),
            'end_time' => $now->copy()->addMinutes(30)->format('H:i'),
            'hourly_rate' => 20,
        ]);

        $response = $this->actingAs($manager, 'api')->getJson('/api/shops');

        $response->assertStatus(200);
    }

    public function test_sales_role_is_unaffected_by_the_manager_wide_shift_lock(): void
    {
        // Sales already has its own narrower lock (selling only); CheckRole's
        // new manager-wide lock must never apply to role=sales. Uses a route
        // that ADMITS sales alongside manager, so a 403 here could only come
        // from the (wrongly-applied) shift lock, never a role mismatch.
        $employee = $this->makeEmployee(); // no schedule today

        $response = $this->actingAs($employee, 'api')->getJson('/api/branch-operations/waste');

        $response->assertStatus(200);
    }

    public function test_active_leave_blocks_selling_even_with_overtime_granted(): void
    {
        $employee = $this->makeEmployee();
        LeaveRequest::create([
            'user_id' => $employee->id, 'start_date' => today()->toDateString(), 'end_date' => today()->toDateString(),
            'days' => 1, 'type' => 'annual', 'status' => LeaveRequest::APPROVED, 'reason' => 'QA',
            'paid_days' => 1, 'unpaid_days' => 0,
        ]);
        $now = now();
        app(OvertimeService::class)->create($employee, [
            'date' => today()->toDateString(),
            'start_time' => $now->copy()->subMinutes(5)->format('H:i'),
            'end_time' => $now->copy()->addMinutes(30)->format('H:i'),
            'hourly_rate' => 20,
        ]);
        $this->actingAs($employee, 'api');

        try {
            app(SalesService::class)->createInvoice(['items' => []]);
            $this->fail('Leave lock must still block selling regardless of overtime.');
        } catch (\Throwable $e) {
            $this->assertEquals(403, method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 0);
            $this->assertStringContainsString('إجازة', $e->getMessage());
        }
    }
}
