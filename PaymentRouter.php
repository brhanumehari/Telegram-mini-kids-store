<?php
/**
 * PaymentRouter.php
 * ENG_251885 APP — Kids Store TMA
 *
 * Unified payment abstraction layer. `PaymentRouter` is the single entry
 * point used by api.php to (a) generate a merchant checkout link for any
 * supported provider, and (b) process inbound webhook callbacks and persist
 * verified payment confirmations back into the `orders` table.
 *
 * Each gateway is implemented as a small adapter behind a common
 * `PaymentProviderInterface`, so adding a new provider later means writing
 * one new class — the router and api.php never need to change.
 *
 * NOTE: The HTTP calls to each bank/PSP are intentionally written against
 * each provider's publicly documented REST conventions. Replace the
 * `// TODO` request bodies with the exact field names from your merchant
 * onboarding packet before going live — Ethiopian bank APIs are typically
 * issued under NDA and field names vary by merchant agreement.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

// =============================================================================
// Contracts
// =============================================================================

interface PaymentProviderInterface
{
    /**
     * Initiates a checkout session with the provider and returns a structure
     * the frontend can act on, e.g. ['type' => 'redirect', 'url' => '...']
     * or ['type' => 'telegram_invoice', 'payload' => '...'].
     */
    public function createCheckout(int $orderId, float $amount, int $telegramUserId): array;

    /**
     * Verifies the authenticity of an inbound webhook request (signature /
     * HMAC / shared-secret check). Must return false on any failure.
     */
    public function verifyWebhook(array $payload, array $headers): bool;

    /**
     * Extracts a normalized status from an already-verified webhook payload.
     * Returns one of: 'paid', 'failed', 'pending'.
     */
    public function extractStatus(array $payload): string;

    /** Extracts the provider's own transaction reference from the payload. */
    public function extractProviderReference(array $payload): ?string;

    /** Extracts the orderId this webhook refers to (from a merchant_ref field). */
    public function extractOrderId(array $payload): ?int;
}

abstract class AbstractPaymentProvider implements PaymentProviderInterface
{
    protected function httpPostJson(string $url, array $body, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response   = curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Payment gateway request failed: ' . $curlError);
        }
        if ($httpStatus >= 400) {
            throw new RuntimeException("Payment gateway returned HTTP {$httpStatus}: {$response}");
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : [];
    }

    protected function merchantReference(int $orderId): string
    {
        return 'ENG251885-' . $orderId . '-' . substr(hash('crc32b', (string) $orderId . microtime()), 0, 6);
    }
}

// =============================================================================
// Ethiopian local providers
// =============================================================================

/**
 * Telebirr — Ethio Telecom's mobile money SuperApp.
 * Flow: server generates a signed checkout request -> Telebirr returns a
 * toMustOpenUrl the frontend redirects/opens inside Telegram's WebApp.
 */
final class TelebirrProvider extends AbstractPaymentProvider
{
    public function createCheckout(int $orderId, float $amount, int $telegramUserId): array
    {
        if (TELEBIRR_APP_ID === '' || TELEBIRR_APP_KEY === '') {
            throw new RuntimeException('Telebirr is not configured on this server.');
        }

        $nonce = bin2hex(random_bytes(16));
        $merchantRef = $this->merchantReference($orderId);

        // Telebirr requires a raw request signed with the merchant's RSA
        // private key (or shared app key, per onboarding tier). Replace this
        // payload shape with the exact fields from your Telebirr contract.
        $payload = [
            'appId'        => TELEBIRR_APP_ID,
            'shortCode'    => TELEBIRR_SHORT_CODE,
            'nonceStr'     => $nonce,
            'outTradeNo'   => $merchantRef,
            'totalAmount'  => number_format($amount, 2, '.', ''),
            'notifyUrl'    => TELEBIRR_NOTIFY_URL,
            'subject'      => 'ENG_251885 Kids Store Order #' . $orderId,
            'timeoutExpress' => '30m',
        ];
        $payload['sign'] = hash_hmac('sha256', http_build_query($payload), TELEBIRR_APP_KEY);

        // TODO: replace with Telebirr's real "apply fund" / "createOrder" endpoint.
        $result = $this->httpPostJson('https://app.telebirr.com/api/order/createOrder', $payload);

        return [
            'type'            => 'redirect',
            'provider'        => 'telebirr',
            'merchant_ref'    => $merchantRef,
            'url'             => $result['toMustOpenUrl'] ?? null,
        ];
    }

    public function verifyWebhook(array $payload, array $headers): bool
    {
        if (!isset($payload['sign'], $payload['outTradeNo'])) {
            return false;
        }
        $sign = $payload['sign'];
        $copy = $payload;
        unset($copy['sign']);
        ksort($copy);
        $expected = hash_hmac('sha256', http_build_query($copy), TELEBIRR_APP_KEY);
        return hash_equals($expected, $sign);
    }

    public function extractStatus(array $payload): string
    {
        return ($payload['tradeStatus'] ?? '') === 'SUCCESS' ? 'paid' : 'failed';
    }

    public function extractProviderReference(array $payload): ?string
    {
        return $payload['tradeNo'] ?? null;
    }

    public function extractOrderId(array $payload): ?int
    {
        $ref = $payload['outTradeNo'] ?? '';
        return self::parseOrderIdFromRef($ref);
    }

    public static function parseOrderIdFromRef(string $ref): ?int
    {
        return preg_match('/^ENG251885-(\d+)-/', $ref, $m) ? (int) $m[1] : null;
    }
}

/** Commercial Bank of Ethiopia — CBE Birr direct API integration. */
final class CbeBirrProvider extends AbstractPaymentProvider
{
    public function createCheckout(int $orderId, float $amount, int $telegramUserId): array
    {
        if (CBE_MERCHANT_ID === '' || CBE_API_KEY === '') {
            throw new RuntimeException('CBE Birr is not configured on this server.');
        }

        $merchantRef = $this->merchantReference($orderId);

        // TODO: replace with CBE's real checkout-session endpoint & field names.
        $result = $this->httpPostJson(CBE_API_BASE . '/checkout/sessions', [
            'merchant_id'  => CBE_MERCHANT_ID,
            'amount'       => number_format($amount, 2, '.', ''),
            'currency'     => 'ETB',
            'reference'    => $merchantRef,
            'description'  => 'ENG_251885 Kids Store Order #' . $orderId,
        ], ['Authorization: Bearer ' . CBE_API_KEY]);

        return [
            'type'         => 'redirect',
            'provider'     => 'cbe_birr',
            'merchant_ref' => $merchantRef,
            'url'          => $result['checkout_url'] ?? null,
        ];
    }

    public function verifyWebhook(array $payload, array $headers): bool
    {
        $signature = $headers['x-cbe-signature'] ?? '';
        if ($signature === '') {
            return false;
        }
        $expected = hash_hmac('sha256', json_encode($payload), CBE_API_KEY);
        return hash_equals($expected, $signature);
    }

    public function extractStatus(array $payload): string
    {
        return strtoupper((string) ($payload['status'] ?? '')) === 'SUCCESS' ? 'paid' : 'failed';
    }

    public function extractProviderReference(array $payload): ?string
    {
        return $payload['transaction_id'] ?? null;
    }

    public function extractOrderId(array $payload): ?int
    {
        return TelebirrProvider::parseOrderIdFromRef((string) ($payload['reference'] ?? ''));
    }
}

/** Dashen Bank — Amole/Dashen wallet API integration. */
final class DashenAmoleProvider extends AbstractPaymentProvider
{
    public function createCheckout(int $orderId, float $amount, int $telegramUserId): array
    {
        if (DASHEN_MERCHANT_ID === '' || DASHEN_API_KEY === '') {
            throw new RuntimeException('Dashen Amole is not configured on this server.');
        }

        $merchantRef = $this->merchantReference($orderId);

        // TODO: replace with Amole's real payment-request endpoint & field names.
        $result = $this->httpPostJson(DASHEN_API_BASE . '/payments/request', [
            'merchantId'  => DASHEN_MERCHANT_ID,
            'amount'      => number_format($amount, 2, '.', ''),
            'currency'    => 'ETB',
            'merchantRef' => $merchantRef,
            'narrative'   => 'ENG_251885 Kids Store Order #' . $orderId,
        ], ['X-Api-Key: ' . DASHEN_API_KEY]);

        return [
            'type'         => 'redirect',
            'provider'     => 'dashen_amole',
            'merchant_ref' => $merchantRef,
            'url'          => $result['paymentUrl'] ?? null,
        ];
    }

    public function verifyWebhook(array $payload, array $headers): bool
    {
        $signature = $headers['x-amole-signature'] ?? '';
        if ($signature === '') {
            return false;
        }
        $expected = hash_hmac('sha256', json_encode($payload), DASHEN_API_KEY);
        return hash_equals($expected, $signature);
    }

    public function extractStatus(array $payload): string
    {
        return strtolower((string) ($payload['status'] ?? '')) === 'completed' ? 'paid' : 'failed';
    }

    public function extractProviderReference(array $payload): ?string
    {
        return $payload['txnId'] ?? null;
    }

    public function extractOrderId(array $payload): ?int
    {
        return TelebirrProvider::parseOrderIdFromRef((string) ($payload['merchantRef'] ?? ''));
    }
}

/** Awash Bank — Awash Birr API integration. */
final class AwashBirrProvider extends AbstractPaymentProvider
{
    public function createCheckout(int $orderId, float $amount, int $telegramUserId): array
    {
        if (AWASH_MERCHANT_ID === '' || AWASH_API_KEY === '') {
            throw new RuntimeException('Awash Birr is not configured on this server.');
        }

        $merchantRef = $this->merchantReference($orderId);

        // TODO: replace with Awash's real checkout endpoint & field names.
        $result = $this->httpPostJson(AWASH_API_BASE . '/checkout', [
            'merchant_id' => AWASH_MERCHANT_ID,
            'amount'      => number_format($amount, 2, '.', ''),
            'currency'    => 'ETB',
            'reference'   => $merchantRef,
        ], ['Authorization: Bearer ' . AWASH_API_KEY]);

        return [
            'type'         => 'redirect',
            'provider'     => 'awash_birr',
            'merchant_ref' => $merchantRef,
            'url'          => $result['redirect_url'] ?? null,
        ];
    }

    public function verifyWebhook(array $payload, array $headers): bool
    {
        $signature = $headers['x-awash-signature'] ?? '';
        if ($signature === '') {
            return false;
        }
        $expected = hash_hmac('sha256', json_encode($payload), AWASH_API_KEY);
        return hash_equals($expected, $signature);
    }

    public function extractStatus(array $payload): string
    {
        return strtoupper((string) ($payload['status'] ?? '')) === 'PAID' ? 'paid' : 'failed';
    }

    public function extractProviderReference(array $payload): ?string
    {
        return $payload['transaction_ref'] ?? null;
    }

    public function extractOrderId(array $payload): ?int
    {
        return TelebirrProvider::parseOrderIdFromRef((string) ($payload['reference'] ?? ''));
    }
}

// =============================================================================
// International fallback providers
// =============================================================================

/** PayPal REST SDK (Orders v2 API). */
final class PaypalProvider extends AbstractPaymentProvider
{
    private function getAccessToken(): string
    {
        $ch = curl_init(PAYPAL_API_BASE . '/v1/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
            CURLOPT_USERPWD        => PAYPAL_CLIENT_ID . ':' . PAYPAL_CLIENT_SECRET,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $response, true);
        if (!isset($data['access_token'])) {
            throw new RuntimeException('Could not authenticate with PayPal.');
        }
        return $data['access_token'];
    }

    public function createCheckout(int $orderId, float $amount, int $telegramUserId): array
    {
        if (PAYPAL_CLIENT_ID === '' || PAYPAL_CLIENT_SECRET === '') {
            throw new RuntimeException('PayPal is not configured on this server.');
        }

        $merchantRef = $this->merchantReference($orderId);
        $token = $this->getAccessToken();

        $result = $this->httpPostJson(PAYPAL_API_BASE . '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $merchantRef,
                'amount' => [
                    'currency_code' => 'USD',
                    'value' => number_format($amount, 2, '.', ''),
                ],
                'description' => 'ENG_251885 Kids Store Order #' . $orderId,
            ]],
        ], ['Authorization: Bearer ' . $token]);

        $approveUrl = null;
        foreach ($result['links'] ?? [] as $link) {
            if (($link['rel'] ?? '') === 'approve') {
                $approveUrl = $link['href'];
                break;
            }
        }

        return [
            'type'         => 'redirect',
            'provider'     => 'paypal',
            'merchant_ref' => $merchantRef,
            'paypal_order_id' => $result['id'] ?? null,
            'url'          => $approveUrl,
        ];
    }

    public function verifyWebhook(array $payload, array $headers): bool
    {
        // Production implementation should call PayPal's
        // /v1/notifications/verify-webhook-signature endpoint with the
        // transmission headers below rather than trusting the body alone.
        return isset($headers['paypal-transmission-id'], $headers['paypal-transmission-sig']);
    }

    public function extractStatus(array $payload): string
    {
        $status = $payload['resource']['status'] ?? ($payload['status'] ?? '');
        return strtoupper((string) $status) === 'COMPLETED' ? 'paid' : 'failed';
    }

    public function extractProviderReference(array $payload): ?string
    {
        return $payload['resource']['id'] ?? null;
    }

    public function extractOrderId(array $payload): ?int
    {
        $ref = $payload['resource']['purchase_units'][0]['reference_id'] ?? '';
        return TelebirrProvider::parseOrderIdFromRef((string) $ref);
    }
}

/** MasterCard Payment Gateway Services (MPGS). */
final class MastercardMpgsProvider extends AbstractPaymentProvider
{
    public function createCheckout(int $orderId, float $amount, int $telegramUserId): array
    {
        if (MPGS_MERCHANT_ID === '' || MPGS_API_PASSWORD === '') {
            throw new RuntimeException('MasterCard MPGS is not configured on this server.');
        }

        $merchantRef = $this->merchantReference($orderId);

        $result = $this->httpPostJson(
            MPGS_API_BASE . '/merchant/' . MPGS_MERCHANT_ID . '/session',
            [
                'apiOperation' => 'CREATE_CHECKOUT_SESSION',
                'order' => [
                    'id'     => $merchantRef,
                    'amount' => number_format($amount, 2, '.', ''),
                    'currency' => 'USD',
                    'description' => 'ENG_251885 Kids Store Order #' . $orderId,
                ],
                'interaction' => ['operation' => 'PURCHASE'],
            ],
            ['Authorization: Basic ' . base64_encode('merchant.' . MPGS_MERCHANT_ID . ':' . MPGS_API_PASSWORD)]
        );

        return [
            'type'         => 'embedded_checkout',
            'provider'     => 'mastercard',
            'merchant_ref' => $merchantRef,
            'session_id'   => $result['session']['id'] ?? null,
        ];
    }

    public function verifyWebhook(array $payload, array $headers): bool
    {
        $signature = $headers['x-mpgs-signature'] ?? '';
        if ($signature === '') {
            return false;
        }
        $expected = hash_hmac('sha256', json_encode($payload), MPGS_API_PASSWORD);
        return hash_equals($expected, $signature);
    }

    public function extractStatus(array $payload): string
    {
        $result = $payload['result'] ?? '';
        return strtoupper((string) $result) === 'SUCCESS' ? 'paid' : 'failed';
    }

    public function extractProviderReference(array $payload): ?string
    {
        return $payload['transaction']['id'] ?? null;
    }

    public function extractOrderId(array $payload): ?int
    {
        return TelebirrProvider::parseOrderIdFromRef((string) ($payload['order']['id'] ?? ''));
    }
}

// =============================================================================
// Router
// =============================================================================

final class PaymentRouter
{
    private PDO $pdo;
    /** @var array<string, PaymentProviderInterface> */
    private array $providers;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->providers = [
            'telebirr'       => new TelebirrProvider(),
            'cbe_birr'       => new CbeBirrProvider(),
            'dashen_amole'   => new DashenAmoleProvider(),
            'awash_birr'     => new AwashBirrProvider(),
            'paypal'         => new PaypalProvider(),
            'mastercard'     => new MastercardMpgsProvider(),
        ];
    }

    private function resolveProvider(string $method): PaymentProviderInterface
    {
        if (!isset($this->providers[$method])) {
            throw new RuntimeException("Unsupported payment method: {$method}");
        }
        return $this->providers[$method];
    }

    /**
     * Step 1 of checkout: generate a merchant checkout link/session for the
     * given order and payment method. Called immediately after an order row
     * is inserted with payment_status = 'pending'.
     */
    public function createCheckout(int $orderId, string $method, float $amount, int $telegramUserId): array
    {
        // telegram_stars is handled natively via Telegram Bot API
        // invoices (sendInvoice / answerPreCheckoutQuery) rather than an
        // external redirect — the frontend calls Telegram.WebApp.openInvoice
        // directly, so the router simply signals that mode here.
        if ($method === 'telegram_stars') {
            return ['type' => 'telegram_invoice', 'provider' => 'telegram_stars', 'order_id' => $orderId];
        }

        $provider = $this->resolveProvider($method);
        return $provider->createCheckout($orderId, $amount, $telegramUserId);
    }

    /**
     * Step 2 of checkout: process an inbound webhook from a given provider,
     * verify its authenticity, and atomically update the matching order row.
     *
     * @return array{ok:bool, order_id:?int, status:?string}
     */
    public function handleWebhook(string $method, array $payload, array $headers): array
    {
        $provider = $this->resolveProvider($method);

        if (!$provider->verifyWebhook($payload, $headers)) {
            error_log("[PaymentRouter] Webhook signature verification failed for provider={$method}");
            return ['ok' => false, 'order_id' => null, 'status' => null];
        }

        $orderId    = $provider->extractOrderId($payload);
        $status     = $provider->extractStatus($payload);
        $providerRef = $provider->extractProviderReference($payload);

        if ($orderId === null) {
            error_log("[PaymentRouter] Could not resolve order_id from webhook payload (provider={$method})");
            return ['ok' => false, 'order_id' => null, 'status' => $status];
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('
                UPDATE orders
                SET payment_status = :status,
                    provider_reference = :provider_ref,
                    webhook_payload = :payload,
                    paid_at = CASE WHEN :status2 = "paid" THEN NOW() ELSE paid_at END
                WHERE id = :order_id
            ');
            $stmt->execute([
                'status'        => $status,
                'provider_ref'  => $providerRef,
                'payload'       => json_encode($payload),
                'status2'       => $status,
                'order_id'      => $orderId,
            ]);

            if ($status === 'paid') {
                $userStmt = $this->pdo->prepare('
                    UPDATE users u
                    JOIN orders o ON o.user_id = u.telegram_id
                    SET u.total_spent = u.total_spent + o.total_amount
                    WHERE o.id = :order_id
                ');
                $userStmt->execute(['order_id' => $orderId]);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            error_log('[PaymentRouter] Failed to persist webhook update: ' . $e->getMessage());
            return ['ok' => false, 'order_id' => $orderId, 'status' => $status];
        }

        return ['ok' => true, 'order_id' => $orderId, 'status' => $status];
    }
}

// =============================================================================
// Webhook HTTP entry point — only runs when this file is hit directly, e.g.
// https://yourdomain.com/PaymentRouter.php?provider=telebirr
// =============================================================================
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    header('Content-Type: application/json; charset=utf-8');

    $provider = $_GET['provider'] ?? '';
    $rawBody  = file_get_contents('php://input');
    $payload  = json_decode($rawBody, true) ?: [];

    $headers = [];
    foreach (getallheaders() as $key => $value) {
        $headers[strtolower($key)] = $value;
    }

    try {
        $pdo = get_pdo();
        $router = new PaymentRouter($pdo);
        $result = $router->handleWebhook($provider, $payload, $headers);

        http_response_code($result['ok'] ? 200 : 400);
        echo json_encode($result);
    } catch (Throwable $e) {
        error_log('[PaymentRouter webhook] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Internal error']);
    }
}
