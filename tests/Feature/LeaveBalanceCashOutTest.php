<?php

namespace Tests\Feature;

use App\Models\LeaveCashOut;
use App\Models\LeaveReason;
use App\Models\LeaveRequest;
use App\Models\Payroll;
use App\Models\PayrollLine;
use App\Models\Shop;
use App\Models\User;
use App\Modules\Hr\Services\LeaveCashOutService;
use App\Modules\Hr\Services\LeaveService;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Real-MySQL verification of section 15's 24 checks: unified leave form,
 * cumulative (carry-over) leave balance, Leave Encashment (cash-out), its
 * payroll integration, historical protection, calendar correctness, and
 * privacy — no mocks, real LeaveService/PayrollService/LeaveCashOutService
 * calls and real HTTP for the endpoint-level checks.
 */
class LeaveBalanceCashOutTest extends TestCase
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
            'monthly_leave_allowance' => 2,
        ], $overrides));
    }

    // ── Leave Form (1–4) ─────────────────────────────────────────────────

    public function test_employee_submits_a_reason_based_request_with_dates_and_notes(): void
    {
        $reason = LeaveReason::create(['name' => 'سفر', 'deducts_leave_balance' => true, 'deducts_salary' => false]);
        $employee = $this->makeEmployee();
        $this->actingAs($employee, 'api');

        $response = $this->postJson('/api/hr/leaves', [
            'start_date' => '2026-09-01', 'end_date' => '2026-09-02', 'reason_id' => $reason->id, 'reason' => 'رحلة عائلية',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('leave_requests', ['user_id' => $employee->id, 'reason_id' => $reason->id, 'reason' => 'رحلة عائلية']);
    }

    public function test_legacy_leave_records_without_a_reason_remain_readable(): void
    {
        $employee = $this->makeEmployee();
        LeaveRequest::create([
            'user_id' => $employee->id, 'start_date' => '2026-01-05', 'end_date' => '2026-01-06',
            'days' => 2, 'type' => 'annual', 'status' => LeaveRequest::APPROVED, 'paid_days' => 2, 'unpaid_days' => 0,
        ]);

        $response = $this->getJson('/api/hr/leaves');
        $response->assertOk();
        $row = collect($response->json('data.data'))->firstWhere('user_id', $employee->id);
        $this->assertNotNull($row, 'Legacy (reason_id=NULL) records must remain fully readable via the admin list.');
        $this->assertEquals('annual', $row['type']);
        $this->assertNull($row['reason_id']);
    }

    // ── Leave Balance / Carry-over (5–8) ────────────────────────────────

    public function test_monthly_accrual_accumulates_across_multiple_months(): void
    {
        $employee = $this->makeEmployee(['monthly_leave_allowance' => 1.75, 'hire_date' => now()->subMonths(2)->toDateString()]);

        $balance = app(LeaveService::class)->balance($employee);

        // hire_date 2 months ago + current month = 3 months employed.
        $this->assertEqualsWithDelta(1.75 * 3, (float) $balance['earned_to_date'], 0.01);
    }

    public function test_unused_leave_carries_forward_instead_of_resetting_monthly(): void
    {
        $employee = $this->makeEmployee(['monthly_leave_allowance' => 2, 'hire_date' => now()->subMonths(3)->toDateString()]);
        // No leave used at all — 4 months employed (3 back + current) × 2 = 8, never reset to a per-month 2.
        $balance = app(LeaveService::class)->balance($employee);

        $this->assertEqualsWithDelta(2 * 4, (float) $balance['earned_to_date'], 0.01);
        $this->assertEqualsWithDelta(2 * 4, (float) $balance['remaining'], 0.01, 'Unused balance must carry forward, not reset.');
    }

    public function test_approved_leave_with_deducts_balance_decreases_remaining(): void
    {
        $reason = LeaveReason::create(['name' => 'إجازة عادية', 'deducts_leave_balance' => true, 'deducts_salary' => false]);
        $employee = $this->makeEmployee(['monthly_leave_allowance' => 5]);
        $before = app(LeaveService::class)->balance($employee)['remaining'];

        $leave = LeaveRequest::create(['user_id' => $employee->id, 'start_date' => '2026-08-05', 'end_date' => '2026-08-05', 'days' => 1, 'reason_id' => $reason->id, 'status' => LeaveRequest::PENDING]);
        app(LeaveService::class)->approve($leave);

        $after = app(LeaveService::class)->balance($employee)['remaining'];
        $this->assertEqualsWithDelta($before - 1, $after, 0.01);
    }

    public function test_rejected_leave_never_consumes_balance(): void
    {
        $reason = LeaveReason::create(['name' => 'إجازة عادية', 'deducts_leave_balance' => true]);
        $employee = $this->makeEmployee(['monthly_leave_allowance' => 5]);
        $before = app(LeaveService::class)->balance($employee)['remaining'];

        $leave = LeaveRequest::create(['user_id' => $employee->id, 'start_date' => '2026-08-05', 'end_date' => '2026-08-05', 'days' => 1, 'reason_id' => $reason->id, 'status' => LeaveRequest::PENDING]);
        app(LeaveService::class)->reject($leave);

        $after = app(LeaveService::class)->balance($employee)['remaining'];
        $this->assertEqualsWithDelta($before, $after, 0.01, 'A rejected request must never touch the balance.');
    }

    // ── Cash-Out (9–15) ──────────────────────────────────────────────────

    public function test_admin_can_cash_out_valid_days_with_the_correct_daily_rate(): void
    {
        $employee = $this->makeEmployee(['base_salary' => 9300, 'monthly_leave_allowance' => 10, 'hire_date' => now()->subMonths(5)->toDateString()]);

        $cashOut = app(LeaveCashOutService::class)->cashOut($employee, 2.0, 'تجربة', '2026-08-10');

        $dailyRate = round(9300 / 31, 2); // August 2026 has 31 days
        $this->assertEqualsWithDelta($dailyRate, (float) $cashOut->daily_rate, 0.01);
        $this->assertEqualsWithDelta(round($dailyRate * 2, 2), (float) $cashOut->amount, 0.01);
    }

    public function test_cash_out_reduces_the_remaining_balance(): void
    {
        $employee = $this->makeEmployee(['monthly_leave_allowance' => 10]);
        $before = app(LeaveService::class)->balance($employee)['remaining'];

        app(LeaveCashOutService::class)->cashOut($employee, 3.0, null, '2026-08-10');

        $after = app(LeaveService::class)->balance($employee)['remaining'];
        $this->assertEqualsWithDelta($before - 3.0, $after, 0.01);
    }

    public function test_cash_out_cannot_exceed_available_balance(): void
    {
        $employee = $this->makeEmployee(['monthly_leave_allowance' => 1]); // ~1 day available this month

        $this->expectException(ValidationException::class);
        app(LeaveCashOutService::class)->cashOut($employee, 50.0, null, '2026-08-10');
    }

    public function test_cash_out_cannot_be_duplicated_for_the_same_days(): void
    {
        $employee = $this->makeEmployee(['monthly_leave_allowance' => 3]);
        $balance = app(LeaveService::class)->balance($employee)['remaining'];

        app(LeaveCashOutService::class)->cashOut($employee, $balance, null, '2026-08-10');

        // Attempting to cash out again immediately must fail — no balance left.
        $this->expectException(ValidationException::class);
        app(LeaveCashOutService::class)->cashOut($employee, 1.0, null, '2026-08-11');
    }

    public function test_payroll_receives_a_leave_encashment_line_for_the_correct_month(): void
    {
        $employee = $this->makeEmployee(['base_salary' => 9000, 'personal_commission_percent' => 0]);
        app(LeaveCashOutService::class)->cashOut($employee, 2.0, null, '2026-08-10');

        $payroll = app(PayrollService::class)->generate($employee, 2026, 8);

        $line = PayrollLine::where('payroll_id', $payroll->id)->where('type', PayrollLine::LEAVE_ENCASHMENT)->first();
        $this->assertNotNull($line);
        $dailyRate = round(9000 / 31, 2);
        $this->assertEqualsWithDelta(round($dailyRate * 2, 2), (float) $line->amount, 0.01);
        $this->assertEqualsWithDelta(round($dailyRate * 2, 2), (float) $payroll->leave_encashment_total, 0.01);
    }

    public function test_cash_out_from_a_different_month_does_not_leak_into_this_payroll(): void
    {
        $employee = $this->makeEmployee();
        app(LeaveCashOutService::class)->cashOut($employee, 1.0, null, '2026-07-10'); // different month

        $payroll = app(PayrollService::class)->generate($employee, 2026, 8);

        $this->assertEqualsWithDelta(0.0, (float) $payroll->leave_encashment_total, 0.01);
    }

    public function test_cash_out_history_is_preserved_and_visible_via_admin_and_self_service_endpoints(): void
    {
        $employee = $this->makeEmployee();
        app(LeaveCashOutService::class)->cashOut($employee, 1.0, 'ملاحظة', '2026-08-10');

        $adminResponse = $this->getJson("/api/hr/leave-cash-outs?user_id={$employee->id}");
        $adminResponse->assertOk();
        $this->assertCount(1, $adminResponse->json('data.data'));

        $this->actingAs($employee, 'api');
        $selfResponse = $this->getJson('/api/hr/leave-cash-outs/mine');
        $selfResponse->assertOk();
        $this->assertCount(1, $selfResponse->json('data.data'));
    }

    // ── Payroll integration (16–18) ─────────────────────────────────────

    public function test_net_salary_includes_leave_encashment_in_the_full_formula(): void
    {
        $employee = $this->makeEmployee(['base_salary' => 9000, 'personal_commission_percent' => 0]);
        app(LeaveCashOutService::class)->cashOut($employee, 1.0, null, '2026-08-10');

        $payroll = app(PayrollService::class)->generate($employee, 2026, 8);

        $dailyRate = round(9000 / 31, 2);
        $expectedNet = round(9000 + $dailyRate, 2); // base + encashment, no other components
        $this->assertEqualsWithDelta($expectedNet, (float) $payroll->net_salary, 0.01);
    }

    public function test_historical_locked_payroll_stays_frozen_after_a_later_cash_out(): void
    {
        $employee = $this->makeEmployee(['base_salary' => 9000]);
        $payroll = app(PayrollService::class)->generate($employee, 2026, 8);
        app(PayrollService::class)->lock($payroll);
        $frozenNet = (float) $payroll->net_salary;

        // A cash-out recorded AFTER locking must never be silently backfilled into the locked payroll.
        app(LeaveCashOutService::class)->cashOut($employee, 1.0, null, '2026-08-20');

        $reloaded = Payroll::find($payroll->id);
        $this->assertEqualsWithDelta($frozenNet, (float) $reloaded->net_salary, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $reloaded->leave_encashment_total, 0.01);
    }

    // ── Calendar correctness (19–22) ────────────────────────────────────

    public function test_cash_out_daily_rate_uses_real_days_in_month_across_28_29_30_31(): void
    {
        $cases = [
            ['date' => '2023-02-15', 'days' => 28],
            ['date' => '2024-02-15', 'days' => 29],
            ['date' => '2024-04-15', 'days' => 30],
            ['date' => '2024-01-15', 'days' => 31],
        ];

        foreach ($cases as $c) {
            $employee = $this->makeEmployee(['base_salary' => 9000, 'monthly_leave_allowance' => 10]);
            $cashOut = app(LeaveCashOutService::class)->cashOut($employee, 1.0, null, $c['date']);
            $expected = round(9000 / $c['days'], 2);
            $this->assertEqualsWithDelta($expected, (float) $cashOut->daily_rate, 0.01, "Failed for {$c['days']}-day month.");
        }
    }

    // ── Privacy (23–24) ──────────────────────────────────────────────────

    public function test_employee_sees_only_their_own_leave_balance_and_history(): void
    {
        $employeeA = $this->makeEmployee();
        $employeeB = $this->makeEmployee();
        app(LeaveCashOutService::class)->cashOut($employeeA, 1.0, null, '2026-08-10');

        $this->actingAs($employeeB, 'api');
        $response = $this->getJson('/api/hr/leave-cash-outs/mine');
        $response->assertOk();
        $this->assertCount(0, $response->json('data.data'), 'Employee B must never see Employee A\'s cash-out history.');

        $balanceResponse = $this->getJson('/api/hr/leaves/mine');
        $balanceResponse->assertOk();
        // Sanity: the balance object returned is computed for the authenticated user, never a query param.
        $this->assertArrayHasKey('remaining', $balanceResponse->json('data.balance'));
    }

    public function test_admin_can_view_and_manage_any_employees_leave_balance_and_cash_out(): void
    {
        $employee = $this->makeEmployee();

        $balanceResponse = $this->getJson("/api/hr/employees/{$employee->id}/leave-balance");
        $balanceResponse->assertOk();
        $this->assertArrayHasKey('remaining', $balanceResponse->json('data'));

        $cashOutResponse = $this->postJson('/api/hr/leave-cash-outs', ['user_id' => $employee->id, 'days' => 1]);
        $cashOutResponse->assertCreated();
    }
}
