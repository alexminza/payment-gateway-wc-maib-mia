# PHP SDK for maib MIA API

![maib MIA](https://repository-images.githubusercontent.com/1076179057/9258aa73-ca53-4f17-9ee4-08b5e068ef47)

* maib MIA QR API docs: https://docs.maibmerchants.md/mia-qr-api
* GitHub project https://github.com/alexminza/maib-mia-sdk-php
* Composer package https://packagist.org/packages/alexminza/maib-mia-sdk

## Installation
To easily install or upgrade to the latest release, use `composer`:
```shell
composer require alexminza/maib-mia-sdk
```

## Getting started
Import SDK:

```php
require_once __DIR__ . '/vendor/autoload.php';

use Maib\MaibMia\MaibMiaClient;
```

Add project configuration:

```php
$DEBUG = getenv('DEBUG');

$MAIB_MIA_BASE_URI = getenv('MAIB_MIA_BASE_URI');
$MAIB_MIA_CLIENT_ID = getenv('MAIB_MIA_CLIENT_ID');
$MAIB_MIA_CLIENT_SECRET = getenv('MAIB_MIA_CLIENT_SECRET');
$MAIB_MIA_SIGNATURE_KEY = getenv('MAIB_MIA_SIGNATURE_KEY');
```

Initialize client:

```php
$options = [
    'base_uri' => $MAIB_MIA_BASE_URI,
    'timeout' => 15
];

if ($DEBUG) {
    $logName = 'maib_mia_guzzle';
    $logFileName = "$logName.log";

    $log = new \Monolog\Logger($logName);
    $log->pushHandler(new \Monolog\Handler\StreamHandler($logFileName, \Monolog\Logger::DEBUG));

    $stack = \GuzzleHttp\HandlerStack::create();
    $stack->push(\GuzzleHttp\Middleware::log($log, new \GuzzleHttp\MessageFormatter(\GuzzleHttp\MessageFormatter::DEBUG)));

    $options['handler'] = $stack;
}

$guzzleClient = new \GuzzleHttp\Client($options);
$maibMiaClient = new MaibMiaClient($guzzleClient);
```

## SDK usage examples
### Get Access Token with Client ID and Client Secret

```php
$tokenResponse = $maibMiaClient->getToken($MAIB_MIA_CLIENT_ID, $MAIB_MIA_CLIENT_SECRET);
$accessToken = $tokenResponse['result']['accessToken'];
```

### Create a dynamic order payment QR

```php
$validityMinutes = 60;
$expiresAt = (new DateTime())->modify("+{$validityMinutes} minutes")->format('c');

$qr_data = array(
    'type' => 'Dynamic',
    'expiresAt' => $expiresAt,
    'amountType' => 'Fixed',
    'amount' => 50.00,
    'currency' => 'MDL',
    'description' => 'Order #123',
    'orderId' => '123',
    'callbackUrl' => 'https://example.com/callback',
    'redirectUrl' => 'https://example.com/success'
);

$createQrResponse = $maibMiaClient->createQr($qrData, $accessToken);
print_r($createQrResponse);
```

### Validate callback signature

```php
$callbackBody = '{
    "result": {
        "qrId": "c3108b2f-6c2e-43a2-bdea-123456789012",
        "extensionId": "3fe7f013-23a6-4d09-a4a4-123456789012",
        "qrStatus": "Paid",
        "payId": "eb361f48-bb39-45e2-950b-123456789012",
        "referenceId": "MIA0001234567",
        "orderId": "123",
        "amount": 50.00,
        "commission": 0.1,
        "currency": "MDL",
        "payerName": "TEST QR PAYMENT",
        "payerIban": "MD88AG000000011621810140",
        "executedAt": "2025-04-18T14:04:11.81145+00:00",
        "terminalId": null
    },
    "signature": "fHM+l4L1ycFWZDRTh/Vr8oybq1Q1xySdjyvmFQCmZ4s="
}';

$callbackData = json_decode($callbackBody, true);
$validationResult = MaibMiaClient::validateCallbackSignature($callbackData, $MAIB_MIA_SIGNATURE_KEY);
print_r($validationResult);
```

### Perform a test QR payment

```php
$qrId = $createQrResponse['result']['qrId'];
$testPayData = [
    'qrId' => $qrId,
    'amount' => 50.00,
    'iban' => 'MD88AG000000011621810140',
    'currency' => 'MDL',
    'payerName' => 'TEST QR PAYMENT'
];

$testPayResponse = $client->testPay($testPayData, $accessToken);
print_r($testPayResponse);
```

### Get payment details

```php
$payId = $testPayResponse['result']['payId'];
$paymentDetailsResponse = $client->paymentDetails($payId, $accessToken);
print_r($paymentDetailsResponse);
```

### Refund payment

```php
$paymentRefundResponse = $client->paymentRefund($payId, 'Test refund reason', $accessToken);
print_r($paymentRefundResponse);
```
