<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,
    // TelescopeServiceProvider is registered conditionally (local only)
    // in AppServiceProvider::register() so production builds (--no-dev) work.
];
