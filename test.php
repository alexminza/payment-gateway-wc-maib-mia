<?php

require_once __DIR__ . '/vendor/autoload.php';

use Maib\MaibMia\MaibMiaClient;


class MAIB_MIA_Test
{
    #region Properties
    /**
     * @var bool
     */
    private $DEBUG;

    /**
     * @var int
     */
    private $DEFAULT_TIMEOUT;

    /**
     * @var string
     */
    private $MAIB_MIA_BASE_URI;

    /**
     * @var string
     */
    private $MAIB_MIA_CLIENT_ID;

    /**
     * @var string
     */
    private $MAIB_MIA_CLIENT_SECRET;

    /**
     * @var string
     */
    private $MAIB_MIA_SIGNATURE_KEY;
    #endregion

    public function __construct()
    {
        $this->DEBUG = getenv('DEBUG');
        $this->DEFAULT_TIMEOUT = getenv('DEFAULT_TIMEOUT');

        $this->MAIB_MIA_BASE_URI = getenv('MAIB_MIA_BASE_URI');
        $this->MAIB_MIA_CLIENT_ID = getenv('MAIB_MIA_CLIENT_ID');
        $this->MAIB_MIA_CLIENT_SECRET = getenv('MAIB_MIA_CLIENT_SECRET');
    }

    private function maib_mia_init_client()
    {
        $options = [
            'base_uri' => $this->MAIB_MIA_BASE_URI,
            'timeout' => $this->DEFAULT_TIMEOUT
        ];

        if ($this->DEBUG) {
            $log = new \Monolog\Logger('maib_mia_guzzle_request');
            $logFileName = 'maib_mia_guzzle.log';
            $log->pushHandler(new \Monolog\Handler\StreamHandler($logFileName, \Monolog\Logger::DEBUG));
            $stack = \GuzzleHttp\HandlerStack::create();
            $stack->push(\GuzzleHttp\Middleware::log($log, new \GuzzleHttp\MessageFormatter(\GuzzleHttp\MessageFormatter::DEBUG)));

            $options['handler'] = $stack;
        }

        $guzzleClient = new \GuzzleHttp\Client($options);
        $client = new MaibMiaClient($guzzleClient);

        return $client;
    }

    /**
     * @param MaibMiaClient $client
     */
    private function maib_mia_generate_token($client)
    {
        $tokenResponse = $client->getToken($this->MAIB_MIA_CLIENT_ID, $this->MAIB_MIA_CLIENT_SECRET);
        $accessToken = $tokenResponse['result']['accessToken'];

        return $accessToken;
    }

    /**
     * @param MaibMiaClient $client
     * @param string $authToken
     * @param string $orderId
     * @param string $orderName
     * @param float  $totalAmount
     * @param string $currency
     * @param string $orderUrl
     * @param string $callbackUrl
     * @param int    $validity_minutes
     */
    private function maib_mia_pay($client, $authToken, $orderId, $orderName, $totalAmount, $currency, $orderUrl, $callbackUrl, $validityMinutes)
    {
        $expiresAt = (new DateTime())->modify("+{$validityMinutes} minutes")->format('c');

        $qr_data = array(
            'type' => 'Dynamic',
            'expiresAt' => $expiresAt,
            'amountType' => 'Fixed',
            'amount' => $totalAmount,
            'currency' => $currency,
            'description' => $orderName,
            'orderId' => $orderId,
            'callbackUrl' => $callbackUrl,
            'redirectUrl' => $orderUrl
        );

        $create_qr_response = $client->createQr($qr_data, $authToken);
        return $create_qr_response;
    }

    public function test()
    {
        $client = $this->maib_mia_init_client();
        $authToken = 'Bearer ' . $this->maib_mia_generate_token($client);

        $orderId = '12345';
        $orderName = "Order #$orderId";
        $totalAmount = 123.45;
        $currency = 'MDL';
        $validityMinutes = 10;
        $orderUrl = 'https://www.example.com/';
        $callbackUrl = 'https://www.example.com/callback/';
        $payResponse = $this->maib_mia_pay($client, $authToken, $orderId, $orderName, $totalAmount, $currency, $orderUrl, $callbackUrl, $validityMinutes);

        print_r($payResponse);
        $qrId = $payResponse['result']['qrId'];
        $qrUrl = $payResponse['result']['url'];
    }
}

$vb_mia_test = new MAIB_MIA_Test();
$vb_mia_test->test();