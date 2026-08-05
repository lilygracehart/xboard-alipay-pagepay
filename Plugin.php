<?php

namespace Plugin\AlipayPagepay;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Services\Plugin\AbstractPlugin;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Plugin\AlipayPagepay\library\AlipayPagePay;

class Plugin extends AbstractPlugin implements PaymentInterface
{
    public function boot(): void
    {
        $this->filter('available_payment_methods', function (array $methods): array {
            if ($this->getConfig('enabled', true)) {
                $methods['AlipayPagepay'] = [
                    'name' => '支付宝网页支付',
                    'icon' => '💙',
                    'plugin_code' => $this->getPluginCode(),
                    'type' => 'plugin',
                ];
            }

            return $methods;
        });
    }

    public function form(): array
    {
        return [
            'app_id' => [
                'label' => '支付宝应用 APPID',
                'type' => 'string',
                'required' => true,
                'description' => '支付宝开放平台应用的 APPID',
            ],
            'private_key' => [
                'label' => '应用私钥',
                'type' => 'text',
                'required' => true,
                'description' => 'PHP 使用 PKCS#1 应用私钥；严禁提交到代码仓库或写入日志',
            ],
            'alipay_public_key' => [
                'label' => '支付宝公钥',
                'type' => 'text',
                'required' => true,
                'description' => '用于验证支付宝异步通知签名，不是应用公钥',
            ],
            'seller_id' => [
                'label' => '支付宝商户 PID',
                'type' => 'string',
                'required' => true,
                'description' => '用于校验异步通知中的 seller_id',
            ],
            'environment' => [
                'label' => '运行环境',
                'type' => 'select',
                'default' => 'production',
                'options' => [
                    ['label' => '正式环境', 'value' => 'production'],
                    ['label' => '沙箱环境', 'value' => 'sandbox'],
                ],
                'description' => '上线前请选择正式环境并填写正式应用配置',
            ],
            'product_name' => [
                'label' => '商品名称',
                'type' => 'string',
                'default' => '',
                'description' => '将显示在支付宝账单中；留空时使用站点名称',
            ],
            'timeout_express' => [
                'label' => '支付有效期',
                'type' => 'string',
                'default' => '30m',
                'description' => '支付宝超时表达式，例如 30m、2h',
            ],
        ];
    }

    public function pay($order): array
    {
        $this->assertConfigured();

        $gateway = $this->gateway();
        $subject = trim((string) $this->getConfig('product_name', ''));
        if ($subject === '') {
            $subject = admin_setting('app_name', 'XBoard') . ' - 订阅';
        }
        $subject = trim((string) preg_replace('/[\/=&,]/u', ' ', $subject));
        if ($subject === '') {
            $subject = 'XBoard 订阅';
        }

        $html = $gateway->pageExecute([
            'out_trade_no' => (string) $order['trade_no'],
            'total_amount' => sprintf('%.2f', ((int) $order['total_amount']) / 100),
            'subject' => $subject,
            'product_code' => 'FAST_INSTANT_TRADE_PAY',
            'timeout_express' => (string) $this->getConfig('timeout_express', '30m'),
        ], (string) $order['notify_url'], (string) $order['return_url']);

        $token = Str::random(48);
        Cache::put($this->checkoutCacheKey($token), $html, now()->addMinutes(10));

        return [
            'type' => 1,
            'data' => URL::temporarySignedRoute(
                'xboard-alipay-pagepay.checkout',
                now()->addMinutes(10),
                ['token' => $token]
            ),
        ];
    }

    public function notify($params): array|bool
    {
        if (!is_array($params)) {
            return false;
        }

        $tradeStatus = $params['trade_status'] ?? null;
        if (!in_array($tradeStatus, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
            return false;
        }

        if (($params['sign_type'] ?? null) !== 'RSA2') {
            return false;
        }

        $required = ['sign', 'sign_type', 'app_id', 'seller_id', 'out_trade_no', 'trade_no', 'total_amount'];
        foreach ($required as $key) {
            if (!isset($params[$key]) || $params[$key] === '') {
                return false;
            }
        }

        if (!hash_equals((string) $this->getConfig('app_id'), (string) $params['app_id'])) {
            return false;
        }

        if (!hash_equals((string) $this->getConfig('seller_id'), (string) $params['seller_id'])) {
            return false;
        }

        try {
            if (!$this->gateway()->verifyNotification($params)) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        $order = Order::where('trade_no', (string) $params['out_trade_no'])->first();
        if (!$order) {
            return false;
        }

        $expectedAmount = (int) $order->total_amount + (int) ($order->handling_amount ?? 0);
        if ($this->amountToCents((string) $params['total_amount']) !== $expectedAmount) {
            return false;
        }

        return [
            'trade_no' => (string) $params['out_trade_no'],
            'callback_no' => (string) $params['trade_no'],
            'custom_result' => 'success',
        ];
    }

    public static function checkoutCacheKey(string $token): string
    {
        return 'alipay_pagepay_checkout:' . $token;
    }

    private function gateway(): AlipayPagePay
    {
        return new AlipayPagePay(
            appId: (string) $this->getConfig('app_id'),
            privateKey: (string) $this->getConfig('private_key'),
            alipayPublicKey: (string) $this->getConfig('alipay_public_key'),
            gatewayUrl: $this->getConfig('environment', 'production') === 'sandbox'
                ? 'https://openapi-sandbox.dl.alipaydev.com/gateway.do'
                : 'https://openapi.alipay.com/gateway.do'
        );
    }

    private function assertConfigured(): void
    {
        foreach (['app_id', 'private_key', 'alipay_public_key', 'seller_id'] as $key) {
            if (trim((string) $this->getConfig($key, '')) === '') {
                throw new ApiException("支付宝配置缺失：{$key}");
            }
        }
    }

    private function amountToCents(string $amount): ?int
    {
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            return null;
        }

        $fraction = str_pad($matches[2] ?? '', 2, '0');
        return ((int) $matches[1] * 100) + (int) $fraction;
    }
}
