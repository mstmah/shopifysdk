# Clover PHP Integration Testing Guide

This guide provides steps and scenarios for testing the PHP functions in `clover_integration.php`. Thorough testing in a sandbox environment is crucial before deploying any payment integration to a live production system.

## 1. Prerequisites

Before you begin testing, ensure you have the following:

*   **Clover Developer Account:** You have successfully created a Clover Developer Account and have access to your Developer Dashboard.
*   **Sandbox Environment:** You have set up a test merchant within your Clover Developer Sandbox.
*   **API Credentials:**
    *   Your **API Secret Key** (also known as a private key or access token).
    *   The correct **Sandbox API Base URL** for your region (e.g., `https://scl-sandbox.dev.clover.com` for North America).
*   **PHP Environment:** A PHP 8.0+ environment with the `curl` and `json` extensions enabled.
*   **`clover_integration.php` file:** The PHP file containing the Clover integration functions.
*   **Test Payment Tokens:** Access to test card numbers or payment tokens provided by the Clover sandbox environment. These are used to simulate card payments without using real financial instruments. You can typically find these in the Clover Developer documentation or your sandbox dashboard.

## 2. Setup for Testing

### 2.1. Configure `clover_integration.php`

1.  **Open `clover_integration.php`:** Locate the file containing the Clover PHP functions.
2.  **Set API Base URL:** Find the constant `CLOVER_API_BASE_URL_PLACEHOLDER`.
    ```php
    // const CLOVER_API_BASE_URL_PLACEHOLDER = 'https://scl-sandbox.dev.clover.com'; // Example
    const CLOVER_API_BASE_URL_PLACEHOLDER = 'YOUR_SANDBOX_API_BASE_URL'; // Replace with your actual Sandbox URL
    ```
    Replace `'YOUR_SANDBOX_API_BASE_URL'` with the actual Sandbox API Base URL provided by Clover for your test merchant's region.

    *Note: For functions like `clover_create_order` that take `$apiBaseUrl` as a direct parameter, you will pass this URL directly when calling the function in your test script.*

3.  **API Secret Key Handling:** The `apiSecretKey` is passed as a parameter to each function. **Do not hardcode your actual API Secret Key directly into `clover_integration.php` or your test scripts if there's any chance they might be committed to version control or deployed to insecure environments.** For testing, you can define it in your test script and pass it, but for production, use environment variables or a secure configuration method as recommended in the library's comments.

### 2.2. Create a Test PHP Script

Create a new PHP file (e.g., `test_clover.php`) in the same directory as `clover_integration.php` or ensure `clover_integration.php` is includable.

```php
<?php

// Include the Clover integration library
require_once 'clover_integration.php';

// --- Your Test Configuration ---
// !!! IMPORTANT: Store your API Secret Key securely. For testing, you can define it here.
// !!! For production, use environment variables or other secure methods.
$apiSecretKey = 'YOUR_CLOVER_API_SECRET_KEY'; // Replace with your Sandbox API Secret Key

// Use the configured base URL from clover_integration.php or define it explicitly if needed for specific functions
$apiBaseUrl = CLOVER_API_BASE_URL_PLACEHOLDER; // Or your specific Sandbox URL for functions that require it as a param

// --- Test Scenarios Below ---

// Example: Generate a UUID v4 for Idempotency Keys
function generate_uuid_v4() {
    // Basic UUID v4 generation for testing purposes
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

echo "<pre>"; // For cleaner output in a browser

// --- clover_create_payment Tests ---
// Test 1: Successful payment
// Replace 'clv_test_xxxxxxxxxxx' with a valid test source token from Clover Sandbox
// $paymentResult = clover_create_payment($apiSecretKey, 1000, 'USD', 'clv_test_YOUR_SANDBOX_SOURCE_TOKEN', generate_uuid_v4(), null, 'Test Payment', null, null, $apiBaseUrl);
// print_r($paymentResult);
// if ($paymentResult['success'] && $paymentResult['paymentId']) {
//     $testPaymentId = $paymentResult['paymentId']; // Save for further tests
//     echo "Payment created successfully. Payment ID: " . $testPaymentId . "\n";
// } else {
//     echo "Payment creation failed: " . ($paymentResult['errorMessage'] ?? 'Unknown error') . "\n";
// }

// --- Add other test scenarios for each function as outlined below ---

echo "</pre>";

?>
```

## 3. General Testing Approach

For each function and scenario:

1.  **Prepare Data:** Set up the necessary input parameters for the function call (e.g., amount, currency, payment ID, line items). Use both valid and intentionally invalid data to test error handling.
2.  **Call the Function:** Execute the Clover PHP function from your `test_clover.php` script.
3.  **Inspect the Return Array:**
    *   Check the `'success'` boolean (true or false).
    *   Examine `'errorMessage'` or `'message'` for any error details if `'success'` is false.
    *   If successful, check relevant fields like `'paymentId'`, `'order_id'`, `'status'`, and the content of `'rawResponse'`, `'paymentDetails'`, or `'data'`.
4.  **Verify in Clover Sandbox Dashboard:**
    *   Log in to your Clover Developer Dashboard and navigate to your test merchant's sandbox environment.
    *   Check for corresponding transactions (payments, refunds, orders) and verify their details (amount, status, line items, customer info).
    *   This step is crucial to confirm that the API calls had the intended effect on the Clover platform.

## 4. Specific Test Scenarios

Below are suggested scenarios. You should expand on these with more variations based on your specific integration needs.

### 4.1. `clover_create_payment`

*   **Successful Payment:**
    *   Use a valid test source token (provided by Clover for sandbox testing).
    *   Provide a valid amount (e.g., 1000 for $10.00) and currency (e.g., "USD").
    *   Use a unique idempotency key.
    *   *Expected:* `success` is true, a `paymentId` is returned, status indicates success (e.g., 'succeeded'). Verify in Clover Dashboard.
*   **Different Amounts/Currencies:**
    *   Test with various valid amounts and supported currencies.
    *   *Expected:* Successful payments reflected correctly in Clover.
*   **With Optional Parameters:**
    *   Include an `orderId`, `description`, `customerDetails`, and `metadata`.
    *   *Expected:* Payment created, and optional data visible in the Clover Dashboard transaction details.
*   **Idempotency Key Test:**
    *   Make the same `clover_create_payment` call twice in quick succession using the *same* idempotency key.
    *   *Expected:* The first call should succeed. The second call should ideally return the original successful response (or a specific error indicating idempotency) but *not* create a second charge. Verify only one payment in Clover Dashboard.
*   **Error Cases:**
    *   **Invalid Source Token:** Use a deliberately incorrect or expired token.
        *   *Expected:* `success` is false, `errorMessage` indicates token issue.
    *   **Invalid API Key:** Temporarily modify your `$apiSecretKey` to an invalid value in your test script.
        *   *Expected:* `success` is false, `errorMessage` indicates authentication failure (e.g., 401 Unauthorized).
    *   **Invalid Amount:** Amount as 0 or negative.
        *   *Expected:* `success` is false, `errorMessage` from internal validation.
    *   **Missing Required Fields:** Call without amount, currency, or source.
        *   *Expected:* `success` is false, `errorMessage` from internal validation.

### 4.2. `clover_get_payment_status`

*   **Retrieve Known Payment:**
    *   Use a `paymentId` from a previously successful `clover_create_payment` call.
    *   *Expected:* `success` is true, `status` and `paymentDetails` reflect the known payment.
*   **Retrieve Non-Existent Payment:**
    *   Use a fake or invalid `paymentId`.
    *   *Expected:* `success` is false, `errorMessage` indicates payment not found (e.g., 404 Not Found).

### 4.3. `clover_refund_payment`

*   **Full Refund:**
    *   Use a `paymentId` from a "refundable" payment (typically one that has succeeded and not been fully refunded).
    *   Do not specify the `amount` parameter (or set to `null`).
    *   Use a unique idempotency key.
    *   *Expected:* `success` is true, a `refundId` is returned, status indicates success. Verify in Clover Dashboard that the payment is marked as refunded.
*   **Partial Refund:**
    *   Use a `paymentId` from a refundable payment.
    *   Specify an `amount` less than the original payment amount.
    *   Use a unique idempotency key.
    *   *Expected:* `success` is true, `refundId` returned. Verify partial refund in Clover Dashboard.
*   **Idempotency Key for Refunds:**
    *   Attempt the same refund request twice with the same idempotency key.
    *   *Expected:* Only one refund should be processed.
*   **Error Cases:**
    *   **Refund Amount Exceeds Balance:** Attempt to refund more than the available (non-refunded) amount of a payment.
        *   *Expected:* `success` is false, `errorMessage` indicates issue.
    *   **Refund Non-Existent Payment:** Use a fake `paymentId`.
        *   *Expected:* `success` is false, `errorMessage` indicates payment not found.
    *   **Refund Unrefundable Payment:** Attempt to refund a payment that is already fully refunded or in a non-refundable state.
        *   *Expected:* `success` is false, `errorMessage` indicates issue.

### 4.4. `clover_create_order`

*   **Successful Order Creation:**
    *   Provide valid `lineItems` (e.g., `[['name' => 'Product A', 'price' => 1500, 'quantity' => 1], ['name' => 'Service B', 'price' => 5000, 'quantity' => 1]]`), `currency`.
    *   Use a unique idempotency key.
    *   *Expected:* `success` is true, an `order_id` is returned, `status` is 'open' (or as specified). Verify order in Clover Dashboard.
*   **Order with Optional Parameters:**
    *   Include `customerDetails`, `externalReferenceId`, and a different `orderState` (if applicable).
    *   *Expected:* Order created with these details visible in Clover Dashboard.
*   **Idempotency Key for Orders:**
    *   Make the same `clover_create_order` call twice with the same idempotency key.
    *   *Expected:* Only one order should be created.
*   **Error Cases:**
    *   **Malformed Line Items:**
        *   Missing `name`, `price`, or `quantity` in a line item.
        *   `price` or `quantity` with invalid values (e.g., negative price).
        *   *Expected:* `success` is false, `message` indicates validation error.
    *   **Invalid Currency:** Use an unsupported currency code.
        *   *Expected:* `success` is false, `message` indicates API error.
    *   **Invalid API Key:**
        *   *Expected:* `success` is false, `message` indicates authentication error.

## 5. Important Note

*   **SANDBOX ONLY:** All tests described here MUST be performed in your Clover Sandbox environment using test merchant accounts and test API credentials.
*   **NO REAL CARD DETAILS:** Never use real credit card numbers, real bank account information, or your production API keys during these sandbox tests.
*   **Refer to Clover Documentation:** The Clover Developer Documentation is the authoritative source for API endpoint details, request/response formats, error codes, and test card information. Always cross-reference with the official documentation.

By following these steps and scenarios, you can gain confidence in the functionality and error handling of your Clover PHP integration. Remember to adapt and expand these tests based on your specific application requirements.Okay, I have created the `CLOVER_TESTING_GUIDE.md` document as requested.
It includes all the specified sections: Prerequisites, Setup for Testing, General Testing Approach, Specific Test Scenarios for each function, and an Important Note about sandbox testing.
The content is structured to be clear and actionable for a user intending to test the `clover_integration.php` library.
I've included example code snippets and emphasized key considerations like API key security and using the Clover Sandbox Dashboard for verification.
