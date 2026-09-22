<?php

namespace Modules\Authentication\Observers;

use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;

class SecurityAuditObserver
{
    public function __construct(private AuditLogger $logger) {}

    public function created(Model $model): void
    {
        $this->write('created', $model, null, $model->getAttributes());
    }

    public function updated(Model $model): void
    {
        $changes = $model->getChanges();
        $before = collect($model->getOriginal())->only(array_keys($changes))->all();
        $this->write('updated', $model, $before, $changes);
    }

    public function deleted(Model $model): void
    {
        $this->write('deleted', $model, $model->getOriginal(), null);
    }

    private function write(string $event, Model $model, ?array $before, ?array $after): void
    {
        if (! app()->bound('request')) return;

        $actor = request()->user();
        if ($actor === null) return;

        $hidden = ['password', 'pin_hash', 'secret', 'recovery_code_hashes', 'device_key_hash'];
        $this->logger->log(
            $actor,
            $model->getTable().'.'.$event,
            $model,
            $before === null ? null : collect($before)->except($hidden)->all(),
            $after === null ? null : collect($after)->except($hidden)->all(),
        );
    }
}
