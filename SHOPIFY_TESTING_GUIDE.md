# Shopify PHP Integration Testing Guide (GraphQL)

This guide provides conceptual steps and scenarios for testing the PHP functions in `shopify_integration.php`. These functions interact with the Shopify Admin API using GraphQL. Thorough testing in a Shopify Development Store is crucial before using any integration in a live environment.

## 1. Prerequisites

Before you begin testing, ensure you have the following:

*   **Shopify Development Environment:**
    *   A Shopify Partner Account.
    *   A Development Store created through your Partner Account.
    *   A Custom App created within your Development Store, with the necessary Admin API access scopes configured (e.g., for orders, draft orders, products, customers).
    *   Your **Admin API Access Token** from this Custom App.
    *   Your Development Store's **myshopify.com domain** (e.g., `your-dev-store.myshopify.com`).
    *   The **Shopify API Version** you are targeting (e.g., `2023-10`, `2024-01`).
*   **PHP GraphQL Client/Library:**
    *   A PHP GraphQL client library installed via Composer (e.g., `gmostafa/php-graphql-client`, or another of your choice).
    *   Alternatively, if you are making direct HTTP calls, your setup for this (e.g., using GuzzleHttp).
*   **PHP Environment:** PHP 8.0+ is recommended, with the `curl` and `json` extensions enabled.
*   **`shopify_integration.php` file:** The PHP file containing the Shopify integration wrapper functions.
*   **Familiarity with GIDs:** Shopify uses GraphQL Global IDs (GIDs) for most resources (e.g., `gid://shopify/ProductVariant/1234567890`). You will need valid GIDs from your development store for testing (e.g., for product variants, existing orders, line items).

## 2. Setup for Testing

### 2.1. Create a Test PHP Script

Create a new PHP file (e.g., `test_shopify.php`) in your project directory. This script will:

1.  Include Composer's autoloader (if you're using a Composer-installed GraphQL client).
2.  Include your `shopify_integration.php` file.
3.  Initialize your chosen PHP GraphQL client.
4.  Contain the test calls to your wrapper functions.

### 2.2. Initialize Your GraphQL Client

Below is a conceptual example of how you might initialize a generic GraphQL client. **You MUST adapt this to the specific PHP GraphQL client library you are using.**

```php
<?php

// Include Composer's autoloader (adjust path if necessary)
// This is typically needed if your GraphQL client is installed via Composer.
require_once __DIR__ . '/vendor/autoload.php';

// Include your Shopify integration wrapper functions
require_once __DIR__ . '/shopify_integration.php';

// --- Your Test Configuration ---
// !!! IMPORTANT: Store your Admin API Access Token securely.
// !!! For production, use environment variables or other secure methods.
$shopifyStoreDomain = 'your-dev-store.myshopify.com'; // Replace with your dev store's .myshopify.com domain
$shopifyAdminAccessToken = 'YOUR_SHOPIFY_ADMIN_API_ACCESS_TOKEN'; // Replace
$shopifyApiVersion = '2024-01'; // Replace with your target API version

// --- Initialize Your Chosen GraphQL Client ---
// This is a CONCEPTUAL example. Refer to your GraphQL client's documentation.
// For example, if using a client like 'gmostafa/php-graphql-client':
/*
use GraphQL\Client;
use GraphQL\Exception\QueryError;

$endpoint = "https://{$shopifyStoreDomain}/admin/api/{$shopifyApiVersion}/graphql.json";
$shopifyClient = null;
try {
    $shopifyClient = new Client(
        $endpoint,
        [], // No specific options for this client constructor
        [ // HTTP Headers
            'Content-Type' => 'application/json',
            'X-Shopify-Access-Token' => $shopifyAdminAccessToken,
        ]
    );
    echo "Shopify GraphQL Client initialized successfully for {$shopifyStoreDomain}.\n\n";
} catch (Exception $e) {
    echo "Failed to initialize Shopify GraphQL Client: " . $e->getMessage() . "\n";
    exit(1);
}
*/

// If using another client (e.g., a generic HTTP client like Guzzle),
// your $shopifyClient might be an adapter object you've written that has a ->query() method.
// For this guide, we'll assume $shopifyClient is an object with a method:
// $response = $shopifyClient->query(string $graphqlQuery, ?array $variables = null);
// where $response is an associative array decoded from Shopify's JSON response.

// Placeholder for a generic client object for the sake of example structure
// Replace this with your actual client initialization.
class GenericShopifyClient {
    private $endpoint;
    private $accessToken;
    public function __construct($domain, $token, $version) {
        $this->endpoint = "https://{$domain}/admin/api/{$version}/graphql.json";
        $this->accessToken = $token;
        echo "GenericShopifyClient initialized (Placeholder - Replace with your actual client).\n";
    }
    public function query(string $query, ?array $variables = null): array {
        // This is a MOCK. Your actual client will make an HTTP POST request.
        echo "MOCK QUERY: " . $query . "\n";
        if ($variables) echo "MOCK VARIABLES: " . json_encode($variables) . "\n";
        // Simulate a successful response structure for a 'shop name' query
        // In a real scenario, this would involve an HTTP call and JSON decoding.
        // return ['data' => ['shop' => ['name' => 'Test Store From Mock Client']]];
        // For testing wrappers, you might want to return mock structures based on the query.
        // For now, let's return an empty success to prevent errors in example calls.
        return ['data' => ['mock' => true], 'errors' => []]; // Placeholder
    }
}
$shopifyClient = new GenericShopifyClient($shopifyStoreDomain, $shopifyAdminAccessToken, $shopifyApiVersion);
// END OF PLACEHOLDER - REPLACE WITH YOUR ACTUAL CLIENT

echo "<pre>"; // For cleaner output in a browser

// --- Test Scenarios Below ---

// Example: Get some product variant GIDs from your Development Store's Admin
// (Products -> select a product -> Variants -> Edit variant -> URL in browser bar has GID)
// $testVariantId = 'gid://shopify/ProductVariant/YOUR_TEST_VARIANT_ID'; // Replace!

// --- shopify_create_draft_order Tests ---
// if ($testVariantId !== 'gid://shopify/ProductVariant/YOUR_TEST_VARIANT_ID') {
//     $lineItems = [
//         ['variantId' => $testVariantId, 'quantity' => 1]
//     ];
//     $draftOrderResult = shopify_create_draft_order($shopifyClient, $lineItems, 'test@example.com', 'Test draft order note');
//     print_r($draftOrderResult);
//     if ($draftOrderResult['success'] && !empty($draftOrderResult['data']['id'])) {
//         $createdDraftOrderId = $draftOrderResult['data']['id'];
//         echo "Draft Order created successfully. ID: " . $createdDraftOrderId . "\n";
//     } else {
//         echo "Draft Order creation failed. Errors: \n";
//         print_r($draftOrderResult['error_messages']);
//     }
// } else {
//     echo "Please set a valid \$testVariantId to run draft order creation tests.\n";
// }

// --- Add other test scenarios for each function as outlined in the guide ---

echo "</pre>";

?>
```

**Important:**
*   Replace placeholder values for `$shopifyStoreDomain`, `$shopifyAdminAccessToken`, `$shopifyApiVersion`, and any GIDs like `$testVariantId`.
*   The client initialization part is highly conceptual. **Follow the documentation for your chosen PHP GraphQL client library.** The key is that the `$shopifyClient` object passed to the wrapper functions should have a method that executes the GraphQL query and returns an associative array representing the JSON response.

## 3. General Testing Approach

For each wrapper function and scenario:

1.  **Prepare Data:**
    *   Set up the necessary input parameters for the function call (e.g., line items, GIDs for orders/variants, customer details).
    *   You'll need valid GIDs from your development store. For example, to test `shopify_get_order_details`, you first need to create an order and get its GID.
    *   Use both valid and intentionally invalid data to test error handling.
2.  **Call the Wrapper Function:** Execute the function from your `test_shopify.php` script, passing the initialized `$shopifyClient` and other parameters.
3.  **Inspect the Return Array:** The wrapper functions return `['success' => bool, 'data' => array|null, 'error_messages' => array|null]`.
    *   Check the `'success'` boolean.
    *   If `'success'` is `false`, examine the `'error_messages'` array. This array will contain messages from GraphQL `userErrors` (business logic errors) or top-level `errors` (syntax or structural issues in the query/mutation itself), or messages from caught PHP exceptions.
    *   If `'success'` is `true`, inspect the `'data'` field, which will contain the relevant data extracted from the Shopify API response (e.g., `['id' => draftOrderId, ...]` for `shopify_create_draft_order`).
4.  **Verify in Shopify Admin:**
    *   Log in to your Development Store's Shopify Admin panel.
    *   Navigate to the relevant sections (e.g., "Draft Orders", "Orders", "Customers", "Products") to verify that the API calls had the intended effect (e.g., a draft order was created, an order was marked as paid, a refund was recorded).

## 4. Specific Test Scenarios

### 4.1. `shopify_create_draft_order`

*   **Successful Creation:**
    *   With a product variant GID: `[['variantId' => 'gid://shopify/ProductVariant/YOUR_VARIANT_ID', 'quantity' => 1]]`
    *   With a custom item: `[['title' => 'Custom Service Fee', 'originalUnitPrice' => '50.00', 'quantity' => 1]]`
    *   With customer email, note, shipping/billing addresses, custom attributes, tags.
    *   With a specific `currencyCode`.
    *   *Expected:* `success` is true, `data` contains `id` (draft order GID) and `checkoutUrl`. Verify in Shopify Admin under "Draft Orders".
*   **Error Cases:**
    *   Invalid product variant GID.
    *   Missing `title` or `originalUnitPrice` for custom items.
    *   Invalid `currencyCode`.
    *   Invalid address structure (if your client doesn't validate it first).
    *   *Expected:* `success` is false, `error_messages` detail the issue (often from `userErrors`).

### 4.2. `shopify_complete_draft_order`

*   **Successful Completion:**
    *   Use a `draftOrderId` from a successful `shopify_create_draft_order` call.
    *   Test with `paymentPending` as `true`.
    *   Test with `paymentPending` as `false` (if you have a way to simulate payment or if it's for an order with $0 total).
    *   *Expected:* `success` is true, `data` contains `orderId` (the GID of the newly created order) and `orderName`. Verify the order appears in "Orders" in Shopify Admin, and the draft order is removed/marked completed.
*   **Error Cases:**
    *   Invalid `draftOrderId`.
    *   Attempting to complete an already completed draft order.
    *   *Expected:* `success` is false, `error_messages` detail the issue.

### 4.3. `shopify_get_order_details`

*   **Retrieve Known Order:**
    *   Use an `orderId` from a successful `shopify_complete_draft_order` call.
    *   *Expected:* `success` is true, `data` contains order details (ID, name, financial status, fulfillment status, transactions).
*   **Retrieve Non-Existent Order:**
    *   Use a fake or invalid `orderId`.
    *   *Expected:* `success` is false, `error_messages` indicate "Order not found" or similar (Shopify might return `null` for the `order` field in `data`, which the wrapper should interpret as failure).

### 4.4. `shopify_create_order_transaction`

*Note: This records an external/manual payment. It does not process a new payment via a gateway.*
*   **Record Manual Payment:**
    *   For an existing order (from `shopify_complete_draft_order`) that is unpaid or partially paid.
    *   Provide valid `orderId`, `amount`, `currencyCode`, and `paymentMethodName` (e.g., "Bank Deposit", "Cash").
    *   *Expected:* `success` is true. `data` might contain a payment ID or transaction ID. Verify in Shopify Admin that the order's financial status is updated (e.g., to 'paid' or 'partially_paid') and a transaction is recorded.
*   **Error Cases:**
    *   Order is already fully paid.
    *   Invalid `orderId`.
    *   Invalid `amount` (e.g., negative, or exceeding amount due if not allowed by API).
    *   *Expected:* `success` is false, `error_messages` detail the issue.

### 4.5. `shopify_create_refund`

*Note: Requires an order with a successful payment transaction (e.g., one marked as paid, potentially via `shopify_create_order_transaction` or by completing a draft order with `paymentPending: false` if the total was $0 or manually paid via invoice link).*
*   **Refund Specific Line Items:**
    *   Use a valid `orderId` and `lineItemId`(s) from that order.
    *   Specify quantities and a valid `restockType` (e.g., `NO_RESTOCK`, `RETURN`).
    *   Test with and without a `note`.
    *   Test with `notifyCustomer` as `true` and `false`.
    *   *Expected:* `success` is true, `data` contains `refundId`. Verify in Shopify Admin that the refund is recorded against the order, and inventory (if restocked) is updated.
*   **Refund Shipping Amount:**
    *   Provide `shippingAmount` and `currencyCode` along with `orderId`.
    *   *Expected:* `success` is true. Verify shipping cost is refunded.
*   **Error Cases:**
    *   Attempting to refund more than the available amount for a line item or order.
    *   Invalid `lineItemId` or `orderId`.
    *   Order not in a refundable state (e.g., no payments made).
    *   *Expected:* `success` is false, `error_messages` detail the issue.

## 5. Important Notes

*   **DEVELOPMENT STORE ONLY:** All tests described here MUST be performed in your Shopify Development Store.
*   **TEST DATA:** Use only test data (e.g., test customer details, dummy product information). Do not use real customer or payment information.
*   **SHOPIFY GRAPHQL ADMIN API DOCUMENTATION:** The [Shopify GraphQL Admin API documentation](https://shopify.dev/api/admin-graphql) is your primary reference for:
    *   Available mutations and queries.
    *   Required fields and input object structures (e.g., `DraftOrderInput`, `RefundInput`).
    *   Possible `userErrors` and their meanings.
    *   Access scopes required for each operation.
    *   API rate limits and best practices.
    Always cross-reference with the official documentation for the most accurate and up-to-date information.

By following these steps and scenarios, you can thoroughly test your Shopify PHP integration functions. Remember to adapt and expand these tests based on your specific application requirements.
