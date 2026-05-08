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

// PHP's json_encode uses scientific notation for very small floats (e.g. 7.5e-5).
// NOWPayments (Node.js) serializes the same value as 0.000075, producing a
// different string and therefore a different HMAC. Convert sci-notation numbers
// back to plain decimal form via string manipulation — going through (float)
// reintroduces binary precision artifacts (7.5e-5 -> 0.00007499999999999999).
function normalizeSciNotation(string $json): string
{
    return preg_replace_callback(
        '/(?<=[:\[,])(-?)(\d+)(?:\.(\d+))?[eE]([+-]?\d+)/',
        function (array $m): string {
            $sign     = $m[1];
            $intPart  = $m[2];
            $fracPart = $m[3];
            $exp      = (int)$m[4];

            $digits     = $intPart . $fracPart;
            $decimalPos = strlen($intPart) + $exp;

            if ($decimalPos <= 0) {
                return $sign . '0.' . str_repeat('0', -$decimalPos) . $digits;
            }
            if ($decimalPos >= strlen($digits)) {
                return $sign . $digits . str_repeat('0', $decimalPos - strlen($digits));
            }
            return $sign . substr($digits, 0, $decimalPos) . '.' . substr($digits, $decimalPos);
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
            // Use flat ksort (top-level keys only) to match NowPayments' Node.js
            // JSON.stringify(params, Object.keys(params).sort()) behaviour.
            // Recursive sorting of nested objects (e.g. "fee") produces a different
            // string than what NowPayments signs on their end.
            ksort($sorted_data);
            $sorted_request_json = json_encode($sorted_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            $sorted_request_json = normalizeSciNotation($sorted_request_json);

            $ipnSecret = trim($gatewayParams['ipnSecret']);
            $hmac = hash_hmac("sha512", $sorted_request_json, $ipnSecret);

            if ($hmac == $recived_hmac) {
                return true;
            } else {
                logTransaction($gatewayParams['name'], array_merge($requestData, [
                    '_debug_computed_hmac' => $hmac,
                    '_debug_received_hmac' => $recived_hmac,
                    '_debug_raw_json'      => $requestJson,
                    '_debug_sorted_json'   => $sorted_request_json,
                ]), 'HMAC signature does not match');
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
