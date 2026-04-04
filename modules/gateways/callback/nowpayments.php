<?php
// Require libraries needed for gateway module functions.
require_once __DIR__ . '/../../../init.php';
App::load_function('gateway');
App::load_function('invoice');

// Detect module name from filename.
$gatewayModuleName = basename(__FILE__, '.php');

// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module is not active.
if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

$server = $_SERVER;

// Read the raw body once — php://input is a one-time stream.
$requestJson = file_get_contents('php://input');
$requestData = json_decode($requestJson, true);

logTransaction($gatewayParams['name'], $requestData, 'Request accepted from NOWPayments.');

// Sort array keys recursively so nested objects (e.g. "fee") are also sorted.
function ksortRecursive(array &$data): void
{
    ksort($data);
    foreach ($data as &$value) {
        if (is_array($value)) {
            ksortRecursive($value);
        }
    }
}

// PHP's json_encode uses scientific notation for very small floats (e.g. 1.6e-5).
// NOWPayments (Node.js) serializes the same value as 0.000016, producing a
// different string and therefore a different HMAC.  Convert any scientific-
// notation numbers in the encoded JSON back to plain decimal form.
function normalizeSciNotation(string $json): string
{
    return preg_replace_callback(
        '/(?<=[:\[,{])-?(?:0|[1-9]\d*)(?:\.\d+)?[eE][+-]?\d+/',
        function (array $m): string {
            $n = rtrim(sprintf('%.20F', (float)$m[0]), '0');
            return rtrim($n, '.') ?: '0';
        },
        $json
    );
}

function checkIpnRequest()
{
    global $gatewayParams;
    global $server;
    global $requestJson;
    global $requestData;

    if (isset($_SERVER['HTTP_X_NOWPAYMENTS_SIG']) && !empty($_SERVER['HTTP_X_NOWPAYMENTS_SIG'])) {
        $recived_hmac = $_SERVER['HTTP_X_NOWPAYMENTS_SIG'];

        if ($requestJson !== false && !empty($requestJson)) {
            $sorted_data = $requestData;
            ksortRecursive($sorted_data);
            $sorted_request_json = json_encode($sorted_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            $sorted_request_json = normalizeSciNotation($sorted_request_json);

            $ipnSecret = trim($gatewayParams['ipnSecret']);
            $hmac = hash_hmac("sha512", $sorted_request_json, $ipnSecret);

            if ($hmac == $recived_hmac) {
                return true;
            } else {
                logTransaction($gatewayParams['name'], $requestData, 'HMAC signature does not match');
                die('HMAC signature does not match');
            }
        } else {
            logTransaction($gatewayParams['name'], [], 'Error reading POST data');
            die('Error reading POST data');
        }
    } else {
        logTransaction($gatewayParams['name'], $requestData, 'No HMAC signature sent.');
        die('No HMAC signature sent');
    }
}

$success = checkIpnRequest();

$transactionId = $requestData['payment_id'];
$invoiceId = str_replace('WHMCS-', '', $requestData['order_id']);
$priceAmount = $requestData['price_amount'];
$paymentAmount = $requestData['pay_amount'];
$invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);



//updateInvoice
if ($success) {
    $status = $requestData["payment_status"];

    if ($status == "finished") {
         addInvoicePayment(
            $invoiceId,
            $transactionId,
            $priceAmount,
            null,
            $gatewayModuleName
        );
        $currency = $requestData['pay_currency'];
        $upper = mb_strtoupper($currency);
        $message = "Invoice ${invoiceId} has been paid. Amount received: ${paymentAmount} ${upper}";
        logTransaction($gatewayParams['name'], $requestData, $message);

    } else if ($status == "partially_paid") {
	    $actuallyPaid = $requestData["actually_paid"];
	    $actuallyPaidAtFiat = $requestData["actually_paid_at_fiat"];

        addInvoicePayment(
            $invoiceId,
            $transactionId,
	        $actuallyPaidAtFiat,
            null,
            $gatewayModuleName
        );
        $currency = $requestData['pay_currency'];
        $upper = mb_strtoupper($currency);
        $message = "Your payment ${$invoiceId} is partially paid. Please contact support@nowpayments.io. Expected amount received: ${paymentAmount} ${upper}. Amount received: ${actuallyPaid} ${upper}. " . "ID invoice: " . $requestData['payment_id'] . ".";
        logTransaction($gatewayParams['name'], $requestData, $message);

    } else if ($status == "confirming") {
        logTransaction($gatewayParams['name'], $requestData, 'Order is processing (confirming).');
    } else if ($status == "confirmed") {
        logTransaction($gatewayParams['name'], $requestData, 'Order is processing (confirmed).');
    } else if ($status == "sending") {
        logTransaction($gatewayParams['name'], $requestData, 'Order is processing (sending).');
    } else if ($status == "failed") {
        logTransaction($gatewayParams['name'], $requestData, 'Order is failed. Please contact support@nowpayments.io');
    } else if ($status == "waiting") {
        logTransaction($gatewayParams['name'], $requestData, 'Waiting for payment.');
    }
} else {
    die('IPN Verification Failure');
}
