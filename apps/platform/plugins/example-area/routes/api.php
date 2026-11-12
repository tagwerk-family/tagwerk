<?php

declare(strict_types=1);

use Fahoba\PluginApi\Households\CurrentHousehold;
use Illuminate\Support\Facades\Route;

Route::get('/greeting', fn (CurrentHousehold $household): array => [
    'household' => $household->name(),
]);
