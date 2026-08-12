<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\HrDeductionSetting;
use App\Models\LeaveRequest;
use App\Models\Payroll;
use App\Models\PayrollLine;
use App\Models\ScheduleEntry;
use App\Models\Shop;
use App\Models\User;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\LeaveService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Real-MySQL verification of the four deduction types (absence, half_day,
 * late, unpaid_leave) against the ACTUAL PayrollService::generate() flow —
 * no frontend math, no mocks. Confirms the exact formula, month-length
 * correctness, multi-deduction summation, historical freezing, and the
 * semantic boundaries between the four types.
 */
class DeductionRulesVerificationTest extends TestCase
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
            'monthly_leave_allowance' => 0,
        ], $overrides));
    }

    /** RefreshDatabase migrates but never seeds — updateOrCreate() so tests
     *  don't silently no-op against a nonexistent row (the real cause of a
     *  "deduction not applied" bug would look identical to this). */
    private function setDeduction(string $code, string $mode, float $value): void
    {
        HrDeductionSetting::updateOrCreate(
            ['code' => $code],
            ['label' => $code, 'mode' => $mode, 'value' => $value, 'is_active' => true],
        );
    }

    private function markAttendance(User $employee, string $date, string $status): Attendance
    {
        return app(AttendanceService::class)->mark($employee->id, Carbon::parse($date), $status);
    }

    // ── 1 & 4: exact formula, percentage mode ───────────────────────────────

    public function test_percentage_absence_deduction_matches_value_times_daily_rate(): void
    {
        $employee = $this->makeEmployee(['base_salary' => 9000]);
        $this->setDeduction('absence', HrDeductionSetting::MODE_DAILY_FRACTION, 1.0);
        $this->markAttendance($employee, '2026-08-05', Attendance::ABSENT);

        $payroll = app(PayrollService::class)->generate($employee, 2026, 8);

        $dailySalary = 9000 / 31; // August 2026 has 31 days
        $expected = round(1.0 * $dailySalary, 2);

        $line = PayrollLine::where('payroll_id', $payroll->id)->where('type', PayrollLine::DEDUCTION)
            ->where('meta->code', 'absence')->first();
        $this->assertNotNull($line);
        $this->assertEqualsWithDelta(-$expected, (float) $line->amount, 0.01);
        $this->assertEqualsWithDelta($expected, (float) $payroll->total_deductions, 0.01);
    }

    public function test_half_day_percentage_deduction_uses_its_own_configured_fraction(): void
    {
        $employee = $this->makeEmployee(['base_salary' => 9000]);
        $this->setDeduction('half_day', HrDeductionSetting::MODE_DAILY_FRACTION, 0.5);
        $this->markAttendance($employee, '2026-08-05', Attendance::HALF_DAY);

        $payroll = app(PayrollService::class)->generate($employee, 2026, 8);

        $dailySalary = 9000 / 31;
        $expected = round(0.5 * $dailySalary, 2);

        $line = PayrollLine::where('payroll_id', $payroll->id)->where('meta->code', 'half_day')->first();
        $this->assertNotNull($line);
        $this->assertEqualsWithDelta(-$expected, (float) $line->amount, 0.01);
    }

    public function test_late_percentage_deduction_with_multiple_occurrences_multiplies_by_count(): void
    {
        $employee = $this->makeEmployee(['base_salary' => 9000]);
        $this->setDeduction('late', HrDeductionSetting::MODE_DAILY_FRACTION, 0.25);
        $this->markAttendance($employee, '2026-08-03', Attendance::LATE);
        $this->markAttendance($employee, '2026-08-10', Attendance::LATE);
        $this->markAttendance($employee, '2026-08-17', Attendance::LATE);

        $payroll = app(PayrollService::class)->generate($employee, 2026, 8);

        $dailySalary = 9000 / 31;
        $expected = round(0.25 * $dailySalary * 3, 2); // 3 late occurrences

        $line = PayrollLine::where('payroll_id', $payroll->id)->where('meta->code', 'late')->first();
        $this->assertNotNull($line);
        $this->assertEqualsWithDelta(-$expected, (float) $line->amount, 0.01);
        $this->assertEquals(3, $line->meta['qty']);
    }

    // ── Fixed mode: NOT a flat single amount — value × occurrence count ────

    public function test_fixed_mode_single_occurrence_equals_configured_value(): void
    {
        $employee = $this->makeEmployee();
        $this->setDeduction('late', HrDeductionSetting::MODE_FIXED, 300);
        $this->markAttendance($employee, '2026-08-05', Attendance::LATE);

        $payroll = app(PayrollService::class)->generate($employee, 2026, 8);

        $line = PayrollLine::where('payroll_id', $payroll->id)->where('meta->code', 'late')->first();
        $this->assertEqualsWithDelta(-300.0, (float) $line->amount, 0.01);
    }

    /**
     * IMPORTANT DOCUMENTED BEHAVIOR: fixed mode is NOT "Deduction = Configured
     * Value" flatly for the whole month — PayrollService::computeComponents()
     * multiplies `$per` (the configured value in fixed mode) by `$qty` (the
     * occurrence count) exactly like percentage mode does. Two late days at a
     * fixed 300 EGP each deduct 600, not 300.
     */
    public function test_fixed_mode_multiplies_by_occurrence_count_not_flat_per_month(): void
    {
        $employee = $this->makeEmployee();
        $this->setDeduction('late', HrDeductionSetting::MODE_FIXED, 300);
        $this->markAttendance($employee, '2026-08-05', Attendance::LATE);
        $this->markAttendance($employee, '2026-08-12', Attendance::LATE);

        $payroll = app(PayrollService::class)->generate($employee, 2026, 8);

        $line = PayrollLine::where('payroll_id', $payroll->id)->where('meta->code', 'late')->first();
        $this->assertEqualsWithDelta(-600.0, (float) $line->amount, 0.01, 'Fixed mode multiplies value by occurrence count.');
    }

    // ── 2: month-length correctness (28/29/30/31 days), no hardcoded 30 ────

    public function test_daily_rate_scales_correctly_across_28_29_30_31_day_months(): void
    {
        $employee = $this->makeEmployee(['base_salary' => 9000]);
        $this->setDeduction('absence', HrDeductionSetting::MODE_DAILY_FRACTION, 1.0);

        $cases = [
            ['year' => 2023, 'month' => 2, 'days' => 28, 'date' => '2023-02-10'], // non-leap Feb
            ['year' => 2024, 'month' => 2, 'days' => 29, 'date' => '2024-02-10'], // leap Feb
            ['year' => 2024, 'month' => 4, 'days' => 30, 'date' => '2024-04-10'],
            ['year' => 2024, 'month' => 1, 'days' => 31, 'date' => '2024-01-10'],
        ];

        foreach ($cases as $c) {
            $this->markAttendance($employee, $c['date'], Attendance::ABSENT);
            $payroll = app(PayrollService::class)->generate($employee, $c['year'], $c['month']);
            $expected = round(9000 / $c['days'], 2);
            $this->assertEqualsWithDelta($expected, (float) $payroll->total_deductions, 0.01, "Failed for {$c['days']}-day month.");
        }
    }

    // ── unpaid_leave: percentage mode, paid leave never deducted ───────────

    public function test_unpaid_leave_percentage_deduction(): void
    {
        $employee = $this->makeEmployee(['base_salary' => 9000]);
        $this->setDeduction('unpaid_leave', HrDeductionSetting::MODE_DAILY_FRACTION, 1.0);
        LeaveRequest::create([
            'user_id' => $employee->id, 'start_date' => '2026-08-05', 'end_date' => '2026-08-06',
            'days' => 2, 'type' => 'annual', 'status' => LeaveRequest::APPROVED,
            'paid_days' => 0, 'unpaid_days' => 2, 'reason' => 'QA',
        ]);

        $payroll = app(PayrollService::class)->generate($employee, 2026, 8);

        $dailySalary = 9000 / 31;
        $expected = round(1.0 * $dailySalary * 2, 2);
        $line = PayrollLine::where('payroll_id', $payroll->id)->where('meta->code', 'unpaid_leave')->first();
        $this->assertNotNull($line);
        $this->assertEqualsWithDelta(-$expected, (float) $line->amount, 0.01);
    }

    public function test_fully_paid_leave_is_never_deducted(): void
    {
        $employee = $this->makeEmployee(['base_salary' => 9000, 'monthly_leave_allowance' => 5]);
        $this->setDeduction('unpaid_leave', HrDeductionSetting::MODE_DAILY_FRACTION, 1.0);
        LeaveRequest::create([
            'user_id' => $employee->id, 'start_date' => '2026-08-05', 'end_date' => '2026-08-06',
            'days' => 2, 'type' => 'annual', 'status' => LeaveRequest::APPROVED,
            'paid_days' => 2, 'unpaid_days' => 0, 'reason' => 'QA',
        ]);

        $payroll = app(PayrollService::class)->generate($employee, 2026, 8);

        $line = PayrollLine::where('payroll_id', $payroll->id)->where('meta->code', 'unpaid_leave')->first();
        $this->assertNull($line, 'Fully paid leave (unpaid_days=0) must never create a deduction line.');
        $this->assertEqualsWithDelta(0.0, (float) $payroll->total_deductions, 0.01);
    }

    public function test_real_leave_approval_flow_correctly_splits_paid_and_unpaid_and_deducts_only_the_unpaid_part(): void
    {
        // Real end-to-end path: LeaveService::approve() (not a direct LeaveRequest::create shortcut).
        $employee = $this->makeEmployee(['base_salary' => 9000, 'monthly_leave_allowance' => 1]);
        $this->setDeduction('unpaid_leave', HrDeductionSetting::MODE_DAILY_FRACTION, 1.0);

        $leave = LeaveRequest::create([
            'user_id' => $employee->id, 'start_date' => '2026-08-10', 'end_date' => '2026-08-12',
            'days' => 3, 'type' => 'annual', 'status' => LeaveRequest::PENDING, 'reason' => 'QA',
        ]);
        app(LeaveService::class)->approve($leave);
        $leave->refresh();

        $this->assertEquals(1, $leave->paid_days);
        $this->assertEquals(2, $leave->unpaid_days);

        $payroll = app(PayrollService::class)->generate($employee, 2026, 8);
        $dailySalary = 9000 / 31;
        $expected = round(1.0 * $dailySalary * 2, 2);
        $this->assertEqualsWithDelta($expected, (float) $payroll->total_deductions, 0.01);

        // Approval also stamps Attendance=leave for the whole range — must
        // NOT show up as absence, since that's a different Attendance status.
        $this->assertEquals(0, Attendance::where('user_id', $employee->id)->where('status', Attendance::ABSENT)->count());
    }

    // ── Semantic boundaries: the four types are never interchangeable ──────

    public function test_half_day_is_never_counted_as_a_full_absence(): void
    {
        $employee = $this->makeEmployee(['base_salary' => 9000]);
        $this->setDeduction('absence', HrDeductionSetting::MODE_DAILY_FRACTION, 1.0);
        $this->setDeduction('half_day', HrDeductionSetting::MODE_DAILY_FRACTION, 0.5);
        $this->markAttendance($employee, '2026-08-05', Attendance::HALF_DAY);

        $payroll = app(PayrollService::class)->generate($employee, 2026, 8);

        $absenceLine = PayrollLine::where('payroll_id', $payroll->id)->where('meta->code', 'absence')->first();
        $halfDayLine = PayrollLine::where('payroll_id', $payroll->id)->where('meta->code', 'half_day')->first();
        $this->assertNull($absenceLine, 'A half_day attendance row must never also count as an absence.');
        $this->assertNotNull($halfDayLine);
        $this->assertEqualsWithDelta(-round(0.5 * 9000 / 31, 2), (float) $halfDayLine->amount, 0.01);
    }

    public function test_weekly_off_schedule_entry_creates_no_absence_deduction(): void
    {
        $employee = $this->makeEmployee(['base_salary' => 9000]);
        $this->setDeduction('absence', HrDeductionSetting::MODE_DAILY_FRACTION, 1.0);
        ScheduleEntry::create([
            'user_id' => $employee->id, 'date' => '2026-08-05', 'type' => ScheduleEntry::OFF_DAY,
            'shop_id' => $this->shop->id, 'is_published' => true, 'created_by' => $this->admin->id,
        ]);

        $payroll = app(PayrollService::class)->generate($employee, 2026, 8);

        $this->assertEquals(0, Attendance::where('user_id', $employee->id)->count(), 'Scheduling a weekly off must never write an Attendance row.');
        $this->assertEqualsWithDelta(0.0, (float) $payroll->total_deductions, 0.01);
    }

    public function test_public_holiday_schedule_entry_creates_no_absence_deduction(): void
    {
        $employee = $this->makeEmployee(['base_salary' => 9000]);
        $this->setDeduction('absence', HrDeductionSetting::MODE_DAILY_FRACTION, 1.0);
        ScheduleEntry::create([
            'user_id' => $employee->id, 'date' => '2026-08-05', 'type' => ScheduleEntry::HOLIDAY,
            'shop_id' => $this->shop->id, 'is_published' => true, 'created_by' => $this->admin->id,
        ]);

        $payroll = app(PayrollService::class)->generate($employee, 2026, 8);

        $this->assertEquals(0, Attendance::where('user_id', $employee->id)->count());
        $this->assertEqualsWithDelta(0.0, (float) $payroll->total_deductions, 0.01);
    }

    public function test_unmarked_day_does_not_default_to_a_persisted_absence(): void
    {
        // AttendanceService::roster() DEFAULTS unmarked days to 'absent' for
        // DISPLAY only — no Attendance row is ever written unless mark() is
        // explicitly called. Confirms an employee with zero attendance
        // activity for the month incurs zero deductions.
        $employee = $this->makeEmployee(['base_salary' => 9000]);
        $this->setDeduction('absence', HrDeductionSetting::MODE_DAILY_FRACTION, 1.0);

        $this->assertEquals(0, Attendance::where('user_id', $employee->id)->count());
        $payroll = app(PayrollService::class)->generate($employee, 2026, 8);
        $this->assertEqualsWithDelta(0.0, (float) $payroll->total_deductions, 0.01);
    }

    public function test_overtime_never_creates_a_late_or_absence_deduction(): void
    {
        $employee = $this->makeEmployee(['base_salary' => 9000]);
        $this->setDeduction('absence', HrDeductionSetting::MODE_DAILY_FRACTION, 1.0);
        $this->setDeduction('late', HrDeductionSetting::MODE_DAILY_FRACTION, 0.25);
        app(\App\Modules\Hr\Services\OvertimeService::class)->create($employee, [
            'date' => '2026-08-05', 'start_time' => '18:00', 'end_time' => '20:00', 'hourly_rate' => 30,
        ]);

        $payroll = app(PayrollService::class)->generate($employee, 2026, 8);

        $this->assertEqualsWithDelta(0.0, (float) $payroll->total_deductions, 0.01, 'Granting overtime must never create an attendance deduction.');
        $this->assertEqualsWithDelta(60.0, (float) $payroll->overtime_total, 0.01, 'Overtime pay must still be applied normally.');
    }

    // ── Multiple deduction types together — separate lines, correct sum ────

    public function test_multiple_deduction_types_in_the_same_month_are_recorded_separately_and_summed_correctly(): void
    {
        $employee = $this->makeEmployee(['base_salary' => 9000, 'personal_commission_percent' => 0]);
        $this->setDeduction('absence', HrDeductionSetting::MODE_DAILY_FRACTION, 1.0);
        $this->setDeduction('half_day', HrDeductionSetting::MODE_DAILY_FRACTION, 0.5);
        $this->setDeduction('late', HrDeductionSetting::MODE_FIXED, 200);

        $this->markAttendance($employee, '2026-08-03', Attendance::ABSENT);
        $this->markAttendance($employee, '2026-08-10', Attendance::HALF_DAY);
        $this->markAttendance($employee, '2026-08-17', Attendance::LATE);

        $payroll = app(PayrollService::class)->generate($employee, 2026, 8);
        $dailySalary = 9000 / 31;

        $lines = PayrollLine::where('payroll_id', $payroll->id)->where('type', PayrollLine::DEDUCTION)->get();
        $this->assertCount(3, $lines, 'Each deduction reason must be its own line — never merged or duplicated.');

        $expectedTotal = round(1.0 * $dailySalary, 2) + round(0.5 * $dailySalary, 2) + 200;
        $this->assertEqualsWithDelta($expectedTotal, (float) $payroll->total_deductions, 0.01);

        // Net Salary = Base + Personal + Branch + Bonus + Overtime − Deductions.
        $expectedNet = round(9000 + 0 + 0 + 0 + 0 - $expectedTotal, 2);
        $this->assertEqualsWithDelta($expectedNet, (float) $payroll->net_salary, 0.01);
    }

    // ── Payroll formula / breakdown integration ─────────────────────────────

    public function test_deduction_appears_correctly_in_the_full_payroll_breakdown_with_bonus_and_overtime(): void
    {
        $employee = $this->makeEmployee(['base_salary' => 9000, 'personal_commission_percent' => 0]);
        $this->setDeduction('absence', HrDeductionSetting::MODE_DAILY_FRACTION, 1.0);
        $this->markAttendance($employee, '2026-08-05', Attendance::ABSENT);
        \App\Models\Bonus::create(['user_id' => $employee->id, 'amount' => 500, 'reason' => 'QA', 'date' => '2026-08-01', 'created_by' => $this->admin->id, 'status' => \App\Models\Bonus::ACTIVE]);
        app(\App\Modules\Hr\Services\OvertimeService::class)->create($employee, [
            'date' => '2026-08-06', 'start_time' => '18:00', 'end_time' => '20:00', 'hourly_rate' => 25,
        ]);

        $payroll = app(PayrollService::class)->generate($employee, 2026, 8);

        $dailySalary = 9000 / 31;
        $absenceDeduction = round($dailySalary, 2);
        $expectedGross = round(9000 + 500 + 50, 2); // base + bonus + overtime(2h*25)
        $expectedNet = round($expectedGross - $absenceDeduction, 2);

        $this->assertEqualsWithDelta($expectedGross, (float) $payroll->gross, 0.01);
        $this->assertEqualsWithDelta($expectedNet, (float) $payroll->net_salary, 0.01);
        $this->assertEqualsWithDelta($absenceDeduction, (float) $payroll->total_deductions, 0.01);
    }

    // ── Isolation: only the intended employee/period is affected ───────────

    public function test_absence_deduction_affects_only_the_marked_employee(): void
    {
        $employeeA = $this->makeEmployee(['base_salary' => 9000]);
        $employeeB = $this->makeEmployee(['base_salary' => 9000]);
        $this->setDeduction('absence', HrDeductionSetting::MODE_DAILY_FRACTION, 1.0);
        $this->markAttendance($employeeA, '2026-08-05', Attendance::ABSENT);

        $payrollA = app(PayrollService::class)->generate($employeeA, 2026, 8);
        $payrollB = app(PayrollService::class)->generate($employeeB, 2026, 8);

        $this->assertGreaterThan(0, (float) $payrollA->total_deductions);
        $this->assertEqualsWithDelta(0.0, (float) $payrollB->total_deductions, 0.01);
    }

    public function test_absence_deduction_affects_only_the_marked_period(): void
    {
        $employee = $this->makeEmployee(['base_salary' => 9000]);
        $this->setDeduction('absence', HrDeductionSetting::MODE_DAILY_FRACTION, 1.0);
        $this->markAttendance($employee, '2026-08-05', Attendance::ABSENT);

        $augustPayroll = app(PayrollService::class)->generate($employee, 2026, 8);
        $julyPayroll = app(PayrollService::class)->generate($employee, 2026, 7);

        $this->assertGreaterThan(0, (float) $augustPayroll->total_deductions);
        $this->assertEqualsWithDelta(0.0, (float) $julyPayroll->total_deductions, 0.01);
    }

    public function test_marking_absence_does_not_alter_sales_history_or_commission(): void
    {
        $employee = $this->makeEmployee(['base_salary' => 9000, 'personal_commission_percent' => 10]);
        $this->setDeduction('absence', HrDeductionSetting::MODE_DAILY_FRACTION, 1.0);

        \App\Models\Invoice::create([
            'seller_id' => $employee->id, 'shop_id' => $this->shop->id, 'date' => '2026-08-05',
            'total_amount' => 1000, 'status' => 'approved', 'customer_id' => null, 'price_type' => 'retail',
        ]);
        $this->markAttendance($employee, '2026-08-05', Attendance::ABSENT);

        $payroll = app(PayrollService::class)->generate($employee, 2026, 8);

        $this->assertEqualsWithDelta(1000.0, (float) $payroll->personal_sales_total, 0.01, 'Marking absence must never alter recorded sales history.');
        $this->assertEqualsWithDelta(100.0, (float) $payroll->personal_commission_amount, 0.01);
    }

    // ── Historical protection: a generated/locked payroll is frozen ────────

    public function test_changing_a_deduction_setting_does_not_retroactively_change_an_already_generated_payroll(): void
    {
        $employee = $this->makeEmployee(['base_salary' => 9000]);
        $this->setDeduction('absence', HrDeductionSetting::MODE_DAILY_FRACTION, 1.0);
        $this->markAttendance($employee, '2026-08-05', Attendance::ABSENT);

        $payroll = app(PayrollService::class)->generate($employee, 2026, 8);
        $frozenDeduction = (float) $payroll->total_deductions;

        // Change the rule AFTER generation.
        $this->setDeduction('absence', HrDeductionSetting::MODE_DAILY_FRACTION, 3.0);

        $reloaded = Payroll::find($payroll->id);
        $this->assertEqualsWithDelta($frozenDeduction, (float) $reloaded->total_deductions, 0.01, 'An already-generated payroll must stay frozen when the setting changes later.');
    }

    public function test_a_locked_payroll_cannot_be_regenerated_at_all(): void
    {
        $employee = $this->makeEmployee(['base_salary' => 9000]);
        $this->setDeduction('absence', HrDeductionSetting::MODE_DAILY_FRACTION, 1.0);
        $this->markAttendance($employee, '2026-08-05', Attendance::ABSENT);

        $payroll = app(PayrollService::class)->generate($employee, 2026, 8);
        app(PayrollService::class)->lock($payroll);

        $this->setDeduction('absence', HrDeductionSetting::MODE_DAILY_FRACTION, 5.0);

        $this->expectException(ValidationException::class);
        app(PayrollService::class)->generate($employee, 2026, 8);
    }

    public function test_an_unlocked_payroll_can_be_explicitly_regenerated_with_the_new_setting(): void
    {
        // Existing, deliberate workflow: regeneration IS allowed for an
        // unlocked payroll — this is the documented escape hatch, not a bug.
        $employee = $this->makeEmployee(['base_salary' => 9000]);
        $this->setDeduction('absence', HrDeductionSetting::MODE_DAILY_FRACTION, 1.0);
        $this->markAttendance($employee, '2026-08-05', Attendance::ABSENT);

        $first = app(PayrollService::class)->generate($employee, 2026, 8);
        $firstDeduction = (float) $first->total_deductions;

        $this->setDeduction('absence', HrDeductionSetting::MODE_DAILY_FRACTION, 2.0);
        $second = app(PayrollService::class)->generate($employee, 2026, 8);

        $this->assertNotEqualsWithDelta($firstDeduction, (float) $second->total_deductions, 0.01);
        $this->assertEquals(1, Payroll::where('user_id', $employee->id)->where('period_year', 2026)->where('period_month', 8)->count(), 'Regeneration replaces the row, never duplicates it.');
    }

    // ── Configuration UI persistence ────────────────────────────────────────

    public function test_deduction_setting_update_persists_mode_and_value_without_silent_conversion(): void
    {
        $this->setDeduction('late', HrDeductionSetting::MODE_DAILY_FRACTION, 0.25);
        $setting = HrDeductionSetting::where('code', 'late')->first();

        $response = $this->putJson("/api/hr/deduction-settings/{$setting->id}", ['mode' => 'fixed', 'value' => 300]);
        $response->assertOk();

        $reloaded = HrDeductionSetting::find($setting->id);
        $this->assertEquals('fixed', $reloaded->mode);
        $this->assertEqualsWithDelta(300.0, (float) $reloaded->value, 0.01);

        // Switch back to percentage — must not get stuck or silently coerced.
        $this->putJson("/api/hr/deduction-settings/{$setting->id}", ['mode' => 'daily_fraction', 'value' => 0.25]);
        $reloaded->refresh();
        $this->assertEquals('daily_fraction', $reloaded->mode);
        $this->assertEqualsWithDelta(0.25, (float) $reloaded->value, 0.0001);
    }

    public function test_deduction_settings_index_reloads_saved_values_correctly(): void
    {
        $this->setDeduction('half_day', HrDeductionSetting::MODE_DAILY_FRACTION, 0.5);
        $setting = HrDeductionSetting::where('code', 'half_day')->first();
        $this->putJson("/api/hr/deduction-settings/{$setting->id}", ['mode' => 'fixed', 'value' => 150]);

        $response = $this->getJson('/api/hr/deduction-settings');
        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('code', 'half_day');
        $this->assertEquals('fixed', $row['mode']);
        $this->assertEqualsWithDelta(150.0, (float) $row['value'], 0.01);
    }
}
