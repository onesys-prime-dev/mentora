<?php

use App\Services\Auth\ActiveRoleService;

if (! function_exists('active_role')) {

    /**
     * The logged-in user's current role (instructor, chairperson, ...),
     * re-validated against their real Shield groups on every call. Empty
     * string if nobody's logged in or they hold no usable group.
     */
    function active_role(): string
    {
        if (! auth()->loggedIn()) {
            return '';
        }

        return (new ActiveRoleService())->resolve(auth()->user());
    }
}
