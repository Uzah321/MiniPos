<?php

namespace Modules\Manufacturing\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait ManufacturingAudit
{
    protected function writeManufacturingAudit(Request $request, array $data): string
    {
        $user = auth()->user();
        $auditId = $data['id'] ?? (string) Str::uuid();

        DB::table('audit_trails')->insert([
            'id' => $auditId,
            'company_id' => $data['company_id'] ?? null,
            'branch_id' => $data['branch_id'] ?? null,
            'user_id' => $data['user_id'] ?? $user?->id,
            'user_name' => $data['user_name'] ?? ($user?->name ?? $user?->username ?? null),
            'user_email' => $data['user_email'] ?? $user?->email,
            'user_role' => $data['user_role'] ?? $user?->role_id,
            'action' => $data['action'],
            'module' => $data['module'] ?? 'manufacturing',
            'description' => $data['description'] ?? null,
            'auditable_type' => $data['auditable_type'] ?? null,
            'auditable_id' => $data['auditable_id'] ?? null,
            'old_values' => $this->auditJson($data['old_values'] ?? null),
            'new_values' => $this->auditJson($data['new_values'] ?? null),
            'changed_fields' => $this->auditJson($data['changed_fields'] ?? null),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
            'device_name' => $request->header('X-Device-Name'),
            'terminal_name' => $request->header('X-Terminal-Name') ?: $request->header('X-Till-Name'),
            'session_id' => $this->safeSessionId($request),
            'status' => $data['status'] ?? 'success',
            'error_message' => $data['error_message'] ?? null,
            'metadata' => $this->auditJson($data['metadata'] ?? []),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $auditId;
    }

    protected function auditJson($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        return json_encode($value);
    }

    protected function safeSessionId(Request $request): ?string
    {
        try {
            return $request->hasSession() ? $request->session()->getId() : session()->getId();
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function jsonToArray($value): array
    {
        if (is_array($value)) return $value;
        if (is_object($value)) return json_decode(json_encode($value), true) ?: [];
        if (is_string($value) && $value !== '') return json_decode($value, true) ?: [];
        return [];
    }
}
