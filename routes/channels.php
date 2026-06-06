<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/**
 * Presence channel for live unit map per project (BR-LNC, RULE-06).
 * Auth: any authenticated user with active tenant access.
 * Returned payload appears in presence channel members list.
 */
Broadcast::channel('project.{projectId}.units', function (User $user, int $projectId) {
    if (! $user->is_active) {
        return false;
    }

    return [
        'id' => $user->id,
        'name' => $user->full_name ?? $user->name,
    ];
});
