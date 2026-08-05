<?php

namespace Plugin\AlipayPagepay\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Plugin\AlipayPagepay\Plugin;

class CheckoutController extends Controller
{
    public function __invoke(string $token): Response
    {
        $html = Cache::get(Plugin::checkoutCacheKey($token));
        if (!is_string($html) || $html === '') {
            abort(410, '支付跳转已过期，请返回订单页面重新发起支付。');
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; form-action https://openapi.alipay.com https://openapi-sandbox.dl.alipaydev.com; script-src 'unsafe-inline'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'",
        ]);
    }
}
