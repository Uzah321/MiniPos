<?php

namespace Modules\GeneralSettings\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Company\Models\CompanyBranch;

class QzCertificate extends Model
{
    protected $table = 'qz_certificates';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'branch_id',
        'till_id',
        'name',
        'certificate_path',
        'private_key_path',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $hidden = [
        'certificate_path',
        'private_key_path',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function branch()
    {
        return $this->belongsTo(CompanyBranch::class, 'branch_id');
    }

    public function till()
    {
        return $this->belongsTo(PosTill::class, 'till_id');
    }
}
