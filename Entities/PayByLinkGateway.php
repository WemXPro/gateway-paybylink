<?php

namespace Modules\PayByLinkGateway\Entities;

use App\Models\Gateways\Gateway;
use App\Models\Gateways\PaymentGatewayInterface;
use Illuminate\Http\RedirectResponse;
use Omnipay\Common\GatewayInterface;
use Illuminate\Http\Request;
use App\Models\Settings;
use App\Models\Payment;

use Illuminate\Support\Facades\Http;

/**
 * Class PayByLinkGateway
 *
 * PayByLinkGateway implements the PaymentGatewayInterface, defining the contract for payment gateways within the system.
 * It provides methods to handle payments, receive responses from the payment gateway, process refunds, configure the gateway,
 * fetch configuration, and check subscriptions.
 *
 * @package Modules\PayByLinkGateway\Entities
 */
class PayByLinkGateway implements PaymentGatewayInterface
{

    /**
     * The method is responsible for preparing and processing the payment get the gateway and payment objects
     * use dd($gateway $payment) for debugging
     *
     * @param Gateway $gateway
     * @param Payment $payment
     */
    public static function processGateway(Gateway $gateway, Payment $payment)
    {
        try {
            // retrieve the gateway
            $gateway = Gateway::query()->where('driver', 'PayByLink')->firstOrFail();

            // store a random key to later use for signature validation
            self::generateWebhookSecret();

            // define variables
            $secretKey = $gateway->config['secret_key'];
            $shopId = (int) $gateway->config['shop_id'];
            $amount = (float) $payment->amount;
            $notifyURL = route('payment.return', ['gateway' => self::endpoint()]);
            $returnUrlSuccess = route('payment.success', ['payment' => $payment->id]);
            
            // Format the amount to 2 decimal places
            $amountFormatted = number_format($amount, 2, '.', '');

            // define the control
            $control = json_encode(['payment_id' => $payment->id, 'webhook_secret' => settings('encrypted::paybylink_webhook_key')]);
            
            // Concatenate the fields with the separator
            $concatenated = "{$secretKey}|{$shopId}|{$amountFormatted}|{$control}|{$payment->description}|{$payment->user->email}|{$notifyURL}|{$returnUrlSuccess}";
            
            // Generate the SHA256 hash of the concatenated string
            $signature = hash('sha256', $concatenated);
            
            // Send the request to the gateway
            $response = Http::post('https://secure.paybylink.pl/api/v1/transfer/generate', [
                'shopId' => $shopId,
                'price' => $amountFormatted, // Use the formatted amount
                'control' => $control,
                'description' => $payment->description,
                'email' => $payment->user->email,
                'notifyURL' => $notifyURL,
                'returnUrlSuccess' => $returnUrlSuccess,
                'signature' => $signature,
            ]);

            if(!$response->successful()) {
                throw new \Exception("Code: {$response['errorCode']} | {$response['error']}");   
            }

            if(!isset($response['url'])) {
                throw new \Exception('Gateway did not return a payment URL');
            }
            
        } catch (\Exception $e) {
           return redirect()->back()->withError($e->getMessage());
        }

        return redirect()->away($response['url']);
    }

    /**
     * Handles the response from the payment gateway. It uses a Request object to receive and handle the response appropriately.
     * endpoint: payment/return/{endpoint_gateway}
     * @param Request $request
     */
    public static function returnGateway(Request $request)
    {        
        try {
            // retrieve the gateway
            $gateway = Gateway::query()->where('driver', 'PayByLink')->firstOrFail();

            // decode the control
            $control = json_decode($request->control);

            // retrieve the payment
            $payment = Payment::where('id', $control->payment_id)->firstOrFail();

            if(!$payment) {
                throw new \Exception('Payment not found');
            }

            // validate the webhook key
            if(settings('encrypted::paybylink_webhook_key') !== $control->webhook_secret) {
                throw new \Exception('Invalid webhook secret key');
            }

            // complete the payment
            $payment->completed($request->get('transactionId'));

        } catch (\Exception $e) {
            ErrorLog('paybylink::webhook', $e->getMessage());
            return response()->json(['success' => false], 500);
        }

        // return 200 response
        return response('OK', 200)->header('Content-Type', 'text/plain');
    }

    protected static function generateWebhookSecret(): void 
    {
        if(!Settings::has('encrypted::paybylink_webhook_key')) {
            Settings::put('encrypted::paybylink_webhook_key', str_random(32));
        }
    }

    /**
     * Handles refunds. It takes a Payment object and additional data required for processing a refund.
     * An optional method to add user refund support
     *
     * @param Payment $payment
     * @param array $data
     */
    public static function processRefund(Payment $payment, array $data)
    {
        return false;
    }


    /**
     * Defines the configuration for the payment gateway. It returns an array with data defining the gateway driver,
     * type, class, endpoint, refund support, etc.
     *
     * @return array
     */
    public static function drivers(): array
    {
        return [
            'PayByLink' => [
                'driver' => 'PayByLink',
                'type' => 'once', // subscription
                'class' => 'Modules\PayByLinkGateway\Entities\PayByLinkGateway',
                'endpoint' => self::endpoint(),
                'refund_support' => false,
            ]
        ];
    }

    /**
     * Defines the endpoint for the payment gateway. This is an ID used to automatically determine which gateway to use.
     *
     * @return string
     */
    public static function endpoint(): string
    {
        return 'paybylink';
    }


    /**
     * Returns an array with the configuration for the payment gateway.
     * These options are displayed for the administrator to configure.
     * You can access them: $gateway->config()
     * @return array
     */
    public static function getConfigMerge(): array
    {
        self::generateWebhookSecret();

        return [
            'shop_id' => '',
            'secret_key' => '',
        ];
    }

    /**
     * Checks the status of a subscription in the payment gateway. If the subscription is active, it returns true; otherwise, it returns false.
     * Do not change this method if you are not using subscriptions
     * @param Gateway $gateway
     * @param $subscriptionId
     * @return bool
     */
    public static function checkSubscription(Gateway $gateway, $subscriptionId): bool
    {
        return false;
    }
}