<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, int $id) {
    return (int) $user->id === $id;
});

Broadcast::channel('project.{projectId}', function ($user, int $projectId) {
    return $user->projects()->where('projects.id', $projectId)->exists();
});
