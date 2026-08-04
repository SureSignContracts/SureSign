<?php

use App\Providers\AppServiceProvider;
use App\Providers\BillingServiceProvider;
use App\Providers\EntitlementServiceProvider;
use App\Providers\GoogleServiceProvider;

return [
    AppServiceProvider::class,
    BillingServiceProvider::class,
    EntitlementServiceProvider::class,
    GoogleServiceProvider::class,
];
