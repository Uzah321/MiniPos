<?php

namespace Modules\FiscalDevice\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FiscalDevice extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'branch_id',
        'device_id',
        'device_serial_no',
        'model_name',
        'model_version',
        'environment',
        'base_url',
        'certificate_path',
        'private_key_path',
        'certificate_password',
        'taxpayer_name',
        'taxpayer_tin',
        'vat_number',
        'device_branch_name',
        'device_operating_mode',
        'taxpayer_day_max_hrs',
        'taxpayer_day_end_notification_hrs',
        'certificate_valid_till',
        'qr_url',
        'is_registered',
        'is_active',
        'last_config_response',
        'last_status_response',
    ];

    protected $casts = [
        'device_id' => 'integer',
        'is_registered' => 'boolean',
        'is_active' => 'boolean',
        'certificate_valid_till' => 'date',
        'last_config_response' => 'array',
        'last_status_response' => 'array',
    ];

    public function certificateAbsolutePath(): string
    {
        return base_path($this->certificate_path);
    }

    public function privateKeyAbsolutePath(): string
    {
        return base_path($this->private_key_path);
    }
}
