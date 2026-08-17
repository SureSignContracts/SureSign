<?php

use App\Providers\AppServiceProvider;
use App\Providers\BillingServiceProvider;
use App\Providers\EntitlementServiceProvider;
use App\Providers\GeocodingServiceProvider;
use App\Providers\GoogleServiceProvider;

return [
    AppServiceProvider::class,
    BillingServiceProvider::class,
    EntitlementServiceProvider::class,
    GeocodingServiceProvider::class,
    GoogleServiceProvider::class,
];
