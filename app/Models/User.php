<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'phone',
        'shift_start',
        'shift_end',
        'shop_id',
        // HR & Payroll
        'base_salary',
        'personal_commission_percent',
        'hire_date',
        'status',
        'monthly_leave_allowance',
        'hr_notes',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at'           => 'datetime',
            'password'                    => 'hashed',
            'hire_date'                   => 'date',
            'base_salary'                 => 'decimal:2',
            'personal_commission_percent' => 'decimal:2',
            'monthly_leave_allowance'      => 'integer',
        ];
    }

    // ── HR & Payroll relations ────────────────────────────────────────────────

    /** The employee's permanent (primary) branch — alias of shop(). */
    public function primaryBranch(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    /** All temporary transfers for this employee. */
    public function transfers(): HasMany
    {
        return $this->hasMany(EmployeeTransfer::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function payrolls(): HasMany
    {
        return $this->hasMany(Payroll::class);
    }

    /** Invoices this user sold (for personal-commission math). */
    public function soldInvoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'seller_id');
    }


    // الفرع الذي ينتمي إليه الموظف
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    // الفرع الذي يديره هذا المستخدم (إن كان مديراً)
    public function managedShop(): HasOne
    {
        return $this->hasOne(Shop::class, 'manager_id');
    }

    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     *
     * @return mixed
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims()
    {
        return [];
    }
}
