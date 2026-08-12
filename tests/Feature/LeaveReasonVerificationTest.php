<?php

namespace Tests\Feature;

use App\Models\LeaveReason;
use App\Models\LeaveRequest;
use App\Models\Payroll;
use App\Models\PayrollLine;
use App\Models\Shop;
use App\Models\User;
use App\Modules\Hr\Services\LeaveService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Real-MySQL verification of the Admin-configurable leave/attendance Reason
 * system (section 5) — no mocks, real LeaveService::approve()/PayrollService
 * ::generate() calls, real HTTP for the CRUD + employee-facing endpoints.
 */
class LeaveReasonVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create(['name' => 'QA Shop', 'branch_bonus_percent' => 5, 'status' => 'active', 'address' => 'x']);
        $this->admin = User::create([
            'name' => 'QA Admin', 'email' => 'qa_admin_' . uniqid() . '@test.local',
            'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'active',
        ]);
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
            'base_salary' => 9000,
            'personal_commission_percent' => 0,
            'monthly_leave_allowance' => 10,
        ], $overrides));
    }

    // ── 1–4: Admin CRUD, real HTTP ──────────────────────────────────────────

    public function test_admin_can_create_a_new_reason(): void
    {
        $response = $this->postJson('/api/hr/leave-reasons', [
            'name' => 'ظرف عائلي', 'deducts_leave_balance' => true, 'deducts_salary' => false,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('leave_reasons', ['name' => 'ظرف عائلي', 'deducts_leave_balance' => 1, 'deducts_salary' => 0]);
    }

    public function test_new_reason_appears_in_the_admin_table(): void
    {
        $this->postJson('/api/hr/leave-reasons', ['name' => 'موعد طبي', 'deducts_leave_balance' => false, 'deducts_salary' => true, 'deduction_mode' => 'fixed', 'deduction_value' => 300]);

        $response = $this->getJson('/api/hr/leave-reasons');
        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertContains('موعد طبي', $names);
    }

    public function test_admin_can_edit_a_reason(): void
    {
        $reason = LeaveReason::create(['name' => 'مهمة شخصية', 'deducts_leave_balance' => true, 'deducts_salary' => false]);

        $response = $this->putJson("/api/hr/leave-reasons/{$reason->id}", ['name' => 'مهمة شخصية معتمدة', 'deducts_salary' => true, 'deduction_mode' => 'fixed', 'deduction_value' => 100]);
        $response->assertOk();

        $reason->refresh();
        $this->assertEquals('مهمة شخصية معتمدة', $reason->name);
        $this->assertTrue((bool) $reason->deducts_salary);
        $this->assertEqualsWithDelta(100.0, (float) $reason->deduction_value, 0.01);
    }

    public function test_admin_can_enable_and_disable_a_reason(): void
    {
        $reason = LeaveReason::create(['name' => 'اختبار', 'is_active' => true]);

        $this->putJson("/api/hr/leave-reasons/{$reason->id}", ['is_active' => false])->assertOk();
        $this->assertFalse((bool) $reason->fresh()->is_active);

        $this->putJson("/api/hr/leave-reasons/{$reason->id}", ['is_active' => true])->assertOk();
        $this->assertTrue((bool) $reason->fresh()->is_active);
    }

    public function test_disabling_salary_deduction_clears_mode_and_value(): void
    {
        $reason = LeaveReason::create(['name' => 'اختبار٢', 'deducts_salary' => true, 'deduction_mode' => 'fixed', 'deduction_value' => 200]);

        $this->putJson("/api/hr/leave-reasons/{$reason->id}", ['deducts_salary' => false])->assertOk();

        $reason->refresh();
        $this->assertFalse((bool) $reason->deducts_salary);
        $this->assertNull($reason->deduction_mode);
        $this->assertNull($reason->deduction_value);
    }

    // ── 5–6: employee-facing reason list ────────────────────────────────────

    public function test_active_reasons_appear_in_the_employee_leave_form_endpoint(): void
    {
        LeaveReason::create(['name' => 'عذر فعّال', 'is_active' => true]);
        LeaveReason::create(['name' => 'عذر معطّل', 'is_active' => false]);
        $employee = $this->makeEmployee();
        $this->actingAs($employee, 'api');

        $response = $this->getJson('/api/hr/leave-reasons/active');
        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertContains('عذر فعّال', $names);
        $this->assertNotContains('عذر معطّل', $names, 'Disabled reasons must never appear in the employee-facing list.');
    }

    /**
     * The two YES/NO policy flags (deducts_leave_balance/deducts_salary) ARE
     * exposed here — the employee-facing leave form uses them to show a
     * plain-language explanation ("سيتم خصم الأيام من رصيد إجازاتك") per the
     * "Leave Request Flow" requirement. What must NEVER leak is the actual
     * internal rate configuration: deduction_mode/deduction_value.
     */
    public function test_employee_active_reason_list_exposes_policy_flags_but_never_the_rate_configuration(): void
    {
        LeaveReason::create(['name' => 'عذر', 'deducts_salary' => true, 'deduction_mode' => 'fixed', 'deduction_value' => 500]);
        $employee = $this->makeEmployee();
        $this->actingAs($employee, 'api');

        $response = $this->getJson('/api/hr/leave-reasons/active');
        $row = collect($response->json('data'))->first();
        $this->assertArrayHasKey('name', $row);
        $this->assertArrayHasKey('deducts_leave_balance', $row);
        $this->assertArrayHasKey('deducts_salary', $row);
        $this->assertArrayNotHasKey('deduction_mode', $row);
        $this->assertArrayNotHasKey('deduction_value', $row);
    }

    public function test_disabled_reason_is_rejected_when_submitting_a_new_leave_request(): void
    {
        $reason = LeaveReason::create(['name' => 'معطّل', 'is_active' => false]);
        $employee = $this->makeEmployee();
        $this->actingAs($employee, 'api');

        $response = $this->postJson('/api/hr/leaves', [
            'start_date' => '2026-09-01', 'end_date' => '2026-09-01', 'reason_id' => $reason->id,
        ]);

        $response->assertStatus(422);
    }

    // ── 7: employee cannot modify the financial policy ──────────────────────

    public function test_employee_cannot_set_financial_policy_fields_when_submitting_a_request(): void
    {
        $reason = LeaveReason::create(['name' => 'عذر', 'deducts_leave_balance' => true, 'deducts_salary' => false]);
        $employee = $this->makeEmployee();
        $this->actingAs($employee, 'api');

        // Employee attempts to smuggle policy overrides — must be silently ignored.
        $response = $this->postJson('/api/hr/leaves', [
            'start_date' => '2026-09-01', 'end_date' => '2026-09-01', 'reason_id' => $reason->id,
            'deducts_leave_balance' => false, 'deducts_salary' => true, 'deduction_mode' => 'fixed', 'deduction_value' => 9999,
        ]);
        $response->assertCreated();

        $reason->refresh();
        $this->assertTrue((bool) $reason->deducts_leave_balance, 'The reason itself must be untouched by the request payload.');
        $this->assertFalse((bool) $reason->deducts_salary);
    }

    // ── 8–10: approved requests use the Admin-configured policy ─────────────

    public function test_reason_with_balance_deduction_only_consumes_balance_and_no_salary_deduction(): void
    {
        $reason = LeaveReason::create(['name' => 'ظرف عائلي', 'deducts_leave_balance' => true, 'deducts_salary' => false]);
        $employee = $this->makeEmployee(['monthly_leave_allowance' => 10]);
        $leave = LeaveRequest::create([
            'user_id' => $employee->id, 'start_date' => '2026-08-05', 'end_date' => '2026-08-06',
            'days' => 2, 'reason_id' => $reason->id, 'status' => LeaveRequest::PENDING, 'reason' => 'QA',
        ]);

        app(LeaveService::class)->approve($leave);
        $leave->refresh();

        $this->assertEquals(2, $leave->paid_days, 'deducts_leave_balance=true must consume the balance.');
        $this->assertEquals(0, $leave->unpaid_days);

        $payroll = app(PayrollService::class)->generate($employee, 2026, 8);
        $line = PayrollLine::where('payroll_id', $payroll->id)->where('meta->reason_id', $reason->id)->first();
        $this->assertNull($line, 'deducts_salary=false must never create a payroll deduction.');
    }

    public function test_reason_with_salary_deduction_only_deducts_pay_and_does_not_touch_balance(): void
    {
        $reason = LeaveReason::create(['name' => 'موعد طبي', 'deducts_leave_balance' => false, 'deducts_salary' => true, 'deduction_mode' => 'fixed', 'deduction_value' => 300]);
        $employee = $this->makeEmployee(['base_salary' => 9000, 'monthly_leave_allowance' => 10]);
        $leave = LeaveRequest::create([
            'user_id' => $employee->id, 'start_date' => '2026-08-05', 'end_date' => '2026-08-05',
            'days' => 1, 'reason_id' => $reason->id, 'status' => LeaveRequest::PENDING, 'reason' => 'QA',
        ]);

        app(LeaveService::class)->approve($leave);
        $leave->refresh();

        $this->assertEquals(0, $leave->paid_days, 'deducts_leave_balance=false must never consume balance.');

        $balance = app(LeaveService::class)->balance($employee, 2026, 8);
        $this->assertEquals(10, $balance['remaining'], 'Balance must be fully untouched.');

        $payroll = app(PayrollService::class)->generate($employee, 2026, 8);
        $line = PayrollLine::where('payroll_id', $payroll->id)->where('meta->reason_id', $reason->id)->first();
        $this->assertNotNull($line, 'deducts_salary=true must create a payroll deduction.');
        $this->assertEqualsWithDelta(-300.0, (float) $line->amount, 0.01);
    }

    public function test_reason_with_neither_flag_has_no_financial_effect_at_all(): void
    {
        $reason = LeaveReason::create(['name' => 'مهمة شخصية معتمدة', 'deducts_leave_balance' => false, 'deducts_salary' => false]);
        $employee = $this->makeEmployee(['monthly_leave_allowance' => 10]);
        $leave = LeaveRequest::create([
            'user_id' => $employee->id, 'start_date' => '2026-08-05', 'end_date' => '2026-08-05',
            'days' => 1, 'reason_id' => $reason->id, 'status' => LeaveRequest::PENDING, 'reason' => 'QA',
        ]);

        app(LeaveService::class)->approve($leave);
        $balance = app(LeaveService::class)->balance($employee, 2026, 8);
        $this->assertEquals(10, $balance['remaining']);

        $payroll = app(PayrollService::class)->generate($employee, 2026, 8);
        $this->assertEqualsWithDelta(0.0, (float) $payroll->total_deductions, 0.01);
    }

    public function test_two_reasons_with_different_values_deduct_independently_never_sharing_a_rate(): void
    {
        $reasonA = LeaveReason::create(['name' => 'عذر أ', 'deducts_leave_balance' => false, 'deducts_salary' => true, 'deduction_mode' => 'fixed', 'deduction_value' => 100]);
        $reasonB = LeaveReason::create(['name' => 'عذر ب', 'deducts_leave_balance' => false, 'deducts_salary' => true, 'deduction_mode' => 'fixed', 'deduction_value' => 250]);
        $employee = $this->makeEmployee();

        $leaveA = LeaveRequest::create(['user_id' => $employee->id, 'start_date' => '2026-08-03', 'end_date' => '2026-08-03', 'days' => 1, 'reason_id' => $reasonA->id, 'status' => LeaveRequest::PENDING]);
        $leaveB = LeaveRequest::create(['user_id' => $employee->id, 'start_date' => '2026-08-10', 'end_date' => '2026-08-10', 'days' => 1, 'reason_id' => $reasonB->id, 'status' => LeaveRequest::PENDING]);
        app(LeaveService::class)->approve($leaveA);
        app(LeaveService::class)->approve($leaveB);

        $payroll = app(PayrollService::class)->generate($employee, 2026, 8);
        $lineA = PayrollLine::where('payroll_id', $payroll->id)->where('meta->reason_id', $reasonA->id)->first();
        $lineB = PayrollLine::where('payroll_id', $payroll->id)->where('meta->reason_id', $reasonB->id)->first();

        $this->assertEqualsWithDelta(-100.0, (float) $lineA->amount, 0.01);
        $this->assertEqualsWithDelta(-250.0, (float) $lineB->amount, 0.01);
        $this->assertEqualsWithDelta(350.0, (float) $payroll->total_deductions, 0.01);
    }

    public function test_percentage_mode_reason_uses_daily_rate_like_the_fixed_deduction_codes(): void
    {
        $reason = LeaveReason::create(['name' => 'عذر نسبي', 'deducts_leave_balance' => false, 'deducts_salary' => true, 'deduction_mode' => 'daily_fraction', 'deduction_value' => 0.5]);
        $employee = $this->makeEmployee(['base_salary' => 9000]);
        $leave = LeaveRequest::create(['user_id' => $employee->id, 'start_date' => '2026-08-05', 'end_date' => '2026-08-06', 'days' => 2, 'reason_id' => $reason->id, 'status' => LeaveRequest::PENDING]);
        app(LeaveService::class)->approve($leave);

        $payroll = app(PayrollService::class)->generate($employee, 2026, 8);
        $dailyRate = 9000 / 31;
        $expected = round(0.5 * $dailyRate * 2, 2);

        $line = PayrollLine::where('payroll_id', $payroll->id)->where('meta->reason_id', $reason->id)->first();
        $this->assertEqualsWithDelta(-$expected, (float) $line->amount, 0.01);
    }

    // ── 11: historical protection for reason-based deductions ──────────────

    public function test_changing_a_reasons_policy_after_approval_does_not_alter_historical_leave_request_or_payroll(): void
    {
        // Zero balance so the single requested day is entirely "excess" —
        // both flags on, sequential application: 0 covered by balance, 1
        // salary-deducted (see the dedicated "both flags" test group below
        // for the full paid/excess split matrix).
        $reason = LeaveReason::create(['name' => 'عذر تاريخي', 'deducts_leave_balance' => true, 'deducts_salary' => true, 'deduction_mode' => 'fixed', 'deduction_value' => 200]);
        $employee = $this->makeEmployee(['monthly_leave_allowance' => 0]);
        $leave = LeaveRequest::create(['user_id' => $employee->id, 'start_date' => '2026-08-05', 'end_date' => '2026-08-05', 'days' => 1, 'reason_id' => $reason->id, 'status' => LeaveRequest::PENDING]);
        app(LeaveService::class)->approve($leave);
        $leave->refresh();
        $this->assertEquals(0, $leave->paid_days);
        $this->assertEquals(1, $leave->unpaid_days);

        $payroll = app(PayrollService::class)->generate($employee, 2026, 8);
        $frozenDeduction = (float) $payroll->total_deductions;
        $this->assertEqualsWithDelta(200.0, $frozenDeduction, 0.01);

        // Admin later changes the reason's policy entirely.
        $reason->update(['deducts_leave_balance' => false, 'deducts_salary' => false, 'deduction_mode' => null, 'deduction_value' => null]);

        // The already-approved LeaveRequest's own frozen columns are untouched.
        $leave->refresh();
        $this->assertEquals(0, $leave->paid_days, 'Historical paid_days must not retroactively change.');

        // The already-generated Payroll stays frozen too.
        $reloaded = Payroll::find($payroll->id);
        $this->assertEqualsWithDelta($frozenDeduction, (float) $reloaded->total_deductions, 0.01, 'Historical payroll must stay frozen when the reason config changes later.');
    }

    // ── Both flags enabled simultaneously — sequential application ─────────
    // (1) balance consumed first, (2) only the excess is salary-deducted.
    // Never double-deducts the same day.

    public function test_both_flags_with_sufficient_balance_covers_everything_no_salary_deduction(): void
    {
        $reason = LeaveReason::create(['name' => 'كلا الأثرين', 'deducts_leave_balance' => true, 'deducts_salary' => true, 'deduction_mode' => 'fixed', 'deduction_value' => 300]);
        $employee = $this->makeEmployee(['monthly_leave_allowance' => 5]);
        $leave = LeaveRequest::create(['user_id' => $employee->id, 'start_date' => '2026-08-05', 'end_date' => '2026-08-06', 'days' => 2, 'reason_id' => $reason->id, 'status' => LeaveRequest::PENDING]);

        app(LeaveService::class)->approve($leave);
        $leave->refresh();

        $this->assertEquals(2, $leave->paid_days, 'All 2 days fit the available balance.');
        $this->assertEquals(0, $leave->unpaid_days, 'Nothing left over to salary-deduct.');

        $payroll = app(PayrollService::class)->generate($employee, 2026, 8);
        $this->assertEqualsWithDelta(0.0, (float) $payroll->total_deductions, 0.01, 'Fully balance-covered — no salary deduction at all.');
    }

    public function test_both_flags_with_partial_balance_splits_paid_and_excess_exactly(): void
    {
        // Balance = 1 day, requested = 2 days → 1 day paid/covered, 1 day excess/salary-deducted.
        $reason = LeaveReason::create(['name' => 'كلا الأثرين', 'deducts_leave_balance' => true, 'deducts_salary' => true, 'deduction_mode' => 'fixed', 'deduction_value' => 300]);
        $employee = $this->makeEmployee(['monthly_leave_allowance' => 1]);
        $leave = LeaveRequest::create(['user_id' => $employee->id, 'start_date' => '2026-08-05', 'end_date' => '2026-08-06', 'days' => 2, 'reason_id' => $reason->id, 'status' => LeaveRequest::PENDING]);

        app(LeaveService::class)->approve($leave);
        $leave->refresh();

        $this->assertEquals(1, $leave->paid_days);
        $this->assertEquals(1, $leave->unpaid_days);

        $payroll = app(PayrollService::class)->generate($employee, 2026, 8);
        $this->assertEqualsWithDelta(300.0, (float) $payroll->total_deductions, 0.01, 'Only the 1 excess day is salary-deducted, at the reason\'s own rate.');
    }

    public function test_both_flags_with_zero_balance_deducts_the_full_requested_days(): void
    {
        $reason = LeaveReason::create(['name' => 'كلا الأثرين', 'deducts_leave_balance' => true, 'deducts_salary' => true, 'deduction_mode' => 'fixed', 'deduction_value' => 300]);
        $employee = $this->makeEmployee(['monthly_leave_allowance' => 0]);
        $leave = LeaveRequest::create(['user_id' => $employee->id, 'start_date' => '2026-08-05', 'end_date' => '2026-08-07', 'days' => 3, 'reason_id' => $reason->id, 'status' => LeaveRequest::PENDING]);

        app(LeaveService::class)->approve($leave);
        $leave->refresh();

        $this->assertEquals(0, $leave->paid_days, 'No balance available to cover any day.');
        $this->assertEquals(3, $leave->unpaid_days, 'All 3 days are excess.');

        $payroll = app(PayrollService::class)->generate($employee, 2026, 8);
        $this->assertEqualsWithDelta(900.0, (float) $payroll->total_deductions, 0.01); // 300 × 3
    }

    public function test_both_flags_never_double_deducts_the_same_day(): void
    {
        // The exact example from the spec: balance=3, requested=5 → 3 paid/covered, 2 unpaid/salary-deducted.
        $reason = LeaveReason::create(['name' => 'كلا الأثرين', 'deducts_leave_balance' => true, 'deducts_salary' => true, 'deduction_mode' => 'fixed', 'deduction_value' => 300]);
        $employee = $this->makeEmployee(['monthly_leave_allowance' => 3]);
        $leave = LeaveRequest::create(['user_id' => $employee->id, 'start_date' => '2026-08-05', 'end_date' => '2026-08-09', 'days' => 5, 'reason_id' => $reason->id, 'status' => LeaveRequest::PENDING]);

        app(LeaveService::class)->approve($leave);
        $leave->refresh();

        $this->assertEquals(3, $leave->paid_days, 'Covered by balance.');
        $this->assertEquals(2, $leave->unpaid_days, 'Excess beyond the balance.');
        $this->assertEquals(5, $leave->paid_days + $leave->unpaid_days, 'Every requested day is accounted for exactly once — paid + unpaid always equals days.');

        $payroll = app(PayrollService::class)->generate($employee, 2026, 8);
        $line = PayrollLine::where('payroll_id', $payroll->id)->where('meta->reason_id', $reason->id)->first();
        $this->assertEqualsWithDelta(-600.0, (float) $line->amount, 0.01); // 300 × 2, never × 5
        $this->assertEquals(2, $line->meta['qty'], 'The deduction line\'s own quantity must be the excess (2), not the full request (5).');

        // Balance side: exactly 3 days were consumed, never more (no double-counting toward the balance either).
        $balanceAfter = app(LeaveService::class)->balance($employee)['remaining'];
        $this->assertEqualsWithDelta(0.0, $balanceAfter, 0.01);
    }

    public function test_both_flags_historical_payroll_stays_frozen_after_reason_policy_changes(): void
    {
        $reason = LeaveReason::create(['name' => 'كلا الأثرين', 'deducts_leave_balance' => true, 'deducts_salary' => true, 'deduction_mode' => 'fixed', 'deduction_value' => 300]);
        $employee = $this->makeEmployee(['monthly_leave_allowance' => 2]);
        $leave = LeaveRequest::create(['user_id' => $employee->id, 'start_date' => '2026-08-05', 'end_date' => '2026-08-09', 'days' => 5, 'reason_id' => $reason->id, 'status' => LeaveRequest::PENDING]);
        app(LeaveService::class)->approve($leave);
        $leave->refresh();
        $this->assertEquals(2, $leave->paid_days);
        $this->assertEquals(3, $leave->unpaid_days);

        $payroll = app(PayrollService::class)->generate($employee, 2026, 8);
        app(PayrollService::class)->lock($payroll);
        $frozenDeductions = (float) $payroll->total_deductions;
        $this->assertEqualsWithDelta(900.0, $frozenDeductions, 0.01); // 300 × 3

        // Admin later changes the reason's value AND flips both flags off.
        $reason->update(['deducts_leave_balance' => false, 'deducts_salary' => false, 'deduction_mode' => null, 'deduction_value' => null]);

        // The already-approved LeaveRequest's frozen split is untouched.
        $leave->refresh();
        $this->assertEquals(2, $leave->paid_days, 'Historical paid/unpaid split must never retroactively change.');
        $this->assertEquals(3, $leave->unpaid_days);

        // The already-locked Payroll stays frozen too.
        $reloaded = Payroll::find($payroll->id);
        $this->assertEqualsWithDelta($frozenDeductions, (float) $reloaded->total_deductions, 0.01);
    }

    // ── 12: no hardcoded reason-name-specific logic ─────────────────────────

    public function test_deduction_behavior_is_governed_purely_by_configured_flags_never_by_reason_name(): void
    {
        // Two reasons with wildly different, unrelated names but IDENTICAL policy — must produce IDENTICAL behavior.
        $reasonX = LeaveReason::create(['name' => 'ظرف طارئ نادر جدًا', 'deducts_leave_balance' => false, 'deducts_salary' => true, 'deduction_mode' => 'fixed', 'deduction_value' => 150]);
        $reasonY = LeaveReason::create(['name' => 'شيء آخر تمامًا', 'deducts_leave_balance' => false, 'deducts_salary' => true, 'deduction_mode' => 'fixed', 'deduction_value' => 150]);

        $empX = $this->makeEmployee();
        $empY = $this->makeEmployee();
        $leaveX = LeaveRequest::create(['user_id' => $empX->id, 'start_date' => '2026-08-05', 'end_date' => '2026-08-05', 'days' => 1, 'reason_id' => $reasonX->id, 'status' => LeaveRequest::PENDING]);
        $leaveY = LeaveRequest::create(['user_id' => $empY->id, 'start_date' => '2026-08-05', 'end_date' => '2026-08-05', 'days' => 1, 'reason_id' => $reasonY->id, 'status' => LeaveRequest::PENDING]);
        app(LeaveService::class)->approve($leaveX);
        app(LeaveService::class)->approve($leaveY);

        $payrollX = app(PayrollService::class)->generate($empX, 2026, 8);
        $payrollY = app(PayrollService::class)->generate($empY, 2026, 8);

        $this->assertEqualsWithDelta((float) $payrollX->total_deductions, (float) $payrollY->total_deductions, 0.01, 'Identical policy must produce identical behavior regardless of the reason name.');
    }

    // ── Legacy compatibility: existing free-type leave flow is unaffected ──

    public function test_legacy_leave_request_without_a_reason_still_uses_the_original_balance_overflow_behavior(): void
    {
        $employee = $this->makeEmployee(['monthly_leave_allowance' => 1]);
        $leave = LeaveRequest::create(['user_id' => $employee->id, 'start_date' => '2026-08-05', 'end_date' => '2026-08-07', 'days' => 3, 'type' => 'annual', 'status' => LeaveRequest::PENDING]);

        app(LeaveService::class)->approve($leave);
        $leave->refresh();

        $this->assertEquals(1, $leave->paid_days);
        $this->assertEquals(2, $leave->unpaid_days, 'Legacy no-reason leaves must keep the original allowance-overflow split.');
    }
}
