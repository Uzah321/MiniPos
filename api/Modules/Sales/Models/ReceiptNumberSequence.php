<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\GeneralSettings\Models\PosTill;

class ReceiptNumberSequence extends Model
{
    use HasUuids;

    protected $table = 'receipt_number_sequences';

    protected $fillable = [
        'id',
        'company_id',
        'branch_id',
        'till_id',
        'sequence_type',
        'prefix',
        'sequence_date',
        'last_number',
    ];

    protected $casts = [
        'sequence_date' => 'date',
        'last_number' => 'integer',
    ];

    public function company()
    {
        return $this->belongsTo(\Modules\Company\Models\Company::class, 'company_id');
    }

    public function branch()
    {
        return $this->belongsTo(\Modules\Company\Models\CompanyBranch::class, 'branch_id');
    }

    public function till()
    {
        return $this->belongsTo(PosTill::class, 'till_id');
    }

    public function scopeForCompany($query, string $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForBranch($query, ?string $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeForTill($query, ?string $tillId)
    {
        return $query->where('till_id', $tillId);
    }

    public function scopeForType($query, string $type)
    {
        return $query->where('sequence_type', $type);
    }
}
