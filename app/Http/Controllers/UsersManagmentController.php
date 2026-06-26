<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Services\UserManagmentService;

class UsersManagmentController extends Controller
{
    

    public function __construct(private UserManagmentService $userManagmentService)
    {
       
       
    }

    


    public function index()
    {
        $query = User::query();

        if (request()->has('name')) {
            $query->where('name', 'like', '%' . request('name') . '%');
        }

        if (request()->has('email')) {
            $query->where('email', 'like', '%' . request('email') . '%');
        }

        if (request()->has('role') && in_array(request('role'), ['admin', 'supervisor', 'employee', 'accountant'])) {
            $query->where('role', request('role'));
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
        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'لم يتم ايجاد المستخدم'], 404);
        }
        return response()->json($user);
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
}
