<?php

use Illuminate\Support\Facades\Route;
use Plugin\AlipayPagepay\Controllers\CheckoutController;

Route::get('/xboard-alipay-pagepay/checkout/{token}', CheckoutController::class)
    ->middleware('signed')
    ->name('xboard-alipay-pagepay.checkout');
