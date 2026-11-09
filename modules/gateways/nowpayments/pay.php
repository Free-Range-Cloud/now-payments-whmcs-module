<?php
/**
 * Creates a NOWPayments invoice through the REST API and redirects the
 * customer to the hosted payment page.
 *
 * Reached from the "Pay Now" button rendered by nowpayments_link().
 */

require_once __DIR__ . '/../../../init.php';
App::load_function('gateway');
App::load_function('invoice');

use WHMCS\Database\Capsule;

$gatewayModuleName = 'nowpayments';
if (!function_exists('nowpayments_createInvoice')) {
    require_once __DIR__ . '/../' . $gatewayModuleName . '.php';
}

function nowpayments_payFail($message, $invoiceId = 0)
{
    $back = $invoiceId ? '../../../viewinvoice.php?id=' . (int) $invoiceId : '../../../clientarea.php';
    http_response_code(400);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Payment error</title></head><body>';
    echo '<p>' . htmlspecialchars($message) . '</p>';
    echo '<p><a href="' . htmlspecialchars($back) . '">Return to invoice</a></p>';
    echo '</body></html>';
    exit;
}

$invoiceId = isset($_REQUEST['invoiceid']) ? (int) $_REQUEST['invoiceid'] : 0;
if ($invoiceId <= 0) {
    nowpayments_payFail('Invalid invoice.');
}

$invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
if (!$invoice) {
    nowpayments_payFail('Invalid invoice.');
}

// Only the owning client (or a logged-in admin) may start a payment.
$clientId = (int) WHMCS\Session::get('uid');
$adminId = (int) WHMCS\Session::get('adminid');
if ($adminId <= 0 && $clientId !== (int) $invoice->userid) {
    nowpayments_payFail('You do not have permission to pay this invoice.', $invoiceId);
}

if ($invoice->status !== 'Unpaid') {
    header('Location: ../../../viewinvoice.php?id=' . $invoiceId);
    exit;
}

// Passing the invoice ID populates amount (balance due), currency,
// description, returnurl, systemurl, clientdetails, etc.
$params = getGatewayVariables($gatewayModuleName, $invoiceId);
if (empty($params['type'])) {
    nowpayments_payFail('This payment method is not currently available.', $invoiceId);
}
if (empty($params['amount']) || (float) $params['amount'] <= 0) {
    header('Location: ../../../viewinvoice.php?id=' . $invoiceId);
    exit;
}

try {
    $response = nowpayments_createInvoice($params);
} catch (Exception $e) {
    logTransaction($params['name'], array('invoiceid' => $invoiceId, 'error' => $e->getMessage()), 'Failed to create NOWPayments invoice');
    nowpayments_payFail('We could not start the crypto payment right now. Please try again shortly or contact support.', $invoiceId);
}

header('Location: ' . $response['invoice_url']);
exit;
