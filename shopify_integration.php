<?php

/**
 * Shopify PHP Integration Library (GraphQL)
 *
 * This library provides functions to interact with the Shopify Admin API using GraphQL.
 * It's designed to be used with a generic PHP GraphQL client or can be adapted
 * for use with specific Shopify PHP SDKs that support GraphQL.
 *
 * @version 1.0.0
 * @license MIT
 *
 * REQUIREMENTS:
 * - PHP 7.4 or higher (recommended for modern syntax, though functions might work on older versions).
 * - A PHP GraphQL client library (e.g., `gmostafa/php-graphql-client`, `webonyx/graphql-php` for server-side, or a generic HTTP client like Guzzle).
 * - Composer: For installing the chosen GraphQL client library and its dependencies.
 *
 * SECURITY & CONFIGURATION:
 * - Admin API Access Token: This is highly sensitive and provides extensive access to your store.
 *   It MUST NOT be hardcoded directly in your application's source code in a production environment.
 *   Store it securely using:
 *     - Environment variables (e.g., $_ENV['SHOPIFY_ADMIN_ACCESS_TOKEN'] or getenv('SHOPIFY_ADMIN_ACCESS_TOKEN')).
 *     - Secure configuration files stored outside the webroot, with restricted access.
 *     - Dedicated secrets management services (e.g., HashiCorp Vault, AWS Secrets Manager, Google Cloud Secret Manager).
 * - Access Scopes: When configuring your Custom App in Shopify, ensure you only grant the *minimum necessary*
 *   API access scopes required for the functions you intend to use (e.g., `write_draft_orders`, `read_orders`).
 *   Requesting excessive permissions increases security risks.
 * - API Versioning: Shopify versions its API quarterly (e.g., `2023-10`, `2024-01`). Ensure your GraphQL queries
 *   are compatible with the API version configured for your client/requests. This library does not enforce a version;
 *   it's determined by the endpoint your client targets.
 * - Store Domain: Your Shopify store domain (e.g., `your-shop-name.myshopify.com`) is needed to construct the API endpoint.
 *
 * SHOPIFY PAYMENT & ORDER WORKFLOWS:
 * - These functions primarily manage orders (drafts, existing orders) and can record payment information
 *   (e.g., for manually processed payments) or create refunds.
 * - Actual online payment processing (e.g., credit card charges via a payment gateway) typically occurs
 *   through Shopify Checkout, a Shopify POS terminal, or an integrated third-party payment gateway app.
 * - Functions like `shopify_create_order_transaction` are for *recording* payments that have been
 *   processed externally or manually, not for initiating new charges against a payment gateway.
 *
 * USAGE:
 * Each function expects an initialized GraphQL client object (`$shopifyClient`) as its first parameter.
 * This client should have a method like `query(string $graphqlQuery, ?array $variables = null)`
 * or `request(string $graphqlQuery, ?array $variables = null)` which executes the GraphQL
 * query/mutation and returns an object or array from which success, data, and errors can be derived.
 * The functions in this library will then parse this response into a standardized array format.
 *
 * GRAPHQL CONTEXT:
 * The functions within this library internally construct and execute GraphQL queries and mutations.
 * For advanced usage, troubleshooting, or customization, a good understanding of the
 * Shopify GraphQL Admin API schema ([https://shopify.dev/api/admin-graphql](https://shopify.dev/api/admin-graphql))
 * and its conventions (like GIDs for identifiers, `userErrors` for business logic errors) is highly beneficial.
 *
 * BASIC USAGE EXAMPLE:
 * ```php
 * <?php
 *
 * // 1. Include Composer's autoloader (if your GraphQL client is from Composer)
 * // require_once __DIR__ . '/vendor/autoload.php';
 *
 * // 2. Include this integration library
 * require_once __DIR__ . '/shopify_integration.php';
 *
 * // 3. Configure your Shopify store details and credentials
 * // !!! REPLACE WITH YOUR ACTUAL SANDBOX/DEVELOPMENT STORE DETAILS !!!
 * $myShopifyStoreDomain = 'your-dev-store.myshopify.com';
 * $myShopifyAdminAccessToken = 'YOUR_SHOPIFY_ADMIN_API_ACCESS_TOKEN'; // KEEP THIS SECRET!
 * $myShopifyApiVersion = '2024-01'; // Use a current, supported API version
 *
 * // 4. CONCEPTUAL CLIENT INITIALIZATION - REPLACE WITH YOUR ACTUAL GRAPHQL CLIENT
 * // This is a placeholder. You MUST use a real GraphQL client library.
 * // Option A: Example using a library like 'gmostafa/php-graphql-client' (install via Composer: composer require gmostafa/php-graphql-client)
 * // use GraphQL\Client;
 * // $endpoint = "https://{$myShopifyStoreDomain}/admin/api/{$myShopifyApiVersion}/graphql.json";
 * // $shopifyClient = null;
 * // try {
 * //     $shopifyClient = new Client($endpoint, []);
 * //     $shopifyClient->setAuthToken($myShopifyAdminAccessToken, 'X-Shopify-Access-Token'); // Or set as a default header
 * //     $shopifyClient->setAuthType('shopify_token'); // Custom auth type for the library if needed for Shopify
 * // } catch (Exception $e) {
 * //     echo "Error initializing GraphQL client: " . $e->getMessage();
 * //     exit;
 * // }
 *
 * // Option B: Placeholder for a custom Guzzle-based client or other HTTP client wrapper
 * // (Ensure it has a ->query(string $query, ?array $variables) method that returns an associative array)
 * class MyPlaceholderShopifyClient {
 *     private string $endpoint;
 *     private array $headers;
 *     public function __construct(string $domain, string $token, string $version) {
 *         $this->endpoint = "https://{$domain}/admin/api/{$version}/graphql.json";
 *         $this->headers = [
 *             'Content-Type' => 'application/json',
 *             'X-Shopify-Access-Token' => $token,
 *         ];
 *         // In a real client, you'd initialize Guzzle or another HTTP client here.
 *     }
 *     public function query(string $graphqlQuery, ?array $variables = null): array {
 *         // This is a MOCK. Your actual client makes an HTTP POST request.
 *         // For testing, you'd need to simulate Shopify's response structure.
 *         // echo "MOCKING GraphQL Query: {$graphqlQuery}\n";
 *         // echo "MOCKING Variables: " . json_encode($variables) . "\n";
 *         // Example: Simulate a 'not found' or error for placeholder ID
 *         if (isset($variables['id']) && $variables['id'] === 'gid://shopify/Order/REPLACE_WITH_REAL_ORDER_ID') {
 *              return ['data' => ['order' => null], 'errors' => [['message' => 'Order not found (mock response).']]];
 *         }
 *         // Simulate some other generic data for other queries to avoid breaking tests.
 *         return ['data' => ['someMockData' => 'mocked value'], 'errors' => []];
 *     }
 * }
 * $shopifyClient = new MyPlaceholderShopifyClient($myShopifyStoreDomain, $myShopifyAdminAccessToken, $myShopifyApiVersion);
 * // END OF CONCEPTUAL CLIENT INITIALIZATION
 *
 * // 5. Example: Get order details
 * $orderIdToTest = 'gid://shopify/Order/REPLACE_WITH_REAL_ORDER_ID'; // !!! REPLACE THIS !!!
 *
 * if (isset($shopifyClient) && $orderIdToTest !== 'gid://shopify/Order/REPLACE_WITH_REAL_ORDER_ID') {
 *     echo "Attempting to fetch order: {$orderIdToTest}\n";
 *     $result = shopify_get_order_details($shopifyClient, $orderIdToTest);
 *
 *     if ($result['success']) {
 *         echo "Order details retrieved successfully: \n";
 *         print_r($result['data']);
 *     } else {
 *         echo "Failed to get order details: \n";
 *         print_r($result['error_messages']);
 *     }
 * } else {
 *     if ($orderIdToTest === 'gid://shopify/Order/REPLACE_WITH_REAL_ORDER_ID') {
 *         echo "Please replace 'gid://shopify/Order/REPLACE_WITH_REAL_ORDER_ID' with an actual Order GID from your Shopify Development Store to test.\n";
 *     }
 *     if (!isset($shopifyClient)) { // Check if $shopifyClient was successfully initialized
 *          echo "Shopify client not initialized. Check your client setup code.\n";
 *     }
 * }
 * ?>
 * ```
 */

// It's assumed the user will configure their GraphQL client elsewhere and pass it to these functions.
// No specific 'use' statements for SDKs are included here to maintain generic client compatibility.

/**
 * Creates a draft order in Shopify.
 *
 * @param object $shopifyClient Initialized GraphQL client object with a `query` or `request` method.
 * @param array $lineItems Array of line items. Each item can be:
 *                         - ['variantId' => 'gid://shopify/ProductVariant/123', 'quantity' => 2]
 *                         - ['title' => 'Custom Product', 'originalUnitPrice' => '20.00', 'quantity' => 1]
 * @param string|null $customerEmail Email of the customer.
 * @param string|null $note A note for the draft order.
 * @param array|null $shippingAddress Shipping address details (Shopify's `MailingAddressInput` structure).
 * @param array|null $billingAddress Billing address details (Shopify's `MailingAddressInput` structure).
 * @param array|null $customAttributes Custom attributes for the draft order (Array of `AttributeInput`).
 * @param array|null $tags Tags for the draft order.
 * @param string|null $currencyCode Currency code (e.g., "USD"). If null, store's default is used.
 *
 * @return array ['success' => bool, 'data' => array|null, 'error_messages' => array|null]
 *               - 'data': Might contain `['id' => draftOrderId, 'checkoutUrl' => checkoutUrl]` on success.
 *               - 'error_messages': Contains details from GraphQL `userErrors` or caught PHP exceptions.
 */
function shopify_create_draft_order(
    object $shopifyClient,
    array $lineItems,
    ?string $customerEmail = null,
    ?string $note = null,
    ?array $shippingAddress = null,
    ?array $billingAddress = null,
    ?array $customAttributes = null,
    ?array $tags = null,
    ?string $currencyCode = null
): array {
    $graphqlMutation = <<<'GRAPHQL'
    mutation draftOrderCreate($input: DraftOrderInput!) {
      draftOrderCreate(input: $input) {
        draftOrder {
          id
          checkoutUrl: invoiceUrl # invoiceUrl is often used as a checkout URL for draft orders
        }
        userErrors {
          field
          message
        }
      }
    }
    GRAPHQL;

    $input = ['lineItems' => []];
    foreach ($lineItems as $item) {
        $lineItemInput = ['quantity' => $item['quantity']];
        if (isset($item['variantId'])) {
            $lineItemInput['variantId'] = $item['variantId'];
        } elseif (isset($item['title']) && isset($item['originalUnitPrice'])) {
            $lineItemInput['title'] = $item['title'];
            $lineItemInput['originalUnitPrice'] = (string)$item['originalUnitPrice']; // Ensure string for Money
        } else {
            // Invalid line item structure
            return ['success' => false, 'data' => null, 'error_messages' => ['Invalid line item provided. Must contain variantId or title and originalUnitPrice.']];
        }
        $input['lineItems'][] = $lineItemInput;
    }

    if ($customerEmail !== null) {
        $input['email'] = $customerEmail;
    }
    if ($note !== null) {
        $input['note'] = $note;
    }
    if ($shippingAddress !== null) {
        $input['shippingAddress'] = $shippingAddress;
    }
    if ($billingAddress !== null) {
        $input['billingAddress'] = $billingAddress;
    }
    if ($customAttributes !== null) {
        $input['customAttributes'] = $customAttributes;
    }
    if ($tags !== null) {
        $input['tags'] = $tags;
    }
    if ($currencyCode !== null) {
        $input['currencyCode'] = $currencyCode;
    }

    try {
        $response = $shopifyClient->query($graphqlMutation, ['input' => $input]);

        $userErrors = $response['data']['draftOrderCreate']['userErrors'] ?? [];
        if (!empty($userErrors)) {
            $errorMessages = array_map(fn($e) => "Field: " . implode(", ", $e['field'] ?? ['N/A']) . " - Message: {$e['message']}", $userErrors);
            return ['success' => false, 'data' => null, 'error_messages' => $errorMessages];
        }
        
        // Check for top-level GraphQL errors if userErrors is empty but data might be missing
        if (isset($response['errors']) && !empty($response['errors'])) {
            $errorMessages = array_map(fn($e) => $e['message'] ?? 'Unknown GraphQL error', $response['errors']);
            return ['success' => false, 'data' => null, 'error_messages' => $errorMessages];
        }

        $draftOrder = $response['data']['draftOrderCreate']['draftOrder'] ?? null;
        if ($draftOrder && isset($draftOrder['id'])) {
            return ['success' => true, 'data' => ['id' => $draftOrder['id'], 'checkoutUrl' => $draftOrder['checkoutUrl'] ?? null], 'error_messages' => null];
        } else {
             // This case might indicate presence of top-level 'errors' in GraphQL response not caught by userErrors
            return ['success' => false, 'data' => null, 'error_messages' => ['Draft order ID not found in response, or other GraphQL error occurred.']];
        }
    } catch (Exception $e) {
        return ['success' => false, 'data' => null, 'error_messages' => ["Exception: " . $e->getMessage()]];
    }
}

/**
 * Completes a draft order, converting it into an actual order.
 * This typically marks the order as pending or paid based on parameters and may send an invoice to the customer.
 * Payment itself is usually handled separately (e.g., via an invoice link, manually, or a subsequent transaction).
 *
 * @param object $shopifyClient Initialized GraphQL client object with a `query` or `request` method.
 * @param string $draftOrderId The GID of the draft order (e.g., "gid://shopify/DraftOrder/12345").
 * @param bool $paymentPending Set to true if payment is handled manually or is expected later.
 *                             If false, it implies payment is considered captured or handled by the specified gateway.
 * @param string|null $paymentGatewayId Optional: The GID of the payment gateway to associate (relevant if not paymentPending).
 *
 * @return array ['success' => bool, 'data' => array|null, 'error_messages' => array|null]
 *               - 'data': Might contain `['orderId' => orderId, 'orderName' => orderName]` on success.
 *               - 'error_messages': Contains details from GraphQL `userErrors` or caught PHP exceptions.
 */
function shopify_complete_draft_order(
    object $shopifyClient,
    string $draftOrderId,
    bool $paymentPending = true,
    ?string $paymentGatewayId = null
): array {
    $graphqlMutation = <<<'GRAPHQL'
    mutation draftOrderComplete($id: ID!, $paymentPending: Boolean, $paymentGatewayId: ID) {
      draftOrderComplete(id: $id, paymentPending: $paymentPending, paymentGatewayId: $paymentGatewayId) {
        order {
          id
          name # Order name like #1001
        }
        userErrors {
          field
          message
        }
      }
    }
    GRAPHQL;

    $variables = [
        'id' => $draftOrderId,
        'paymentPending' => $paymentPending,
    ];
    if ($paymentGatewayId !== null) {
        $variables['paymentGatewayId'] = $paymentGatewayId;
    }

    try {
        $response = $shopifyClient->query($graphqlMutation, $variables);

        $userErrors = $response['data']['draftOrderComplete']['userErrors'] ?? [];
        if (!empty($userErrors)) {
            $errorMessages = array_map(fn($e) => "Field: " . implode(", ", $e['field'] ?? ['N/A']) . " - Message: {$e['message']}", $userErrors);
            return ['success' => false, 'data' => null, 'error_messages' => $errorMessages];
        }
        
        if (isset($response['errors']) && !empty($response['errors'])) {
            $errorMessages = array_map(fn($e) => $e['message'] ?? 'Unknown GraphQL error', $response['errors']);
            return ['success' => false, 'data' => null, 'error_messages' => $errorMessages];
        }

        $order = $response['data']['draftOrderComplete']['order'] ?? null;
        if ($order && isset($order['id'])) {
            return ['success' => true, 'data' => ['orderId' => $order['id'], 'orderName' => $order['name']], 'error_messages' => null];
        } else {
            return ['success' => false, 'data' => null, 'error_messages' => ['Order ID not found after completing draft order, or other GraphQL error occurred.']];
        }
    } catch (Exception $e) {
        return ['success' => false, 'data' => null, 'error_messages' => ["Exception: " . $e->getMessage()]];
    }
}

/**
 * Retrieves details of a specific order.
 *
 * @param object $shopifyClient Initialized GraphQL client object with a `query` or `request` method.
 * @param string $orderId The GID of the order (e.g., "gid://shopify/Order/12345").
 *
 * @return array ['success' => bool, 'data' => array|null, 'error_messages' => array|null]
 *               - 'data': Will contain order details on success.
 *               - 'error_messages': Contains details from GraphQL `errors` (top-level) or caught PHP exceptions.
 */
function shopify_get_order_details(object $shopifyClient, string $orderId): array
{
    $graphqlQuery = <<<'GRAPHQL'
    query order($id: ID!) {
      order(id: $id) {
        id
        name
        displayFinancialStatus
        displayFulfillmentStatus
        checkoutUrl
        transactions(first: 10) {
          edges {
            node {
              id
              kind
              status
              amount {
                amount
                currencyCode
              }
              gateway
            }
          }
        }
      }
    }
    GRAPHQL;

    try {
        $response = $shopifyClient->query($graphqlQuery, ['id' => $orderId]);

        if (isset($response['errors']) && !empty($response['errors'])) {
            $errorMessages = array_map(fn($e) => $e['message'] ?? 'Unknown GraphQL error', $response['errors']);
            return ['success' => false, 'data' => null, 'error_messages' => $errorMessages];
        }
        
        $order = $response['data']['order'] ?? null;
        if ($order && isset($order['id'])) {
            if (isset($order['transactions']['edges'])) {
                $order['transactions'] = array_map(fn($edge) => $edge['node'], $order['transactions']['edges']);
            }
            return ['success' => true, 'data' => $order, 'error_messages' => null];
        } else {
            // This also handles cases where order(id: $id) returns null, which means not found.
            return ['success' => false, 'data' => null, 'error_messages' => ['Order not found or ID missing in response.']];
        }
    } catch (Exception $e) {
        return ['success' => false, 'data' => null, 'error_messages' => ["Exception: " . $e->getMessage()]];
    }
}

/**
 * Creates a manual payment transaction for an existing order using the `orderCreateManualPayment` mutation.
 * This function is intended for recording payments that have been processed externally or manually
 * (e.g., bank transfer, cash, external POS). It does not initiate a new charge against a payment gateway.
 *
 * @param object $shopifyClient Initialized GraphQL client object with a `query` or `request` method.
 * @param string $orderId The GID of the order (e.g., "gid://shopify/Order/12345").
 * @param string $amount The amount of the payment (e.g., "10.99").
 * @param string $currencyCode The currency code (e.g., "USD").
 * @param string $paymentMethodName The name of the payment method (e.g., "Manual Bank Transfer", "External POS").
 *
 * @return array ['success' => bool, 'data' => array|null, 'error_messages' => array|null]
 *               - 'data': Might contain `['paymentId' => paymentId, 'orderId' => orderId]` on success, subject to actual API response.
 *               - 'error_messages': Contains details from GraphQL `userErrors` or caught PHP exceptions.
 */
function shopify_create_order_transaction(
    object $shopifyClient,
    string $orderId,
    string $amount,
    string $currencyCode,
    string $paymentMethodName
): array {
    $graphqlMutation = <<<'GRAPHQL'
    mutation orderCreateManualPayment($orderId: ID!, $payment: OrderPaymentInput!) {
      orderCreateManualPayment(orderId: $orderId, payment: $payment) {
        order {
            id
        }
        payment {
            id
        }
        userErrors {
          field
          message
        }
      }
    }
    GRAPHQL;

    $paymentInput = [
        'amount' => [
            'amount' => (string)$amount,
            'currencyCode' => $currencyCode,
        ],
        'paymentMethodName' => $paymentMethodName,
    ];

    $variables = [
        'orderId' => $orderId,
        'payment' => $paymentInput,
    ];

    try {
        $response = $shopifyClient->query($graphqlMutation, $variables);

        $userErrors = $response['data']['orderCreateManualPayment']['userErrors'] ?? [];
        if (!empty($userErrors)) {
            $errorMessages = array_map(fn($e) => "Field: " . implode(", ", $e['field'] ?? ['N/A']) . " - Message: {$e['message']}", $userErrors);
            return ['success' => false, 'data' => null, 'error_messages' => $errorMessages];
        }

        if (isset($response['errors']) && !empty($response['errors'])) {
            $errorMessages = array_map(fn($e) => $e['message'] ?? 'Unknown GraphQL error', $response['errors']);
            return ['success' => false, 'data' => null, 'error_messages' => $errorMessages];
        }

        $paymentData = $response['data']['orderCreateManualPayment']['payment'] ?? null;
        $orderData = $response['data']['orderCreateManualPayment']['order'] ?? null;

        if ($paymentData && isset($paymentData['id'])) {
            return ['success' => true, 'data' => ['paymentId' => $paymentData['id'], 'orderId' => $orderData['id'] ?? $orderId], 'error_messages' => null];
        } elseif ($orderData && isset($orderData['id'])) {
             return ['success' => true, 'data' => ['orderId' => $orderData['id']], 'error_messages' => null];
        } else {
            return ['success' => false, 'data' => null, 'error_messages' => ['Payment/Transaction ID not found in response, or other GraphQL error occurred.']];
        }
    } catch (Exception $e) {
        return ['success' => false, 'data' => null, 'error_messages' => ["Exception: " . $e->getMessage()]];
    }
}


/**
 * Creates a refund for an order.
 *
 * @param object $shopifyClient Initialized GraphQL client object with a `query` or `request` method.
 * @param string $orderId The GID of the order to refund (e.g., "gid://shopify/Order/12345").
 * @param array $refundLineItems Array of line items to refund. Each item:
 *                               ['lineItemId' => 'gid://shopify/LineItem/67890', 'quantity' => 1, 'restockType' => 'NO_RESTOCK'|'CANCEL'|'RETURN']
 * @param string|null $note Optional note for the refund.
 * @param bool $notifyCustomer Whether to notify the customer. Defaults to false.
 * @param string|null $shippingAmount Optional amount for shipping refund (e.g., "5.00"). This is part of the `shipping` input object.
 * @param string|null $currencyCode Currency code for shipping amount (e.g., "USD"). Required if `shippingAmount` is set.
 *
 * @return array ['success' => bool, 'data' => array|null, 'error_messages' => array|null]
 *               - 'data': Might contain `['refundId' => refundId]` on success.
 *               - 'error_messages': Contains details from GraphQL `userErrors` or caught PHP exceptions.
 */
function shopify_create_refund(
    object $shopifyClient,
    string $orderId,
    array $refundLineItems,
    ?string $note = null,
    bool $notifyCustomer = false,
    ?string $shippingAmount = null,
    ?string $currencyCode = null
): array {
    $graphqlMutation = <<<'GRAPHQL'
    mutation refundCreate($input: RefundInput!) {
      refundCreate(input: $input) {
        refund {
          id
        }
        userErrors {
          field
          message
        }
      }
    }
    GRAPHQL;

    $input = [
        'orderId' => $orderId,
        'refundLineItems' => [],
        'notify' => $notifyCustomer,
    ];

    if ($note !== null) {
        $input['note'] = $note;
    }

    foreach ($refundLineItems as $item) {
        if (!isset($item['lineItemId']) || !isset($item['quantity']) || !isset($item['restockType'])) {
            return ['success' => false, 'data' => null, 'error_messages' => ["Invalid refund line item. Must contain 'lineItemId', 'quantity', and 'restockType'."]];
        }
        $input['refundLineItems'][] = [
            'lineItemId' => $item['lineItemId'],
            'quantity' => $item['quantity'],
            'restockType' => $item['restockType'],
        ];
    }
    
    if ($shippingAmount !== null) {
        if ($currencyCode === null) {
             return ['success' => false, 'data' => null, 'error_messages' => ["Currency code is required if shipping amount is provided for refund."]];
        }
        // Assuming `RefundInput.shipping` is of type `MoneyInput` or similar structure
        $input['shipping'] = [
             'amount' => (string)$shippingAmount,
             'currencyCode' => $currencyCode
        ];
    }

    try {
        $response = $shopifyClient->query($graphqlMutation, ['input' => $input]);

        $userErrors = $response['data']['refundCreate']['userErrors'] ?? [];
        if (!empty($userErrors)) {
            $errorMessages = array_map(fn($e) => "Field: " . implode(", ", $e['field'] ?? ['N/A']) . " - Message: {$e['message']}", $userErrors);
            return ['success' => false, 'data' => null, 'error_messages' => $errorMessages];
        }

        if (isset($response['errors']) && !empty($response['errors'])) {
            $errorMessages = array_map(fn($e) => $e['message'] ?? 'Unknown GraphQL error', $response['errors']);
            return ['success' => false, 'data' => null, 'error_messages' => $errorMessages];
        }

        $refund = $response['data']['refundCreate']['refund'] ?? null;
        if ($refund && isset($refund['id'])) {
            return ['success' => true, 'data' => ['refundId' => $refund['id']], 'error_messages' => null];
        } else {
            return ['success' => false, 'data' => null, 'error_messages' => ['Refund ID not found in response, or other GraphQL error occurred.']];
        }
    } catch (Exception $e) {
        return ['success' => false, 'data' => null, 'error_messages' => ["Exception: " . $e->getMessage()]];
    }
}

?>
