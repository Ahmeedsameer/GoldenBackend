<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/*
| Private channel: each user may only listen to their own notifications.
| The authenticated user is resolved by the broadcasting/auth route's "api"
| (JWT) middleware, so $user is the current JWT user.
*/
Broadcast::channel('notifications.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
