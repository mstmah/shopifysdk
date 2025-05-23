# Square PHP Integration Testing Guide

This guide provides steps and scenarios for testing the PHP wrapper functions in `square_integration.php` that utilize the Square PHP SDK. Thorough testing in the Square Sandbox environment is crucial before deploying any payment integration to a live production system.

## 1. Prerequisites

Before you begin testing, ensure you have the following:

*   **Square Developer Account & Sandbox Application:** You have a Square Developer Account and have created a Sandbox application. From this application in your Square Developer Dashboard, you should have:
    *   Your **Sandbox Access Token**.
    *   At least one **Sandbox Location ID**.
*   **Square PHP SDK Installed:** The Square PHP SDK (`square/square`) is installed in your project via Composer.
*   **PHP Environment:** A PHP 8.0+ environment with the `curl` and `json` extensions enabled.
*   **`square_integration.php` file:** The PHP file containing the Square integration wrapper functions.
*   **Square Sandbox Test Values:** Access to Square's sandbox test values. This includes:
    *   **`source_id` values:** Such as generic nonces like `'cnon:card-nonce-ok'` (for successful payments), `'cnon:card-nonce-declined'` (for declines), etc. You can also generate nonces using Square's test card numbers with the Web Payments SDK in a test HTML form.
    *   Refer to [Square Testing: Test Values](https://developer.squareup.com/docs/testing/test-values) for a comprehensive list.

## 2. Setup for Testing

### 2.1. Create a Test PHP Script

Create a new PHP file (e.g., `test_square.php`) in your project directory.

This script will:
1.  Include Composer's autoloader to load the Square PHP SDK classes.
2.  Include your `square_integration.php` file.
3.  Initialize the `Square\SquareClient`.
4.  Contain the test calls to your wrapper functions.

```php
<?php

// Include Composer's autoloader (adjust path if necessary)
require_once __DIR__ . '/vendor/autoload.php';

// Include your Square integration wrapper functions
require_once __DIR__ . '/square_integration.php';

// Use necessary Square SDK classes
use Square\SquareClient;
use Square\Environment;
use Square\Exceptions\ApiException; // For context, though wrappers handle this

// --- Your Test Configuration ---
// !!! IMPORTANT: Store your Access Token securely. For testing, you can define it here.
// !!! For production, use environment variables or other secure methods.
$sandboxAccessToken = 'YOUR_SANDBOX_ACCESS_TOKEN'; // Replace with your Square Sandbox Access Token
$sandboxLocationId  = 'YOUR_SANDBOX_LOCATION_ID';  // Replace with your Square Sandbox Location ID

// Initialize the Square Client for SANDBOX environment
try {
    $squareClient = new SquareClient([
        'accessToken' => $sandboxAccessToken,
        'environment' => Environment::SANDBOX,
    ]);
    echo "Square SDK Client initialized successfully for Sandbox.\n\n";
} catch (Exception $e) {
    echo "Failed to initialize Square Client: " . $e->getMessage() . "\n";
    exit(1);
}

// --- Test Scenarios Below ---

// Example: Generate a UUID v4 for Idempotency Keys (highly recommended)
function generate_square_uuid_v4() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

echo "<pre>"; // For cleaner output in a browser

// --- square_create_payment Tests ---
// Test 1: Successful payment
// $idempotencyKeyPayment = generate_square_uuid_v4();
// $paymentResult = square_create_payment(
//     $squareClient,
//     'cnon:card-nonce-ok', // Standard Square test nonce for success
//     100, // 100 cents = $1.00
//     'USD',
//     $idempotencyKeyPayment,
//     $sandboxLocationId, // Pass your sandbox location ID
//     null, // Optional orderId
//     'Test payment from PHP script', // Optional note
//     null, // Optional customerId
//     null  // Optional appFeeAmount
// );
// print_r($paymentResult);
// if ($paymentResult['success'] && isset($paymentResult['data'])) {
//     $testPaymentId = $paymentResult['data']->getId();
//     echo "Payment created successfully. Payment ID: " . $testPaymentId . "\n";
// } else {
//     echo "Payment creation failed. Errors: \n";
//     print_r($paymentResult['error_messages']);
// }

// --- Add other test scenarios for each function as outlined in the guide ---

echo "</pre>";

?>
```

**Remember to replace `'YOUR_SANDBOX_ACCESS_TOKEN'` and `'YOUR_SANDBOX_LOCATION_ID'` with your actual Sandbox credentials.**

## 3. General Testing Approach

For each wrapper function and scenario:

1.  **Prepare Data:** Set up the necessary input parameters. This includes the initialized `$squareClient`, valid Sandbox test values (like `sourceId`, amounts, currency codes), and your `$sandboxLocationId`. Use both valid and intentionally invalid data to test error handling.
2.  **Call the Wrapper Function:** Execute the function from your `test_square.php` script.
3.  **Inspect the Return Array:** The wrapper functions return an associative array: `['success' => bool, 'data' => SDKModel|null, 'error_messages' => array|null]`.
    *   Check the `'success'` boolean.
    *   If `'success'` is `false`, examine the `'error_messages'` array for details. These messages are derived from the Square SDK's `ApiException`.
    *   If `'success'` is `true`, inspect the `'data'` field, which will contain the relevant Square SDK Model object (e.g., `\Square\Models\Payment`, `\Square\Models\Order`). You can then call getter methods on this object (e.g., `$paymentResult['data']->getId()`).
4.  **Verify in Square Developer Sandbox Dashboard:**
    *   Log in to your Square Developer Dashboard ([developer.squareup.com/apps](https://developer.squareup.com/apps)).
    *   Select your Sandbox application.
    *   Navigate to the "API Logs" and "Sandbox Test Accounts" (Transactions, Orders sections) to verify that the API calls had the intended effect. This is crucial for confirming data persistence and status changes.

## 4. Specific Test Scenarios

Below are suggested scenarios. Expand on these based on your integration's specific needs.

### 4.1. `square_create_payment`

*   **Successful Payment:**
    *   Use a valid Sandbox `sourceId` (e.g., `'cnon:card-nonce-ok'`).
    *   Provide a valid amount (e.g., 100 for $1.00) and currency (e.g., "USD").
    *   Use a unique `idempotencyKey`.
    *   Provide your `$sandboxLocationId`.
    *   *Expected:* `success` is true, `data` contains a `\Square\Models\Payment` object. Verify the transaction in the Sandbox Dashboard.
*   **Different Amounts/Currencies:**
    *   Test with various valid amounts and supported currencies.
    *   *Expected:* Successful payments reflected correctly.
*   **With Optional Parameters:**
    *   Include an `orderId` (you might need to create an order first to get a valid ID), `note`, `customerId`.
    *   *Expected:* Payment created, and optional data visible in the payment details in the Sandbox Dashboard.
*   **Idempotency Key Test:**
    *   Make the same `square_create_payment` call twice in quick succession using the *same* `idempotencyKey`.
    *   *Expected:* The first call should succeed. The second call should return the original successful payment response (or a specific error indicating idempotency if the payment status changed), but *not* create a second charge. Verify only one payment in the Dashboard.
*   **Error Cases:**
    *   **Invalid `sourceId`:** Use `'cnon:card-nonce-declined'` or an invalid/expired nonce.
        *   *Expected:* `success` is false, `error_messages` indicate the decline or invalid source.
    *   **Invalid Access Token:** Temporarily modify `$sandboxAccessToken` to an invalid value.
        *   *Expected:* `success` is false, `error_messages` indicate authentication failure (e.g., 401 Unauthorized).
    *   **Missing `locationId` (if your account default isn't set up for it):**
        *   *Expected:* Potential error if location cannot be determined by Square.
    *   **Insufficient "Funds" (simulated by specific nonces if available):** Square's test nonces might simulate this.
        *   *Expected:* `success` is false, `error_messages` indicate decline.

### 4.2. `square_get_payment`

*   **Retrieve Known Payment:**
    *   Use a `paymentId` from a previously successful `square_create_payment` call.
    *   *Expected:* `success` is true, `data` contains the `\Square\Models\Payment` object with correct details.
*   **Retrieve Non-Existent Payment:**
    *   Use a fake or invalid `paymentId`.
    *   *Expected:* `success` is false, `error_messages` indicate payment not found (e.g., 404 Not Found).

### 4.3. `square_create_refund`

*   **Full Refund:**
    *   Use a `paymentId` from a "refundable" payment (succeeded and not yet refunded).
    *   Specify the full `amount` and correct `currency`.
    *   Provide a `reason` and a unique `idempotencyKey`.
    *   *Expected:* `success` is true, `data` contains a `\Square\Models\Refund` object. Verify in Sandbox Dashboard that the payment is marked as refunded.
*   **Partial Refund:**
    *   Use a `paymentId` from a refundable payment.
    *   Specify an `amount` less than the original payment amount.
    *   *Expected:* `success` is true. Verify partial refund in Sandbox Dashboard.
*   **Idempotency Key for Refunds:**
    *   Attempt the same refund request twice with the same `idempotencyKey`.
    *   *Expected:* Only one refund should be processed.
*   **Error Cases:**
    *   **Refund Amount Exceeds Balance:** Attempt to refund more than the available (non-refunded) amount.
        *   *Expected:* `success` is false, `error_messages` detail the issue.
    *   **Refund Non-Existent Payment:** Use a fake `paymentId`.
        *   *Expected:* `success` is false, `error_messages` indicate payment not found.
    *   **Refund Unrefundable Payment:** Attempt to refund a payment that is already fully refunded or in a non-refundable state (e.g., 'FAILED').
        *   *Expected:* `success` is false, `error_messages` detail the issue.

### 4.4. `square_create_order`

*   **Successful Order Creation:**
    *   Provide your `$sandboxLocationId`.
    *   Provide valid `$lineItems` (e.g., `[['name' => 'Test Item', 'quantity' => '1', 'amount' => 1500, 'currency' => 'USD']]`). Remember `quantity` must be a string.
    *   Use a unique `idempotencyKey`.
    *   *Expected:* `success` is true, `data` contains a `\Square\Models\Order` object. Verify order in Sandbox Dashboard.
*   **Order with Optional Parameters:**
    *   Include `referenceId`, `customerId`, `note`.
    *   *Expected:* Order created with these details visible in Sandbox Dashboard.
*   **Idempotency Key for Orders:**
    *   Make the same `square_create_order` call twice with the same `idempotencyKey`.
    *   *Expected:* Only one order should be created.
*   **Error Cases:**
    *   **Malformed Line Items:**
        *   Missing `name`, `quantity`, `amount`, or `currency`.
        *   `quantity` not a string.
        *   `amount` as negative.
        *   *Expected:* `success` is false, `error_messages` from internal validation or API.
    *   **Missing `locationId`:**
        *   *Expected:* `success` is false, API error.
    *   **Invalid Currency in Line Items:**
        *   *Expected:* `success` is false, API error.

## 5. Important Notes

*   **SANDBOX ONLY:** All tests described here MUST be performed in your Square Sandbox environment.
*   **TEST CREDENTIALS & VALUES:** Use only your Sandbox Access Token, Sandbox Location ID(s), and Square's official test card nonces/values. **Never use real card details or production credentials in the sandbox.**
*   **Refer to Square Developer Documentation:** The [Square Developer Documentation](https://developer.squareup.com/docs) is the authoritative source for API endpoint details, request/response formats, error codes, supported test values, and SDK specifics. Always cross-reference with the official documentation for the most accurate and up-to-date information.

By following these steps and scenarios, you can gain confidence in the functionality and error handling of your Square PHP integration. Remember to adapt and expand these tests based on your specific application requirements.
