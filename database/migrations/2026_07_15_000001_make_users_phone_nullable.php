<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The users.phone column was created NOT NULL, but every user-creation form
 * (admin "Add User" and the HR "Employees" module) has always treated phone
 * as optional ('phone' => 'nullable' in both StoreUserRequest and
 * StoreEmployeeRequest). Omitting it caused a DB integrity-constraint error
 * ("Column 'phone' cannot be null") when creating an employee/user without a
 * phone number. This aligns the schema with the validation that was already
 * in place — no business logic changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users MODIFY phone VARCHAR(255) NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users MODIFY phone VARCHAR(255) NOT NULL');
    }
};
