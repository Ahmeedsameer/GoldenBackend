<?php

use App\Http\Controllers\Admin\AdminSalesReportController;
use App\Http\Controllers\Admin\AdminPaymentMethodReportController;
use App\Http\Controllers\Admin\AdminFinancialReportController;
use App\Http\Controllers\Admin\AdminPerfumeReportController;
use App\Http\Controllers\Admin\AdminBranchComparisonController;
use App\Http\Controllers\Admin\AdminMonthlyProfitController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminStockIntelligenceController;
use App\Http\Controllers\Admin\ShopController;
use App\Http\Controllers\Admin\ShopEmployeeController;
use App\Http\Controllers\Admin\ShopManagerController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CompanySettingsController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductTypeController;
use App\Http\Controllers\UsersManagmentController;
use App\Http\Middleware\CheckRole;
use App\Modules\Safe\Controllers\CurrencyController;
use App\Modules\Safe\Controllers\SafeController;
use App\Modules\Safe\Controllers\SafeManagementController;
use App\Modules\Safe\Controllers\SafeTypeController;
use App\Modules\Safe\Controllers\PaymentMethodController;
use App\Modules\Safe\Controllers\TransactionReasonController;
use App\Modules\Convention\Controllers\ConventionController;
use App\Modules\Convention\Controllers\ConventionTransactionController;
use App\Modules\Convention\Controllers\ManagerConventionController;
use App\Modules\Convention\Controllers\NotificationController;
use App\Modules\Pricing\Controllers\PricingController;
use App\Modules\BranchOperations\Controllers\TransferRequestController;
use App\Modules\BranchOperations\Controllers\RequiredMaterialsController;
use App\Modules\BranchOperations\Controllers\GlobalMaterialStatusController;
use App\Modules\BranchOperations\Controllers\WasteController;
use App\Modules\BranchOperations\Controllers\InventoryAdjustmentController;
use App\Modules\BranchOperations\Controllers\InventoryCountController;
use App\Modules\BranchOperations\Controllers\BranchDashboardController;
use App\Modules\BranchOperations\Controllers\AdminLogisticsDashboardController;
use App\Modules\BranchOperations\Controllers\InternalTransferInvoiceController;
use App\Modules\BranchOperations\Controllers\TransferReportController;
use App\Modules\BranchOperations\Controllers\WasteReportController;
use App\Modules\BranchOperations\Controllers\InventoryCountReportController;
use App\Modules\BranchOperations\Controllers\InventoryAdjustmentReportController;
use App\Modules\BranchOperations\Controllers\StockMovementReportController;
use App\Modules\BranchOperations\Controllers\BatchTraceabilityController;
use App\Modules\BranchOperations\Controllers\InventoryAuditReportController;
use App\Modules\Sales\Controllers\CashierController;
use App\Modules\Sales\Controllers\InvoiceController;
use App\Modules\Sales\Controllers\ManagerOverrideController;
use App\Modules\Sales\Controllers\OverrideRequestController;
use App\Modules\Sales\Controllers\ReportsController;
use App\Modules\Stock\Controllers\InventoryController;
use App\Modules\Stock\Controllers\ManagerInventoryController;
use App\Modules\Stock\Controllers\SupplierController;
use App\Modules\Stock\Controllers\SupplierPaymentController;
use App\Modules\Stock\Controllers\SupplierReportController;
use App\Modules\Stock\Controllers\SupplyController;


// ─── Auth ─────────────────────────────────────────────────────────────────────

Route::group(['middleware' => 'api', 'prefix' => 'auth'], function () {
    Route::post('login',   [AuthController::class, 'login']);
    Route::post('logout',  [AuthController::class, 'logout']);
    Route::post('refresh', [AuthController::class, 'refresh']);
    Route::post('me',      [AuthController::class, 'me']);
});


// ─── Company branding (public — needed by the login page and app-init, pre-auth) ─

Route::get('company-settings', [CompanySettingsController::class, 'show']);


// ─── Notifications (any authenticated user) ─────────────────────────────────────

Route::group(['middleware' => ['api', CheckRole::class . ':*'], 'prefix' => 'notifications'], function () {
    Route::get('',              [NotificationController::class, 'index']);
    Route::get('unread-count',  [NotificationController::class, 'unreadCount']);
    Route::get('vapid-key',     [NotificationController::class, 'vapidKey']);
    Route::put('read-all',      [NotificationController::class, 'markAllRead']);
    Route::post('subscribe',    [NotificationController::class, 'subscribe']);
    Route::post('unsubscribe',  [NotificationController::class, 'unsubscribe']);
    Route::post('test',         [NotificationController::class, 'test']);
    Route::put('{id}/read',     [NotificationController::class, 'markRead']);
});


// ─── Admin ────────────────────────────────────────────────────────────────────

Route::group(['middleware' => ['api', CheckRole::class . ':admin']], function () {

    // ── User management ──────────────────────────────────────────────────────
    Route::group(['prefix' => 'user-managment'], function () {
        Route::get('list',          [UsersManagmentController::class, 'index']);
        Route::get('search',        [UsersManagmentController::class, 'search']);
        Route::post('create',       [UsersManagmentController::class, 'store']);
        Route::get('show/{id}',     [UsersManagmentController::class, 'show']);
        // Always scoped to the authenticated admin's own account — no :id param.
        Route::put('profile',           [UsersManagmentController::class, 'updateProfile']);
        Route::put('change-password',   [UsersManagmentController::class, 'changePassword']);
        // Admin resetting another user's password — new password only, never reads the old one.
        Route::put('{id}/reset-password', [UsersManagmentController::class, 'resetPassword']);
        // Deactivate/reactivate an admin account — never a hard delete (see UsersManagmentController::toggleStatus).
        Route::put('{id}/toggle-status', [UsersManagmentController::class, 'toggleStatus']);
    });

    // ── Product Types (inventory behavior: Oil, Bottle, Accessory, Packaging) ─
    Route::get('product-types', [ProductTypeController::class, 'index']);

    // ── Company branding settings (logo upload via POST — multipart) ─────────
    Route::post('company-settings', [CompanySettingsController::class, 'update']);

    // ── Invoice review (pending queue → approve / reject) ────────────────────
    Route::group(['prefix' => 'admin/invoices'], function () {
        Route::get('',            [\App\Modules\Sales\Controllers\AdminInvoiceController::class, 'index']);
        Route::get('{id}',        [\App\Modules\Sales\Controllers\AdminInvoiceController::class, 'show']);
        Route::put('{id}/status', [\App\Modules\Sales\Controllers\AdminInvoiceController::class, 'updateStatus']);
        Route::post('{id}/cancel', [\App\Modules\Sales\Controllers\AdminInvoiceController::class, 'cancel']);
        Route::put('{id}/edit', [\App\Modules\Sales\Controllers\AdminInvoiceController::class, 'edit']);
        Route::put('{id}/edit/preview', [\App\Modules\Sales\Controllers\AdminInvoiceController::class, 'previewEdit']);
    });

    // ── Unified cross-module invoice timeline (sales + purchase + internal transfer) ──
    Route::get('admin/all-invoices', [\App\Http\Controllers\Admin\AdminAllInvoicesController::class, 'index']);

    // ── Categories ───────────────────────────────────────────────────────────
    Route::group(['prefix' => 'categories'], function () {
        Route::get('list',           [CategoryController::class, 'index']);
        Route::post('create',        [CategoryController::class, 'store']);
        Route::get('show/{id}',      [CategoryController::class, 'show']);
        Route::post('update/{id}',   [CategoryController::class, 'update']);
        Route::post('destroy/{id}',  [CategoryController::class, 'destroy']);
    });

    // ── Products ─────────────────────────────────────────────────────────────
    // Listing itself is registered outside this admin-only group — see below —
    // since branch managers also need to read the product catalog for transfers.
    Route::group(['prefix' => 'products'], function () {
        // Four independent Product Creation Forms — each its own request
        // class/validation, called from its own dedicated frontend page.
        // (No generic POST '' create route — superseded entirely by these four.)
        Route::post('raw-materials',   [ProductController::class, 'storeRawMaterial']);
        Route::post('packaging',       [ProductController::class, 'storePackaging']);
        Route::post('ready-products',  [ProductController::class, 'storeReadyProduct']);
        Route::post('compounds',       [ProductController::class, 'storeCompound']);
        // Registered before the {id} wildcard so the literal path always wins.
        Route::get('oil-options', [ProductController::class, 'oilOptions']);
        Route::get('{id}',    [ProductController::class, 'show']);
        Route::put('{id}',    [ProductController::class, 'update']);
        // Products are never physically deleted — archive/restore only (see
        // ProductController::archive()/restore() and Product::scopeNotArchived()).
        Route::post('{id}/archive', [ProductController::class, 'archive']);
        Route::post('{id}/restore', [ProductController::class, 'restore']);
        // BOM / recipe (components of a composed product)
        Route::get('{id}/components', [\App\Http\Controllers\ProductComponentController::class, 'index']);
        Route::put('{id}/components', [\App\Http\Controllers\ProductComponentController::class, 'sync']);

        // Product Details page
        Route::get('{id}/detail',           [ProductController::class, 'detail']);
        Route::get('{id}/purchase-history', [ProductController::class, 'purchaseHistory']);
        Route::get('{id}/purchase-history/export', [ProductController::class, 'exportPurchaseHistory']);
        Route::get('{id}/movements',        [ProductController::class, 'movements']);
        Route::get('{id}/supplier-history', [ProductController::class, 'supplierHistory']);
        Route::get('{id}/analytics',        [ProductController::class, 'analytics']);
    });

    // ── Shops ─────────────────────────────────────────────────────────────────
    Route::group(['prefix' => 'shops'], function () {

        // CRUD (listing itself is registered outside this admin-only group — see below —
        // since branch managers also need to read shop names for transfer creation/filters).
        Route::post('create',                   [ShopController::class, 'store']);
        Route::get('show/{id}',                 [ShopController::class, 'show']);
        Route::put('update/{id}',               [ShopController::class, 'update']);
        Route::delete('destroy/{id}',           [ShopController::class, 'destroy']);
        Route::get('check-username/{username}', [ShopController::class, 'checkUsername']);

        // Manager
        Route::post('{id}/manager/assign',   [ShopManagerController::class, 'assign']);
        Route::delete('{id}/manager/remove', [ShopManagerController::class, 'remove']);

        // Employees
        Route::get('{id}/employees',             [ShopEmployeeController::class, 'index']);
        Route::post('{id}/employees/add',        [ShopEmployeeController::class, 'add']);
        Route::delete('{id}/employees/{userId}', [ShopEmployeeController::class, 'remove']);
    });

    // ── Stock & Inventory ─────────────────────────────────────────────────────
    Route::group(['prefix' => 'stock'], function () {

        // Suppliers
        Route::get('suppliers',                 [SupplierController::class, 'index']);
        Route::post('suppliers/create',         [SupplierController::class, 'store']);
        Route::get('suppliers/show/{id}',       [SupplierController::class, 'show']);
        Route::put('suppliers/update/{id}',     [SupplierController::class, 'update']);
        Route::delete('suppliers/destroy/{id}', [SupplierController::class, 'destroy']);

        // Supplier Profile page
        Route::get('suppliers/insights',            [SupplierController::class, 'insights']);
        Route::get('suppliers/{id}/profile',           [SupplierController::class, 'profile']);
        Route::get('suppliers/{id}/profile/products',  [SupplierController::class, 'profileProducts']);
        Route::get('suppliers/{id}/profile/analytics', [SupplierController::class, 'profileAnalytics']);
        Route::get('suppliers/{id}/profile/export',    [SupplierController::class, 'exportProfile']);

        // Supplier Contacts — no limit per supplier
        Route::get('suppliers/{id}/contacts',                [SupplierController::class, 'contacts']);
        Route::post('suppliers/{id}/contacts',                [SupplierController::class, 'storeContact']);
        Route::put('suppliers/{id}/contacts/{contactId}',     [SupplierController::class, 'updateContact']);
        Route::delete('suppliers/{id}/contacts/{contactId}',  [SupplierController::class, 'destroyContact']);

        // Supplier Ledger — purchase history, payment history, balances (own page/tab)
        Route::get('suppliers/{id}/ledger', [SupplierController::class, 'ledger']);

        // Supplier Payments — always against exactly one invoice; money moves through the existing Safe system
        Route::get('supplier-payments',  [SupplierPaymentController::class, 'index']);
        Route::post('supplier-payments', [SupplierPaymentController::class, 'store']);

        // Supplier Reports (cross-supplier) — registered before any suppliers/{id}/* wildcard so the literal path always wins
        Route::get('suppliers/reports/balances',    [SupplierReportController::class, 'balances']);
        Route::get('suppliers/reports/outstanding', [SupplierReportController::class, 'outstanding']);
        Route::get('suppliers/reports/purchases',   [SupplierReportController::class, 'purchases']);
        Route::get('suppliers/reports/payments',    [SupplierReportController::class, 'payments']);

        // Supplies (create/update/destroy stay admin-only; index/show/cancel are
        // shared with managers — see the 'admin,manager' stock group below)
        Route::get('supplier-intelligence',     [SupplyController::class, 'supplierIntelligence']);
        Route::post('supplies/create',          [SupplyController::class, 'store']);
        Route::put('supplies/update/{id}',      [SupplyController::class, 'update']);
        Route::delete('supplies/destroy/{id}',  [SupplyController::class, 'destroy']);

        // Inventory exploration & transfers
        Route::get('inventory',           [InventoryController::class, 'index']);
        Route::post('inventory/transfer', [InventoryController::class, 'transfer']);
    });

    // ── Currencies ────────────────────────────────────────────────────────────
    Route::group(['prefix' => 'currencies'], function () {
        Route::get('',       [CurrencyController::class, 'index']);
        Route::post('',      [CurrencyController::class, 'store']);
        Route::put('{id}',   [CurrencyController::class, 'update']);
    });

    // ── Safe Types ────────────────────────────────────────────────────────────
    Route::group(['prefix' => 'safe-types'], function () {
        Route::get('',       [SafeTypeController::class, 'index']);
        Route::post('',      [SafeTypeController::class, 'store']);
        Route::put('{id}',   [SafeTypeController::class, 'update']);
    });

    // ── Transaction Reasons ───────────────────────────────────────────────────
    Route::group(['prefix' => 'transaction-reasons'], function () {
        Route::get('',       [TransactionReasonController::class, 'index']);
        Route::post('',      [TransactionReasonController::class, 'store']);
        Route::put('{id}',   [TransactionReasonController::class, 'update']);
    });

    // ── Safe Management (admin: create & toggle safes) ────────────────────────
    Route::group(['prefix' => 'safe-management'], function () {
        Route::get('',               [SafeManagementController::class, 'index']);
        Route::post('',              [SafeManagementController::class, 'store']);
        Route::put('{id}/toggle',    [SafeManagementController::class, 'toggle']);
    });

    // ── Payment Methods (admin: unlimited, replaces the old hardcoded cash/visa enum) ──
    Route::group(['prefix' => 'payment-methods'], function () {
        Route::get('',            [PaymentMethodController::class, 'index']);
        Route::post('',           [PaymentMethodController::class, 'store']);
        Route::put('{id}',        [PaymentMethodController::class, 'update']);
        Route::put('{id}/toggle', [PaymentMethodController::class, 'toggle']);
    });

    // ── Payment Methods reports (Payment Method / Card Fees / Bank Charges / Branch / Currency) ────
    Route::group(['prefix' => 'admin/reports/payment-methods'], function () {
        Route::get('',                 [AdminPaymentMethodReportController::class, 'paymentMethods']);
        Route::get('card-fees',        [AdminPaymentMethodReportController::class, 'cardFees']);
        Route::get('bank-charges',     [AdminPaymentMethodReportController::class, 'bankCharges']);
        Route::get('branch-payments',  [AdminPaymentMethodReportController::class, 'branchPayments']);
        Route::get('currency',         [AdminPaymentMethodReportController::class, 'currencyReport']);
        Route::get('safe-balance',     [AdminPaymentMethodReportController::class, 'balanceByPaymentMethod']);
        Route::get('child-transfers',  [AdminPaymentMethodReportController::class, 'childSafeTransfers']);
    });

    // ── Admin Home Dashboard ──────────────────────────────────────────────────────
    Route::get('admin/dashboard', [AdminDashboardController::class, 'index']);

    // ── Sales Reports (admin: cross-shop) ────────────────────────────────────────
    Route::group(['prefix' => 'admin/reports/sales'], function () {
        Route::get('summary',     [AdminSalesReportController::class, 'summary']);
        Route::get('trend',       [AdminSalesReportController::class, 'trend']);
        Route::get('by-shop',     [AdminSalesReportController::class, 'byShop']);
        Route::get('by-seller',   [AdminSalesReportController::class, 'bySeller']);
        Route::get('by-product',  [AdminSalesReportController::class, 'byProduct']);
        Route::get('by-category', [AdminSalesReportController::class, 'byCategory']);
        Route::get('customers',   [AdminSalesReportController::class, 'customers']);
        Route::get('hourly',      [AdminSalesReportController::class, 'hourly']);
        Route::get('invoices',    [AdminSalesReportController::class, 'invoices']);
        Route::get('export',     [AdminSalesReportController::class, 'export']);
    });

    // ── Financial Reports (admin: cross-shop) ────────────────────────────────────
    Route::group(['prefix' => 'admin/reports/financial'], function () {
        Route::get('summary',      [AdminFinancialReportController::class, 'summary']);
        Route::get('trend',        [AdminFinancialReportController::class, 'trend']);
        Route::get('by-shop',      [AdminFinancialReportController::class, 'byShop']);
        Route::get('balances',     [AdminFinancialReportController::class, 'balances']);
        Route::get('transactions', [AdminFinancialReportController::class, 'transactions']);
        Route::get('export',       [AdminFinancialReportController::class, 'export']);
    });

    // ── Perfume Composition Reports — real oil/bottle usage across Compound sales ─
    Route::group(['prefix' => 'admin/reports/perfume'], function () {
        Route::get('summary', [AdminPerfumeReportController::class, 'summary']);
        Route::get('trend',   [AdminPerfumeReportController::class, 'trend']);
        Route::get('export',  [AdminPerfumeReportController::class, 'export']);
    });

    // ── Branch Comparison — revenue/profit/top seller/top oil-bottle per shop ────
    Route::group(['prefix' => 'admin/reports/branch-comparison'], function () {
        Route::get('', [AdminBranchComparisonController::class, 'compare']);
        Route::get('export', [AdminBranchComparisonController::class, 'export']);
    });

    // ── Monthly Profit — revenue vs. estimated cost trend ────────────────────────
    Route::group(['prefix' => 'admin/reports/monthly-profit'], function () {
        Route::get('', [AdminMonthlyProfitController::class, 'trend']);
        Route::get('export', [AdminMonthlyProfitController::class, 'export']);
    });

    // ── Branch Required Materials — admin cross-branch report (manager's own-branch
    // endpoint is in the manager-role group below) ──────────────────────────────
    Route::group(['prefix' => 'admin/reports/required-materials'], function () {
        Route::get('', [RequiredMaterialsController::class, 'report']);
    });

    // ── Global Branch Material Status — admin, cross-branch (By Branch / By Material) ──
    Route::group(['prefix' => 'admin/inventory/branch-material-status'], function () {
        Route::get('by-branch',   [GlobalMaterialStatusController::class, 'byBranch']);
        Route::get('by-material', [GlobalMaterialStatusController::class, 'byMaterial']);
    });
    Route::group(['prefix' => 'admin/reports/branch-material-status'], function () {
        Route::get('', [GlobalMaterialStatusController::class, 'report']);
    });

    // ── Stock Intelligence (admin: cross-shop) ───────────────────────────────────
    Route::group(['prefix' => 'admin/stock-intelligence'], function () {
        Route::get('dashboard', [AdminStockIntelligenceController::class, 'dashboard']);
        Route::get('inventory', [AdminStockIntelligenceController::class, 'inventory']);
        Route::get('supplies',  [AdminStockIntelligenceController::class, 'supplies']);
        Route::get('export',    [AdminStockIntelligenceController::class, 'export']);
    });

    // ── Conventions / Cash Advance (عهدة) ──────────────────────────────────────
    Route::group(['prefix' => 'conventions'], function () {

        // CRUD
        Route::get('',                [ConventionController::class, 'index']);
        Route::get('shop/{shopId}',   [ConventionController::class, 'byShop']);
        Route::post('create',         [ConventionController::class, 'store']);
        Route::get('show/{id}',       [ConventionController::class, 'show']);
        Route::put('update/{id}',     [ConventionController::class, 'update']);
        Route::delete('destroy/{id}', [ConventionController::class, 'destroy']);

        // Transactions
        Route::get('{id}/transactions',      [ConventionTransactionController::class, 'index']);
        Route::post('{id}/transactions',     [ConventionTransactionController::class, 'store']);
        Route::put('transactions/{txId}',    [ConventionTransactionController::class, 'update']);
        Route::delete('transactions/{txId}', [ConventionTransactionController::class, 'destroy']);
    });

    // ── Safe Operations (admin: any safe) ─────────────────────────────────────
    Route::group(['prefix' => 'safe'], function () {
        Route::get('shops/{shopId}',              [SafeController::class, 'adminShopSafes']);
        Route::get('{safeId}',                    [SafeController::class, 'adminShowSafe']);
        Route::get('{safeId}/transactions',       [SafeController::class, 'adminTransactions']);
        Route::post('{safeId}/deposit',           [SafeController::class, 'adminDeposit']);
        Route::post('{safeId}/withdraw',          [SafeController::class, 'adminWithdraw']);
        Route::post('transfer',                   [SafeController::class, 'transfer']);
    });

    // ── HR & Payroll — Employee management (admin only) ───────────────────────
    Route::group(['prefix' => 'hr'], function () {
        Route::get('employees',                    [\App\Modules\Hr\Controllers\EmployeeController::class, 'index']);
        Route::post('employees',                   [\App\Modules\Hr\Controllers\EmployeeController::class, 'store']);
        Route::get('employees/{id}',               [\App\Modules\Hr\Controllers\EmployeeController::class, 'show']);
        Route::put('employees/{id}',               [\App\Modules\Hr\Controllers\EmployeeController::class, 'update']);
        Route::put('employees/{id}/toggle-status',   [\App\Modules\Hr\Controllers\EmployeeController::class, 'toggleStatus']);
        Route::post('employees/{id}/end-employment/preview', [\App\Modules\Hr\Controllers\EmployeeController::class, 'previewEndEmployment']);
        Route::put('employees/{id}/end-employment',  [\App\Modules\Hr\Controllers\EmployeeController::class, 'endEmployment']);
        Route::get('employees/{id}/timeline',      [\App\Modules\Hr\Controllers\SelfServiceController::class, 'timelineFor']);

        // Leave review (approve/reject) — admin only
        Route::put('leaves/{id}/approve', [\App\Modules\Hr\Controllers\LeaveController::class, 'approve']);
        Route::put('leaves/{id}/reject',  [\App\Modules\Hr\Controllers\LeaveController::class, 'reject']);

        // Bonuses & Penalties — admin only (create/edit/delete)
        Route::get('bonuses',          [\App\Modules\Hr\Controllers\BonusPenaltyController::class, 'bonusIndex']);
        Route::post('bonuses',         [\App\Modules\Hr\Controllers\BonusPenaltyController::class, 'bonusStore']);
        Route::put('bonuses/{id}',     [\App\Modules\Hr\Controllers\BonusPenaltyController::class, 'bonusUpdate']);
        Route::delete('bonuses/{id}',  [\App\Modules\Hr\Controllers\BonusPenaltyController::class, 'bonusDestroy']);
        Route::get('penalties',         [\App\Modules\Hr\Controllers\BonusPenaltyController::class, 'penaltyIndex']);
        Route::post('penalties',        [\App\Modules\Hr\Controllers\BonusPenaltyController::class, 'penaltyStore']);
        Route::put('penalties/{id}',    [\App\Modules\Hr\Controllers\BonusPenaltyController::class, 'penaltyUpdate']);
        Route::delete('penalties/{id}', [\App\Modules\Hr\Controllers\BonusPenaltyController::class, 'penaltyDestroy']);

        // Salary advances — review/approve/plan is admin only
        Route::put('advances/{id}/reject',      [\App\Modules\Hr\Controllers\SalaryAdvanceController::class, 'reject']);
        Route::put('advances/{id}/approve',     [\App\Modules\Hr\Controllers\SalaryAdvanceController::class, 'approve']);
        Route::put('advances/{id}/cancel',      [\App\Modules\Hr\Controllers\SalaryAdvanceController::class, 'cancel']);
        Route::put('advances/{id}/plan',        [\App\Modules\Hr\Controllers\SalaryAdvanceController::class, 'updatePlan']);
        Route::post('advances/{id}/repayments', [\App\Modules\Hr\Controllers\SalaryAdvanceController::class, 'storeRepayment']);
        Route::put('advances/{id}/default-safe', [\App\Modules\Hr\Controllers\SalaryAdvanceController::class, 'changeDefaultSafe']);
        Route::get('advances/{id}/transactions', [\App\Modules\Hr\Controllers\SalaryAdvanceController::class, 'transactions']);

        // Phase 6.3 — dedicated Salary Advance report (admin only)
        Route::get('reports/advances/export', [\App\Modules\Hr\Controllers\SalaryAdvanceReportController::class, 'export']);
        Route::get('reports/advances',        [\App\Modules\Hr\Controllers\SalaryAdvanceReportController::class, 'data']);

        // Payroll + Treasury integration — dedicated reports (admin only)
        Route::get('reports/payroll/export',            [\App\Modules\Hr\Controllers\PayrollReportController::class, 'export']);
        Route::get('reports/payroll',                    [\App\Modules\Hr\Controllers\PayrollReportController::class, 'data']);
        Route::get('reports/salary-payments/export',     [\App\Modules\Hr\Controllers\SalaryPaymentReportController::class, 'export']);
        Route::get('reports/salary-payments',             [\App\Modules\Hr\Controllers\SalaryPaymentReportController::class, 'data']);
        Route::get('reports/advance-installments/export', [\App\Modules\Hr\Controllers\AdvanceInstallmentReportController::class, 'export']);
        Route::get('reports/advance-installments',         [\App\Modules\Hr\Controllers\AdvanceInstallmentReportController::class, 'data']);
        Route::get('reports/leave-deductions/export',     [\App\Modules\Hr\Controllers\LeaveDeductionReportController::class, 'export']);
        Route::get('reports/leave-deductions',             [\App\Modules\Hr\Controllers\LeaveDeductionReportController::class, 'data']);

        // Deduction settings (configurable)
        Route::get('deduction-settings',       [\App\Modules\Hr\Controllers\DeductionSettingController::class, 'index']);
        Route::put('deduction-settings/{id}',  [\App\Modules\Hr\Controllers\DeductionSettingController::class, 'update']);

        // Payroll engine (generate / lock / unlock / paid) — admin only
        Route::post('payrolls/generate',    [\App\Modules\Hr\Controllers\PayrollController::class, 'generate']);
        Route::put('payrolls/{id}/lock',    [\App\Modules\Hr\Controllers\PayrollController::class, 'lock']);
        Route::put('payrolls/{id}/unlock',  [\App\Modules\Hr\Controllers\PayrollController::class, 'unlock']);
        Route::put('payrolls/{id}/paid',    [\App\Modules\Hr\Controllers\PayrollController::class, 'markPaid']);
        Route::put('payrolls/{id}/pay',     [\App\Modules\Hr\Controllers\PayrollController::class, 'pay']);
        Route::post('payrolls/pay-all',     [\App\Modules\Hr\Controllers\PayrollController::class, 'payAll']);
        Route::get('payrolls/summary',      [\App\Modules\Hr\Controllers\PayrollController::class, 'summary']);
        Route::get('payrolls/{id}/transactions', [\App\Modules\Hr\Controllers\PayrollController::class, 'transactions']);

        // Final Settlement documents — read-only, admin only (created by end-employment above)
        Route::get('settlements',      [\App\Modules\Hr\Controllers\SettlementController::class, 'index']);
        Route::get('settlements/{id}', [\App\Modules\Hr\Controllers\SettlementController::class, 'show']);

        // Employee temporary transfers
        Route::get('transfers',              [\App\Modules\Hr\Controllers\TransferController::class, 'index']);
        Route::post('transfers',             [\App\Modules\Hr\Controllers\TransferController::class, 'store']);
        Route::get('transfers/{id}',         [\App\Modules\Hr\Controllers\TransferController::class, 'show']);
        Route::put('transfers/{id}',         [\App\Modules\Hr\Controllers\TransferController::class, 'update']);
        Route::put('transfers/{id}/approve', [\App\Modules\Hr\Controllers\TransferController::class, 'approve']);
        Route::put('transfers/{id}/cancel',  [\App\Modules\Hr\Controllers\TransferController::class, 'cancel']);

        // Shift templates management (Weekly Schedule)
        Route::post('shift-templates',              [\App\Modules\Hr\Controllers\ScheduleController::class, 'storeShiftTemplate']);
        Route::get('shift-templates/all',            [\App\Modules\Hr\Controllers\ScheduleController::class, 'allShiftTemplates']);
        Route::put('shift-templates/{id}',           [\App\Modules\Hr\Controllers\ScheduleController::class, 'updateShiftTemplate']);
        Route::put('shift-templates/{id}/archive',   [\App\Modules\Hr\Controllers\ScheduleController::class, 'archiveShiftTemplate']);
        Route::put('shift-templates/{id}/restore',   [\App\Modules\Hr\Controllers\ScheduleController::class, 'restoreShiftTemplate']);
        Route::delete('shift-templates/{id}',        [\App\Modules\Hr\Controllers\ScheduleController::class, 'destroyShiftTemplate']);
    });

});


// ─── Manager ──────────────────────────────────────────────────────────────────

Route::group(['middleware' => ['api', CheckRole::class . ':manager'], 'prefix' => 'manager'], function () {

    // ── Safe (own shop only) ──────────────────────────────────────────────────
    Route::get('safe/my-shop',                              [SafeController::class, 'managerShopSafes']);
    Route::get('safe/my-shop/{safeId}/transactions',        [SafeController::class, 'managerTransactions']);
    Route::post('safe/my-shop/{safeId}/deposit',            [SafeController::class, 'managerDeposit']);
    Route::post('safe/my-shop/{safeId}/withdraw',           [SafeController::class, 'managerWithdraw']);

    // ── Conventions (own branch only) ─────────────────────────────────────────
    Route::get('conventions',                   [ManagerConventionController::class, 'index']);
    Route::get('conventions/{id}',              [ManagerConventionController::class, 'show']);
    Route::get('conventions/{id}/transactions', [ManagerConventionController::class, 'transactions']);
    Route::post('conventions/{id}/withdraw',    [ManagerConventionController::class, 'withdraw']);

    // ── Read-only lookups ─────────────────────────────────────────────────────
    Route::get('currencies',          [CurrencyController::class, 'index']);
    Route::get('transaction-reasons', [TransactionReasonController::class, 'index']);
    Route::get('payment-methods',     [PaymentMethodController::class, 'index']);

    // ── Override requests ─────────────────────────────────────────────────────
    Route::get('override-requests',      [ManagerOverrideController::class, 'index']);
    Route::put('override-requests/{id}', [ManagerOverrideController::class, 'respond']);

    // ── Inventory (view only) ─────────────────────────────────────────────────
    // A manager can see their own branch's stock, but can never move it
    // directly — the only legitimate way stock enters/leaves a branch is the
    // Stock Request / Transfer Request workflow (branch-operations/transfers,
    // branch-operations/stock-requests). The old instant "manager inventory
    // transfer" endpoint is removed on purpose, not merely hidden.
    Route::get('inventory', [ManagerInventoryController::class, 'index']);
    Route::get('inventory/{productId}/history', [ManagerInventoryController::class, 'productHistory']);

    // ── Sales invoices (own branch, every seller — not just the manager's own sales) ──
    Route::get('invoices',              [\App\Modules\Sales\Controllers\ManagerInvoiceController::class, 'index']);
    Route::get('invoices/{id}',         [\App\Modules\Sales\Controllers\ManagerInvoiceController::class, 'show']);
    Route::post('invoices/{id}/cancel', [\App\Modules\Sales\Controllers\ManagerInvoiceController::class, 'cancel']);
    Route::put('invoices/{id}/edit',    [\App\Modules\Sales\Controllers\ManagerInvoiceController::class, 'edit']);
    Route::put('invoices/{id}/edit/preview', [\App\Modules\Sales\Controllers\ManagerInvoiceController::class, 'previewEdit']);

    // ── Analytics & reports ───────────────────────────────────────────────────
    Route::get('reports/sales',                [ReportsController::class, 'salesSummary']);
    Route::get('reports/sales/trend',          [ReportsController::class, 'salesTrend']);
    Route::get('reports/sellers',              [ReportsController::class, 'sellerPerformance']);
    Route::get('reports/products',             [ReportsController::class, 'topProducts']);
    Route::get('reports/inventory',            [ReportsController::class, 'inventoryStatus']);
    Route::get('reports/inventory/movements',  [ReportsController::class, 'inventoryMovements']);
    Route::get('reports/customers',            [ReportsController::class, 'topCustomers']);
    Route::get('reports/financial',            [ReportsController::class, 'financialSummary']);
    Route::get('reports/payment-methods-breakdown', [ReportsController::class, 'paymentMethodsForDate']);

});


// ─── Pricing Management ─────────────────────────────────────────────────────────
// Sells always read Product.selling_price / default_selling_price — Pricing is
// the only place those change. Purchasing/FIFO never touch them (see
// PricingService::applyPriceUpdate, which updates cost only).

// View-only for admin + manager (current prices/cost/profit — NOT history).
Route::group(['middleware' => ['api', CheckRole::class . ':admin,manager'], 'prefix' => 'pricing'], function () {
    Route::get('',              [PricingController::class, 'index']);
    Route::get('export',        [PricingController::class, 'export']);
    Route::get('{id}',          [PricingController::class, 'show']);
    Route::get('{id}/batches',  [PricingController::class, 'batches']);
});

// Admin-only: price history, refreshing costs, and changing selling prices.
Route::group(['middleware' => ['api', CheckRole::class . ':admin'], 'prefix' => 'pricing'], function () {
    Route::get('{id}/history',          [PricingController::class, 'history']);
    Route::get('update/preview',        [PricingController::class, 'updatePreview']);
    Route::post('update/apply',         [PricingController::class, 'applyUpdate']);
    Route::put('{id}/selling-price',    [PricingController::class, 'updateSellingPrice']);
    Route::put('{id}/batches/{supplyItemId}/price', [PricingController::class, 'priceBatch']);
    Route::patch('{id}/batches/{supplyItemId}/price', [PricingController::class, 'updateBatchPrice']);
    Route::post('{id}/batches/{supplyItemId}/archive', [PricingController::class, 'archiveBatch']);
});

// ─── Customer Management ─────────────────────────────────────────────────────
// Admin: full access. Manager: view customers + reports, scoped to their own
// branch (see CustomerController::scopeToManager()). Seller: no list access —
// only sales/customers (search + quick-create, cashier group above) and a
// single customer's own show(), scoped to customers they've personally sold to.
Route::group(['middleware' => ['api', CheckRole::class . ':admin,manager']], function () {
    Route::get('customers/reports/new',      [\App\Modules\Sales\Controllers\CustomerController::class, 'newCustomers']);
    Route::get('customers/reports/inactive', [\App\Modules\Sales\Controllers\CustomerController::class, 'inactiveCustomers']);
    Route::get('customers',                  [\App\Modules\Sales\Controllers\CustomerController::class, 'index']);
    // Internal notes — Admin/Manager can edit; Seller still sees them (read-only) via show() below.
    Route::put('customers/{id}/notes',       [\App\Modules\Sales\Controllers\CustomerController::class, 'updateNotes']);
});
// Edit the customer's own info — Admin only (Manager stays view-only, per the original permission model).
Route::group(['middleware' => ['api', CheckRole::class . ':admin']], function () {
    Route::put('customers/{id}', [\App\Modules\Sales\Controllers\CustomerController::class, 'update']);
});
Route::group(['middleware' => ['api', CheckRole::class . ':admin,manager,sales']], function () {
    Route::get('customers/{id}', [\App\Modules\Sales\Controllers\CustomerController::class, 'show']);
    Route::get('customers/{id}/similar', [\App\Modules\Sales\Controllers\CustomerController::class, 'similar']);
    Route::get('customers/{id}/notes',   [\App\Modules\Sales\Controllers\CustomerController::class, 'notesHistory']);
    Route::post('customers/{id}/notes-history', [\App\Modules\Sales\Controllers\CustomerController::class, 'addNote']);
    Route::put('customers/{id}/notes-history/{noteId}', [\App\Modules\Sales\Controllers\CustomerController::class, 'editNote']);
    // Delete is Admin-only, enforced in-method (abort_unless in deleteNote())
    // — same "everyone hits the route, permission checked in-method" pattern
    // authorizeView() already uses elsewhere in this controller.
    Route::delete('customers/{id}/notes-history/{noteId}', [\App\Modules\Sales\Controllers\CustomerController::class, 'deleteNote']);
    Route::get('tags', [\App\Modules\Sales\Controllers\TagController::class, 'index']);
});
// Tags — manage (create/attach/detach) and Export — Admin/Manager only.
Route::group(['middleware' => ['api', CheckRole::class . ':admin,manager']], function () {
    Route::post('tags', [\App\Modules\Sales\Controllers\TagController::class, 'store']);
    Route::post('customers/{id}/tags', [\App\Modules\Sales\Controllers\CustomerController::class, 'attachTag']);
    Route::delete('customers/{id}/tags/{tagId}', [\App\Modules\Sales\Controllers\CustomerController::class, 'detachTag']);
    Route::get('customers/{id}/export', [\App\Modules\Sales\Controllers\CustomerController::class, 'export']);
});

// ═══════════════════════════════════════════════════════════════════════════
// Branch Operations & Logistics — Phase 5.1 permission model (manager-driven):
//   Admin        — observes everything, is the Warehouse's manager, can
//                  cancel/override any transfer, creates Emergency Transfers.
//   Branch Manager (manager) — creates requests; approves/rejects/ships as
//                  the SOURCE (owning) shop's manager; receives as the
//                  DESTINATION shop's manager. Inventory ownership decides
//                  authority, not who requested — enforced per-shop in
//                  TransferRequestController::assertActsForShop(), not by role alone.
//   Employee (sales) — read-only, own shop only. Never approve/override.
// ═══════════════════════════════════════════════════════════════════════════

// ── Shop listing (read-only) — branch managers need shop names for transfer creation/filters, unlike the admin-only Shop CRUD above ──
Route::group(['middleware' => ['api', CheckRole::class . ':admin,manager'], 'prefix' => 'shops'], function () {
    Route::get('', [ShopController::class, 'index']);
});

// ── Product listing (read-only) — branch managers need the product catalog to pick
// items when creating a Transfer Request, unlike the admin-only Product CRUD above ──
Route::group(['middleware' => ['api', CheckRole::class . ':admin,manager'], 'prefix' => 'products'], function () {
    Route::get('', [ProductController::class, 'index']);
});

// ── Stock Intelligence overview/low-stock (read-only) — branch managers need their own
// branch's data for the Branch Dashboard; applyLocationFilter forces a manager to their own shop_id ──
Route::group(['middleware' => ['api', CheckRole::class . ':admin,manager'], 'prefix' => 'admin/stock-intelligence'], function () {
    Route::get('overview',  [AdminStockIntelligenceController::class, 'overview']);
    Route::get('low-stock', [AdminStockIntelligenceController::class, 'lowStock']);
});

// ── Purchase invoices — view + cancel are admin+manager; create/update/destroy stay admin-only (see 'stock' group above) ──
Route::group(['middleware' => ['api', CheckRole::class . ':admin,manager'], 'prefix' => 'stock'], function () {
    Route::get('supplies',              [SupplyController::class, 'index']);
    Route::get('supplies/show/{id}',    [SupplyController::class, 'show']);
    Route::post('supplies/{id}/cancel', [SupplyController::class, 'cancel']);
});

// ── Transfer Requests — read: all roles (shop-scoped); every write action is admin+manager, with per-shop ownership enforced inside the controller ──
Route::group(['middleware' => ['api', CheckRole::class . ':admin,manager,sales'], 'prefix' => 'branch-operations/transfers'], function () {
    Route::get('',                  [TransferRequestController::class, 'index']);
    Route::get('available-stock',   [TransferRequestController::class, 'availableStock']);
    Route::get('{id}/invoice/pdf',  [InternalTransferInvoiceController::class, 'pdf']);
    Route::get('{id}',              [TransferRequestController::class, 'show']);
});
Route::group(['middleware' => ['api', CheckRole::class . ':admin,manager'], 'prefix' => 'branch-operations/transfers'], function () {
    Route::post('',                 [TransferRequestController::class, 'store']);
    Route::post('{id}/submit',      [TransferRequestController::class, 'submit']);
    Route::post('{id}/approve',     [TransferRequestController::class, 'approve']); // source-shop manager (or admin for the warehouse)
    Route::post('{id}/reject',      [TransferRequestController::class, 'reject']); // source-shop manager (or admin for the warehouse)
    Route::post('{id}/prepare',     [TransferRequestController::class, 'prepare']); // source-shop manager (or admin for the warehouse)
    Route::post('{id}/ship',        [TransferRequestController::class, 'ship']); // source-shop manager (or admin for the warehouse)
    Route::post('{id}/receive',     [TransferRequestController::class, 'receive']); // destination-shop manager (or admin for the warehouse)
    Route::post('{id}/receiving-waste', [TransferRequestController::class, 'registerReceivingWaste']); // destination-shop manager (or admin)
    Route::post('{id}/close',       [TransferRequestController::class, 'close']); // either side's manager, or admin
});
Route::group(['middleware' => ['api', CheckRole::class . ':admin'], 'prefix' => 'branch-operations/transfers'], function () {
    Route::post('{id}/cancel',      [TransferRequestController::class, 'cancel']);
});

// ── Stock Requests — a Branch Manager entry point onto the SAME TransferRequest
// engine above (source hard-locked to the Main Warehouse server-side). Reading
// the resulting requests uses the existing GET branch-operations/transfers
// endpoint with ?warehouse_source=1 — no separate list/detail/report endpoint.
Route::group(['middleware' => ['api', CheckRole::class . ':manager'], 'prefix' => 'branch-operations/stock-requests'], function () {
    Route::post('', [TransferRequestController::class, 'storeStockRequest']);
});

// ── Branch Required Materials — manager's own-branch dashboard (admin's cross-branch
// report is at admin/reports/required-materials above; both share RequiredMaterialsService) ──
Route::group(['middleware' => ['api', CheckRole::class . ':manager'], 'prefix' => 'branch-operations/required-materials'], function () {
    Route::get('', [RequiredMaterialsController::class, 'index']);
});

// ── Waste Management (Part 7) — read: all roles (shop-scoped); register: admin+manager ──
Route::group(['middleware' => ['api', CheckRole::class . ':admin,manager,sales'], 'prefix' => 'branch-operations/waste'], function () {
    Route::get('',   [WasteController::class, 'index']);
});
Route::group(['middleware' => ['api', CheckRole::class . ':admin,manager'], 'prefix' => 'branch-operations/waste'], function () {
    Route::post('',  [WasteController::class, 'store']);
});

// ── Inventory Adjustments (Part 9) — Manager Dashboard Cleanup: admin only, no longer accessible to managers/sales ──
Route::group(['middleware' => ['api', CheckRole::class . ':admin'], 'prefix' => 'branch-operations/adjustments'], function () {
    Route::get('',   [InventoryAdjustmentController::class, 'index']);
    Route::post('',  [InventoryAdjustmentController::class, 'store']);
    Route::post('{id}/approve', [InventoryAdjustmentController::class, 'approve']);
    Route::post('{id}/reject',  [InventoryAdjustmentController::class, 'reject']);
    Route::post('{id}/execute', [InventoryAdjustmentController::class, 'execute']);
});

// ── Inventory Count Sessions (Part 8) — Manager Dashboard Cleanup: admin only, no longer accessible to managers/sales ──
Route::group(['middleware' => ['api', CheckRole::class . ':admin'], 'prefix' => 'branch-operations/counts'], function () {
    Route::get('',                    [InventoryCountController::class, 'index']);
    Route::get('{id}',                [InventoryCountController::class, 'show']);
    Route::get('{id}/export',         [InventoryCountController::class, 'export']);
    Route::post('',                   [InventoryCountController::class, 'store']);
    Route::post('{id}/record',        [InventoryCountController::class, 'recordCounts']);
    Route::post('{id}/submit-review', [InventoryCountController::class, 'submitForReview']);
    Route::put('{id}/items/{itemId}/reason', [InventoryCountController::class, 'setItemReason']);
    Route::post('{id}/approve',          [InventoryCountController::class, 'approve']);
    Route::post('{id}/adjust-inventory', [InventoryCountController::class, 'adjustInventory']);
});

// ── Branch Operations Dashboard (Phase 4, Part 10) — admin + manager, shop-scoped ──
Route::group(['middleware' => ['api', CheckRole::class . ':admin,manager'], 'prefix' => 'branch-operations/dashboard'], function () {
    Route::get('', [BranchDashboardController::class, 'show']);
});

// ── Admin Logistics Dashboard (Phase 4, Part 11) — admin only, cross-branch ──
Route::group(['middleware' => ['api', CheckRole::class . ':admin'], 'prefix' => 'branch-operations/logistics-dashboard'], function () {
    Route::get('', [AdminLogisticsDashboardController::class, 'overview']);
});

// ── Transfer Reports (Phase 4.7) — admin only, cross-branch ──────────────────
Route::group(['middleware' => ['api', CheckRole::class . ':admin'], 'prefix' => 'branch-operations/reports/transfers'], function () {
    Route::get('summary', [TransferReportController::class, 'summary']);
    Route::get('{type}/export', [TransferReportController::class, 'export']);
    Route::get('{type}', [TransferReportController::class, 'data']);
});

// ── Internal Transfer Invoice dedicated report (Phase 5.8) — admin only ──────
Route::group(['middleware' => ['api', CheckRole::class . ':admin'], 'prefix' => 'branch-operations/reports/transfer-invoices'], function () {
    Route::get('export', [TransferReportController::class, 'invoiceReportExport']);
    Route::get('', [TransferReportController::class, 'invoiceReportData']);
});

// ── Waste Reports (Phase 4.8) — admin only, cross-branch ─────────────────────
Route::group(['middleware' => ['api', CheckRole::class . ':admin'], 'prefix' => 'branch-operations/reports/waste'], function () {
    Route::get('summary', [WasteReportController::class, 'summary']);
    Route::get('{type}/export', [WasteReportController::class, 'export']);
    Route::get('{type}', [WasteReportController::class, 'data']);
});

// ── Inventory Count Reports (Phase 4.9) — admin only, cross-branch ───────────
Route::group(['middleware' => ['api', CheckRole::class . ':admin'], 'prefix' => 'branch-operations/reports/counts'], function () {
    Route::get('summary', [InventoryCountReportController::class, 'summary']);
    Route::get('{type}/export', [InventoryCountReportController::class, 'export']);
    Route::get('{type}', [InventoryCountReportController::class, 'data']);
});

// ── Inventory Adjustment Reports (Phase 4.10) — admin only, cross-branch ─────
Route::group(['middleware' => ['api', CheckRole::class . ':admin'], 'prefix' => 'branch-operations/reports/adjustments'], function () {
    Route::get('summary', [InventoryAdjustmentReportController::class, 'summary']);
    Route::get('{type}/export', [InventoryAdjustmentReportController::class, 'export']);
    Route::get('{type}', [InventoryAdjustmentReportController::class, 'data']);
});

// ── Stock Movement Report (Phase 4.11) — admin only, cross-branch ────────────
Route::group(['middleware' => ['api', CheckRole::class . ':admin'], 'prefix' => 'branch-operations/reports/stock-movement'], function () {
    Route::get('export', [StockMovementReportController::class, 'export']);
    Route::get('', [StockMovementReportController::class, 'data']);
});

// ── FIFO / Batch Traceability (Phase 4.13) — admin only, cross-branch ────────
Route::group(['middleware' => ['api', CheckRole::class . ':admin'], 'prefix' => 'branch-operations/reports/batches'], function () {
    Route::get('summary', [BatchTraceabilityController::class, 'summary']);
    Route::get('export', [BatchTraceabilityController::class, 'export']);
    Route::get('movements', [BatchTraceabilityController::class, 'movements']);
    Route::get('movements/export', [BatchTraceabilityController::class, 'movementsExport']);
    Route::get('{supplyItem}', [BatchTraceabilityController::class, 'show']);
    Route::get('', [BatchTraceabilityController::class, 'index']);
});

// ── Inventory Audit Report (Phase 4.14) — admin only, cross-branch ───────────
Route::group(['middleware' => ['api', CheckRole::class . ':admin'], 'prefix' => 'branch-operations/reports/inventory-audit'], function () {
    Route::get('export', [InventoryAuditReportController::class, 'export']);
    Route::get('', [InventoryAuditReportController::class, 'data']);
});


// ─── Seller (Cashier) — managers may sell exactly like sellers ─────────────────

Route::group(['middleware' => ['api', CheckRole::class . ':sales,manager']], function () {

    Route::group(['prefix' => 'sales'], function () {

        // ── Cashier utility endpoints (for UI dropdowns / search) ─────────────
        Route::get('goods',       [CashierController::class, 'searchGoods']);
        Route::get('goods/barcode', [CashierController::class, 'findGoodsByBarcode']);
        Route::get('unstocked-products', [CashierController::class, 'unstockedProducts']);
        Route::get('composable-products', [CashierController::class, 'composableProducts']);
        Route::get('oil-products', [CashierController::class, 'oilProducts']);
        Route::get('bottle-products', [CashierController::class, 'bottleProducts']);
        Route::get('alcohol-products', [CashierController::class, 'alcoholProducts']);
        Route::get('compound-price', [CashierController::class, 'compoundPrice']);
        Route::get('products/{id}/components', [CashierController::class, 'productComponents']);
        Route::get('categories',  [CashierController::class, 'getCategories']);
        Route::get('customers',   [CashierController::class, 'searchCustomers']);
        Route::post('customers',  [CashierController::class, 'createCustomer']);
        Route::get('currencies',  [CashierController::class, 'getCurrencies']);
        Route::get('payment-methods', [CashierController::class, 'getPaymentMethods']);
        Route::post('quote-cost', [CashierController::class, 'quoteCost']);
        Route::get('safes',       [CashierController::class, 'getShopSafes']);

        // ── Invoice management ────────────────────────────────────────────────
        Route::get('invoices',             [InvoiceController::class, 'index']);
        Route::post('invoices',            [InvoiceController::class, 'store']);
        Route::get('invoices/{id}',        [InvoiceController::class, 'show']);
        Route::put('invoices/{id}/status', [InvoiceController::class, 'updateStatus']);

        // ── Override requests (seller side) ───────────────────────────────────
        Route::post('override-requests',        [OverrideRequestController::class, 'store']);
        Route::get('override-requests/{id}',    [OverrideRequestController::class, 'show']);
    });

});

// Sales Catalog — also reachable by admin (with an explicit shop_id), for
// Edit Invoice reusing the exact same POS catalog scoped to the invoice's
// original branch. Kept out of the sales,manager group above since every
// other endpoint there assumes auth()->user()->shop_id exists, which admin
// never has — role/branch enforcement itself stays inside the controller.
Route::group(['middleware' => ['api', CheckRole::class . ':sales,manager,admin'], 'prefix' => 'sales'], function () {
    Route::get('catalog-products', [CashierController::class, 'catalogProducts']);
});


// ─── Salary advance self-service (any authenticated employee) ─────────────────
// Registered BEFORE the admin+manager `advances/{id}` route below so the
// literal `advances/mine` path always wins over the `{id}` wildcard.

Route::group(['middleware' => ['api', CheckRole::class . ':*'], 'prefix' => 'hr'], function () {
    Route::post('advances',     [\App\Modules\Hr\Controllers\SalaryAdvanceController::class, 'store']);
    Route::get('advances/mine', [\App\Modules\Hr\Controllers\SalaryAdvanceController::class, 'mine']);
});


// ─── HR shared endpoints (Admin + Branch Manager) ──────────────────────────────

Route::group(['middleware' => ['api', CheckRole::class . ':admin,manager'], 'prefix' => 'hr'], function () {
    // Attendance (manager scoped to own branch; admin any branch)
    Route::get('attendance', [\App\Modules\Hr\Controllers\AttendanceController::class, 'roster']);
    Route::put('attendance', [\App\Modules\Hr\Controllers\AttendanceController::class, 'mark']);

    // Leave review list (manager sees own branch; admin sees all)
    Route::get('leaves', [\App\Modules\Hr\Controllers\LeaveController::class, 'index']);

    // End an APPROVED leave early — admin: any employee; manager: own-branch non-managers only (checked in controller)
    Route::put('leaves/{id}/end-early', [\App\Modules\Hr\Controllers\LeaveController::class, 'endEarly']);

    // Salary advances — manager sees own-branch requests read-only (no approve/reject/plan routes here)
    Route::get('advances',      [\App\Modules\Hr\Controllers\SalaryAdvanceController::class, 'index']);
    Route::get('advances/{id}', [\App\Modules\Hr\Controllers\SalaryAdvanceController::class, 'show']);

    // Payroll list/detail (manager sees own branch; admin sees all)
    Route::get('payrolls',      [\App\Modules\Hr\Controllers\PayrollController::class, 'index']);
    Route::get('payrolls/{id}', [\App\Modules\Hr\Controllers\PayrollController::class, 'show']);

    // Reports (JSON data + CSV/PDF export)
    Route::get('reports/{type}/export', [\App\Modules\Hr\Controllers\HrReportController::class, 'export']);
    Route::get('reports/{type}',        [\App\Modules\Hr\Controllers\HrReportController::class, 'data']);

    // Weekly Schedule (manager scoped to own branch; admin any branch)
    Route::get('schedule',          [\App\Modules\Hr\Controllers\ScheduleController::class, 'roster']);
    Route::put('schedule',          [\App\Modules\Hr\Controllers\ScheduleController::class, 'upsert']);
    Route::put('schedule/bulk',     [\App\Modules\Hr\Controllers\ScheduleController::class, 'bulkUpsert']);
    Route::post('schedule/publish', [\App\Modules\Hr\Controllers\ScheduleController::class, 'publish']);
    Route::post('schedule/cancel',  [\App\Modules\Hr\Controllers\ScheduleController::class, 'cancel']);
    Route::get('schedule/export',   [\App\Modules\Hr\Controllers\ScheduleController::class, 'export']);
    Route::get('shift-templates',   [\App\Modules\Hr\Controllers\ScheduleController::class, 'shiftTemplates']);

    // Bulk Shift Assignment — additive only, does not touch any route above
    Route::get('schedule/bulk-assign/employees',  [\App\Modules\Hr\Controllers\ScheduleController::class, 'assignableEmployees']);
    Route::get('schedule/bulk-assign/conflicts',  [\App\Modules\Hr\Controllers\ScheduleController::class, 'bulkAssignConflicts']);
    Route::post('schedule/bulk-assign',           [\App\Modules\Hr\Controllers\ScheduleController::class, 'bulkAssign']);

    // Attendance self-history browsing for managers reviewing their own record
    // (the roster endpoint above already covers branch-wide management).
});


// ─── HR self-service (any authenticated employee) ──────────────────────────────

Route::group(['middleware' => ['api', CheckRole::class . ':*'], 'prefix' => 'hr'], function () {
    Route::post('leaves',     [\App\Modules\Hr\Controllers\LeaveController::class, 'store']);
    Route::get('leaves/mine', [\App\Modules\Hr\Controllers\LeaveController::class, 'mine']);

    // Personal HR dashboard summary (each employee sees only their own data)
    Route::get('me/summary',  [\App\Modules\Hr\Controllers\SelfServiceController::class, 'summary']);
    Route::get('me/profile',  [\App\Modules\Hr\Controllers\SelfServiceController::class, 'profile']);
    Route::get('me/sales',    [\App\Modules\Hr\Controllers\SelfServiceController::class, 'sales']);

    // My Schedule (published entries only) + My Attendance (own history)
    Route::get('schedule/mine',   [\App\Modules\Hr\Controllers\ScheduleController::class, 'mine']);
    Route::get('attendance/mine', [\App\Modules\Hr\Controllers\AttendanceController::class, 'mine']);

    // My Bonuses / My Penalties (own rows only) + Employment Timeline
    Route::get('bonuses/mine',   [\App\Modules\Hr\Controllers\BonusPenaltyController::class, 'bonusMine']);
    Route::get('penalties/mine', [\App\Modules\Hr\Controllers\BonusPenaltyController::class, 'penaltyMine']);
    Route::get('me/timeline',    [\App\Modules\Hr\Controllers\SelfServiceController::class, 'timeline']);

    // Leave self-service: cancel own PENDING request
    Route::put('leaves/{id}/cancel', [\App\Modules\Hr\Controllers\LeaveController::class, 'cancel']);

});
