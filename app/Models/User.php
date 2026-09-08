<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property UserRole $role
 * @property bool $is_active
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'email', 'role', 'is_active', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * Limit the query to employees who still work here.
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * What each employee put on the record over a stretch of days.
     *
     * Correlated counts rather than four separate queries and a merge in PHP:
     * the workforce report wants one row per employee, and this is one query.
     * Every table names its recorder the same way, which is what makes it read
     * as a single question.
     *
     * @return Collection<int, array{id: int, name: string, role: UserRole, is_active: bool, sales: int, runs: int, movements: int, expenses: int, total: int}>
     */
    public static function recordedBetween(string $from, string $to): Collection
    {
        $countOf = fn (string $table, string $dateColumn): QueryBuilder => DB::table($table)
            ->selectRaw('count(*)')
            ->whereColumn($table.'.recorded_by', 'users.id')
            ->whereDate($table.'.'.$dateColumn, '>=', $from)
            ->whereDate($table.'.'.$dateColumn, '<=', $to);

        return DB::table('users')
            ->orderBy('users.name')
            ->select('users.id', 'users.name', 'users.role', 'users.is_active')
            ->selectSub($countOf('sales', 'sold_at'), 'sales_recorded')
            ->selectSub($countOf('production_runs', 'produced_at'), 'runs_logged')
            ->selectSub($countOf('inventory_movements', 'occurred_at'), 'movements_logged')
            ->selectSub($countOf('expenses', 'spent_on'), 'expenses_recorded')
            ->get()
            ->map(function (object $row): array {
                $sales = (int) $row->sales_recorded;
                $runs = (int) $row->runs_logged;
                $movements = (int) $row->movements_logged;
                $expenses = (int) $row->expenses_recorded;

                return [
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'role' => UserRole::from((string) $row->role),
                    'is_active' => (bool) $row->is_active,
                    'sales' => $sales,
                    'runs' => $runs,
                    'movements' => $movements,
                    'expenses' => $expenses,
                    'total' => $sales + $runs + $movements + $expenses,
                ];
            });
    }

    /**
     * The employee's places on the schedule.
     *
     * @return HasMany<ShiftAssignment, $this>
     */
    public function shiftAssignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class);
    }

    /**
     * The shifts the employee is on.
     *
     * @return BelongsToMany<Shift, $this>
     */
    public function shifts(): BelongsToMany
    {
        return $this->belongsToMany(Shift::class, 'shift_assignments')->withTimestamps();
    }

    /**
     * Determine whether the user holds any of the given roles.
     */
    public function hasRole(UserRole ...$roles): bool
    {
        return in_array($this->role, $roles, strict: true);
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
