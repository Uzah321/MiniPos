<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum token keyed by UUID, so every primary key in the system is a UUID.
 * Plain-text tokens take the form "{uuid}|{secret}".
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    use HasUuids;
}
