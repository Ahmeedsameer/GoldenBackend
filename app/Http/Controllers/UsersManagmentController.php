<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateOwnProfileRequest;
use App\Http\Requests\ChangeOwnPasswordRequest;
use App\Http\Requests\AdminResetUserPasswordRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Http\Services\UserManagmentService;
use App\Modules\Hr\Services\EmployeeService;

class UsersManagmentController extends Controller
{


    public function __construct(
        private UserManagmentService $userManagmentService,
        private EmployeeService $employees,
    )
    {


    }




    // This page is scoped to Admin accounts only — other roles (manager, sales) are
    // managed separately under HR > الموظفون (see EmployeeController).
    // Deactivated accounts (status = inactive) are hidden by default, exactly like
    // EmployeeController::index() — pass ?status=inactive to see only deactivated
    // admin accounts ("Show Inactive Users").
    public function index()
    {
        $query = User::query()->where('role', 'admin');

        if (request()->has('name')) {
            $term = request('name');
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                  ->orWhere('email', 'like', "%{$term}%");
            });
        }

        if (request()->has('email')) {
            $query->where('email', 'like', '%' . request('email') . '%');
        }

        $status = request('status', 'active');
        if (in_array($status, ['active', 'inactive'], true)) {
            $query->where('status', $status);
        }

        $limit = 20;

        if (request()->has('limit')) {
            $limit = request('limit');
        }

        $users = $query->paginate($limit);


        return response()->json($users);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreUserRequest $request)
    {
       

    try {
        $this->userManagmentService->createUser($request->validated());
    } catch (\Throwable $th) {
        return response()->json(['message' => 'Failed to create user' , 'error' => $th->getMessage()] , 500);
    }
        
        return response()->json(['message' => 'User created successfully'] , 200);

    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $user = User::with('shop')->find($id);
        if (!$user) {
            return response()->json(['message' => 'لم يتم ايجاد المستخدم'], 404);
        }
        return response()->json($user);
    }

    /**
     * Update the AUTHENTICATED admin's own profile (name/email/phone only).
     * Always scoped to auth()->id() — there is no way to pass another user's
     * ID into this endpoint, so it can never edit someone else's account.
     */
    public function updateProfile(UpdateOwnProfileRequest $request)
    {
        $user = auth()->user();
        $user->update($request->validated());

        return response()->json([
            'message' => 'تم تحديث الملف الشخصي بنجاح',
            'user'    => $user->fresh('shop'),
        ]);
    }

    /**
     * Change the AUTHENTICATED admin's own password. Requires the current
     * password (verified server-side against the existing hash — never
     * exposed to the client) plus a new password + confirmation.
     */
    public function changePassword(ChangeOwnPasswordRequest $request)
    {
        $user = auth()->user();
        $user->password = Hash::make($request->validated('new_password'));
        $user->save();

        return response()->json(['message' => 'تم تغيير كلمة المرور بنجاح']);
    }

    /**
     * Admin-only: reset another user's password. The admin supplies only a
     * brand new password — the target user's existing password/hash is never
     * read, returned, or displayed anywhere.
     */
    public function resetPassword(AdminResetUserPasswordRequest $request, string $id)
    {
        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'لم يتم ايجاد المستخدم'], 404);
        }

        $user->password = Hash::make($request->validated('new_password'));
        $user->save();

        return response()->json(['message' => 'تم إعادة تعيين كلمة المرور بنجاح']);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * بحث سريع عن مستخدمين بالاسم أو البريد الإلكتروني
     * (مخصص لواجهة اختيار المدير)
     */
    public function search()
    {
        $term  = request('q', '');
        $role  = request('role');
        $limit = min(request()->integer('limit', 10), 50);

        if (strlen($term) < 2) {
            return response()->json([
                'message' => 'يجب إدخال حرفين على الأقل للبحث',
                'data'    => [],
            ], 422);
        }

        $query = User::query()
            ->select('id', 'name', 'email', 'role', 'phone')
            ->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                  ->orWhere('email', 'like', "%{$term}%");
            });

        if ($role && in_array($role, ['admin', 'manager', 'sales'])) {
            $query->where('role', $role);
        }

        $users = $query->limit($limit)->get();

        return response()->json([
            'message' => 'تم جلب نتائج البحث بنجاح',
            'data'    => $users,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    /**
     * PUT /api/user-managment/{id}/toggle-status — deactivate/reactivate an
     * admin account. Never deletes the row: flips the same `status` column
     * EmployeeController::toggleStatus() already uses for manager/sales
     * accounts, reusing that exact service method (no duplicated logic).
     * A deactivated account can no longer log in (see AuthController::login())
     * and any already-issued token is rejected on the next request (see
     * CheckRole middleware) — but every historical record it authored
     * (invoices, audit logs, approvals, ...) is untouched.
     */
    public function toggleStatus(string $id)
    {
        $user = User::where('role', 'admin')->findOrFail($id);

        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'لا يمكنك إلغاء تفعيل حسابك الخاص'], 422);
        }

        $user = $this->employees->toggleStatus($user);

        return response()->json([
            'message' => 'تم تغيير حالة الحساب بنجاح',
            'data'    => ['id' => $user->id, 'status' => $user->status],
        ]);
    }
}
