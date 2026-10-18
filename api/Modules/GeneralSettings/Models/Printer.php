<?php

namespace Modules\GeneralSettings\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Company\Models\Company;
use Modules\Company\Models\CompanyBranch;

// use Modules\GeneralSettings\Database\Factories\PrinterFactory;

class Printer extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'printers';

    protected $fillable = [
        'company_id',
        'branch_id',
        'till_id',
        'printer_name',
        'target_printing',
        'printer_type',
        'is_default',
        'is_active',
        'paper_size',
        'connection_type',
        'ip_address',
        'port',
        'notes',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'port' => 'integer',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function branch()
    {
        return $this->belongsTo(CompanyBranch::class);
    }

    public function till()
    {
        return $this->belongsTo(PosTill::class, 'till_id');
    }
}
