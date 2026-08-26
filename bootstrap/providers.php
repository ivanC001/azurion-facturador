<?php

use App\Providers\AppServiceProvider;
use App\Providers\FacturadorServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    FacturadorServiceProvider::class,
    AppServiceProvider::class,
    HorizonServiceProvider::class,
];
