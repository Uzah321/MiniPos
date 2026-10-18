<?php

namespace Modules\Authentication\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// use Modules\Authentication\Database\Factories\GeneralSettingFactory;

class GeneralSetting extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'branch_id',
        'setting_key',
        'setting_value',
        'setting_type',
        'module',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(\Modules\Company\Models\Company::class);
    }

    public function branch()
    {
        return $this->belongsTo(\Modules\Company\Models\CompanyBranch::class);
    }

    /**
     * Helper: cast value properly
     */
    public function getValueAttribute()
    {
        switch ($this->setting_type) {
            case 'boolean':
                return filter_var($this->setting_value, FILTER_VALIDATE_BOOLEAN);

            case 'integer':
                return (int) $this->setting_value;

            case 'decimal':
                return (float) $this->setting_value;

            case 'json':
                return json_decode($this->setting_value, true);

            default:
                return $this->setting_value;
        }
    }
}
