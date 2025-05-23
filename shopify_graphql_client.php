<?php

declare(strict_types=1);

/**
 * Shopify GraphQL Admin API Client Library for PHP
 *
 * This file provides a set of functions to interact with the Shopify GraphQL Admin API,
 * simplifying common operations such as managing products, orders, customers, collections,
 * inventory, and performing bulk operations.
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // 30 seconds execution timeout for typical requests

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
            'details' => $response
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
 * @return array<string, mixed> Associative array with 'status' and 'data' (containing product details) or 'message'/'details' on error.
 * @example
 * // $productId = 'gid://shopify/Product/1234567890123';
 * // $fields = ['id', 'title', 'status', 'totalInventory'];
 * // $result = getProduct($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $productId, $fields);
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
 * @param array<string, mixed> $productInput The input data for creating the product.
 * @return array<string, mixed> Associative array with 'status' and 'data' or 'message'/'details' on error.
 * @example
 * // $productInput = ['title' => 'Awesome New T-Shirt', 'vendor' => 'MyBrand'];
 * // $result = createProduct($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $productInput);
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
 * @param string $productId The GID of the product to update.
 * @param array<string, mixed> $productInput The input data for updating the product. Must include 'id' => $productId.
 * @return array<string, mixed> Associative array with 'status' and 'data' or 'message'/'details' on error.
 * @example
 * // $productIdToUpdate = 'gid://shopify/Product/1234567890123';
 * // $updateInput = ['id' => $productIdToUpdate, 'title' => 'Updated T-Shirt Title'];
 * // $result = updateProduct($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $productIdToUpdate, $updateInput);
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
        // Consider warning/error for mismatch
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
 * @param string $productId The GID of the product to delete.
 * @return array<string, mixed> Associative array with 'status' and 'data' or 'message'/'details' on error.
 * @example
 * // $productIdToDelete = 'gid://shopify/Product/1234567890123';
 * // $result = deleteProduct($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $productIdToDelete);
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
 * @param string $orderId The GID of the order to fetch.
 * @param array<string|array<mixed>> $fields The list of fields to retrieve.
 * @return array<string, mixed> Associative array with 'status' and 'data' or 'message'/'details' on error.
 * @example
 * // $orderId = 'gid://shopify/Order/1234567890123';
 * // $result = getOrder($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $orderId);
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
 * Updates an existing order.
 *
 * @param string $shopifyUrl The Shopify store URL.
 * @param string $accessToken The Admin API access token.
 * @param string $apiVersion The API version.
 * @param string $orderId The GID of the order to update.
 * @param array<string, mixed> $orderInput The input data for updating the order. Must include 'id' => $orderId.
 * @return array<string, mixed> Associative array with 'status' and 'data' or 'message'/'details' on error.
 * @example
 * // $orderIdToUpdate = 'gid://shopify/Order/1234567890123';
 * // $orderUpdateInput = ['id' => $orderIdToUpdate, 'tags' => ['VIP']];
 * // $result = updateOrder($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $orderIdToUpdate, $orderUpdateInput);
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
 * @param string $orderId The GID of the order to cancel.
 * @param ?string $reason Optional. The reason for cancellation.
 * @param bool $restock Optional. Whether to restock items. Defaults to false.
 * @param ?string $staffNote Optional. A note for the cancellation.
 * @param ?bool $notifyCustomer Optional. Whether to send a notification to the customer.
 * @return array<string, mixed> Associative array with 'status' and 'data' or 'message'/'details' on error.
 * @example
 * // $orderIdToCancel = 'gid://shopify/Order/1234567890123';
 * // $result = cancelOrder($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $orderIdToCancel, 'CUSTOMER_REQUEST');
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
    if ($reason !== null) $input['reason'] = $reason;
    $input['restock'] = $restock;
    if ($staffNote !== null) $input['staffNote'] = $staffNote;
    if ($notifyCustomer !== null) $input['notifyCustomer'] = $notifyCustomer;
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
 * @param string $customerId The GID of the customer.
 * @param array<string|array<mixed>> $fields The list of fields to retrieve.
 * @return array<string, mixed> Associative array with 'status' and 'data' or 'message'/'details' on error.
 * @example
 * // $customerId = 'gid://shopify/Customer/1234567890123';
 * // $result = getCustomer($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $customerId);
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
 * @param array<string, mixed> $customerInput The input data for creating the customer.
 * @return array<string, mixed> Associative array with 'status' and 'data' or 'message'/'details' on error.
 * @example
 * // $customerData = ['firstName' => 'Jane', 'lastName' => 'Doe', 'email' => 'jane.doe@example.com'];
 * // $result = createCustomer($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $customerData);
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
 * @param array<string, mixed> $customerInput The input data. Must include 'id' => $customerId.
 * @return array<string, mixed> Associative array with 'status' and 'data' or 'message'/'details' on error.
 * @example
 * // $customerIdToUpdate = 'gid://shopify/Customer/1234567890123';
 * // $updateData = ['id' => $customerIdToUpdate, 'firstName' => 'Janet'];
 * // $result = updateCustomer($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $customerIdToUpdate, $updateData);
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
 * @return array<string, mixed> Associative array with 'status' and 'data' or 'message'/'details' on error.
 * @example
 * // $customerIdToDelete = 'gid://shopify/Customer/1234567890123';
 * // $result = deleteCustomer($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $customerIdToDelete);
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
 * @param string $inventoryItemId The GID of the InventoryItem.
 * @param array<string> $locationIds Optional. Not used for API-side filtering in this version.
 * @param array<string|array<mixed>> $fields The list of fields to retrieve for each InventoryLevel node.
 * @return array<string, mixed> Associative array with 'status' and 'data' or 'message'/'details'.
 * @example
 * // $inventoryItemId = 'gid://shopify/InventoryItem/1234567890123';
 * // $result = getInventoryLevels($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $inventoryItemId);
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
 * @param string $inventoryLevelId The GID of the InventoryLevel to adjust.
 * @param int $availableDelta The change in quantity.
 * @return array<string, mixed> Associative array with 'status' and 'data' or 'message'/'details' on error.
 * @example
 * // $inventoryLevelId = 'gid://shopify/InventoryLevel/12345?inventory_item_id=67890';
 * // $delta = 5;
 * // $result = adjustInventoryLevel($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $inventoryLevelId, $delta);
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
 * @param string $collectionId The GID of the collection.
 * @param array<string|array<mixed>> $fields The list of fields to retrieve.
 * @return array<string, mixed> Associative array with 'status' and 'data' or 'message'/'details' on error.
 * @example
 * // $collectionId = 'gid://shopify/Collection/1234567890123';
 * // $result = getCollection($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $collectionId);
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
 * @return array<string, mixed> Associative array with 'status' and 'data' or 'message'/'details' on error.
 * @example
 * // $customCollectionInput = ['title' => 'Featured Products'];
 * // $result = createCollection($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $customCollectionInput);
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
 * @param array<string, mixed> $collectionInput Input data. Must include 'id' => $collectionId.
 * @return array<string, mixed> Associative array with 'status' and 'data' or 'message'/'details' on error.
 * @example
 * // $collectionIdToUpdate = 'gid://shopify/Collection/1234567890123';
 * // $updateData = ['id' => $collectionIdToUpdate, 'title' => 'Super Featured Products'];
 * // $result = updateCollection($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $collectionIdToUpdate, $updateData);
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
 * @return array<string, mixed> Associative array with 'status' and 'data' or 'message'/'details' on error.
 * @example
 * // $collectionIdToDelete = 'gid://shopify/Collection/1234567890123';
 * // $result = deleteCollection($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $collectionIdToDelete);
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
 *
 * @param string $shopifyUrl The Shopify store URL.
 * @param string $accessToken The Admin API access token.
 * @param string $apiVersion The API version.
 * @param string $collectionId The GID of the custom collection.
 * @param string $productId The GID of the product to add.
 * @return array<string, mixed> Associative array with 'status' and 'data' or 'message'/'details' on error.
 * @example
 * // $customCollectionId = 'gid://shopify/Collection/1234567890123';
 * // $productIdToAdd = 'gid://shopify/Product/9876543210987';
 * // $result = addProductToCollection($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $customCollectionId, $productIdToAdd);
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
 *
 * @param string $shopifyUrl The Shopify store URL.
 * @param string $accessToken The Admin API access token.
 * @param string $apiVersion The API version.
 * @param string $collectionId The GID of the custom collection.
 * @param string $productId The GID of the product to remove.
 * @return array<string, mixed> Associative array with 'status' and 'data' or 'message'/'details' on error.
 * @example
 * // $customCollectionId = 'gid://shopify/Collection/1234567890123';
 * // $productIdToRemove = 'gid://shopify/Product/9876543210987';
 * // $result = removeProductFromCollection($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $customCollectionId, $productIdToRemove);
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

/**
 * Starts a Shopify bulk query operation.
 *
 * @param string $shopifyUrl The Shopify store URL.
 * @param string $accessToken The Admin API access token.
 * @param string $apiVersion The API version.
 * @param string $gqlQuery The GraphQL query string for the data to be exported.
 * @return array<string, mixed> Associative array with 'status' and 'data' or 'message'/'details' on error.
 * @example
 * // $bulkQuery = "{ products { edges { node { id title } } } }";
 * // $result = startBulkQuery($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $bulkQuery);
 */
function startBulkQuery(
    string $shopifyUrl,
    string $accessToken,
    string $apiVersion,
    string $gqlQuery
): array {
    $mutation = <<<GRAPHQL
    mutation bulkOperationRunQuery(\$query: String!) {
      bulkOperationRunQuery(query: \$query) {
        bulkOperation {
          id
          status
          createdAt
          type
          objectCount
          errorCode
        }
        userErrors {
          field
          message
        }
      }
    }
    GRAPHQL;
    $variables = ['query' => $gqlQuery];
    return sendShopifyGraphQLRequest($shopifyUrl, $accessToken, $apiVersion, $mutation, $variables);
}

/**
 * Polls the status of an ongoing bulk operation using its GID.
 *
 * @param string $shopifyUrl The Shopify store URL.
 * @param string $accessToken The Admin API access token.
 * @param string $apiVersion The API version.
 * @param string $operationId The GID of the bulk operation.
 * @param array<string|array<mixed>> $fields The list of BulkOperation fields to retrieve.
 * @return array<string, mixed> Associative array with 'status' and 'data' or 'message'/'details' on error.
 * @example
 * // $operationId = 'gid://shopify/BulkOperation/1234567890';
 * // $result = getBulkOperationStatus($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $operationId);
 */
function getBulkOperationStatus(
    string $shopifyUrl,
    string $accessToken,
    string $apiVersion,
    string $operationId,
    array $fields = ['id', 'status', 'errorCode', 'createdAt', 'completedAt', 'objectCount', 'fileSize', 'url', 'partialDataUrl', 'type']
): array {
    $fieldsString = formatGqlFieldsForQuery($fields);
    if (empty($fieldsString)) {
        $fieldsString = 'id status url errorCode'; // Minimal fallback
    }
    $query = <<<GRAPHQL
    query getBulkOperationStatus(\$id: ID!) {
      node(id: \$id) {
        ... on BulkOperation {
          {$fieldsString}
        }
      }
    }
    GRAPHQL;
    $variables = ['id' => $operationId];
    return sendShopifyGraphQLRequest($shopifyUrl, $accessToken, $apiVersion, $query, $variables);
}

/**
 * Downloads the JSONL result file from the URL provided by a completed bulk query operation.
 *
 * @param string $fileUrl The URL of the result file.
 * @param string $localFilePath The local path where the downloaded file should be saved.
 * @return array<string, string> Associative array with 'status': 'success' or 'error', and 'message' on error.
 * @example
 * // $downloadUrl = 'https://example.com/path/to/results.jsonl';
 * // $savePath = __DIR__ . '/shopify_bulk_results.jsonl';
 * // $result = downloadBulkQueryResult($downloadUrl, $savePath);
 */
function downloadBulkQueryResult(string $fileUrl, string $localFilePath): array
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $fileUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);

    $fileContent = curl_exec($ch);
    $curlErrorNo = curl_errno($ch);
    $curlError = curl_error($ch);
    $httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErrorNo) {
        return ['status' => 'error', 'message' => "cURL error during download: Code {$curlErrorNo} - {$curlError}"];
    }
    if ($httpStatusCode !== 200) {
        return ['status' => 'error', 'message' => "HTTP error during download: Status Code {$httpStatusCode}. Response: " . substr((string)$fileContent, 0, 500)];
    }
    if ($fileContent === false || $fileContent === '') {
        return ['status' => 'error', 'message' => 'Downloaded file content is empty or download failed.'];
    }
    try {
        $bytesWritten = file_put_contents($localFilePath, $fileContent);
        if ($bytesWritten === false) {
            if (!is_writable(dirname($localFilePath))) {
                 return ['status' => 'error', 'message' => "Failed to write to local file: Directory '{$localFilePath}' is not writable."];
            }
            return ['status' => 'error', 'message' => "Failed to write to local file: {$localFilePath}. Unknown error."];
        }
        return ['status' => 'success', 'message' => "File downloaded and saved to {$localFilePath} ({$bytesWritten} bytes)."];
    } catch (Throwable $e) {
        return ['status' => 'error', 'message' => "Exception during file write: " . $e->getMessage()];
    }
}

/**
 * Exports all products (optionally filtered) to a local JSONL file using Shopify's bulk operations.
 *
 * @param string $shopifyUrl Your Shopify store URL (e.g., 'your-store.myshopify.com').
 * @param string $accessToken Your Admin API access token.
 * @param string $apiVersion The Shopify API version (e.g., '2024-04').
 * @param string $localFilePath The full local path to save the downloaded JSONL file (e.g., '/tmp/all_products.jsonl').
 * @param ?string $queryFilter Optional. A filter string for the products query (e.g., "status:active AND vendor:'MyVendor'").
 * @param array<string|array<mixed>> $productFields The product fields to export.
 * @param int $pollingIntervalSeconds Interval in seconds to poll for bulk operation status.
 * @param int $maxAttempts Maximum polling attempts before timing out.
 * @return array<string, mixed> Result array:
 *         - Success (download): ['status' => 'success', 'message' => 'Products exported successfully...', 'bulkOperationId' => ..., 'downloadPath' => ...]
 *         - Success (no data): ['status' => 'success', 'message' => 'Bulk product export completed, but no data...', 'bulkOperationId' => ...]
 *         - Error: ['status' => 'error', 'message' => '...', 'details' => [...]]
 *
 * @example
 * // $filePath = __DIR__ . '/exported_products.jsonl';
 * // $filter = "product_type:'Shoes' AND status:active";
 * // $fields = ['id', 'title', 'handle', 'status', 'productType', 'variants(first:3){edges{node{id sku price}}}'];
 * // $exportResult = exportAllProducts(
 * //   $shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $filePath, $filter, $fields
 * // );
 */
function exportAllProducts(
    string $shopifyUrl,
    string $accessToken,
    string $apiVersion,
    string $localFilePath,
    ?string $queryFilter = null,
    array $productFields = ['id', 'title', 'handle', 'vendor', 'status', 'createdAt', 'updatedAt', 'tags', 'productType', 'options { name values }', 'variants(first: 10) { edges { node { id title sku price inventoryQuantity } } }', 'images(first: 5) { edges { node { id url altText } } }'],
    int $pollingIntervalSeconds = 5,
    int $maxAttempts = 60
): array {
    $formattedProductFields = formatGqlFieldsForQuery($productFields);
    if (empty($formattedProductFields)) {
        $formattedProductFields = 'id title handle';
    }

    $productsQueryArgument = '';
    if ($queryFilter !== null && trim($queryFilter) !== '') {
        $productsQueryArgument = sprintf('(query: "%s")', addslashes($queryFilter));
    }

    $bulkGqlQuery = sprintf(
        "query { products%s { edges { node { %s } } } }",
        $productsQueryArgument,
        $formattedProductFields
    );

    $startResult = startBulkQuery($shopifyUrl, $accessToken, $apiVersion, $bulkGqlQuery);

    if ($startResult['status'] === 'error') {
        return $startResult;
    }

    if (!isset($startResult['data']['bulkOperationRunQuery']['bulkOperation']['id'])) {
        return [
            'status' => 'error',
            'message' => 'Failed to retrieve bulk operation ID after starting.',
            'details' => $startResult['data']['bulkOperationRunQuery']['userErrors'] ?? $startResult['data'] ?? []
        ];
    }
    $bulkOperationId = $startResult['data']['bulkOperationRunQuery']['bulkOperation']['id'];

    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        sleep($pollingIntervalSeconds);
        $statusResult = getBulkOperationStatus($shopifyUrl, $accessToken, $apiVersion, $bulkOperationId);

        if ($statusResult['status'] === 'error') {
            return [
                'status' => 'error',
                'message' => 'Failed to get bulk operation status.',
                'details' => $statusResult['message'] ?? $statusResult['details'] ?? [],
                'bulkOperationId' => $bulkOperationId
            ];
        }

        $operationStatusNode = $statusResult['data']['node'] ?? null;
        if ($operationStatusNode === null) {
             return [
                'status' => 'error',
                'message' => 'Bulk operation status node is missing in the response.',
                'details' => ['bulkOperationId' => $bulkOperationId, 'rawStatusResponse' => $statusResult],
            ];
        }

        $operationStatus = $operationStatusNode['status'] ?? null;
        $errorCode = $operationStatusNode['errorCode'] ?? null;

        switch ($operationStatus) {
            case 'COMPLETED':
                $downloadUrl = $operationStatusNode['url'] ?? null;
                if (empty($downloadUrl)) {
                    return [
                        'status' => 'success',
                        'message' => 'Bulk product export completed, but no data was found/generated (no download URL).',
                        'bulkOperationId' => $bulkOperationId
                    ];
                }
                $downloadResult = downloadBulkQueryResult($downloadUrl, $localFilePath);
                if ($downloadResult['status'] === 'success') {
                    return [
                        'status' => 'success',
                        'message' => 'Products exported successfully to ' . $localFilePath,
                        'bulkOperationId' => $bulkOperationId,
                        'downloadPath' => $localFilePath,
                        'fileSize' => $operationStatusNode['fileSize'] ?? null
                    ];
                } else {
                    return [
                        'status' => 'error',
                        'message' => 'Failed to download bulk export result file.',
                        'details' => $downloadResult['message'] ?? [],
                        'bulkOperationId' => $bulkOperationId,
                        'downloadUrl' => $downloadUrl
                    ];
                }
            case 'FAILED':
                $partialDataUrl = $operationStatusNode['partialDataUrl'] ?? null;
                $errorMessage = 'Bulk product export failed.';
                if ($partialDataUrl) {
                    $errorMessage .= " Partial data might be available at: {$partialDataUrl}";
                }
                return [
                    'status' => 'error',
                    'message' => $errorMessage,
                    'details' => ['errorCode' => $errorCode, 'bulkOperationId' => $bulkOperationId],
                    'partialDataUrl' => $partialDataUrl
                ];
            case 'CANCELLED':
                return [
                    'status' => 'error',
                    'message' => 'Bulk product export was cancelled.',
                    'details' => ['bulkOperationId' => $bulkOperationId]
                ];
            case 'CREATED':
            case 'RUNNING':
                break;
            default:
                return [
                    'status' => 'error',
                    'message' => "Bulk product export encountered an unexpected status: {$operationStatus}.",
                    'details' => ['bulkOperationId' => $bulkOperationId, 'statusDetails' => $operationStatusNode]
                ];
        }
    }

    return [
        'status' => 'error',
        'message' => "Bulk product export timed out after {$maxAttempts} attempts.",
        'details' => ['bulkOperationId' => $bulkOperationId, 'lastStatus' => $operationStatus ?? 'UNKNOWN']
    ];
}

/**
 * Exports all customers (optionally filtered) to a local JSONL file using Shopify's bulk operations.
 *
 * @param string $shopifyUrl Your Shopify store URL.
 * @param string $accessToken Your Admin API access token.
 * @param string $apiVersion The Shopify API version.
 * @param string $localFilePath The full local path to save the downloaded JSONL file.
 * @param ?string $queryFilter Optional. A filter string for the customers query.
 * @param array<string|array<mixed>> $customerFields The customer fields to export.
 * @param int $pollingIntervalSeconds Interval in seconds to poll for bulk operation status.
 * @param int $maxAttempts Maximum polling attempts before timing out.
 * @return array<string, mixed> Result array with status, message, and potentially data or details.
 * @example
 * // $filePath = __DIR__ . '/exported_customers.jsonl';
 * // $filter = "accepts_marketing:true AND number_of_orders:>0";
 * // $fields = ['id', 'firstName', 'lastName', 'email', 'tags'];
 * // $exportResult = exportAllCustomers($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $filePath, $filter, $fields);
 */
function exportAllCustomers(
    string $shopifyUrl,
    string $accessToken,
    string $apiVersion,
    string $localFilePath,
    ?string $queryFilter = null,
    array $customerFields = ['id', 'firstName', 'lastName', 'email', 'phone', 'acceptsMarketing', 'createdAt', 'updatedAt', 'verifiedEmail', 'taxExempt', 'tags', 'numberOfOrders', 'totalSpent { amount currencyCode }', 'defaultAddress { id address1 address2 city provinceCode countryCode zip phone }', 'addresses(first:5) { edges { node { id address1 city countryCode zip } } }'],
    int $pollingIntervalSeconds = 5,
    int $maxAttempts = 60
): array {
    $formattedCustomerFields = formatGqlFieldsForQuery($customerFields);
    if (empty($formattedCustomerFields)) {
        $formattedCustomerFields = 'id email firstName lastName';
    }

    $customersQueryArgument = '';
    if ($queryFilter !== null && trim($queryFilter) !== '') {
        $customersQueryArgument = sprintf('(query: "%s")', addslashes($queryFilter));
    }

    $bulkGqlQuery = sprintf(
        "query { customers%s { edges { node { %s } } } }",
        $customersQueryArgument,
        $formattedCustomerFields
    );

    $startResult = startBulkQuery($shopifyUrl, $accessToken, $apiVersion, $bulkGqlQuery);

    if ($startResult['status'] === 'error') {
        return $startResult;
    }

    if (!isset($startResult['data']['bulkOperationRunQuery']['bulkOperation']['id'])) {
        return [
            'status' => 'error',
            'message' => 'Failed to retrieve bulk operation ID after starting customer export.',
            'details' => $startResult['data']['bulkOperationRunQuery']['userErrors'] ?? $startResult['data'] ?? []
        ];
    }
    $bulkOperationId = $startResult['data']['bulkOperationRunQuery']['bulkOperation']['id'];

    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        sleep($pollingIntervalSeconds);
        $statusResult = getBulkOperationStatus($shopifyUrl, $accessToken, $apiVersion, $bulkOperationId);

        if ($statusResult['status'] === 'error') {
            return [
                'status' => 'error',
                'message' => 'Failed to get bulk customer operation status.',
                'details' => $statusResult['message'] ?? $statusResult['details'] ?? [],
                'bulkOperationId' => $bulkOperationId
            ];
        }
        
        $operationStatusNode = $statusResult['data']['node'] ?? null;
        if ($operationStatusNode === null) {
             return [
                'status' => 'error',
                'message' => 'Bulk operation status node is missing in the response for customer export.',
                'details' => ['bulkOperationId' => $bulkOperationId, 'rawStatusResponse' => $statusResult],
            ];
        }

        $operationStatus = $operationStatusNode['status'] ?? null;
        $errorCode = $operationStatusNode['errorCode'] ?? null;

        switch ($operationStatus) {
            case 'COMPLETED':
                $downloadUrl = $operationStatusNode['url'] ?? null;
                if (empty($downloadUrl)) {
                    return [
                        'status' => 'success',
                        'message' => 'Bulk customer export completed, but no data was found/generated (no download URL).',
                        'bulkOperationId' => $bulkOperationId
                    ];
                }
                $downloadResult = downloadBulkQueryResult($downloadUrl, $localFilePath);
                if ($downloadResult['status'] === 'success') {
                    return [
                        'status' => 'success',
                        'message' => 'Customers exported successfully to ' . $localFilePath,
                        'bulkOperationId' => $bulkOperationId,
                        'downloadPath' => $localFilePath,
                        'fileSize' => $operationStatusNode['fileSize'] ?? null
                    ];
                } else {
                    return [
                        'status' => 'error',
                        'message' => 'Failed to download bulk customer export result file.',
                        'details' => $downloadResult['message'] ?? [],
                        'bulkOperationId' => $bulkOperationId,
                        'downloadUrl' => $downloadUrl
                    ];
                }
            case 'FAILED':
                $partialDataUrl = $operationStatusNode['partialDataUrl'] ?? null;
                $errorMessage = 'Bulk customer export failed.';
                if ($partialDataUrl) {
                    $errorMessage .= " Partial data might be available at: {$partialDataUrl}";
                }
                return [
                    'status' => 'error',
                    'message' => $errorMessage,
                    'details' => ['errorCode' => $errorCode, 'bulkOperationId' => $bulkOperationId],
                    'partialDataUrl' => $partialDataUrl
                ];
            case 'CANCELLED':
                return [
                    'status' => 'error',
                    'message' => 'Bulk customer export was cancelled.',
                    'details' => ['bulkOperationId' => $bulkOperationId]
                ];
            case 'CREATED':
            case 'RUNNING':
                break;
            default:
                return [
                    'status' => 'error',
                    'message' => "Bulk customer export encountered an unexpected status: {$operationStatus}.",
                    'details' => ['bulkOperationId' => $bulkOperationId, 'statusDetails' => $operationStatusNode]
                ];
        }
    }

    return [
        'status' => 'error',
        'message' => "Bulk customer export timed out after {$maxAttempts} attempts.",
        'details' => ['bulkOperationId' => $bulkOperationId, 'lastStatus' => $operationStatus ?? 'UNKNOWN']
    ];
}

/**
 * Exports all orders (optionally filtered) to a local JSONL file using Shopify's bulk operations.
 *
 * @param string $shopifyUrl Your Shopify store URL.
 * @param string $accessToken Your Admin API access token.
 * @param string $apiVersion The Shopify API version.
 * @param string $localFilePath The full local path to save the downloaded JSONL file.
 * @param ?string $queryFilter Optional. A filter string for the orders query (e.g., "financial_status:paid AND created_at:>=YYYY-MM-DD").
 * @param array<string|array<mixed>> $orderFields The order fields to export.
 * @param int $pollingIntervalSeconds Interval in seconds to poll for bulk operation status.
 * @param int $maxAttempts Maximum polling attempts before timing out.
 * @return array<string, mixed> Result array with status, message, and potentially data or details.
 *
 * @example
 * // $filePath = __DIR__ . '/exported_orders.jsonl';
 * // $filter = "financial_status:paid AND created_at:>=2023-01-01T00:00:00Z";
 * // $fields = ['id', 'name', 'email', 'processedAt', 'totalPriceSet { shopMoney { amount currencyCode } }'];
 * // $exportResult = exportAllOrders(
 * //   $shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $filePath, $filter, $fields
 * // );
 * // if ($exportResult['status'] === 'success') {
 * //   // echo $exportResult['message'] . "\n";
 * // } else {
 * //   // echo "Order export failed: " . $exportResult['message'] . "\n";
 * // }
 */
function exportAllOrders(
    string $shopifyUrl,
    string $accessToken,
    string $apiVersion,
    string $localFilePath,
    ?string $queryFilter = null,
    array $orderFields = ['id', 'name', 'email', 'displayFinancialStatus', 'displayFulfillmentStatus', 'processedAt', 'createdAt', 'updatedAt', 'tags', 'totalPriceSet { shopMoney { amount currencyCode } }', 'subtotalPriceSet { shopMoney { amount currencyCode } }', 'totalTaxSet { shopMoney { amount currencyCode } }', 'customer { id firstName lastName email }', 'billingAddress { address1 city countryCode zip }', 'shippingAddress { address1 city countryCode zip }', 'lineItems(first: 10) { edges { node { id title quantity sku variant { id title } priceSet { shopMoney { amount currencyCode } } } } }'],
    int $pollingIntervalSeconds = 5,
    int $maxAttempts = 60
): array {
    $formattedOrderFields = formatGqlFieldsForQuery($orderFields);
    if (empty($formattedOrderFields)) {
        $formattedOrderFields = 'id name email totalPriceSet { shopMoney { amount currencyCode } }'; // Minimal fallback
    }

    $ordersQueryArgument = '';
    if ($queryFilter !== null && trim($queryFilter) !== '') {
        $ordersQueryArgument = sprintf('(query: "%s")', addslashes($queryFilter));
    }

    $bulkGqlQuery = sprintf(
        "query { orders%s { edges { node { %s } } } }",
        $ordersQueryArgument,
        $formattedOrderFields
    );

    $startResult = startBulkQuery($shopifyUrl, $accessToken, $apiVersion, $bulkGqlQuery);

    if ($startResult['status'] === 'error') {
        return $startResult;
    }

    if (!isset($startResult['data']['bulkOperationRunQuery']['bulkOperation']['id'])) {
        return [
            'status' => 'error',
            'message' => 'Failed to retrieve bulk operation ID after starting order export.',
            'details' => $startResult['data']['bulkOperationRunQuery']['userErrors'] ?? $startResult['data'] ?? []
        ];
    }
    $bulkOperationId = $startResult['data']['bulkOperationRunQuery']['bulkOperation']['id'];

    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        sleep($pollingIntervalSeconds);
        $statusResult = getBulkOperationStatus($shopifyUrl, $accessToken, $apiVersion, $bulkOperationId);

        if ($statusResult['status'] === 'error') {
            return [
                'status' => 'error',
                'message' => 'Failed to get bulk order operation status.',
                'details' => $statusResult['message'] ?? $statusResult['details'] ?? [],
                'bulkOperationId' => $bulkOperationId
            ];
        }
        
        $operationStatusNode = $statusResult['data']['node'] ?? null;
        if ($operationStatusNode === null) {
             return [
                'status' => 'error',
                'message' => 'Bulk operation status node is missing in the response for order export.',
                'details' => ['bulkOperationId' => $bulkOperationId, 'rawStatusResponse' => $statusResult],
            ];
        }

        $operationStatus = $operationStatusNode['status'] ?? null;
        $errorCode = $operationStatusNode['errorCode'] ?? null;

        switch ($operationStatus) {
            case 'COMPLETED':
                $downloadUrl = $operationStatusNode['url'] ?? null;
                if (empty($downloadUrl)) {
                    return [
                        'status' => 'success',
                        'message' => 'Bulk order export completed, but no data was found/generated (no download URL).',
                        'bulkOperationId' => $bulkOperationId
                    ];
                }
                $downloadResult = downloadBulkQueryResult($downloadUrl, $localFilePath);
                if ($downloadResult['status'] === 'success') {
                    return [
                        'status' => 'success',
                        'message' => 'Orders exported successfully to ' . $localFilePath,
                        'bulkOperationId' => $bulkOperationId,
                        'downloadPath' => $localFilePath,
                        'fileSize' => $operationStatusNode['fileSize'] ?? null
                    ];
                } else {
                    return [
                        'status' => 'error',
                        'message' => 'Failed to download bulk order export result file.',
                        'details' => $downloadResult['message'] ?? [],
                        'bulkOperationId' => $bulkOperationId,
                        'downloadUrl' => $downloadUrl
                    ];
                }
            case 'FAILED':
                $partialDataUrl = $operationStatusNode['partialDataUrl'] ?? null;
                $errorMessage = 'Bulk order export failed.';
                if ($partialDataUrl) {
                    $errorMessage .= " Partial data might be available at: {$partialDataUrl}";
                }
                return [
                    'status' => 'error',
                    'message' => $errorMessage,
                    'details' => ['errorCode' => $errorCode, 'bulkOperationId' => $bulkOperationId],
                    'partialDataUrl' => $partialDataUrl
                ];
            case 'CANCELLED':
                return [
                    'status' => 'error',
                    'message' => 'Bulk order export was cancelled.',
                    'details' => ['bulkOperationId' => $bulkOperationId]
                ];
            case 'CREATED':
            case 'RUNNING':
                // Continue polling
                break;
            default:
                return [
                    'status' => 'error',
                    'message' => "Bulk order export encountered an unexpected status: {$operationStatus}.",
                    'details' => ['bulkOperationId' => $bulkOperationId, 'statusDetails' => $operationStatusNode]
                ];
        }
    }

    return [
        'status' => 'error',
        'message' => "Bulk order export timed out after {$maxAttempts} attempts.",
        'details' => ['bulkOperationId' => $bulkOperationId, 'lastStatus' => $operationStatus ?? 'UNKNOWN']
    ];
}

?>
