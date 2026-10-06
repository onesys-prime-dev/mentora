<?php

// Overrides for the sign-in library's (Shield) messages.
return [
    // Shield says "check your password" when the email has an account and
    // "check your credentials" when it does not, which tells anyone who tries
    // an address whether it is registered. Both refusals use the same wording
    // (Shield's own "badAttempt" text). AuthFlowTest checks that they match.
    'invalidPassword' => 'Unable to log you in. Please check your credentials.',
];
