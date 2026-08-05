<?php

namespace Plugin\AlipayPagepay\library;

use RuntimeException;

class AlipayPagePay
{
    public function __construct(
        private readonly string $appId,
        private readonly string $privateKey,
        private readonly string $alipayPublicKey,
        private readonly string $gatewayUrl
    ) {
    }

    public function pageExecute(array $bizContent, string $notifyUrl, string $returnUrl): string
    {
        $params = [
            'app_id' => $this->appId,
            'method' => 'alipay.trade.page.pay',
            'format' => 'json',
            'charset' => 'UTF-8',
            'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'notify_url' => $notifyUrl,
            'return_url' => $returnUrl,
            'biz_content' => json_encode(
                $bizContent,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
        ];

        $params['sign'] = $this->sign($this->canonicalize($params));

        $inputs = '';
        foreach ($params as $name => $value) {
            $inputs .= sprintf(
                '<input type="hidden" name="%s" value="%s">',
                htmlspecialchars((string) $name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            );
        }

        $action = htmlspecialchars($this->gatewayUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="referrer" content="no-referrer"><title>正在前往支付宝收银台</title></head>'
            . '<body><form id="alipay-pagepay-form" method="post" action="' . $action . '">'
            . $inputs . '<noscript><button type="submit">继续前往支付宝</button></noscript></form>'
            . '<script>document.getElementById("alipay-pagepay-form").submit();</script></body></html>';
    }

    public function verifyNotification(array $params): bool
    {
        $signature = (string) ($params['sign'] ?? '');
        unset($params['sign'], $params['sign_type']);

        if ($signature === '') {
            return false;
        }

        $signatureBytes = base64_decode($signature, true);
        if ($signatureBytes === false) {
            return false;
        }

        $publicKey = openssl_pkey_get_public($this->normalizePublicKey($this->alipayPublicKey));
        if ($publicKey === false) {
            throw new RuntimeException('支付宝公钥不可用');
        }

        return openssl_verify(
            $this->canonicalize($params),
            $signatureBytes,
            $publicKey,
            OPENSSL_ALGO_SHA256
        ) === 1;
    }

    private function sign(string $content): string
    {
        $privateKey = openssl_pkey_get_private($this->normalizePrivateKey($this->privateKey));
        if ($privateKey === false) {
            throw new RuntimeException('支付宝应用私钥不可用');
        }

        $signature = '';
        if (!openssl_sign($content, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('支付宝请求签名失败');
        }

        return base64_encode($signature);
    }

    private function canonicalize(array $params): string
    {
        $params = array_filter($params, static fn ($value): bool => $value !== null && $value !== '');
        ksort($params);

        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }

        return implode('&', $pairs);
    }

    private function normalizePrivateKey(string $key): string
    {
        $key = trim(str_replace(["\r\n", "\r"], "\n", $key));
        if (str_contains($key, 'BEGIN PRIVATE KEY') || str_contains($key, 'BEGIN RSA PRIVATE KEY')) {
            return $key;
        }

        $body = preg_replace('/\s+/', '', $key);
        return "-----BEGIN RSA PRIVATE KEY-----\n"
            . chunk_split((string) $body, 64, "\n")
            . "-----END RSA PRIVATE KEY-----";
    }

    private function normalizePublicKey(string $key): string
    {
        $key = trim(str_replace(["\r\n", "\r"], "\n", $key));
        if (str_contains($key, 'BEGIN PUBLIC KEY')) {
            return $key;
        }

        $body = preg_replace('/\s+/', '', $key);
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split((string) $body, 64, "\n")
            . "-----END PUBLIC KEY-----";
    }
}
