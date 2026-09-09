<?php

namespace App\Payments;

use App\Contracts\PaymentGateway;
use App\Support\PaymentAttemptReference;
use App\Support\PaymentMethod;
use App\Support\VnPayAmount;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Config\Repository;
use RuntimeException;

final class VnPayGateway implements PaymentGateway
{
    private const COMMAND_PAY = 'pay';

    private const SUPPORTED_VERSION = '2.1.0';

    public function __construct(private readonly Repository $config) {}

    public function gatewayName(): string
    {
        return PaymentMethod::VNPAY;
    }

    public function assertConfigured(): void
    {
        $this->validatedSettings();
    }

    public function buildPaymentUrl(PaymentUrlRequest $request): string
    {
        $settings = $this->validatedSettings();
        $this->assertPaymentRequest($request, $settings);

        $timezone = new DateTimeZone($settings['timezone']);
        $parameters = [
            'vnp_Version' => $settings['version'],
            'vnp_Command' => self::COMMAND_PAY,
            'vnp_TmnCode' => $settings['tmn_code'],
            'vnp_Amount' => VnPayAmount::toGatewayAmount($request->amount, $request->currency),
            'vnp_CurrCode' => $settings['currency'],
            'vnp_TxnRef' => $request->merchantReference,
            'vnp_OrderInfo' => trim($request->orderInfo),
            'vnp_OrderType' => $settings['order_type'],
            'vnp_Locale' => $settings['locale'],
            'vnp_ReturnUrl' => $settings['return_url'],
            'vnp_IpAddr' => $request->clientIp,
            'vnp_CreateDate' => $request->createdAt->setTimezone($timezone)->format('YmdHis'),
            'vnp_ExpireDate' => $request->expiresAt->setTimezone($timezone)->format('YmdHis'),
        ];

        if ($request->bankCode !== null && trim($request->bankCode) !== '') {
            $parameters['vnp_BankCode'] = trim($request->bankCode);
        }

        $query = $this->canonicalize($parameters);

        return $settings['payment_url'].'?'.$query.'&vnp_SecureHash='.$this->hash($query, $settings['hash_secret']);
    }

    public function verifyReturn(array $parameters): VerifiedPaymentEvent
    {
        return $this->verifyEvent($parameters, VerifiedPaymentEvent::TYPE_RETURN);
    }

    public function verifyIpn(array $parameters): VerifiedPaymentEvent
    {
        return $this->verifyEvent($parameters, VerifiedPaymentEvent::TYPE_IPN);
    }

    public function buildQueryRequest(QueryTransactionRequest $request): array
    {
        $settings = $this->validatedSettings();
        if (preg_match('/^[A-Za-z0-9]{1,32}$/D', $request->requestId) !== 1
            || preg_match('/^[A-Za-z0-9]{1,100}$/D', $request->merchantReference) !== 1
            || filter_var($request->serverIp, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException('Invalid VNPay query identity.');
        }
        $timezone = new DateTimeZone($settings['timezone']);
        // QueryDR uses ordered, pipe-separated VALUES, not the pay URL canonicalizer.
        $fields = [
            'vnp_RequestId' => $request->requestId,
            'vnp_Version' => $settings['version'],
            'vnp_Command' => 'querydr',
            'vnp_TmnCode' => $settings['tmn_code'],
            'vnp_TxnRef' => $request->merchantReference,
            'vnp_TransactionDate' => $request->transactionDate->setTimezone($timezone)->format('YmdHis'),
            'vnp_CreateDate' => $request->createdAt->setTimezone($timezone)->format('YmdHis'),
            'vnp_IpAddr' => $request->serverIp,
            'vnp_OrderInfo' => 'Query '.$request->merchantReference,
        ];
        $fields['vnp_SecureHash'] = $this->hash(implode('|', $fields), $settings['hash_secret']);

        return $fields;
    }

    public function verifyQueryResponse(array $parameters, QueryTransactionRequest $request): VerifiedPaymentEvent
    {
        $settings = $this->validatedSettings();
        // Official QueryDR response order includes empty optional promotion fields.
        $keys = ['vnp_ResponseId', 'vnp_Command', 'vnp_ResponseCode', 'vnp_Message',
            'vnp_TmnCode', 'vnp_TxnRef', 'vnp_Amount', 'vnp_BankCode', 'vnp_PayDate',
            'vnp_TransactionNo', 'vnp_TransactionType', 'vnp_TransactionStatus',
            'vnp_OrderInfo', 'vnp_PromotionCode', 'vnp_PromotionAmount'];
        $values = [];
        foreach ($keys as $key) {
            $value = $parameters[$key] ?? '';
            if (! is_string($value) && ! is_int($value)) {
                throw new GatewayVerificationException(PaymentEventOutcome::InvalidEvent);
            }
            if (str_contains((string) $value, '|')) {
                throw new GatewayVerificationException(PaymentEventOutcome::InvalidEvent);
            }
            $values[] = (string) $value;
        }
        $hash = $parameters['vnp_SecureHash'] ?? null;
        if (! is_string($hash) || preg_match('/^[a-fA-F0-9]{128}$/D', $hash) !== 1
            || ! hash_equals($this->hash(implode('|', $values), $settings['hash_secret']), strtolower($hash))) {
            throw new GatewayVerificationException(PaymentEventOutcome::InvalidSignature);
        }
        foreach (['vnp_ResponseId', 'vnp_TmnCode', 'vnp_TxnRef', 'vnp_ResponseCode', 'vnp_Message',
            'vnp_Amount', 'vnp_OrderInfo', 'vnp_BankCode', 'vnp_TransactionNo',
            'vnp_TransactionType', 'vnp_TransactionStatus'] as $required) {
            if (! isset($parameters[$required]) || (string) $parameters[$required] === '') {
                throw new GatewayVerificationException(PaymentEventOutcome::InvalidEvent);
            }
        }
        if ((string) $parameters['vnp_TmnCode'] !== $settings['tmn_code']
            || (string) $parameters['vnp_TxnRef'] !== $request->merchantReference
            || preg_match('/^[A-Za-z0-9]{1,32}$/D', (string) $parameters['vnp_ResponseId']) !== 1
            || (isset($parameters['vnp_Command']) && $parameters['vnp_Command'] !== 'querydr')
            || (isset($parameters['vnp_Version']) && $parameters['vnp_Version'] !== $settings['version'])
            || (isset($parameters['vnp_CurrCode']) && $parameters['vnp_CurrCode'] !== 'VND')
            || (string) $parameters['vnp_TransactionType'] !== '01'
            || preg_match('/^\d{2}$/D', (string) $parameters['vnp_ResponseCode']) !== 1
            || preg_match('/^\d{2}$/D', (string) $parameters['vnp_TransactionStatus']) !== 1
            || preg_match('/^\d{1,15}$/D', (string) $parameters['vnp_TransactionNo']) !== 1) {
            throw new GatewayVerificationException(PaymentEventOutcome::InvalidEvent);
        }
        // 91/not-found and other query errors are uncertainty, never proof of no funds.
        if ((string) $parameters['vnp_ResponseCode'] !== '00') {
            throw new GatewayVerificationException(PaymentEventOutcome::ReconciliationRequired);
        }
        try {
            $amount = VnPayAmount::toDecimal((string) $parameters['vnp_Amount'], 'VND');
        } catch (RuntimeException) {
            throw new GatewayVerificationException(PaymentEventOutcome::AmountMismatch);
        }
        $number = ltrim((string) $parameters['vnp_TransactionNo'], '0') ?: '0';
        $paid = (string) $parameters['vnp_TransactionStatus'] === '00';
        $date = $this->parseGatewayDate($parameters['vnp_PayDate'] ?? null, $settings['timezone']);
        if ($paid && ($number === '0' || ! $date)) {
            throw new GatewayVerificationException(PaymentEventOutcome::InvalidEvent);
        }

        return new VerifiedPaymentEvent(
            VerifiedPaymentEvent::TYPE_QUERY, PaymentMethod::VNPAY, $request->merchantReference,
            $number, $amount, 'VND', '00', (string) $parameters['vnp_TransactionStatus'], $paid, $date,
            ['request_id' => $request->requestId],
        );
    }

    /** @param array<string, mixed> $parameters */
    public function sign(array $parameters): string
    {
        $settings = $this->validatedSettings();

        return $this->hash($this->canonicalize($parameters), $settings['hash_secret']);
    }

    /** @param array<string, mixed> $parameters */
    public function verifySignature(array $parameters): bool
    {
        $provided = $parameters['vnp_SecureHash'] ?? null;

        if (! is_string($provided) || preg_match('/^[a-fA-F0-9]{128}$/D', $provided) !== 1) {
            return false;
        }

        $expected = $this->sign($parameters);

        return hash_equals($expected, strtolower($provided));
    }

    /** @param array<string, mixed> $parameters */
    public function canonicalize(array $parameters): string
    {
        $signable = [];

        foreach ($parameters as $key => $value) {
            if (! is_string($key)
                || ! str_starts_with($key, 'vnp_')
                || in_array($key, ['vnp_SecureHash', 'vnp_SecureHashType'], true)
                || $value === null
                || $value === '') {
                continue;
            }

            if (! is_scalar($value)) {
                throw new RuntimeException('VNPay parameters must be scalar values.');
            }

            $signable[$key] = (string) $value;
        }

        ksort($signable, SORT_STRING);

        return implode('&', array_map(
            static fn (string $key, string $value): string => urlencode($key).'='.urlencode($value),
            array_keys($signable),
            array_values($signable),
        ));
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function verifyEvent(array $parameters, string $eventType): VerifiedPaymentEvent
    {
        $settings = $this->validatedSettings();
        $required = [
            'vnp_TmnCode',
            'vnp_Amount',
            'vnp_TxnRef',
            'vnp_TransactionNo',
            'vnp_ResponseCode',
            'vnp_TransactionStatus',
            'vnp_SecureHash',
        ];

        // Authenticate the complete signed payload before interpreting any fields.
        if (! $this->verifySignature($parameters)) {
            throw new GatewayVerificationException(PaymentEventOutcome::InvalidSignature);
        }

        foreach ($required as $key) {
            if (! isset($parameters[$key]) || ! is_scalar($parameters[$key]) || trim((string) $parameters[$key]) === '') {
                throw new GatewayVerificationException(PaymentEventOutcome::InvalidEvent);
            }
        }

        if (! hash_equals($settings['tmn_code'], (string) $parameters['vnp_TmnCode'])) {
            throw new GatewayVerificationException(PaymentEventOutcome::InvalidEvent);
        }

        // VNPay 2.1.0 Return/IPN does not always include the request version.
        if (isset($parameters['vnp_Version']) && (string) $parameters['vnp_Version'] !== $settings['version']) {
            throw new GatewayVerificationException(PaymentEventOutcome::InvalidEvent);
        }
        if (isset($parameters['vnp_CurrCode']) && $parameters['vnp_CurrCode'] !== 'VND') {
            throw new GatewayVerificationException(PaymentEventOutcome::InvalidEvent);
        }

        $merchantReference = (string) $parameters['vnp_TxnRef'];
        if (preg_match('/^[A-Za-z0-9]{1,100}$/D', $merchantReference) !== 1) {
            throw new RuntimeException('The VNPay merchant reference is invalid.');
        }

        $gatewayTransactionId = (string) $parameters['vnp_TransactionNo'];
        if (preg_match('/^\d{1,15}$/D', $gatewayTransactionId) !== 1) {
            throw new RuntimeException('The VNPay transaction number is invalid.');
        }
        $gatewayTransactionId = ltrim($gatewayTransactionId, '0') ?: '0';

        $responseCode = (string) $parameters['vnp_ResponseCode'];
        $transactionStatus = (string) $parameters['vnp_TransactionStatus'];
        if (preg_match('/^\d{2}$/D', $responseCode) !== 1
            || preg_match('/^\d{2}$/D', $transactionStatus) !== 1) {
            throw new RuntimeException('The VNPay response status is invalid.');
        }

        $occurredAt = $this->parseGatewayDate($parameters['vnp_PayDate'] ?? null, $settings['timezone']);
        $paid = $responseCode === '00' && $transactionStatus === '00';
        if ($paid && (ltrim($gatewayTransactionId, '0') === '' || $occurredAt === null)) {
            throw new GatewayVerificationException(PaymentEventOutcome::InvalidEvent);
        }
        try {
            $amount = VnPayAmount::toDecimal((string) $parameters['vnp_Amount'], $settings['currency']);
        } catch (RuntimeException) {
            throw new GatewayVerificationException(PaymentEventOutcome::AmountMismatch);
        }

        return new VerifiedPaymentEvent(
            eventType: $eventType,
            gateway: PaymentMethod::VNPAY,
            merchantReference: $merchantReference,
            gatewayTransactionId: $gatewayTransactionId,
            amount: $amount,
            currency: $settings['currency'],
            responseCode: $responseCode,
            transactionStatus: $transactionStatus,
            paid: $paid,
            occurredAt: $occurredAt,
            metadata: $this->safeMetadata($parameters),
        );
    }

    /** @return array<string, string> */
    private function validatedSettings(): array
    {
        if ($this->config->get('vnpay.enabled', false) !== true) {
            throw new RuntimeException('VNPay is disabled.');
        }

        $settings = [
            'tmn_code' => trim((string) $this->config->get('vnpay.tmn_code')),
            'hash_secret' => (string) $this->config->get('vnpay.hash_secret'),
            'payment_url' => rtrim(trim((string) $this->config->get('vnpay.payment_url')), '?'),
            'return_url' => trim((string) $this->config->get('vnpay.return_url')),
            'ipn_url' => trim((string) $this->config->get('vnpay.ipn_url')),
            'version' => trim((string) $this->config->get('vnpay.version')),
            'locale' => strtolower(trim((string) $this->config->get('vnpay.locale'))),
            'currency' => strtoupper(trim((string) $this->config->get('vnpay.currency'))),
            'order_type' => trim((string) $this->config->get('vnpay.order_type')),
            'timezone' => trim((string) $this->config->get('vnpay.timezone')),
        ];

        if (preg_match('/^[A-Za-z0-9]{8}$/D', $settings['tmn_code']) !== 1) {
            throw new RuntimeException('VNPay terminal configuration is invalid.');
        }

        if (trim($settings['hash_secret']) === '') {
            throw new RuntimeException('VNPay signing configuration is incomplete.');
        }

        foreach (['payment_url', 'return_url', 'ipn_url'] as $urlKey) {
            $url = $settings[$urlKey];
            if (filter_var($url, FILTER_VALIDATE_URL) === false
                || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
                || parse_url($url, PHP_URL_USER) !== null
                || parse_url($url, PHP_URL_PASS) !== null
                || parse_url($url, PHP_URL_FRAGMENT) !== null
                || ($urlKey === 'payment_url' && parse_url($url, PHP_URL_QUERY) !== null)) {
                throw new RuntimeException('VNPay URL configuration is invalid.');
            }
        }

        // Phase 6B accepts sandbox payments only. Production needs a separate review.
        if (strtolower((string) parse_url($settings['payment_url'], PHP_URL_HOST)) !== 'sandbox.vnpayment.vn') {
            throw new RuntimeException('Only the VNPay sandbox payment endpoint is enabled.');
        }

        if ($settings['version'] !== self::SUPPORTED_VERSION
            || ! in_array($settings['locale'], ['vn', 'en'], true)
            || $settings['currency'] !== VnPayAmount::CURRENCY_VND
            || $settings['timezone'] !== 'Asia/Ho_Chi_Minh'
            || $settings['order_type'] === ''
            || strlen($settings['order_type']) > 100) {
            throw new RuntimeException('VNPay protocol configuration is invalid.');
        }

        try {
            new DateTimeZone($settings['timezone']);
        } catch (\Exception) {
            throw new RuntimeException('VNPay timezone configuration is invalid.');
        }

        return $settings;
    }

    /** @param array<string, string> $settings */
    private function assertPaymentRequest(PaymentUrlRequest $request, array $settings): void
    {
        if (! PaymentAttemptReference::isValid($request->merchantReference)) {
            throw new RuntimeException('The merchant payment-attempt reference is invalid.');
        }

        if (strtoupper(trim($request->currency)) !== $settings['currency']) {
            throw new RuntimeException('The payment currency does not match VNPay configuration.');
        }

        $orderInfo = trim($request->orderInfo);
        if ($orderInfo === '' || strlen($orderInfo) > 255) {
            throw new RuntimeException('VNPay order information is invalid.');
        }

        if (filter_var($request->clientIp, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException('The client IP address is invalid.');
        }

        if ($request->expiresAt <= $request->createdAt) {
            throw new RuntimeException('The VNPay payment expiry must be after its creation time.');
        }

        if ($request->bankCode !== null
            && trim($request->bankCode) !== ''
            && preg_match('/^[A-Za-z0-9]{1,20}$/D', trim($request->bankCode)) !== 1) {
            throw new RuntimeException('The VNPay bank code is invalid.');
        }

        VnPayAmount::toGatewayAmount($request->amount, $request->currency);
    }

    private function hash(string $canonical, string $secret): string
    {
        return hash_hmac('sha512', $canonical, $secret);
    }

    private function parseGatewayDate(mixed $value, string $timezone): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_scalar($value) || preg_match('/^\d{14}$/D', (string) $value) !== 1) {
            throw new RuntimeException('The VNPay payment date is invalid.');
        }

        $date = DateTimeImmutable::createFromFormat('!YmdHis', (string) $value, new DateTimeZone($timezone));
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('YmdHis') !== (string) $value) {
            throw new RuntimeException('The VNPay payment date is invalid.');
        }

        return $date;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, string>
     */
    private function safeMetadata(array $parameters): array
    {
        $mapping = [
            'vnp_BankCode' => 'bank_code',
            'vnp_BankTranNo' => 'bank_transaction_id',
            'vnp_CardType' => 'card_type',
            'vnp_PayDate' => 'pay_date',
            'vnp_OrderInfo' => 'order_info',
        ];
        $metadata = [];

        foreach ($mapping as $source => $target) {
            if (isset($parameters[$source]) && is_scalar($parameters[$source])) {
                $metadata[$target] = (string) $parameters[$source];
            }
        }

        return $metadata;
    }
}
