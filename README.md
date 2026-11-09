# NOWPayments WHMCS Gateway — Community Fork

This is a community-maintained fork of the [NOWPayments WHMCS payment gateway](https://nowpayments.io) module.

## Why this fork exists

The upstream module has an unfixed bug that causes all IPN (Instant Payment Notification) callbacks to fail with an **"HMAC Signature Invalid"** error, meaning payments are never automatically marked as paid in WHMCS.

The upstream developer has indicated there are no plans to fix this. This fork addresses the following root causes:

- **`php://input` read twice** — PHP's request body stream can only be read once. The original code consumed it inside the HMAC verification function, leaving nothing for the payment processing code that followed. This caused the gateway log in WHMCS to record no content.
- **`json_encode` without correct flags** — After sorting the payload keys, the code re-encoded the JSON without `JSON_UNESCAPED_UNICODE`, `JSON_UNESCAPED_SLASHES`, or `JSON_PRESERVE_ZERO_FRACTION`. PHP's default encoding escapes slashes and unicode characters, and can alter floating-point representation — all of which produce a different string than what NOWPayments signed.
- **Scientific notation in floats** — PHP serializes very small floats (e.g. fee values) in scientific notation (`1.6e-5`), while NOWPayments' backend (Node.js) serializes the same value as `0.000016`. This difference caused an HMAC mismatch even with the correct secret.
- **Non-recursive key sorting** — The original `ksort()` call only sorted top-level keys. Nested objects (such as the `fee` field) were left unsorted, which would cause HMAC failures on affected payloads.
- **Empty gateway logs** — NOWPayments sends IPN data as a JSON body (`application/json`), not as form-encoded POST. The original code logged `$_POST`, which is always empty for JSON requests, making debugging impossible.

## API-only checkout

NOWPayments has disabled their hosted WHMCS integration (the `nowpayments.io/payment?data=...` link with `dataSource: whmcs`). This fork no longer uses it. Instead:

1. The invoice page shows a **Pay Now** button that posts to `modules/gateways/nowpayments/pay.php`.
2. `pay.php` checks that the logged-in client owns the invoice and that it is unpaid. It then calls the NOWPayments REST API (`POST /v1/invoice`) with your API key and redirects the customer to the returned `invoice_url`.
3. NOWPayments sends IPN callbacks to `modules/gateways/callback/nowpayments.php`, which verifies the HMAC and applies the payment.

A NOWPayments invoice is created only when the customer clicks Pay, not every time the invoice page is viewed. API requests and responses are recorded in **Utilities > Logs > Module Log** (with debug logging enabled), and the API key is masked.

## Installation

1. Copy the `modules/` directory into your WHMCS root.
2. In the WHMCS admin panel, go to **Setup > Payment Gateways** and activate **NOWPayments**.
3. Enter your **API Key** and **IPN Secret** from your NOWPayments dashboard (Store Settings). Optionally enable *Fee paid by customer*, *Fixed rate*, or *Sandbox mode*.
4. Under **Setup > General Settings**, ensure the **WHMCS System URL** is set correctly — this is used to construct the IPN callback URL.

## Disclaimer

This module is provided **as-is, without any warranty or service level agreement of any kind**, express or implied. It is maintained on a **best-effort basis** by volunteers. There is no guarantee of timely updates, bug fixes, or compatibility with future versions of WHMCS or the NOWPayments API.

Use in production is at your own risk.

## Contributing

Pull requests are welcome and encouraged. If you encounter a bug, have a fix, or want to improve the module, please open a PR — that is how this fork stays useful for everyone.

When submitting a PR please:
- Describe what the problem is and what your fix does
- Test against a live or sandbox NOWPayments account if possible
- Keep changes focused; one fix or feature per PR
