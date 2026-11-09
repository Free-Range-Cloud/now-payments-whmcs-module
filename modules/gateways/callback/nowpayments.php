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

// Sort the top-level keys of a flat JSON object string WITHOUT decoding values.
// This preserves NowPayments' exact byte representation for numbers (avoiding
// PHP vs. JS float-formatting mismatches: 0.000008 vs 0.0000080, 1e-7 vs 0.0000001,
// 8.0 vs 8, etc.) and matches what their Node.js code signs with
// JSON.stringify(params, Object.keys(params).sort()).
// Returns null if the input is not a flat JSON object we can safely re-serialize.
function sortTopLevelJsonKeys(string $json): ?string
{
    $trimmed = trim($json);
    if ($trimmed === '' || $trimmed[0] !== '{' || substr($trimmed, -1) !== '}') {
        return null;
    }
    $inner = substr($trimmed, 1, -1);
    $len = strlen($inner);

    $pairs = [];
    $depth = 0;
    $inString = false;
    $escape = false;
    $start = 0;

    for ($i = 0; $i < $len; $i++) {
        $c = $inner[$i];
        if ($escape) { $escape = false; continue; }
        if ($inString) {
            if ($c === '\\') { $escape = true; }
            elseif ($c === '"') { $inString = false; }
            continue;
        }
        if ($c === '"') { $inString = true; }
        elseif ($c === '{' || $c === '[') { $depth++; }
        elseif ($c === '}' || $c === ']') { $depth--; }
        elseif ($c === ',' && $depth === 0) {
            $pairs[] = substr($inner, $start, $i - $start);
            $start = $i + 1;
        }
    }
    if ($start <= $len) { $pairs[] = substr($inner, $start); }

    $byKey = [];
    foreach ($pairs as $pair) {
        if (!preg_match('/^\s*"((?:[^"\\\\]|\\\\.)*)"\s*:/s', $pair, $m)) {
            return null;
        }
        $key = json_decode('"' . $m[1] . '"');
        if ($key === null && $m[1] !== 'null') { return null; }
        $byKey[$key] = $pair;
    }

    ksort($byKey);
    return '{' . implode(',', array_values($byKey)) . '}';
}

function checkIpnRequest()
{
    global $gatewayParams;
    global $server;
    global $requestJson;
    global $requestData;

    if (!isset($_SERVER['HTTP_X_NOWPAYMENTS_SIG']) || empty($_SERVER['HTTP_X_NOWPAYMENTS_SIG'])) {
        logTransaction($gatewayParams['name'], $requestData, 'No HMAC signature sent.');
        die('No HMAC signature sent');
    }
    if ($requestJson === false || empty($requestJson)) {
        logTransaction($gatewayParams['name'], [], 'Error reading POST data');
        die('Error reading POST data');
    }

    $recived_hmac = $_SERVER['HTTP_X_NOWPAYMENTS_SIG'];
    $ipnSecret = trim($gatewayParams['ipnSecret']);

    // Strategy 1: HMAC the raw body bytes. NowPayments computes the signature
    // over JSON.stringify(params, sortedKeys) and (in observed traffic) sends
    // that same byte sequence as the request body. Verifying the raw body
    // avoids any PHP/JS serialization mismatches.
    $hmacRaw = hash_hmac('sha512', $requestJson, $ipnSecret);
    if (hash_equals($hmacRaw, $recived_hmac)) {
        return true;
    }

    // Strategy 2: top-level key sort that preserves original value bytes.
    // Covers the case where NowPayments sends the body with unsorted keys
    // but signs the sorted form.
    $sortedJson = sortTopLevelJsonKeys($requestJson);
    $hmacSorted = $sortedJson !== null ? hash_hmac('sha512', $sortedJson, $ipnSecret) : null;
    if ($hmacSorted !== null && hash_equals($hmacSorted, $recived_hmac)) {
        return true;
    }

    logTransaction($gatewayParams['name'], array_merge($requestData ?? [], [
        '_debug_received_hmac'     => $recived_hmac,
        '_debug_hmac_raw'          => $hmacRaw,
        '_debug_hmac_sorted'       => $hmacSorted,
        '_debug_raw_json'          => $requestJson,
        '_debug_sorted_json'       => $sortedJson,
    ]), 'HMAC signature does not match');
    die('HMAC signature does not match');
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
        $message = "Invoice {$invoiceId} has been paid. Amount received: {$paymentAmount} {$upper}";
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
        $message = "Your payment {$invoiceId} is partially paid. Please contact support@nowpayments.io. Expected amount received: {$paymentAmount} {$upper}. Amount received: {$actuallyPaid} {$upper}. " . "ID invoice: " . $requestData['payment_id'] . ".";
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
