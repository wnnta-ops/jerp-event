<?php

declare(strict_types=1);

if (! function_exists('tenant_id')) {
    /**
     * Ambil tenant_id aktif dari request context.
     * Set otomatis oleh middleware SetTenant.
     */
    function tenant_id(): ?int
    {
        return app()->bound('tenant.id') ? (int) app('tenant.id') : null;
    }
}

if (! function_exists('default_tenant_id')) {
    /**
     * Tenant default (MUP) sebagai jaring pengaman ketika konteks request tidak
     * meng-set tenant.id (mis. job antrian, webhook, command). Di-cache per request.
     */
    function default_tenant_id(): ?int
    {
        static $cached = false;
        static $id = null;

        if ($cached) {
            return $id;
        }

        $cached = true;
        $id = \App\Domain\Foundation\Tenant\Models\Tenant::query()
            ->whereRaw('LOWER(code) = ?', ['mup'])
            ->value('id');

        $id ??= \App\Domain\Foundation\Tenant\Models\Tenant::query()->orderBy('id')->value('id');

        return $id !== null ? (int) $id : null;
    }
}

if (! function_exists('current_project_id')) {
    /**
     * Project context aktif (di-set saat user pilih project dari topbar atau dari session).
     */
    function current_project_id(): ?int
    {
        $user = auth()->user();
        if (! $user) {
            return null;
        }

        return (int) session('current_project_id', $user->projects()->first()?->id ?? 0) ?: null;
    }
}
