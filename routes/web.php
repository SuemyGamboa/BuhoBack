<?php

use App\Http\Controllers\Api\StripeBillingController;
use Illuminate\Support\Facades\Route;

Route::post('/stripe/webhook', [StripeBillingController::class, 'webhook']);

Route::get('/', function () {
    return view('welcome');
});
