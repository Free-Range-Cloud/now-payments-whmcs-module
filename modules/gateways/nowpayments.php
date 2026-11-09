<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


function nowpayments_MetaData()
{
    return array(
        'DisplayName' => 'NOWPayments',
        'APIVersion' => '1.1',
    );
}


function nowpayments_config()
{
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'NOWPayments',
        ),
        'apiKey' => array(
            'FriendlyName' => 'API key',
            'Type' => 'password',
            'Default' => '',
            'Description' => 'Enter your NOWPayments API key here',
        ),
        'ipnSecret' => array(
            'FriendlyName' => 'IPN Secret',
            'Type' => 'password',
            'Default' => '',
            'Description' => 'Enter your IPN Secret here',
        ),
        'feePaidByUser' => array(
            'FriendlyName' => 'Fee paid by customer',
            'Type' => 'yesno',
            'Description' => 'Tick to have the customer pay the NOWPayments network/service fee',
        ),
        'fixedRate' => array(
            'FriendlyName' => 'Fixed rate',
            'Type' => 'yesno',
            'Description' => 'Tick to lock the exchange rate for the payment window',
        ),
        'sandbox' => array(
            'FriendlyName' => 'Sandbox mode',
            'Type' => 'yesno',
            'Description' => 'Tick to use the NOWPayments sandbox API (api-sandbox.nowpayments.io). Requires a sandbox API key.',
        ),
    );
}


/**
 * Base URL for the NOWPayments REST API.
 */
function nowpayments_apiBaseUrl(array $params)
{
    return !empty($params['sandbox'])
        ? 'https://api-sandbox.nowpayments.io/v1'
        : 'https://api.nowpayments.io/v1';
}


/**
 * Create a hosted invoice via the NOWPayments API (POST /v1/invoice).
 *
 * Returns the decoded response array (contains 'invoice_url' and 'id') on
 * success, or throws an Exception with a descriptive message on failure.
 */
function nowpayments_createInvoice(array $params)
{
    $systemUrl = rtrim($params['systemurl'], '/') . '/';

    $payload = array(
        'price_amount' => (float) $params['amount'],
        'price_currency' => strtolower($params['currency']),
        'order_id' => 'WHMCS-' . $params['invoiceid'],
        'order_description' => $params['description'],
        'ipn_callback_url' => $systemUrl . 'modules/gateways/callback/nowpayments.php',
        'success_url' => $params['returnurl'],
        'cancel_url' => $params['returnurl'],
        'is_fee_paid_by_user' => !empty($params['feePaidByUser']),
        'is_fixed_rate' => !empty($params['fixedRate']),
    );

    $url = nowpayments_apiBaseUrl($params) . '/invoice';

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array(
            'x-api-key: ' . trim($params['apiKey']),
            'Content-Type: application/json',
        ),
        CURLOPT_POSTFIELDS => json_encode($payload),
    ));
    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $response = is_string($body) ? json_decode($body, true) : null;

    logModuleCall('nowpayments', 'createInvoice', $payload, $body, $response, array(trim($params['apiKey'])));

    if ($body === false) {
        throw new Exception('Could not reach NOWPayments: ' . $curlError);
    }
    if ($httpCode < 200 || $httpCode >= 300 || empty($response['invoice_url'])) {
        $message = isset($response['message']) ? $response['message'] : $body;
        throw new Exception('NOWPayments API error (HTTP ' . $httpCode . '): ' . $message);
    }

    return $response;
}


/**
 * Renders the "Pay Now" button on the invoice page. The NOWPayments invoice
 * itself is only created when the customer clicks the button (see
 * modules/gateways/nowpayments/pay.php), so simply viewing an invoice does not
 * generate API calls or orphaned payment invoices.
 */
function nowpayments_link($params)
{
    $systemUrl = rtrim($params['systemurl'], '/') . '/';
    $payUrl = $systemUrl . 'modules/gateways/nowpayments/pay.php';
    $logoUrl = $systemUrl . 'modules/gateways/nowpayments/logo.png';
    $label = !empty($params['langpaynow']) ? $params['langpaynow'] : 'Pay Now';

    $htmlOutput = '<form method="post" action="' . htmlspecialchars($payUrl) . '">';
    $htmlOutput .= '<input type="hidden" name="invoiceid" value="' . (int) $params['invoiceid'] . '" />';
    $htmlOutput .= '<button type="submit" class="btn btn-success" style="padding:6px 12px;">';
    $htmlOutput .= '<img src="' . htmlspecialchars($logoUrl) . '" alt="NOWPayments" style="max-height:24px;vertical-align:middle;margin-right:6px;" />';
    $htmlOutput .= htmlspecialchars($label);
    $htmlOutput .= '</button>';
    $htmlOutput .= '</form>';

    return $htmlOutput;
}
