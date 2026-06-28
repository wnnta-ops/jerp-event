<?php

declare(strict_types=1);

namespace App\Domain\Foundation\Audit\Observers;

use App\Domain\Foundation\Audit\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Auto-record CRUD events ke audit_logs. Dipasang via HasAuditTrail trait.
 */
final class AuditObserver
{
    public function created(Model $model): void
    {
        $this->write($model, 'created', null, $model->getAttributes());
    }

    public function updated(Model $model): void
    {
        if (count($model->getDirty()) === 0) {
            return;
        }
        $this->write($model, 'updated', $model->getOriginal(), $model->getChanges());
    }

    public function deleted(Model $model): void
    {
        $event = method_exists($model, 'trashed') && $model->trashed() ? 'soft_deleted' : 'deleted';
        $this->write($model, $event, $model->getOriginal(), null);
    }

    public function restored(Model $model): void
    {
        $this->write($model, 'restored', null, $model->getAttributes());
    }

    /**
     * @param array<string, mixed>|null $old
     * @param array<string, mixed>|null $new
     */
    private function write(Model $model, string $event, ?array $old, ?array $new): void
    {
        $excluded = method_exists($model, 'auditExcluded') ? $model->auditExcluded() : [];

        $context = app()->bound('audit.context') ? app('audit.context') : [];

        AuditLog::query()->create([
            'tenant_id'      => $model->getAttribute('tenant_id') ?? tenant_id(),
            'user_id'        => auth()->id(),
            'event'          => $event,
            'auditable_type' => $model->getMorphClass(),
            'auditable_id'   => $model->getKey(),
            'old_values'     => $old !== null ? array_diff_key($old, array_flip($excluded)) : null,
            'new_values'     => $new !== null ? array_diff_key($new, array_flip($excluded)) : null,
            'context'        => $context,
            'ip_address'     => $context['ip']         ?? null,
            'user_agent'     => $context['user_agent'] ?? null,
            'created_at'     => now(),
        ]);
    }
}
