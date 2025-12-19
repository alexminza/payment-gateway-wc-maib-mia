<?php

namespace Maib\MaibMia;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Command\Guzzle\DescriptionInterface;
use GuzzleHttp\Command\Guzzle\GuzzleClient;
use GuzzleHttp\Command\Result;
use GuzzleHttp\Exception\BadResponseException;

/**
 * maib MIA API client
 * @link https://docs.maibmerchants.md/mia-qr-api
 */
class MaibMiaClient extends GuzzleClient
{
    const DEFAULT_BASE_URL = 'https://api.maibmerchants.md/';
    const SANDBOX_BASE_URL = 'https://sandbox.maibmerchants.md/';

    /**
     * @param ClientInterface      $client
     * @param DescriptionInterface $description
     * @param array                $config
     */
    public function __construct(
        ?ClientInterface $client = null,
        ?DescriptionInterface $description = null,
        array $config = []
    ) {
        $client = $client instanceof ClientInterface ? $client : new Client();
        $description = $description instanceof DescriptionInterface ? $description : new MaibMiaDescription($config);
        parent::__construct($client, $description, null, null, null, $config);
    }

    /**
     * Obtain Authentication Token
     * @link https://docs.maibmerchants.md/mia-qr-api/en/endpoints/authentication/obtain-authentication-token
     * @link https://docs.maibmerchants.md/mia-qr-api/en/overview/general-technical-specifications#authentication
     * @param string $clientId
     * @param string $clientSecret
     */
    public function getToken($clientId, $clientSecret)
    {
        $args = [
            'clientId' => $clientId,
            'clientSecret' => $clientSecret
        ];

        return parent::getToken($args);
    }

    /**
     * Create QR Code (Static, Dynamic)
     * @link https://docs.maibmerchants.md/mia-qr-api/en/endpoints/payment-initiation/create-qr-code-static-dynamic
     * @param array  $qrData
     * @param string $authToken
     */
    public function createQr($qrData, $authToken)
    {
        self::setBearerAuthToken($qrData, $authToken);
        return parent::createQr($qrData);
    }

    /**
     * Create Hybrid QR Code
     * @link https://docs.maibmerchants.md/mia-qr-api/en/endpoints/payment-initiation/create-hybrid-qr-code
     * @param array  $qrData
     * @param string $authToken
     */
    public function createHybridQr($qrData, $authToken)
    {
        self::setBearerAuthToken($qrData, $authToken);
        return parent::createHybridQr($qrData);
    }

    /**
     * Create Extension for QR Code by ID
     * @link https://docs.maibmerchants.md/mia-qr-api/en/endpoints/payment-initiation/create-hybrid-qr-code/create-extension-for-qr-code-by-id
     * @param string $qrId
     * @param array  $qrData
     * @param string $authToken
     */
    public function createQrExtension($qrId, $qrData, $authToken)
    {
        $args = $qrData;
        $args['qrId'] = $qrId;

        self::setBearerAuthToken($args, $authToken);
        return parent::createQrExtension($args);
    }

    /**
     * Cancel Active QR (Static, Dynamic)
     * @link https://docs.maibmerchants.md/mia-qr-api/en/endpoints/payment-cancellation/cancel-active-qr-static-dynamic
     * @param string $qrId
     * @param string $reason
     * @param string $authToken
     */
    public function cancelQr($qrId, $reason, $authToken)
    {
        $args = [
            'qrId' => $qrId,
            'reason' => $reason,
        ];

        self::setBearerAuthToken($args, $authToken);
        return parent::cancelQr($args);
    }

    /**
     * Cancel Active QR Extension (Hybrid)
     * @link https://docs.maibmerchants.md/mia-qr-api/en/endpoints/payment-cancellation/cancel-active-qr-extension-hybrid
     * @param string $qrId
     * @param string $reason
     * @param string $authToken
     */
    public function cancelQrExtension($qrId, $reason, $authToken)
    {
        $args = [
            'qrId' => $qrId,
            'reason' => $reason,
        ];

        self::setBearerAuthToken($args, $authToken);
        return parent::cancelQrExtension($args);
    }

    /**
     * Refund Completed Payment
     * @link https://docs.maibmerchants.md/mia-qr-api/en/endpoints/payment-refund/refund-completed-payment
     * @param string $payId
     * @param string $reason
     * @param string $authToken
     */
    public function paymentRefund($payId, $reason, $authToken)
    {
        $args = [
            'payId' => $payId,
            'reason' => $reason,
        ];

        self::setBearerAuthToken($args, $authToken);
        return parent::paymentRefund($args);
    }

    /**
     * Display List of QR Codes with Filtering Options
     * @link https://docs.maibmerchants.md/mia-qr-api/en/endpoints/information-retrieval-get/display-list-of-qr-codes-with-filtering-options
     * @param array $qrListData
     * @param string $authToken
     */
    public function qrList($qrListData, $authToken)
    {
        self::setBearerAuthToken($qrListData, $authToken);
        return parent::qrList($qrListData);
    }

    /**
     * Retrieve QR Details by ID
     * @link https://docs.maibmerchants.md/mia-qr-api/en/endpoints/information-retrieval-get/retrieve-qr-details-by-id
     * @param string $qrId
     * @param string $authToken
     */
    public function qrDetails($qrId, $authToken)
    {
        $args = [
            'qrId' => $qrId,
        ];

        self::setBearerAuthToken($args, $authToken);
        return parent::qrDetails($args);
    }

    /**
     * Retrieve List of Payments with Filtering Options
     * @link https://docs.maibmerchants.md/mia-qr-api/en/endpoints/information-retrieval-get/retrieve-list-of-payments-with-filtering-options
     * @param array $paymentListData
     * @param string $authToken
     */
    public function paymentList($paymentListData, $authToken)
    {
        self::setBearerAuthToken($paymentListData, $authToken);
        return parent::paymentList($paymentListData);
    }

    /**
     * Retrieve Payment Details by ID
     * @link https://docs.maibmerchants.md/mia-qr-api/en/endpoints/information-retrieval-get/retrieve-payment-details-by-id
     * @param string $payId
     * @param string $authToken
     */
    public function paymentDetails($payId, $authToken)
    {
        $args = [
            'payId' => $payId,
        ];

        self::setBearerAuthToken($args, $authToken);
        return parent::paymentDetails($args);
    }

    /**
     * Payment Simulation (Sandbox)
     * @link https://docs.maibmerchants.md/mia-qr-api/en/payment-simulation-sandbox
     * @param array $testPayData
     * @param string $authToken
     */
    public function testPay($testPayData, $authToken)
    {
        self::setBearerAuthToken($testPayData, $authToken);
        return parent::testPay($testPayData);
    }

    /**
     * @param array  $args
     * @param string $authToken
     */
    private static function setBearerAuthToken(&$args, $authToken)
    {
        $args['authToken'] = "Bearer $authToken";
    }

    /**
     * Callback Payload Signature Key Verification
     * @link https://docs.maibmerchants.md/mia-qr-api/en/examples/signature-key-verification
     * @param array  $callbackData
     * @param string $signatureKey
     */
    public static function validateCallbackSignature($callbackData, $signatureKey)
    {
        $resultElement = $callbackData['result'] ?? [];
        $expectedSignature = $callbackData['signature'] ?? '';

        $keys = [];
        foreach ($resultElement as $key => $value) {
            if (is_null($value)) {
                continue;
            }

            // Format "amount" and "commission" with 2 decimal places
            if ($key === 'amount' || $key === 'commission') {
                $valueStr = number_format((float)$value, 2, '.', '');
            } else {
                $valueStr = (string)$value;
            }

            if (trim($valueStr) !== '') {
                $keys[$key] = $valueStr;
            }
        }

        // Sort keys by key name (case-insensitive)
        uksort($keys, 'strcasecmp');

        // Build the string to hash
        $additionalString = implode(':', $keys);
        $hashInput = $additionalString . ':' . $signatureKey;

        // Generate SHA256 hash and base64-encode it
        $hash = hash('sha256', $hashInput, true);
        $result = base64_encode($hash);

        // Compare the result with the signature
        return hash_equals($expectedSignature, $result);
    }
}
