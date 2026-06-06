<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Auto-populates `created_by` / `updated_by` / `deleted_by` from the
 * authenticated user. Safe to use in seeders, jobs, and tests where there is
 * no authenticated user — the columns simply stay null.
 *
 * Requires the table to have nullable `created_by`, `updated_by`, and
 * (when soft-deleting) `deleted_by` columns referencing `users`.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::creating(function (Model $model) {
            $id = Auth::id();
            if ($id !== null) {
                $model->created_by ??= $id;
                $model->updated_by ??= $id;
            }
        });

        static::updating(function (Model $model) {
            $id = Auth::id();
            if ($id !== null) {
                $model->updated_by = $id;
            }
        });

        static::deleting(function (Model $model) {
            $id = Auth::id();
            if ($id !== null && in_array('deleted_by', $model->getFillable(), true)) {
                $model->deleted_by = $id;
                $model->saveQuietly();
            }
        });
    }
}
