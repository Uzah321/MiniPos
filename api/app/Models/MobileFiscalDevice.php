<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MobileFiscalDevice extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id', 'branch_id', 'till_id', 'sync_node_id', 'device_id',
        'device_serial_no', 'environment', 'device_operating_mode', 'qr_url', 'certificate_pem',
        'certificate_sha256', 'certificate_valid_until', 'is_active', 'registered_by',
    ];

    protected $casts = [
        'device_id' => 'integer',
        'certificate_valid_until' => 'datetime',
        'is_active' => 'boolean',
    ];

    protected $hidden = ['certificate_pem'];
}
