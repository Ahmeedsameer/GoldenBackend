<?php

use App\Http\Controllers\Admin\AdminSalesReportController;
use App\Http\Controllers\Admin\AdminFinancialReportController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminStockIntelligenceController;
use App\Http\Controllers\Admin\ShopController;
use App\Http\Controllers\Admin\ShopEmployeeController;
use App\Http\Controllers\Admin\ShopManagerController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductTypeController;
use App\Http\Controllers\UsersManagmentController;
use App\Http\Middleware\CheckRole;
use App\Modules\Safe\Controllers\CurrencyController;
use App\Modules\Safe\Controllers\SafeController;
use App\Modules\Safe\Controllers\SafeManagementController;
use App\Modules\Safe\Controllers\SafeTypeController;
use App\Modules\Safe\Controllers\TransactionReasonController;
use App\Modules\Convention\Controllers\ConventionController;
use App\Modules\Convention\Controllers\ConventionTransactionController;
use App\Modules\Convention\Controllers\ManagerConventionController;
use App\Modules\Convention\Controllers\NotificationController;
use App\Modules\Sales\Controllers\CashierController;
use App\Modules\Sales\Controllers\InvoiceController;
use App\Modules\Sales\Controllers\ManagerOverrideController;
use App\Modules\Sales\Controllers\OverrideRequestController;
use App\Modules\Sales\Controllers\ReportsController;
use App\Modules\Stock\Controllers\InventoryController;
use App\Modules\Stock\Controllers\ManagerInventoryController;
use App\Modules\Stock\Controllers\SupplierController;
use App\Modules\Stock\Controllers\SupplyController;


// ─── Auth ─────────────────────────────────────────────────────────────────────

Route::group(['middleware' => 'api', 'prefix' => 'auth'], function () {
    Route::post('login',   [AuthController::class, 'login']);
    Route::post('logout',  [AuthController::class, 'logout']);
    Route::post('refresh', [AuthController::class, 'refresh']);
    Route::post('me',      [AuthController::class, 'me']);
});


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
    });

    // ── Product Types (inventory behavior: Oil, Bottle, Accessory, Packaging) ─
    Route::get('product-types', [ProductTypeController::class, 'index']);

    // ── Invoice review (pending queue → approve / reject) ────────────────────
    Route::group(['prefix' => 'admin/invoices'], function () {
        Route::get('',            [\App\Modules\Sales\Controllers\AdminInvoiceController::class, 'index']);
        Route::get('{id}',        [\App\Modules\Sales\Controllers\AdminInvoiceController::class, 'show']);
        Route::put('{id}/status', [\App\Modules\Sales\Controllers\AdminInvoiceController::class, 'updateStatus']);
    });

    // ── Categories ───────────────────────────────────────────────────────────
    Route::group(['prefix' => 'categories'], function () {
        Route::get('list',           [CategoryController::class, 'index']);
        Route::post('create',        [CategoryController::class, 'store']);
        Route::get('show/{id}',      [CategoryController::class, 'show']);
        Route::post('update/{id}',   [CategoryController::class, 'update']);
        Route::post('destroy/{id}',  [CategoryController::class, 'destroy']);
    });

    // ── Products ─────────────────────────────────────────────────────────────
    Route::group(['prefix' => 'products'], function () {
        Route::get('',        [ProductController::class, 'index']);
        Route::post('',       [ProductController::class, 'store']);
        Route::get('{id}',    [ProductController::class, 'show']);
        Route::put('{id}',    [ProductController::class, 'update']);
        Route::delete('{id}', [ProductController::class, 'destroy']);
        // BOM / recipe (components of a composed product)
        Route::get('{id}/components', [\App\Http\Controllers\ProductComponentController::class, 'index']);
        Route::put('{id}/components', [\App\Http\Controllers\ProductComponentController::class, 'sync']);
    });

    // ── Shops ─────────────────────────────────────────────────────────────────
    Route::group(['prefix' => 'shops'], function () {

        // CRUD
        Route::get('',                          [ShopController::class, 'index']);
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

        // Supplies
        Route::get('supplies',                  [SupplyController::class, 'index']);
        Route::post('supplies/create',          [SupplyController::class, 'store']);
        Route::get('supplies/show/{id}',        [SupplyController::class, 'show']);
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
    });

    // ── Financial Reports (admin: cross-shop) ────────────────────────────────────
    Route::group(['prefix' => 'admin/reports/financial'], function () {
        Route::get('summary',      [AdminFinancialReportController::class, 'summary']);
        Route::get('trend',        [AdminFinancialReportController::class, 'trend']);
        Route::get('by-shop',      [AdminFinancialReportController::class, 'byShop']);
        Route::get('balances',     [AdminFinancialReportController::class, 'balances']);
        Route::get('transactions', [AdminFinancialReportController::class, 'transactions']);
    });

    // ── Stock Intelligence (admin: cross-shop) ───────────────────────────────────
    Route::group(['prefix' => 'admin/stock-intelligence'], function () {
        Route::get('overview',  [AdminStockIntelligenceController::class, 'overview']);
        Route::get('inventory', [AdminStockIntelligenceController::class, 'inventory']);
        Route::get('low-stock', [AdminStockIntelligenceController::class, 'lowStock']);
        Route::get('supplies',  [AdminStockIntelligenceController::class, 'supplies']);
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
        Route::put('employees/{id}/toggle-status', [\App\Modules\Hr\Controllers\EmployeeController::class, 'toggleStatus']);

        // Leave review (approve/reject) — admin only
        Route::put('leaves/{id}/approve', [\App\Modules\Hr\Controllers\LeaveController::class, 'approve']);
        Route::put('leaves/{id}/reject',  [\App\Modules\Hr\Controllers\LeaveController::class, 'reject']);

        // Deduction settings (configurable)
        Route::get('deduction-settings',       [\App\Modules\Hr\Controllers\DeductionSettingController::class, 'index']);
        Route::put('deduction-settings/{id}',  [\App\Modules\Hr\Controllers\DeductionSettingController::class, 'update']);

        // Payroll engine (generate / lock / unlock / paid) — admin only
        Route::post('payrolls/generate',    [\App\Modules\Hr\Controllers\PayrollController::class, 'generate']);
        Route::put('payrolls/{id}/lock',    [\App\Modules\Hr\Controllers\PayrollController::class, 'lock']);
        Route::put('payrolls/{id}/unlock',  [\App\Modules\Hr\Controllers\PayrollController::class, 'unlock']);
        Route::put('payrolls/{id}/paid',    [\App\Modules\Hr\Controllers\PayrollController::class, 'markPaid']);

        // Employee temporary transfers
        Route::get('transfers',              [\App\Modules\Hr\Controllers\TransferController::class, 'index']);
        Route::post('transfers',             [\App\Modules\Hr\Controllers\TransferController::class, 'store']);
        Route::get('transfers/{id}',         [\App\Modules\Hr\Controllers\TransferController::class, 'show']);
        Route::put('transfers/{id}',         [\App\Modules\Hr\Controllers\TransferController::class, 'update']);
        Route::put('transfers/{id}/approve', [\App\Modules\Hr\Controllers\TransferController::class, 'approve']);
        Route::put('transfers/{id}/cancel',  [\App\Modules\Hr\Controllers\TransferController::class, 'cancel']);
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

    // ── Override requests ─────────────────────────────────────────────────────
    Route::get('override-requests',      [ManagerOverrideController::class, 'index']);
    Route::put('override-requests/{id}', [ManagerOverrideController::class, 'respond']);

    // ── Inventory & inter-branch transfers ────────────────────────────────────
    Route::get('inventory',           [ManagerInventoryController::class, 'index']);
    Route::get('shops',               [ManagerInventoryController::class, 'shops']);
    Route::post('inventory/transfer', [ManagerInventoryController::class, 'transfer']);

    // ── Analytics & reports ───────────────────────────────────────────────────
    Route::get('reports/sales',                [ReportsController::class, 'salesSummary']);
    Route::get('reports/sales/trend',          [ReportsController::class, 'salesTrend']);
    Route::get('reports/sellers',              [ReportsController::class, 'sellerPerformance']);
    Route::get('reports/products',             [ReportsController::class, 'topProducts']);
    Route::get('reports/inventory',            [ReportsController::class, 'inventoryStatus']);
    Route::get('reports/inventory/movements',  [ReportsController::class, 'inventoryMovements']);
    Route::get('reports/customers',            [ReportsController::class, 'topCustomers']);
    Route::get('reports/financial',            [ReportsController::class, 'financialSummary']);

});


// ─── Seller (Cashier) — managers may sell exactly like sellers ─────────────────

Route::group(['middleware' => ['api', CheckRole::class . ':sales,manager']], function () {

    Route::group(['prefix' => 'sales'], function () {

        // ── Cashier utility endpoints (for UI dropdowns / search) ─────────────
        Route::get('goods',       [CashierController::class, 'searchGoods']);
        Route::get('unstocked-products', [CashierController::class, 'unstockedProducts']);
        Route::get('composable-products', [CashierController::class, 'composableProducts']);
        Route::get('products/{id}/components', [CashierController::class, 'productComponents']);
        Route::get('categories',  [CashierController::class, 'getCategories']);
        Route::get('customers',   [CashierController::class, 'searchCustomers']);
        Route::get('currencies',  [CashierController::class, 'getCurrencies']);
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


// ─── HR shared endpoints (Admin + Branch Manager) ──────────────────────────────

Route::group(['middleware' => ['api', CheckRole::class . ':admin,manager'], 'prefix' => 'hr'], function () {
    // Attendance (manager scoped to own branch; admin any branch)
    Route::get('attendance', [\App\Modules\Hr\Controllers\AttendanceController::class, 'roster']);
    Route::put('attendance', [\App\Modules\Hr\Controllers\AttendanceController::class, 'mark']);

    // Leave review list (manager sees own branch; admin sees all)
    Route::get('leaves', [\App\Modules\Hr\Controllers\LeaveController::class, 'index']);

    // Payroll list/detail (manager sees own branch; admin sees all)
    Route::get('payrolls',      [\App\Modules\Hr\Controllers\PayrollController::class, 'index']);
    Route::get('payrolls/{id}', [\App\Modules\Hr\Controllers\PayrollController::class, 'show']);

    // Reports (JSON data + CSV/PDF export)
    Route::get('reports/{type}/export', [\App\Modules\Hr\Controllers\HrReportController::class, 'export']);
    Route::get('reports/{type}',        [\App\Modules\Hr\Controllers\HrReportController::class, 'data']);
});


// ─── HR self-service (any authenticated employee) ──────────────────────────────

Route::group(['middleware' => ['api', CheckRole::class . ':*'], 'prefix' => 'hr'], function () {
    Route::post('leaves',     [\App\Modules\Hr\Controllers\LeaveController::class, 'store']);
    Route::get('leaves/mine', [\App\Modules\Hr\Controllers\LeaveController::class, 'mine']);

    // Personal HR dashboard summary (each employee sees only their own data)
    Route::get('me/summary',  [\App\Modules\Hr\Controllers\SelfServiceController::class, 'summary']);
});
