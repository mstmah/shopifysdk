<?php

/**
 * Clover PHP Integration Library
 *
 * This library provides functions to interact with the Clover REST API for payments and orders.
 *
 * @version 1.0.0
 * @license MIT
 *
 * SECURITY CONSIDERATIONS:
 * - API Secret Key: The API secret key (`apiSecretKey`) is a sensitive credential.
 *   It MUST NOT be hardcoded directly in your application's source code in a production environment.
 *   Store it securely using:
 *     - Environment variables (e.g., $_ENV['CLOVER_API_SECRET_KEY'] or getenv('CLOVER_API_SECRET_KEY'))
 *     - Secure configuration files outside the webroot, with restricted access.
 *     - Secrets management services (e.g., HashiCorp Vault, AWS Secrets Manager, Google Cloud Secret Manager).
 * - PCI Compliance: When handling payments, ensure your integration adheres to PCI DSS requirements.
 *   This library is designed to work with tokenized payment information (source tokens) provided by
 *   Clover's client-side solutions (like Clover.js) to minimize your PCI scope.
 *   NEVER transmit or store raw credit card numbers, CVV codes, or full magnetic stripe data on your server.
 *
 * PHP VERSION & EXTENSIONS:
 * - This code is written to be compatible with PHP 8.0 and higher, including PHP 8.4.
 * - Requires the following standard PHP extensions:
 *   - `curl`: For making HTTP requests.
 *   - `json`: For encoding and decoding JSON data.
 *
 * API CONFIGURATION:
 * - Before using these functions, you must configure your Clover API credentials and the appropriate API base URL.
 * - The `CLOVER_API_BASE_URL_PLACEHOLDER` constant below needs to be updated.
 * - API Base URLs differ for sandbox and production environments and also by region (North America, EU, etc.).
 *   Consult the official Clover documentation for the correct base URLs for your specific account and region.
 *   Example North American URLs:
 *     Sandbox: https://scl-sandbox.dev.clover.com
 *     Production: https://scl.clover.com
 *   Example European Union URLs:
 *     Sandbox: https://scl-sandbox.eu.clover.com
 *     Production: https://scl.eu.clover.com
 */

/**
 * Base URL for the Clover API.
 * !!! IMPORTANT !!!
 * Replace this placeholder with the correct Clover API base URL for your environment (sandbox or production) and region.
 * Failure to do so will result in API connection errors.
 */
const CLOVER_API_BASE_URL_PLACEHOLDER = 'https://scl-sandbox.dev.clover.com'; // Example Sandbox URL for North America

/**
 * Helper function to make HTTP requests to the Clover API.
 * This will be used internally by the other functions.
 *
 * @param string $apiSecretKey The API secret key (private key). **Store this securely, not hardcoded.**
 * @param string $method The HTTP method (GET, POST).
 * @param string $url The full URL for the API endpoint.
 * @param array|null $data The data to send with POST requests (will be JSON encoded).
 * @param string|null $idempotencyKey Optional idempotency key (e.g., UUID v4). Helps prevent accidental duplicate operations.
 *
 * @return array An associative array:
 *               ['success' => bool, 'statusCode' => int, 'body' => array|null, 'errorMessage' => string|null]
 *               - 'success': True if the HTTP request was successful (2xx status code) and response decoded, false otherwise.
 *               - 'statusCode': The HTTP status code returned by the server.
 *               - 'body': The decoded JSON response as an associative array if successful and response is valid JSON, null otherwise.
 *               - 'errorMessage': Contains messages for cURL execution errors (e.g., network issues),
 *                                 HTTP status codes indicating failure (non-2xx), or JSON decoding problems.
 */
function _clover_make_api_request(
    string $apiSecretKey,
    string $method,
    string $url,
    ?array $data = null,
    ?string $idempotencyKey = null
): array {
    // Initialize cURL session
    $ch = curl_init();

    // Standard headers
    $headers = [
        'Authorization: Bearer ' . $apiSecretKey,
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    // Add Idempotency-Key header if provided
    // Idempotency keys help prevent unintended side effects from duplicate requests.
    if ($idempotencyKey !== null) {
        $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    }

    // Set cURL options
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // 30-second timeout

    // Set HTTP method and request body for POST requests
    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data !== null) {
            $jsonData = json_encode($data);
            if ($jsonData === false) {
                // Handle JSON encoding error
                curl_close($ch);
                return [
                    'success' => false,
                    'statusCode' => 0, // No HTTP request made
                    'body' => null,
                    'errorMessage' => 'Failed to encode request data to JSON: ' . json_last_error_msg(),
                ];
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        }
    } elseif (strtoupper($method) !== 'GET') {
        // For other methods like PUT, DELETE, etc.
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        if ($data !== null) {
            $jsonData = json_encode($data);
             if ($jsonData === false) {
                curl_close($ch);
                return [
                    'success' => false,
                    'statusCode' => 0,
                    'body' => null,
                    'errorMessage' => 'Failed to encode request data to JSON: ' . json_last_error_msg(),
                ];
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        }
    }

    // Execute the request
    $responseBody = curl_exec($ch);
    $curlErrorNo = curl_errno($ch);
    $curlErrorMessage = curl_error($ch);
    $httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    // Close cURL session
    curl_close($ch);

    // Handle cURL errors (e.g., network connectivity, DNS resolution)
    if ($curlErrorNo > 0) {
        return [
            'success' => false,
            'statusCode' => $httpStatusCode, // May be 0 if the server wasn't reached
            'body' => null,
            'errorMessage' => "cURL Error ({$curlErrorNo}): {$curlErrorMessage}",
        ];
    }

    // Decode the JSON response body
    $decodedBody = null;
    if ($responseBody) {
        $decodedBody = json_decode($responseBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Treat JSON decoding failure as an error, even if HTTP status is 2xx
            return [
                'success' => false,
                'statusCode' => $httpStatusCode,
                'body' => null, // Or $responseBody if you want to return raw on decode failure
                'errorMessage' => 'Failed to decode JSON response: ' . json_last_error_msg() . ". Raw response: " . substr($responseBody, 0, 200) . "...",
            ];
        }
    }

    // Determine success based on HTTP status code (200-299 range)
    $isSuccess = ($httpStatusCode >= 200 && $httpStatusCode < 300);

    $errorMessage = null;
    if (!$isSuccess && $decodedBody !== null && isset($decodedBody['message'])) {
        // Use Clover's error message if available from the decoded response body
        $errorMessage = "API Error ({$httpStatusCode}): " . $decodedBody['message'];
        if (isset($decodedBody['code'])) {
             $errorMessage .= " (Code: " . $decodedBody['code'] . ")";
        }
        if (isset($decodedBody['details'])) {
             $errorMessage .= " Details: " . (is_array($decodedBody['details']) ? json_encode($decodedBody['details']) : $decodedBody['details']);
        }
    } elseif (!$isSuccess) {
        $errorMessage = "HTTP Error: Received status code {$httpStatusCode}.";
        if ($responseBody) { // Include part of raw response if it wasn't valid JSON or didn't have a message field
            $errorMessage .= " Raw response: " . substr($responseBody, 0, 200) . "...";
        }
    }


    return [
        'success' => $isSuccess,
        'statusCode' => $httpStatusCode,
        'body' => $decodedBody,
        'errorMessage' => $errorMessage,
    ];
}


/**
 * Creates a payment (charge) using the Clover API.
 *
 * @param string $apiSecretKey The API secret key (private key). **Store this securely.**
 * @param int $amount The amount to charge, in cents (e.g., 1000 for $10.00).
 * @param string $currency The currency code (e.g., "USD", "EUR").
 * @param string $sourceToken The payment source token (e.g., from Clover.js or a saved card).
 *                            **IMPORTANT for PCI Compliance: This token represents card details.
 *                            Never handle raw credit card numbers directly on your server.**
 * @param string|null $idempotencyKey Optional idempotency key (UUID v4 recommended) to prevent duplicate charges.
 *                                    This helps ensure that if a request is sent multiple times (e.g., due to network retry),
 *                                    it's only processed once by Clover.
 * @param string|null $orderId Optional order ID to associate with the charge. Ensure this ID is in a format acceptable to Clover if used.
 * @param string|null $description Optional description for the charge.
 * @param array|null $customerDetails Optional customer details (e.g., email, name). Refer to Clover API docs for expected structure.
 * @param array|null $metadata Optional metadata to store with the charge. Refer to Clover API docs for structure and limits.
 * @param string $apiBaseUrl The base URL for the Clover API. Defaults to CLOVER_API_BASE_URL_PLACEHOLDER.
 *                           **Ensure this is correctly set for your environment (sandbox/production).**
 *
 * @return array An associative array:
 *               ['success' => bool, 'paymentId' => string|null, 'status' => string|null, 'rawResponse' => array|null, 'errorMessage' => string|null]
 *               - 'success': True if payment creation was successful.
 *               - 'paymentId': The ID of the created payment if successful.
 *               - 'status': The status of the payment (e.g., 'succeeded', 'pending', 'failed').
 *               - 'rawResponse': The full API response from Clover.
 *               - 'errorMessage': Details on failure, or null on success.
 */
function clover_create_payment(
    string $apiSecretKey,
    int $amount,
    string $currency,
    string $sourceToken,
    ?string $idempotencyKey = null,
    ?string $orderId = null,
    ?string $description = null,
    ?array $customerDetails = null,
    ?array $metadata = null,
    string $apiBaseUrl = CLOVER_API_BASE_URL_PLACEHOLDER
): array {
    if ($amount <= 0) {
        return ['success' => false, 'paymentId' => null, 'status' => null, 'rawResponse' => null, 'errorMessage' => 'Amount must be a positive integer.'];
    }
    if (empty(trim($currency))) {
        return ['success' => false, 'paymentId' => null, 'status' => null, 'rawResponse' => null, 'errorMessage' => 'Currency cannot be empty.'];
    }
     if (empty(trim($sourceToken))) {
        return ['success' => false, 'paymentId' => null, 'status' => null, 'rawResponse' => null, 'errorMessage' => 'Source token cannot be empty.'];
    }

    $url = rtrim($apiBaseUrl, '/') . '/v1/charges';
    $payload = [
        'amount' => $amount,
        'currency' => strtoupper($currency),
        'source' => $sourceToken,
    ];

    if ($orderId !== null) {
        $payload['order_id'] = $orderId;
    }
    if ($description !== null) {
        $payload['description'] = $description;
    }
    if ($customerDetails !== null) {
        $payload['customer_details'] = $customerDetails;
    }
    if ($metadata !== null) {
        $payload['metadata'] = $metadata;
    }

    $response = _clover_make_api_request($apiSecretKey, 'POST', $url, $payload, $idempotencyKey);

    if (!$response['success']) {
        return [
            'success' => false,
            'paymentId' => null,
            'status' => null,
            'rawResponse' => $response['body'], // Contains API error details if available
            'errorMessage' => $response['errorMessage'] ?? 'Failed to create payment.',
        ];
    }

    // Assuming Clover API returns 'id' for payment ID and 'status' for payment status in the charge object
    $paymentId = $response['body']['id'] ?? null;
    $status = $response['body']['status'] ?? null;

    if ($paymentId === null) {
         return [
            'success' => false, // Even if HTTP 2xx, if ID is missing, treat as an issue.
            'paymentId' => null,
            'status' => $status,
            'rawResponse' => $response['body'],
            'errorMessage' => 'Payment created but ID not found in response.',
        ];
    }

    return [
        'success' => true,
        'paymentId' => $paymentId,
        'status' => $status,
        'rawResponse' => $response['body'],
        'errorMessage' => null,
    ];
}

/**
 * Retrieves the status of a specific payment from the Clover API.
 *
 * @param string $apiSecretKey The API secret key (private key). **Store this securely.**
 * @param string $paymentId The ID of the payment to retrieve.
 * @param string $apiBaseUrl The base URL for the Clover API. Defaults to CLOVER_API_BASE_URL_PLACEHOLDER.
 *                           **Ensure this is correctly set for your environment (sandbox/production).**
 *
 * @return array An associative array:
 *               ['success' => bool, 'status' => string|null, 'paymentDetails' => array|null, 'errorMessage' => string|null]
 *               - 'success': True if retrieval was successful.
 *               - 'status': The status of the payment.
 *               - 'paymentDetails': The full charge object from Clover.
 *               - 'errorMessage': Details on failure, or null on success.
 */
function clover_get_payment_status(
    string $apiSecretKey,
    string $paymentId,
    string $apiBaseUrl = CLOVER_API_BASE_URL_PLACEHOLDER
): array {
    if (empty(trim($paymentId))) {
        return ['success' => false, 'status' => null, 'paymentDetails' => null, 'errorMessage' => 'Payment ID cannot be empty.'];
    }

    $url = rtrim($apiBaseUrl, '/') . '/v1/charges/' . $paymentId;
    $response = _clover_make_api_request($apiSecretKey, 'GET', $url);

    if (!$response['success']) {
        return [
            'success' => false,
            'status' => null,
            'paymentDetails' => $response['body'], // Return raw body on error for inspection
            'errorMessage' => $response['errorMessage'] ?? 'Failed to get payment status.',
        ];
    }

    $status = $response['body']['status'] ?? null;

    return [
        'success' => true,
        'status' => $status,
        'paymentDetails' => $response['body'],
        'errorMessage' => null,
    ];
}

/**
 * Refunds a payment using the Clover API.
 *
 * @param string $apiSecretKey The API secret key (private key). **Store this securely.**
 * @param string $paymentId The ID of the payment to refund.
 * @param int|null $amount The amount to refund, in cents. If null, a full refund is attempted.
 * @param string|null $reason Optional reason for the refund (e.g., "requested_by_customer").
 *                            Check Clover API documentation for allowed reason codes or formats.
 * @param string|null $idempotencyKey Optional idempotency key (UUID v4 recommended) for the refund request.
 *                                    Crucial for preventing duplicate refunds.
 * @param string $apiBaseUrl The base URL for the Clover API. Defaults to CLOVER_API_BASE_URL_PLACEHOLDER.
 *                           **Ensure this is correctly set for your environment (sandbox/production).**
 *
 * @return array An associative array:
 *               ['success' => bool, 'refundId' => string|null, 'status' => string|null, 'rawResponse' => array|null, 'errorMessage' => string|null]
 *               - 'success': True if refund was processed successfully.
 *               - 'refundId': The ID of the refund transaction.
 *               - 'status': The status of the refund (e.g., 'succeeded', 'pending').
 *               - 'rawResponse': The full API response from Clover.
 *               - 'errorMessage': Details on failure, or null on success.
 */
function clover_refund_payment(
    string $apiSecretKey,
    string $paymentId,
    ?int $amount = null,
    ?string $reason = null,
    ?string $idempotencyKey = null,
    string $apiBaseUrl = CLOVER_API_BASE_URL_PLACEHOLDER
): array {
    if (empty(trim($paymentId))) {
        return ['success' => false, 'refundId' => null, 'status' => null, 'rawResponse' => null, 'errorMessage' => 'Payment ID cannot be empty.'];
    }
    if ($amount !== null && $amount <= 0) {
        return ['success' => false, 'refundId' => null, 'status' => null, 'rawResponse' => null, 'errorMessage' => 'Refund amount must be a positive integer if specified.'];
    }

    $url = rtrim($apiBaseUrl, '/') . '/v1/charges/' . $paymentId . '/refunds';
    $payload = [];

    if ($amount !== null) {
        $payload['amount'] = $amount;
    }
    if ($reason !== null) {
        $payload['reason'] = $reason;
    }
    
    if (empty($payload) && strtoupper('POST') === 'POST') { // Ensure payload is an object for POST if otherwise empty
        $payload = new stdClass(); // To ensure json_encode creates {} for an empty POST body if API requires it
    }

    $response = _clover_make_api_request($apiSecretKey, 'POST', $url, $payload, $idempotencyKey);

    if (!$response['success']) {
        return [
            'success' => false,
            'refundId' => null,
            'status' => null,
            'rawResponse' => $response['body'],
            'errorMessage' => $response['errorMessage'] ?? 'Failed to process refund.',
        ];
    }

    $refundId = $response['body']['id'] ?? null;
    $status = $response['body']['status'] ?? null;
    
    if ($refundId === null) {
         return [
            'success' => false, // If refund ID is missing, consider it a partial failure.
            'refundId' => null,
            'status' => $status,
            'rawResponse' => $response['body'],
            'errorMessage' => 'Refund processed but refund ID not found in response.',
        ];
    }

    return [
        'success' => true,
        'refundId' => $refundId,
        'status' => $status,
        'rawResponse' => $response['body'],
        'errorMessage' => null,
    ];
}

/**
 * Creates an order/invoice in Clover.
 *
 * @param string $apiSecretKey The Clover API Secret Key. **Store this securely.**
 * @param string $apiBaseUrl The base URL for the Clover API. **Ensure this is correctly set for your environment (sandbox/production).**
 * @param array $lineItems An array of line items. Each item is an associative array. Example:
 *   `[['name' => 'Item 1', 'price' => 1000 (in cents), 'quantity' => 1], ...]`
 *   The exact structure for line items must match Clover's API expectation (e.g., `price` vs `amount`).
 *   This function assumes `price` for line items. Prices must be in cents.
 * @param string $currency The currency code (e.g., "USD").
 * @param array|null $customerDetails Optional: Associative array with customer information.
 *                                    The structure must align with Clover API specifications (e.g., for 'customer' object).
 * @param string|null $externalReferenceId Optional: An ID from the external web system.
 *                                         Ensure this ID's format and uniqueness comply with Clover's requirements.
 * @param string|null $idempotencyKey Optional: A unique key (UUID v4 recommended) to prevent duplicate orders.
 * @param string $orderState Optional: The state for the new order (e.g., 'open', 'draft'). Defaults to 'open'.
 *
 * @return array An associative array containing:
 *               - 'success' (bool): True if the order was created successfully.
 *               - 'order_id' (string|null): The ID of the created order if successful.
 *               - 'status' (string|null): The status/state of the order (e.g., 'open', 'paid').
 *               - 'message' (string|null): A success or error message.
 *               - 'data' (array|null): The full API response data if successful, or error details if not.
 */
function clover_create_order(
    string $apiSecretKey,
    string $apiBaseUrl,
    array $lineItems,
    string $currency,
    ?array $customerDetails = null,
    ?string $externalReferenceId = null,
    ?string $idempotencyKey = null,
    string $orderState = 'open'
): array {
    // Basic input validation
    if (empty(trim($apiSecretKey))) {
         return ['success' => false, 'order_id' => null, 'status' => null, 'message' => 'API secret key cannot be empty.', 'data' => null];
    }
    if (empty(trim($apiBaseUrl))) {
         return ['success' => false, 'order_id' => null, 'status' => null, 'message' => 'API base URL cannot be empty.', 'data' => null];
    }
    if (empty($lineItems)) {
        return ['success' => false, 'order_id' => null, 'status' => null, 'message' => 'Line items cannot be empty.', 'data' => null];
    }
    foreach ($lineItems as $index => $item) { // Added $index for better error reporting
        if (!is_array($item) || !isset($item['name']) || !isset($item['price']) || !isset($item['quantity'])) {
            return ['success' => false, 'order_id' => null, 'status' => null, 'message' => "Each line item (index: {$index}) must be an array and contain name, price (in cents), and quantity.", 'data' => ['invalid_item_index' => $index, 'item_data' => $item]];
        }
        if (!is_int($item['price']) || $item['price'] < 0) {
            return ['success' => false, 'order_id' => null, 'status' => null, 'message' => "Line item (index: {$index}, name: {$item['name']}) price must be a non-negative integer (cents).", 'data' => ['invalid_item_index' => $index, 'item_data' => $item]];
        }
        if (!is_int($item['quantity']) || $item['quantity'] <= 0) {
            return ['success' => false, 'order_id' => null, 'status' => null, 'message' => "Line item (index: {$index}, name: {$item['name']}) quantity must be a positive integer.", 'data' => ['invalid_item_index' => $index, 'item_data' => $item]];
        }
    }
    if (empty(trim($currency))) {
        return ['success' => false, 'order_id' => null, 'status' => null, 'message' => 'Currency cannot be empty.', 'data' => null];
    }

    $url = rtrim($apiBaseUrl, '/') . '/v1/orders';

    $payload = [
        'currency' => strtoupper($currency),
        'line_items' => [],
        'state' => $orderState,
    ];

    foreach ($lineItems as $item) {
        $payload['line_items'][] = [
            'name' => $item['name'],
            'price' => $item['price'],
            'quantity' => $item['quantity'],
        ];
    }

    if ($customerDetails !== null) {
        $payload['customer'] = $customerDetails;
    }

    if ($externalReferenceId !== null) {
        $payload['external_reference_id'] = $externalReferenceId;
    }

    $response = _clover_make_api_request($apiSecretKey, 'POST', $url, $payload, $idempotencyKey);

    if (!$response['success']) {
        return [
            'success' => false,
            'order_id' => null,
            'status' => null,
            'message' => $response['errorMessage'] ?? 'Failed to create order.',
            'data' => $response['body'],
        ];
    }

    $orderId = $response['body']['id'] ?? null;
    $status = $response['body']['state'] ?? ($response['body']['status'] ?? null);

    if ($orderId === null) {
        return [
            'success' => false,
            'order_id' => null,
            'status' => $status,
            'message' => 'Order creation API call succeeded but order ID not found in response.',
            'data' => $response['body'],
        ];
    }

    return [
        'success' => true,
        'order_id' => $orderId,
        'status' => $status,
        'message' => 'Order created successfully.',
        'data' => $response['body'],
    ];
}

?>
