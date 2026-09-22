<?php

namespace Modules\Authentication\Models;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Terminals\Terminal;

class UserAccessAssignment extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id', 'role_id', 'scope_type', 'country_id', 'brand_id', 'complex_id',
        'store_id', 'terminal_id', 'status', 'starts_at', 'ends_at', 'granted_by',
    ];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function role(): BelongsTo { return $this->belongsTo(Role::class); }
    public function country(): BelongsTo { return $this->belongsTo(Country::class); }
    public function brand(): BelongsTo { return $this->belongsTo(Brand::class); }
    public function complex(): BelongsTo { return $this->belongsTo(Complex::class); }
    public function store(): BelongsTo { return $this->belongsTo(Store::class); }
    public function terminal(): BelongsTo { return $this->belongsTo(Terminal::class); }
    public function grantor(): BelongsTo { return $this->belongsTo(User::class, 'granted_by'); }
}
