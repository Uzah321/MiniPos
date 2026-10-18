<?php

namespace Modules\ZimraIntegration\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\ZimraIntegration\Database\Factories\TaxReportExportFactory;

class TaxReportExport extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id','branch_id','requested_by','report_type','format','date_from','date_to','status','file_path','filters','summary',
    ];

    protected $casts = [
        'date_from' => 'date',
        'date_to' => 'date',
        'filters' => 'array',
        'summary' => 'array',
    ];
}

