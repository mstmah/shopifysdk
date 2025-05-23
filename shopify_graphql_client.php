<?php

declare(strict_types=1);

/**
 * Shopify GraphQL Admin API Client Library for PHP
 *
 * This file provides a set of functions to interact with the Shopify GraphQL Admin API,
 * simplifying common operations such as managing products, orders, customers, collections,
 * and inventory.
 *
 * PHP Version: 8.4+
 *
 * Usage:
 * require_once 'shopify_graphql_client.php';
 *
 * // Initialize your Shopify store details
 * // $shopifyStoreUrl = 'your-store-name.myshopify.com';
 * // $shopifyAccessToken = 'your-admin-api-access-token'; // Keep this secure!
 * // $apiVersion = '2024-04'; // Or your target API version
 *
 * // Example: Fetch a product
 * // $productId = 'gid://shopify/Product/1234567890123';
 * // $result = getProduct($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $productId);
 * // if ($result['status'] === 'success') {
 * //   print_r($result['data']['product']);
 * // } else {
 * //   echo "Error: " . $result['message'];
 * // }
 *
 * IMPORTANT: Securely manage your Shopify Access Token. Do not hardcode it directly in
 * production code. Consider environment variables or a secure configuration management system.
 */

/**
 * Sends a GraphQL request to the Shopify Admin API.
 * This is the core function used by all other specific operation functions.
 *
 * @param string $shopifyUrl The base URL of the Shopify store (e.g., 'your-store.myshopify.com').
 * @param string $accessToken The Shopify Admin API access token.
 * @param string $apiVersion The Shopify API version (e.g., '2024-04').
 * @param string $query The GraphQL query string.
 * @param array<string, mixed> $variables An optional associative array of variables for the GraphQL query.
 * @return array<string, mixed> An associative array with:
 *                             - 'status': 'success' or 'error'.
 *                             - 'data': The GraphQL data response if successful.
 *                             - 'message': Error message if an error occurred.
 *                             - 'details': Additional error details (e.g., cURL error, API error response) if available.
 * @example
 * // $query = 'query { shop { name } }';
 * // $result = sendShopifyGraphQLRequest($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $query);
 * // if ($result['status'] === 'success') {
 * //   // echo "Shop Name: " . $result['data']['shop']['name'];
 * // } else {
 * //   // echo "GraphQL Request Failed: " . $result['message'];
 * //   // if (isset($result['details'])) print_r($result['details']);
 * // }
 */
function sendShopifyGraphQLRequest(
    string $shopifyUrl,
    string $accessToken,
    string $apiVersion,
    string $query,
    array $variables = []
): array {
    $endpoint = "https://{$shopifyUrl}/admin/api/{$apiVersion}/graphql.json";

    $headers = [
        'Content-Type: application/json',
        "X-Shopify-Access-Token: {$accessToken}",
    ];

    $payload = json_encode(['query' => $query, 'variables' => $variables]);

    if ($payload === false) {
        return [
            'status' => 'error',
            'message' => 'Failed to encode JSON payload.',
            'details' => json_last_error_msg()
        ];
    }

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // 10 seconds connection timeout
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // 30 seconds execution timeout

    $response = curl_exec($ch);
    $curlErrorNo = curl_errno($ch);
    $curlError = curl_error($ch);
    $httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($curlErrorNo) {
        return [
            'status' => 'error',
            'message' => 'cURL error occurred.',
            'details' => "Error Code: {$curlErrorNo}, Message: {$curlError}"
        ];
    }

    if ($httpStatusCode !== 200) {
        return [
            'status' => 'error',
            'message' => "Shopify API request failed with HTTP status code {$httpStatusCode}.",
            'details' => $response // Shopify often returns JSON error details even for non-200 responses
        ];
    }

    $responseData = json_decode((string)$response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'status' => 'error',
            'message' => 'Failed to decode Shopify API JSON response.',
            'details' => json_last_error_msg()
        ];
    }

    if (isset($responseData['errors']) && !empty($responseData['errors'])) {
        return [
            'status' => 'error',
            'message' => 'GraphQL query returned errors.',
            'details' => $responseData['errors']
        ];
    }

    if (!isset($responseData['data'])) {
        return [
            'status' => 'error',
            'message' => 'GraphQL response did not contain data and no errors were reported.',
            'details' => $responseData
        ];
    }

    return [
        'status' => 'success',
        'data' => $responseData['data']
    ];
}

/**
 * Converts an array of field definitions into a GraphQL query string.
 * This is an internal helper function and not typically called directly by client code.
 *
 * @param array<string|array<mixed>> $fields The fields to include in the query.
 *        Simple string elements are taken as field names (e.g., 'id', 'title').
 *        Nested arrays are expected to have a single key-value pair,
 *        where the key is the field name and the value is the sub-selection string
 *        (e.g., `['variants(first:1)' => 'edges { node { id title } }']`).
 * @return string The formatted fields string (e.g., "id title variants(first:1) { edges { node { id title } } }").
 */
function formatGqlFieldsForQuery(array $fields): string
{
    $formattedFields = [];
    foreach ($fields as $field) {
        if (is_string($field)) {
            $formattedFields[] = $field;
        } elseif (is_array($field) && count($field) === 1) {
            $key = array_key_first($field);
            $value = $field[$key];
            if (is_string($key) && is_string($value)) {
                $formattedFields[] = "{$key} { {$value} }";
            }
        }
    }
    return implode(' ', $formattedFields);
}

/**
 * Fetches specified fields of a product by its GID.
 *
 * @param string $shopifyUrl The Shopify store URL (e.g., 'your-store.myshopify.com').
 * @param string $accessToken The Admin API access token.
 * @param string $apiVersion The API version (e.g., '2024-04').
 * @param string $productId The GID of the product to fetch (e.g., "gid://shopify/Product/1234567890123").
 * @param array<string|array<mixed>> $fields The list of fields to retrieve. Default fields are provided.
 *        Supports simple field names as strings (e.g., 'id', 'title').
 *        Supports pre-formatted nested field strings (e.g., 'variants(first:5) { edges { node { id title price } } }').
 *        Supports simple nested structures via arrays e.g. `['metafields(first:10)' => 'edges { node { id namespace key value } }']`
 * @return array<string, mixed> Associative array with 'status' and 'data' (containing product details) or 'message'/'details' on error.
 *
 * @example
 * // $productId = 'gid://shopify/Product/1234567890123';
 * // $fields = ['id', 'title', 'status', 'totalInventory'];
 * // $result = getProduct($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $productId, $fields);
 * // if ($result['status'] === 'success') {
 * //   // print_r($result['data']['product']);
 * //   // echo "Product Title: " . $result['data']['product']['title'];
 * // } else {
 * //   // echo "Error fetching product: " . $result['message'];
 * //   // if (isset($result['details'])) print_r($result['details']);
 * // }
 */
function getProduct(
    string $shopifyUrl,
    string $accessToken,
    string $apiVersion,
    string $productId,
    array $fields = ['id', 'title', 'handle', 'descriptionHtml', 'createdAt', 'updatedAt', 'vendor', 'productType', 'status', 'tags', 'totalInventory']
): array {
    $fieldsString = formatGqlFieldsForQuery($fields);
    if (empty($fieldsString)) {
        $fieldsString = 'id title handle';
    }

    $query = <<<GRAPHQL
    query getProduct(\$id: ID!) {
      product(id: \$id) {
        {$fieldsString}
      }
    }
    GRAPHQL;

    $variables = ['id' => $productId];
    return sendShopifyGraphQLRequest($shopifyUrl, $accessToken, $apiVersion, $query, $variables);
}

/**
 * Creates a new product.
 *
 * @param string $shopifyUrl The Shopify store URL.
 * @param string $accessToken The Admin API access token.
 * @param string $apiVersion The API version.
 * @param array<string, mixed> $productInput The input data for creating the product, conforming to Shopify's ProductInput type.
 *        Refer to Shopify ProductInput documentation for all possible fields.
 * @return array<string, mixed> Associative array with 'status' and 'data' (containing created product's id, title, handle, createdAt) or 'message'/'details' on error.
 *
 * @example
 * // $productInput = [
 * //   'title' => 'Awesome New T-Shirt',
 * //   'bodyHtml' => '<h1>Amazing T-Shirt</h1><p>This is the best t-shirt ever.</p>',
 * //   'vendor' => 'MyBrand',
 * //   'productType' => 'Apparel',
 * //   'tags' => ['new', 't-shirt', 'summer'],
 * //   'status' => 'ACTIVE' // DRAFT, ACTIVE, ARCHIVED
 * // ];
 * // $result = createProduct($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $productInput);
 * // if ($result['status'] === 'success' && isset($result['data']['productCreate']['product'])) {
 * //   // echo "Created Product ID: " . $result['data']['productCreate']['product']['id'];
 * //   // print_r($result['data']['productCreate']['product']);
 * // } elseif (isset($result['data']['productCreate']['userErrors']) && count($result['data']['productCreate']['userErrors']) > 0) {
 * //   // echo "Product creation failed with user errors:";
 * //   // print_r($result['data']['productCreate']['userErrors']);
 * // } else {
 * //   // echo "Error creating product: " . $result['message'];
 * //   // if (isset($result['details'])) print_r($result['details']);
 * // }
 */
function createProduct(
    string $shopifyUrl,
    string $accessToken,
    string $apiVersion,
    array $productInput
): array {
    $query = <<<GRAPHQL
    mutation productCreate(\$input: ProductInput!) {
      productCreate(input: \$input) {
        product {
          id
          title
          handle
          createdAt
        }
        userErrors {
          field
          message
        }
      }
    }
    GRAPHQL;

    $variables = ['input' => $productInput];
    return sendShopifyGraphQLRequest($shopifyUrl, $accessToken, $apiVersion, $query, $variables);
}

/**
 * Updates an existing product.
 *
 * @param string $shopifyUrl The Shopify store URL.
 * @param string $accessToken The Admin API access token.
 * @param string $apiVersion The API version.
 * @param string $productId The GID of the product to update (e.g., "gid://shopify/Product/1234567890123").
 * @param array<string, mixed> $productInput The input data for updating the product. Must include 'id' => $productId.
 *        Refer to Shopify ProductInput documentation for updatable fields.
 * @return array<string, mixed> Associative array with 'status' and 'data' (containing updated product's id, title, handle, updatedAt) or 'message'/'details' on error.
 *
 * @example
 * // $productIdToUpdate = 'gid://shopify/Product/1234567890123';
 * // $updateInput = [
 * //   'id' => $productIdToUpdate,
 * //   'title' => 'Even More Awesome T-Shirt - Updated Title',
 * //   'tags' => ['updated', 'sale']
 * // ];
 * // $result = updateProduct($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $productIdToUpdate, $updateInput);
 * // if ($result['status'] === 'success' && isset($result['data']['productUpdate']['product'])) {
 * //   // echo "Updated Product ID: " . $result['data']['productUpdate']['product']['id'];
 * //   // print_r($result['data']['productUpdate']['product']);
 * // } elseif (isset($result['data']['productUpdate']['userErrors']) && count($result['data']['productUpdate']['userErrors']) > 0) {
 * //   // echo "Product update failed with user errors:";
 * //   // print_r($result['data']['productUpdate']['userErrors']);
 * // } else {
 * //   // echo "Error updating product: " . $result['message'];
 * //   // if (isset($result['details'])) print_r($result['details']);
 * // }
 */
function updateProduct(
    string $shopifyUrl,
    string $accessToken,
    string $apiVersion,
    string $productId,
    array $productInput
): array {
    if (!isset($productInput['id'])) {
        $productInput['id'] = $productId;
    } elseif ($productInput['id'] !== $productId) {
        // It's crucial that $productInput['id'] matches $productId for clarity and correctness.
        // Consider throwing an error or logging a warning if they mismatch.
        // For this implementation, we ensure 'id' is set, prioritizing $productInput['id'] if it exists.
    }

    $query = <<<GRAPHQL
    mutation productUpdate(\$input: ProductInput!) {
      productUpdate(input: \$input) {
        product {
          id
          title
          handle
          updatedAt
        }
        userErrors {
          field
          message
        }
      }
    }
    GRAPHQL;

    $variables = ['input' => $productInput];
    return sendShopifyGraphQLRequest($shopifyUrl, $accessToken, $apiVersion, $query, $variables);
}

/**
 * Deletes a product.
 *
 * @param string $shopifyUrl The Shopify store URL.
 * @param string $accessToken The Admin API access token.
 * @param string $apiVersion The API version.
 * @param string $productId The GID of the product to delete (e.g., "gid://shopify/Product/1234567890123").
 * @return array<string, mixed> Associative array with 'status' and 'data' (containing deletedProductId) or 'message'/'details' on error.
 *
 * @example
 * // $productIdToDelete = 'gid://shopify/Product/1234567890123';
 * // $result = deleteProduct($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $productIdToDelete);
 * // if ($result['status'] === 'success' && isset($result['data']['productDelete']['deletedProductId'])) {
 * //   // echo "Deleted Product ID: " . $result['data']['productDelete']['deletedProductId'];
 * // } elseif (isset($result['data']['productDelete']['userErrors']) && count($result['data']['productDelete']['userErrors']) > 0) {
 * //   // echo "Product deletion failed with user errors:";
 * //   // print_r($result['data']['productDelete']['userErrors']);
 * // } else {
 * //   // echo "Error deleting product: " . $result['message'];
 * //   // if (isset($result['details'])) print_r($result['details']);
 * // }
 */
function deleteProduct(
    string $shopifyUrl,
    string $accessToken,
    string $apiVersion,
    string $productId
): array {
    $query = <<<GRAPHQL
    mutation productDelete(\$input: ProductDeleteInput!) {
      productDelete(input: \$input) {
        deletedProductId
        userErrors {
          field
          message
        }
      }
    }
    GRAPHQL;

    $variables = ['input' => ['id' => $productId]];
    return sendShopifyGraphQLRequest($shopifyUrl, $accessToken, $apiVersion, $query, $variables);
}

/**
 * Fetches specified fields of an order by its GID.
 *
 * @param string $shopifyUrl The Shopify store URL.
 * @param string $accessToken The Admin API access token.
 * @param string $apiVersion The API version.
 * @param string $orderId The GID of the order to fetch (e.g., "gid://shopify/Order/1234567890123").
 * @param array<string|array<mixed>> $fields The list of fields to retrieve. Default fields are provided.
 * @return array<string, mixed> Associative array with 'status' and 'data' (containing order details) or 'message'/'details' on error.
 *
 * @example
 * // $orderId = 'gid://shopify/Order/1234567890123';
 * // $orderFields = ['id', 'name', 'email', 'totalPriceSet { shopMoney { amount currencyCode } }'];
 * // $result = getOrder($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $orderId, $orderFields);
 * // if ($result['status'] === 'success') {
 * //   // print_r($result['data']['order']);
 * //   // echo "Order Name: " . $result['data']['order']['name'];
 * // } else {
 * //   // echo "Error fetching order: " . $result['message'];
 * // }
 */
function getOrder(
    string $shopifyUrl,
    string $accessToken,
    string $apiVersion,
    string $orderId,
    array $fields = [
        'id', 'name', 'email', 'displayFinancialStatus', 'displayFulfillmentStatus', 'createdAt', 'processedAt',
        'totalPriceSet { shopMoney { amount currencyCode } }',
        'lineItems(first: 5) { edges { node { title quantity currentQuantity priceSet { shopMoney { amount currencyCode } } } } }',
        'customer { id firstName lastName }'
    ]
): array {
    $fieldsString = formatGqlFieldsForQuery($fields);
    if (empty($fieldsString)) {
        $fieldsString = 'id name email';
    }

    $query = <<<GRAPHQL
    query getOrder(\$id: ID!) {
      order(id: \$id) {
        {$fieldsString}
      }
    }
    GRAPHQL;

    $variables = ['id' => $orderId];
    return sendShopifyGraphQLRequest($shopifyUrl, $accessToken, $apiVersion, $query, $variables);
}

/**
 * Updates an existing order (e.g., adding tags, notes, or metafields).
 *
 * @param string $shopifyUrl The Shopify store URL.
 * @param string $accessToken The Admin API access token.
 * @param string $apiVersion The API version.
 * @param string $orderId The GID of the order to update (e.g., "gid://shopify/Order/1234567890123").
 * @param array<string, mixed> $orderInput The input data for updating the order, conforming to Shopify's OrderInput type.
 *        Must include 'id' => $orderId.
 * @return array<string, mixed> Associative array with 'status' and 'data' (containing updated order details) or 'message'/'details' on error.
 *
 * @example
 * // $orderIdToUpdate = 'gid://shopify/Order/1234567890123';
 * // $orderUpdateInput = [
 * //   'id' => $orderIdToUpdate,
 * //   'tags' => ['VIP Customer', 'Follow Up'],
 * //   'note' => 'Customer requested a follow-up call regarding their order.'
 * // ];
 * // $result = updateOrder($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $orderIdToUpdate, $orderUpdateInput);
 * // if ($result['status'] === 'success' && isset($result['data']['orderUpdate']['order'])) {
 * //   // print_r($result['data']['orderUpdate']['order']);
 * //   // echo "Order " . $result['data']['orderUpdate']['order']['id'] . " updated successfully.";
 * // } elseif (isset($result['data']['orderUpdate']['userErrors']) && count($result['data']['orderUpdate']['userErrors']) > 0) {
 * //   // echo "Order update failed with user errors:";
 * //   // print_r($result['data']['orderUpdate']['userErrors']);
 * // } else {
 * //   // echo "Error updating order: " . $result['message'];
 * // }
 */
function updateOrder(
    string $shopifyUrl,
    string $accessToken,
    string $apiVersion,
    string $orderId,
    array $orderInput
): array {
    if (!isset($orderInput['id'])) {
        $orderInput['id'] = $orderId;
    } elseif ($orderInput['id'] !== $orderId) {
        // Ensure ID consistency
    }

    $query = <<<GRAPHQL
    mutation orderUpdate(\$input: OrderInput!) {
      orderUpdate(input: \$input) {
        order {
          id
          name
          email
          note
          tags
          updatedAt
        }
        userErrors {
          field
          message
        }
      }
    }
    GRAPHQL;

    $variables = ['input' => $orderInput];
    return sendShopifyGraphQLRequest($shopifyUrl, $accessToken, $apiVersion, $query, $variables);
}

/**
 * Cancels an order.
 *
 * @param string $shopifyUrl The Shopify store URL.
 * @param string $accessToken The Admin API access token.
 * @param string $apiVersion The API version.
 * @param string $orderId The GID of the order to cancel (e.g., "gid://shopify/Order/1234567890123").
 * @param ?string $reason Optional. The reason for cancellation (e.g., CUSTOMER_REQUEST, FRAUD, INVENTORY, OTHER).
 * @param bool $restock Optional. Whether to restock items from the order. Defaults to false.
 * @param ?string $staffNote Optional. A note for the cancellation.
 * @param ?bool $notifyCustomer Optional. Whether to send a notification to the customer about the cancellation.
 * @return array<string, mixed> Associative array with 'status' and 'data' (containing cancelled order details) or 'message'/'details' on error.
 *
 * @example
 * // $orderIdToCancel = 'gid://shopify/Order/1234567890123';
 * // $reason = 'CUSTOMER_REQUEST';
 * // $restockItems = true;
 * // $note = 'Customer changed their mind.';
 * // $notify = true;
 * // $result = cancelOrder($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $orderIdToCancel, $reason, $restockItems, $note, $notify);
 * // if ($result['status'] === 'success' && isset($result['data']['orderCancel']['order'])) {
 * //   // print_r($result['data']['orderCancel']['order']);
 * //   // echo "Order " . $result['data']['orderCancel']['order']['id'] . " cancelled successfully.";
 * // } elseif (isset($result['data']['orderCancel']['userErrors']) && count($result['data']['orderCancel']['userErrors']) > 0) {
 * //   // echo "Order cancellation failed with user errors:";
 * //   // print_r($result['data']['orderCancel']['userErrors']);
 * // } else {
 * //   // echo "Error cancelling order: " . $result['message'];
 * // }
 */
function cancelOrder(
    string $shopifyUrl,
    string $accessToken,
    string $apiVersion,
    string $orderId,
    ?string $reason = null,
    bool $restock = false,
    ?string $staffNote = null,
    ?bool $notifyCustomer = null
): array {
    $input = ['id' => $orderId];

    if ($reason !== null) {
        $input['reason'] = $reason;
    }
    $input['restock'] = $restock;
    if ($staffNote !== null) {
        $input['staffNote'] = $staffNote;
    }
    if ($notifyCustomer !== null) {
        $input['notifyCustomer'] = $notifyCustomer;
    }

    $query = <<<GRAPHQL
    mutation orderCancel(\$input: OrderCancelInput!) {
      orderCancel(input: \$input) {
        order {
          id
          displayFinancialStatus
          displayFulfillmentStatus
          cancelledAt
          cancelReason
        }
        userErrors {
          field
          message
        }
      }
    }
    GRAPHQL;

    $variables = ['input' => $input];
    return sendShopifyGraphQLRequest($shopifyUrl, $accessToken, $apiVersion, $query, $variables);
}

/**
 * Fetches specified fields of a customer by its GID.
 *
 * @param string $shopifyUrl The Shopify store URL.
 * @param string $accessToken The Admin API access token.
 * @param string $apiVersion The API version.
 * @param string $customerId The GID of the customer (e.g., "gid://shopify/Customer/1234567890123").
 * @param array<string|array<mixed>> $fields The list of fields to retrieve. Default fields are provided.
 * @return array<string, mixed> Associative array with 'status' and 'data' (containing customer details) or 'message'/'details' on error.
 *
 * @example
 * // $customerId = 'gid://shopify/Customer/1234567890123';
 * // $customerFields = ['id', 'firstName', 'lastName', 'email', 'phone', 'numberOfOrders'];
 * // $result = getCustomer($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $customerId, $customerFields);
 * // if ($result['status'] === 'success') {
 * //   // print_r($result['data']['customer']);
 * //   // echo "Customer Name: " . $result['data']['customer']['firstName'] . " " . $result['data']['customer']['lastName'];
 * // } else {
 * //   // echo "Error fetching customer: " . $result['message'];
 * // }
 */
function getCustomer(
    string $shopifyUrl,
    string $accessToken,
    string $apiVersion,
    string $customerId,
    array $fields = [
        'id', 'firstName', 'lastName', 'email', 'phone', 'acceptsMarketing',
        'createdAt', 'updatedAt', 'numberOfOrders', 'totalSpent { amount currencyCode }',
        'defaultAddress { id address1 city country zip }',
        'addresses(first:3) { id address1 city country zip }'
    ]
): array {
    $fieldsString = formatGqlFieldsForQuery($fields);
    if (empty($fieldsString)) {
        $fieldsString = 'id firstName lastName email';
    }

    $query = <<<GRAPHQL
    query getCustomer(\$id: ID!) {
      customer(id: \$id) {
        {$fieldsString}
      }
    }
    GRAPHQL;

    $variables = ['id' => $customerId];
    return sendShopifyGraphQLRequest($shopifyUrl, $accessToken, $apiVersion, $query, $variables);
}

/**
 * Creates a new customer.
 *
 * @param string $shopifyUrl The Shopify store URL.
 * @param string $accessToken The Admin API access token.
 * @param string $apiVersion The API version.
 * @param array<string, mixed> $customerInput The input data for creating the customer, conforming to Shopify's CustomerInput type.
 * @return array<string, mixed> Associative array with 'status' and 'data' (containing created customer's details) or 'message'/'details' on error.
 *
 * @example
 * // $customerData = [
 * //   'firstName' => 'Jane',
 * //   'lastName' => 'Doe',
 * //   'email' => 'jane.doe@example.com',
 * //   'phone' => '+1234567890',
 * //   'acceptsMarketing' => true,
 * //   'tags' => ['new_customer', 'newsletter_signup']
 * // ];
 * // $result = createCustomer($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $customerData);
 * // if ($result['status'] === 'success' && isset($result['data']['customerCreate']['customer'])) {
 * //   // echo "Created Customer ID: " . $result['data']['customerCreate']['customer']['id'];
 * //   // print_r($result['data']['customerCreate']['customer']);
 * // } elseif (isset($result['data']['customerCreate']['userErrors']) && count($result['data']['customerCreate']['userErrors']) > 0) {
 * //   // echo "Customer creation failed with user errors:";
 * //   // print_r($result['data']['customerCreate']['userErrors']);
 * // } else {
 * //   // echo "Error creating customer: " . $result['message'];
 * // }
 */
function createCustomer(
    string $shopifyUrl,
    string $accessToken,
    string $apiVersion,
    array $customerInput
): array {
    $query = <<<GRAPHQL
    mutation customerCreate(\$input: CustomerInput!) {
      customerCreate(input: \$input) {
        customer {
          id
          firstName
          lastName
          email
          phone
          acceptsMarketing
          createdAt
          updatedAt
          tags
        }
        userErrors {
          field
          message
        }
      }
    }
    GRAPHQL;

    $variables = ['input' => $customerInput];
    return sendShopifyGraphQLRequest($shopifyUrl, $accessToken, $apiVersion, $query, $variables);
}

/**
 * Updates an existing customer.
 *
 * @param string $shopifyUrl The Shopify store URL.
 * @param string $accessToken The Admin API access token.
 * @param string $apiVersion The API version.
 * @param string $customerId The GID of the customer to update.
 * @param array<string, mixed> $customerInput The input data for updating the customer. Must include 'id' => $customerId.
 * @return array<string, mixed> Associative array with 'status' and 'data' (containing updated customer's details) or 'message'/'details' on error.
 *
 * @example
 * // $customerIdToUpdate = 'gid://shopify/Customer/1234567890123';
 * // $updateData = [
 * //   'id' => $customerIdToUpdate,
 * //   'firstName' => 'Janet',
 * //   'email' => 'janet.doe.updated@example.com',
 * //   'tags' => ['vip_customer', 'updated_profile']
 * // ];
 * // $result = updateCustomer($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $customerIdToUpdate, $updateData);
 * // if ($result['status'] === 'success' && isset($result['data']['customerUpdate']['customer'])) {
 * //   // echo "Updated Customer ID: " . $result['data']['customerUpdate']['customer']['id'];
 * //   // print_r($result['data']['customerUpdate']['customer']);
 * // } elseif (isset($result['data']['customerUpdate']['userErrors']) && count($result['data']['customerUpdate']['userErrors']) > 0) {
 * //   // echo "Customer update failed with user errors:";
 * //   // print_r($result['data']['customerUpdate']['userErrors']);
 * // } else {
 * //   // echo "Error updating customer: " . $result['message'];
 * // }
 */
function updateCustomer(
    string $shopifyUrl,
    string $accessToken,
    string $apiVersion,
    string $customerId,
    array $customerInput
): array {
    if (!isset($customerInput['id'])) {
        $customerInput['id'] = $customerId;
    } elseif ($customerInput['id'] !== $customerId) {
        // ID consistency check
    }

    $query = <<<GRAPHQL
    mutation customerUpdate(\$input: CustomerInput!) {
      customerUpdate(input: \$input) {
        customer {
          id
          firstName
          lastName
          email
          phone
          acceptsMarketing
          createdAt
          updatedAt
          tags
        }
        userErrors {
          field
          message
        }
      }
    }
    GRAPHQL;

    $variables = ['input' => $customerInput];
    return sendShopifyGraphQLRequest($shopifyUrl, $accessToken, $apiVersion, $query, $variables);
}

/**
 * Deletes a customer.
 *
 * @param string $shopifyUrl The Shopify store URL.
 * @param string $accessToken The Admin API access token.
 * @param string $apiVersion The API version.
 * @param string $customerId The GID of the customer to delete.
 * @return array<string, mixed> Associative array with 'status' and 'data' (containing deletedCustomerId) or 'message'/'details' on error.
 *
 * @example
 * // $customerIdToDelete = 'gid://shopify/Customer/1234567890123';
 * // $result = deleteCustomer($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $customerIdToDelete);
 * // if ($result['status'] === 'success' && isset($result['data']['customerDelete']['deletedCustomerId'])) {
 * //   // echo "Deleted Customer ID: " . $result['data']['customerDelete']['deletedCustomerId'];
 * // } elseif (isset($result['data']['customerDelete']['userErrors']) && count($result['data']['customerDelete']['userErrors']) > 0) {
 * //   // echo "Customer deletion failed with user errors:";
 * //   // print_r($result['data']['customerDelete']['userErrors']);
 * // } else {
 * //   // echo "Error deleting customer: " . $result['message'];
 * // }
 */
function deleteCustomer(
    string $shopifyUrl,
    string $accessToken,
    string $apiVersion,
    string $customerId
): array {
    $query = <<<GRAPHQL
    mutation customerDelete(\$input: CustomerDeleteInput!) {
      customerDelete(input: \$input) {
        deletedCustomerId
        userErrors {
          field
          message
        }
      }
    }
    GRAPHQL;
    $variables = ['input' => ['id' => $customerId]];
    return sendShopifyGraphQLRequest($shopifyUrl, $accessToken, $apiVersion, $query, $variables);
}

/**
 * Fetches inventory levels for a given InventoryItem GID.
 *
 * @param string $shopifyUrl The Shopify store URL.
 * @param string $accessToken The Admin API access token.
 * @param string $apiVersion The API version.
 * @param string $inventoryItemId The GID of the InventoryItem (e.g., "gid://shopify/InventoryItem/1234567890123").
 * @param array<string> $locationIds Optional. Array of Location GIDs. If provided, results should ideally be filtered by these locations.
 *        However, direct API filtering for multiple locations in a single inventoryLevels query is not standard.
 *        This parameter is noted for client-side filtering or future enhancement.
 *        The current implementation fetches all levels for the item (up to 25 by default).
 * @param array<string|array<mixed>> $fields The list of fields to retrieve for each InventoryLevel node.
 * @return array<string, mixed> Associative array with 'status' and 'data' or 'message'/'details'.
 *         The data path for levels is typically `['inventoryItem']['inventoryLevels']['edges']`.
 *
 * @example
 * // $inventoryItemId = 'gid://shopify/InventoryItem/1234567890123';
 * // $invFields = ['id', 'available', 'location { id name }'];
 * // $result = getInventoryLevels($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $inventoryItemId, [], $invFields);
 * // if ($result['status'] === 'success' && isset($result['data']['inventoryItem']['inventoryLevels']['edges'])) {
 * //   // echo "Inventory Item SKU: " . $result['data']['inventoryItem']['sku'] . "\n";
 * //   // foreach ($result['data']['inventoryItem']['inventoryLevels']['edges'] as $edge) {
 * //   //   // print_r($edge['node']);
 * //   // }
 * // } else {
 * //   // echo "Error fetching inventory levels: " . $result['message'];
 * // }
 */
function getInventoryLevels(
    string $shopifyUrl,
    string $accessToken,
    string $apiVersion,
    string $inventoryItemId,
    array $locationIds = [],
    array $fields = ['id', 'available', 'quantities { name quantity }', 'location { id name }', 'item { id sku }', 'updatedAt']
): array {
    $inventoryLevelsFieldsString = formatGqlFieldsForQuery($fields);
    if (empty($inventoryLevelsFieldsString)) {
        $inventoryLevelsFieldsString = 'id available location { id name } updatedAt';
    }

    $query = <<<GRAPHQL
    query getInventoryLevels(\$inventoryItemId: ID!) {
      inventoryItem(id: \$inventoryItemId) {
        id
        sku
        tracked
        inventoryLevels(first: 25) {
          edges {
            node {
              {$inventoryLevelsFieldsString}
            }
          }
          pageInfo {
            hasNextPage
            endCursor
          }
        }
      }
    }
    GRAPHQL;

    $variables = ['inventoryItemId' => $inventoryItemId];
    return sendShopifyGraphQLRequest($shopifyUrl, $accessToken, $apiVersion, $query, $variables);
}

/**
 * Adjusts the inventory quantity for a specific inventory level GID.
 *
 * @param string $shopifyUrl The Shopify store URL.
 * @param string $accessToken The Admin API access token.
 * @param string $apiVersion The API version.
 * @param string $inventoryLevelId The GID of the InventoryLevel to adjust (e.g., "gid://shopify/InventoryLevel/12345?inventory_item_id=67890").
 * @param int $availableDelta The change in quantity. Positive to increase, negative to decrease.
 * @return array<string, mixed> Associative array with 'status' and 'data' (containing adjusted inventoryLevel details) or 'message'/'details' on error.
 *
 * @example
 * // $inventoryLevelId = 'gid://shopify/InventoryLevel/12345?inventory_item_id=67890'; // Replace with actual GID
 * // $delta = 5; // Increase quantity by 5
 * // $result = adjustInventoryLevel($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $inventoryLevelId, $delta);
 * // if ($result['status'] === 'success' && isset($result['data']['inventoryAdjustQuantity']['inventoryLevel'])) {
 * //   // echo "Inventory adjusted successfully. New available quantity: " . $result['data']['inventoryAdjustQuantity']['inventoryLevel']['available'];
 * //   // print_r($result['data']['inventoryAdjustQuantity']['inventoryLevel']);
 * // } elseif (isset($result['data']['inventoryAdjustQuantity']['userErrors']) && count($result['data']['inventoryAdjustQuantity']['userErrors']) > 0) {
 * //   // echo "Inventory adjustment failed with user errors:";
 * //   // print_r($result['data']['inventoryAdjustQuantity']['userErrors']);
 * // } else {
 * //   // echo "Error adjusting inventory: " . $result['message'];
 * // }
 */
function adjustInventoryLevel(
    string $shopifyUrl,
    string $accessToken,
    string $apiVersion,
    string $inventoryLevelId,
    int $availableDelta
): array {
    $input = [
        'inventoryLevelId' => $inventoryLevelId,
        'availableDelta' => $availableDelta,
    ];

    $query = <<<GRAPHQL
    mutation inventoryAdjustQuantity(\$input: InventoryAdjustQuantityInput!) {
      inventoryAdjustQuantity(input: \$input) {
        inventoryLevel {
          id
          available
          updatedAt
          location { id name }
          item { id sku }
        }
        userErrors {
          field
          message
        }
      }
    }
    GRAPHQL;

    $variables = ['input' => $input];
    return sendShopifyGraphQLRequest($shopifyUrl, $accessToken, $apiVersion, $query, $variables);
}

/**
 * Fetches specified fields of a collection by its GID.
 *
 * @param string $shopifyUrl The Shopify store URL.
 * @param string $accessToken The Admin API access token.
 * @param string $apiVersion The API version.
 * @param string $collectionId The GID of the collection (e.g., "gid://shopify/Collection/1234567890123").
 * @param array<string|array<mixed>> $fields The list of fields to retrieve. Default fields are provided.
 * @return array<string, mixed> Associative array with 'status' and 'data' (containing collection details) or 'message'/'details' on error.
 *
 * @example
 * // $collectionId = 'gid://shopify/Collection/1234567890123';
 * // $collectionFields = ['id', 'title', 'handle', 'productsCount'];
 * // $result = getCollection($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $collectionId, $collectionFields);
 * // if ($result['status'] === 'success') {
 * //   // print_r($result['data']['collection']);
 * //   // echo "Collection Title: " . $result['data']['collection']['title'];
 * // } else {
 * //   // echo "Error fetching collection: " . $result['message'];
 * // }
 */
function getCollection(
    string $shopifyUrl,
    string $accessToken,
    string $apiVersion,
    string $collectionId,
    array $fields = ['id', 'title', 'handle', 'descriptionHtml', 'updatedAt', 'productsCount', 'sortOrder', 'products(first: 10) { edges { node { id title } } }']
): array {
    $fieldsString = formatGqlFieldsForQuery($fields);
    if (empty($fieldsString)) {
        $fieldsString = 'id title handle';
    }

    $query = <<<GRAPHQL
    query getCollection(\$id: ID!) {
      collection(id: \$id) {
        {$fieldsString}
      }
    }
    GRAPHQL;

    $variables = ['id' => $collectionId];
    return sendShopifyGraphQLRequest($shopifyUrl, $accessToken, $apiVersion, $query, $variables);
}

/**
 * Creates a new collection (custom or smart).
 *
 * @param string $shopifyUrl The Shopify store URL.
 * @param string $accessToken The Admin API access token.
 * @param string $apiVersion The API version.
 * @param array<string, mixed> $collectionInput Input data conforming to CollectionInput.
 *        For smart collections, include 'ruleSet'. Example: `['title' => 'Summer Vibes']`.
 * @return array<string, mixed> Associative array with 'status' and 'data' (containing created collection details) or 'message'/'details' on error.
 *
 * @example
 * // // For a custom collection:
 * // $customCollectionInput = ['title' => 'Featured Products'];
 * // $result = createCollection($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $customCollectionInput);
 * //
 * // // For a smart collection:
 * // $smartCollectionInput = [
 * //   'title' => 'Products Over $50',
 * //   'ruleSet' => [
 * //     'appliedDisjunctively' => false,
 * //     'rules' => [
 * //       ['column' => 'VARIANT_PRICE', 'relation' => 'GREATER_THAN', 'condition' => '50.00']
 * //     ]
 * //   ]
 * // ];
 * // $resultSmart = createCollection($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $smartCollectionInput);
 * //
 * // if ($result['status'] === 'success' && isset($result['data']['collectionCreate']['collection'])) {
 * //   // echo "Created Collection ID: " . $result['data']['collectionCreate']['collection']['id'];
 * //   // print_r($result['data']['collectionCreate']['collection']);
 * // } elseif (isset($result['data']['collectionCreate']['userErrors']) && count($result['data']['collectionCreate']['userErrors']) > 0) {
 * //   // echo "Collection creation failed with user errors:";
 * //   // print_r($result['data']['collectionCreate']['userErrors']);
 * // } else {
 * //   // echo "Error creating collection: " . $result['message'];
 * // }
 */
function createCollection(
    string $shopifyUrl,
    string $accessToken,
    string $apiVersion,
    array $collectionInput
): array {
    $query = <<<GRAPHQL
    mutation collectionCreate(\$input: CollectionInput!) {
      collectionCreate(input: \$input) {
        collection {
          id
          title
          handle
          updatedAt
          descriptionHtml
          ruleSet {
            appliedDisjunctively
            rules {
              column
              condition
              relation
            }
          }
        }
        userErrors {
          field
          message
        }
      }
    }
    GRAPHQL;

    $variables = ['input' => $collectionInput];
    return sendShopifyGraphQLRequest($shopifyUrl, $accessToken, $apiVersion, $query, $variables);
}

/**
 * Updates an existing collection.
 *
 * @param string $shopifyUrl The Shopify store URL.
 * @param string $accessToken The Admin API access token.
 * @param string $apiVersion The API version.
 * @param string $collectionId The GID of the collection to update.
 * @param array<string, mixed> $collectionInput Input data conforming to CollectionInput. Must include 'id' => $collectionId.
 * @return array<string, mixed> Associative array with 'status' and 'data' (containing updated collection details) or 'message'/'details' on error.
 *
 * @example
 * // $collectionIdToUpdate = 'gid://shopify/Collection/1234567890123';
 * // $updateData = [
 * //   'id' => $collectionIdToUpdate,
 * //   'title' => 'Super Featured Products - Updated',
 * //   'descriptionHtml' => '<p>Check out these amazing updated products!</p>'
 * // ];
 * // $result = updateCollection($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $collectionIdToUpdate, $updateData);
 * // if ($result['status'] === 'success' && isset($result['data']['collectionUpdate']['collection'])) {
 * //   // echo "Updated Collection ID: " . $result['data']['collectionUpdate']['collection']['id'];
 * //   // print_r($result['data']['collectionUpdate']['collection']);
 * // } elseif (isset($result['data']['collectionUpdate']['userErrors']) && count($result['data']['collectionUpdate']['userErrors']) > 0) {
 * //   // echo "Collection update failed with user errors:";
 * //   // print_r($result['data']['collectionUpdate']['userErrors']);
 * // } else {
 * //   // echo "Error updating collection: " . $result['message'];
 * // }
 */
function updateCollection(
    string $shopifyUrl,
    string $accessToken,
    string $apiVersion,
    string $collectionId,
    array $collectionInput
): array {
    if (!isset($collectionInput['id'])) {
        $collectionInput['id'] = $collectionId;
    } elseif ($collectionInput['id'] !== $collectionId) {
        // ID consistency check
    }

    $query = <<<GRAPHQL
    mutation collectionUpdate(\$input: CollectionInput!) {
      collectionUpdate(input: \$input) {
        collection {
          id
          title
          handle
          updatedAt
          descriptionHtml
          ruleSet {
            appliedDisjunctively
            rules {
              column
              condition
              relation
            }
          }
        }
        userErrors {
          field
          message
        }
      }
    }
    GRAPHQL;

    $variables = ['input' => $collectionInput];
    return sendShopifyGraphQLRequest($shopifyUrl, $accessToken, $apiVersion, $query, $variables);
}

/**
 * Deletes a collection.
 *
 * @param string $shopifyUrl The Shopify store URL.
 * @param string $accessToken The Admin API access token.
 * @param string $apiVersion The API version.
 * @param string $collectionId The GID of the collection to delete.
 * @return array<string, mixed> Associative array with 'status' and 'data' (containing deletedCollectionId) or 'message'/'details' on error.
 *
 * @example
 * // $collectionIdToDelete = 'gid://shopify/Collection/1234567890123';
 * // $result = deleteCollection($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $collectionIdToDelete);
 * // if ($result['status'] === 'success' && isset($result['data']['collectionDelete']['deletedCollectionId'])) {
 * //   // echo "Deleted Collection ID: " . $result['data']['collectionDelete']['deletedCollectionId'];
 * // } elseif (isset($result['data']['collectionDelete']['userErrors']) && count($result['data']['collectionDelete']['userErrors']) > 0) {
 * //   // echo "Collection deletion failed with user errors:";
 * //   // print_r($result['data']['collectionDelete']['userErrors']);
 * // } else {
 * //   // echo "Error deleting collection: " . $result['message'];
 * // }
 */
function deleteCollection(
    string $shopifyUrl,
    string $accessToken,
    string $apiVersion,
    string $collectionId
): array {
    $input = ['id' => $collectionId];
    $query = <<<GRAPHQL
    mutation collectionDelete(\$input: CollectionDeleteInput!) {
      collectionDelete(input: \$input) {
        deletedCollectionId
        userErrors {
          field
          message
        }
      }
    }
    GRAPHQL;

    $variables = ['input' => $input];
    return sendShopifyGraphQLRequest($shopifyUrl, $accessToken, $apiVersion, $query, $variables);
}

/**
 * Adds a product to a custom collection.
 * Note: This operation is for custom collections. Smart collections are rule-based.
 *
 * @param string $shopifyUrl The Shopify store URL.
 * @param string $accessToken The Admin API access token.
 * @param string $apiVersion The API version.
 * @param string $collectionId The GID of the custom collection.
 * @param string $productId The GID of the product to add.
 * @return array<string, mixed> Associative array with 'status' and 'data' (containing collection details) or 'message'/'details' on error.
 *
 * @example
 * // $customCollectionId = 'gid://shopify/Collection/1234567890123'; // Must be a Custom Collection
 * // $productIdToAdd = 'gid://shopify/Product/9876543210987';
 * // $result = addProductToCollection($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $customCollectionId, $productIdToAdd);
 * // if ($result['status'] === 'success' && isset($result['data']['collectionAddProducts']['collection'])) {
 * //   // echo "Product added to collection. New product count: " . $result['data']['collectionAddProducts']['collection']['productCount'];
 * //   // print_r($result['data']['collectionAddProducts']['collection']);
 * // } elseif (isset($result['data']['collectionAddProducts']['userErrors']) && count($result['data']['collectionAddProducts']['userErrors']) > 0) {
 * //   // echo "Failed to add product to collection due to user errors:";
 * //   // print_r($result['data']['collectionAddProducts']['userErrors']);
 * // } else {
 * //   // echo "Error adding product to collection: " . $result['message'];
 * // }
 */
function addProductToCollection(
    string $shopifyUrl,
    string $accessToken,
    string $apiVersion,
    string $collectionId,
    string $productId
): array {
    $query = <<<GRAPHQL
    mutation collectionAddProducts(\$collectionId: ID!, \$productIds: [ID!]!) {
      collectionAddProducts(collectionId: \$collectionId, productIds: \$productIds) {
        collection {
          id
          title
          productCount
          products(first: 10) {
            edges {
              node {
                id
                title
              }
            }
          }
        }
        userErrors {
          field
          message
        }
      }
    }
    GRAPHQL;

    $variables = ['collectionId' => $collectionId, 'productIds' => [$productId]];
    return sendShopifyGraphQLRequest($shopifyUrl, $accessToken, $apiVersion, $query, $variables);
}

/**
 * Removes a product from a custom collection.
 * Note: This operation is for custom collections. Smart collections are rule-based.
 *
 * @param string $shopifyUrl The Shopify store URL.
 * @param string $accessToken The Admin API access token.
 * @param string $apiVersion The API version.
 * @param string $collectionId The GID of the custom collection.
 * @param string $productId The GID of the product to remove.
 * @return array<string, mixed> Associative array with 'status' and 'data' (containing collection details) or 'message'/'details' on error.
 *
 * @example
 * // $customCollectionId = 'gid://shopify/Collection/1234567890123'; // Must be a Custom Collection
 * // $productIdToRemove = 'gid://shopify/Product/9876543210987';
 * // $result = removeProductFromCollection($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $customCollectionId, $productIdToRemove);
 * // if ($result['status'] === 'success' && isset($result['data']['collectionRemoveProducts']['collection'])) {
 * //   // echo "Product removed from collection. New product count: " . $result['data']['collectionRemoveProducts']['collection']['productCount'];
 * //   // print_r($result['data']['collectionRemoveProducts']['collection']);
 * // } elseif (isset($result['data']['collectionRemoveProducts']['userErrors']) && count($result['data']['collectionRemoveProducts']['userErrors']) > 0) {
 * //   // echo "Failed to remove product from collection due to user errors:";
 * //   // print_r($result['data']['collectionRemoveProducts']['userErrors']);
 * // } else {
 * //   // echo "Error removing product from collection: " . $result['message'];
 * // }
 */
function removeProductFromCollection(
    string $shopifyUrl,
    string $accessToken,
    string $apiVersion,
    string $collectionId,
    string $productId
): array {
    $query = <<<GRAPHQL
    mutation collectionRemoveProducts(\$collectionId: ID!, \$productIds: [ID!]!) {
      collectionRemoveProducts(collectionId: \$collectionId, productIds: \$productIds) {
        collection {
          id
          title
          productCount
          products(first: 10) {
            edges {
              node {
                id
                title
              }
            }
          }
        }
        userErrors {
          field
          message
        }
      }
    }
    GRAPHQL;

    $variables = ['collectionId' => $collectionId, 'productIds' => [$productId]];
    return sendShopifyGraphQLRequest($shopifyUrl, $accessToken, $apiVersion, $query, $variables);
}

?>
