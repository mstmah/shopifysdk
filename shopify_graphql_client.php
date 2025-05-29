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
/*
// --- DEMO USAGE for sendShopifyGraphQLRequest ---
// Note: Replace placeholder values with your actual Shopify store URL, access token, and API version.
// These examples assume $shopifyStoreUrl, $shopifyAccessToken, and $apiVersion are defined globally.

// $myQuery = 'query { shop { name currencyCode } }';
// $variables = []; // No variables for this simple query

// $result = sendShopifyGraphQLRequest($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $myQuery, $variables);

// if (isset($result['status'])) {
//   if ($result['status'] === 'success') {
//     echo "sendShopifyGraphQLRequest SUCCESS:
";
//     // print_r($result['data']); // Uncomment to see full data
//     // Example of accessing specific data:
//     if (isset($result['data']['shop']['name'])) {
//       echo "Shop Name: " . $result['data']['shop']['name'] . "
";
//       echo "Currency Code: " . $result['data']['shop']['currencyCode'] . "
";
//     }
//   } else {
//     echo "sendShopifyGraphQLRequest ERROR: " . $result['message'] . "
";
//     if (!empty($result['details'])) {
//       // echo "Details: 
";
//       // print_r($result['details']); // Uncomment for full error details
//     }
//   }
// } else {
//   echo "sendShopifyGraphQLRequest UNEXPECTED RESPONSE:
";
//   // print_r($result);
// }
// echo "---\n";
*/

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
// No demo block for internal function formatGqlFieldsForQuery

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
/*
// --- DEMO USAGE for getProduct ---
// Note: Replace placeholder values with your actual Shopify store URL, access token, API version, and a real Product GID.
// These examples assume $shopifyStoreUrl, $shopifyAccessToken, and $apiVersion are defined globally.

// $exampleProductId = 'gid://shopify/Product/0123456789123'; // Replace with a real Product GID from your store
// $customFields = ['id', 'title', 'handle', 'status', 'totalInventory', 'onlineStoreUrl'];

// $result = getProduct($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $exampleProductId, $customFields);

// if (isset($result['status'])) {
//   if ($result['status'] === 'success') {
//     echo "getProduct SUCCESS:
";
//     // print_r($result['data']); // Uncomment to see full data
//     if (isset($result['data']['product'])) {
//       echo "Product Title: " . ($result['data']['product']['title'] ?? 'N/A') . "
";
//       echo "Product Status: " . ($result['data']['product']['status'] ?? 'N/A') . "
";
//       echo "Product Inventory: " . ($result['data']['product']['totalInventory'] ?? 'N/A') . "
";
//     }
//   } else {
//     echo "getProduct ERROR: " . $result['message'] . "
";
//     if (!empty($result['details'])) {
//       // echo "Details: 
";
//       // print_r($result['details']); // Uncomment for full error details
//     }
//   }
// } else {
//   echo "getProduct UNEXPECTED RESPONSE:
";
//   // print_r($result);
// }
// echo "---\n";
*/

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
/*
// --- DEMO USAGE for createProduct ---
// Note: Replace placeholder values. Assumes $shopifyStoreUrl, $shopifyAccessToken, $apiVersion are defined.

// $newProductData = [
//   'title' => 'My Demo Product - ' . date('Y-m-d H:i:s'),
//   'bodyHtml' => '<p>This is a fantastic product created via API for demo purposes.</p>',
//   'vendor' => 'Demo Vendor',
//   'productType' => 'Demo Type',
//   'status' => 'DRAFT' // Or 'ACTIVE'
// ];

// $result = createProduct($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $newProductData);

// if (isset($result['status'])) {
//   if ($result['status'] === 'success') {
//     echo "createProduct SUCCESS:
";
//     // print_r($result['data']); // Uncomment to see full data
//     if (isset($result['data']['productCreate']['product']['id'])) {
//       echo "Created Product ID: " . $result['data']['productCreate']['product']['id'] . "
";
//       echo "Title: " . $result['data']['productCreate']['product']['title'] . "
";
//     } elseif (isset($result['data']['productCreate']['userErrors']) && count($result['data']['productCreate']['userErrors']) > 0) {
//        echo "createProduct USER ERRORS:
";
//        // print_r($result['data']['productCreate']['userErrors']);
//     }
//   } else {
//     echo "createProduct ERROR: " . $result['message'] . "
";
//     if (!empty($result['details'])) {
//       // echo "Details: 
";
//       // print_r($result['details']);
//     }
//   }
// } else {
//   echo "createProduct UNEXPECTED RESPONSE:
";
//   // print_r($result);
// }
// echo "---\n";
*/

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
/*
// --- DEMO USAGE for updateProduct ---
// Note: Replace placeholder values. Assumes $shopifyStoreUrl, $shopifyAccessToken, $apiVersion are defined.
// Important: You need a REAL product GID that exists in your store for this to work.

// $existingProductId = 'gid://shopify/Product/0123456789123'; // Replace with a real Product GID
// $productUpdateData = [
//   'id' => $existingProductId,
//   'title' => 'Updated Product Title - ' . date('Y-m-d H:i:s'),
//   'tags' => ['demo_update', 'php_client_test']
// ];

// $result = updateProduct($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $existingProductId, $productUpdateData);

// if (isset($result['status'])) {
//   if ($result['status'] === 'success') {
//     echo "updateProduct SUCCESS:
";
//     // print_r($result['data']); // Uncomment to see full data
//     if (isset($result['data']['productUpdate']['product']['id'])) {
//       echo "Updated Product ID: " . $result['data']['productUpdate']['product']['id'] . "
";
//       echo "New Title: " . $result['data']['productUpdate']['product']['title'] . "
";
//     } elseif (isset($result['data']['productUpdate']['userErrors']) && count($result['data']['productUpdate']['userErrors']) > 0) {
//        echo "updateProduct USER ERRORS:
";
//        // print_r($result['data']['productUpdate']['userErrors']);
//     }
//   } else {
//     echo "updateProduct ERROR: " . $result['message'] . "
";
//     if (!empty($result['details'])) {
//       // echo "Details: 
";
//       // print_r($result['details']);
//     }
//   }
// } else {
//   echo "updateProduct UNEXPECTED RESPONSE:
";
//   // print_r($result);
// }
// echo "---\n";
*/

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
/*
// --- DEMO USAGE for deleteProduct ---
// Note: Replace placeholder values. Assumes $shopifyStoreUrl, $shopifyAccessToken, $apiVersion are defined.
// WARNING: This will permanently delete the product. Use with caution, preferably with a test product GID.

// $productGidToDelete = 'gid://shopify/Product/0123456789123'; // Replace with a GID of a product you want to delete

// $result = deleteProduct($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $productGidToDelete);

// if (isset($result['status'])) {
//   if ($result['status'] === 'success') {
//     echo "deleteProduct SUCCESS:
";
//     // print_r($result['data']); // Uncomment to see full data
//     if (isset($result['data']['productDelete']['deletedProductId'])) {
//       echo "Deleted Product ID: " . $result['data']['productDelete']['deletedProductId'] . "
";
//     } elseif (isset($result['data']['productDelete']['userErrors']) && count($result['data']['productDelete']['userErrors']) > 0) {
//        echo "deleteProduct USER ERRORS:
";
//        // print_r($result['data']['productDelete']['userErrors']);
//     }
//   } else {
//     echo "deleteProduct ERROR: " . $result['message'] . "
";
//     if (!empty($result['details'])) {
//       // echo "Details: 
";
//       // print_r($result['details']);
//     }
//   }
// } else {
//   echo "deleteProduct UNEXPECTED RESPONSE:
";
//   // print_r($result);
// }
// echo "---\n";
*/

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
/*
// --- DEMO USAGE for getOrder ---
// Note: Replace placeholder values. Assumes $shopifyStoreUrl, $shopifyAccessToken, $apiVersion are defined.

// $exampleOrderId = 'gid://shopify/Order/0234567890123'; // Replace with a real Order GID from your store
// $customOrderFields = ['id', 'name', 'processedAt', 'totalPriceSet { shopMoney { amount currencyCode } }', 'customer { email }'];

// $result = getOrder($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $exampleOrderId, $customOrderFields);

// if (isset($result['status'])) {
//   if ($result['status'] === 'success') {
//     echo "getOrder SUCCESS:
";
//     // print_r($result['data']); // Uncomment to see full data
//     if (isset($result['data']['order'])) {
//       echo "Order Name: " . ($result['data']['order']['name'] ?? 'N/A') . "
";
//       echo "Order Total: " . ($result['data']['order']['totalPriceSet']['shopMoney']['amount'] ?? 'N/A') . " " . ($result['data']['order']['totalPriceSet']['shopMoney']['currencyCode'] ?? '') . "
";
//     }
//   } else {
//     echo "getOrder ERROR: " . $result['message'] . "
";
//     if (!empty($result['details'])) {
//       // echo "Details: 
";
//       // print_r($result['details']);
//     }
//   }
// } else {
//   echo "getOrder UNEXPECTED RESPONSE:
";
//   // print_r($result);
// }
// echo "---\n";
*/

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
/*
// --- DEMO USAGE for updateOrder ---
// Note: Replace placeholder values. Assumes $shopifyStoreUrl, $shopifyAccessToken, $apiVersion are defined.
// Important: You need a REAL order GID that exists in your store for this to work.

// $existingOrderId = 'gid://shopify/Order/0234567890123'; // Replace with a real Order GID
// $orderUpdateData = [
//   'id' => $existingOrderId,
//   'note' => 'Customer called to confirm shipping address. All good. - ' . date('Y-m-d H:i:s'),
//   'tags' => ['customer_contacted', 'address_verified']
// ];

// $result = updateOrder($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $existingOrderId, $orderUpdateData);

// if (isset($result['status'])) {
//   if ($result['status'] === 'success') {
//     echo "updateOrder SUCCESS:
";
//     // print_r($result['data']); // Uncomment to see full data
//     if (isset($result['data']['orderUpdate']['order']['id'])) {
//       echo "Updated Order ID: " . $result['data']['orderUpdate']['order']['id'] . "
";
//       echo "New Note: " . ($result['data']['orderUpdate']['order']['note'] ?? 'N/A') . "
";
//     } elseif (isset($result['data']['orderUpdate']['userErrors']) && count($result['data']['orderUpdate']['userErrors']) > 0) {
//        echo "updateOrder USER ERRORS:
";
//        // print_r($result['data']['orderUpdate']['userErrors']);
//     }
//   } else {
//     echo "updateOrder ERROR: " . $result['message'] . "
";
//     if (!empty($result['details'])) {
//       // echo "Details: 
";
//       // print_r($result['details']);
//     }
//   }
// } else {
//   echo "updateOrder UNEXPECTED RESPONSE:
";
//   // print_r($result);
// }
// echo "---\n";
*/

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
/*
// --- DEMO USAGE for cancelOrder ---
// Note: Replace placeholder values. Assumes $shopifyStoreUrl, $shopifyAccessToken, $apiVersion are defined.
// WARNING: This will attempt to cancel an order. Use with an order GID that can be cancelled.

// $orderGidToCancel = 'gid://shopify/Order/0234567890123'; // Replace with an order GID that can be cancelled
// $cancelReason = 'CUSTOMER_REQUEST'; // e.g., CUSTOMER_REQUEST, FRAUD, INVENTORY, OTHER
// $shouldRestock = true;
// $cancellationStaffNote = 'Customer requested cancellation due to changed mind.';
// $notifyCust = true;

// $result = cancelOrder($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $orderGidToCancel, $cancelReason, $shouldRestock, $cancellationStaffNote, $notifyCust);

// if (isset($result['status'])) {
//   if ($result['status'] === 'success') {
//     echo "cancelOrder SUCCESS:
";
//     // print_r($result['data']); // Uncomment to see full data
//     if (isset($result['data']['orderCancel']['order']['id'])) {
//       echo "Cancelled Order ID: " . $result['data']['orderCancel']['order']['id'] . "
";
//       echo "New Financial Status: " . ($result['data']['orderCancel']['order']['displayFinancialStatus'] ?? 'N/A') . "
";
//     } elseif (isset($result['data']['orderCancel']['userErrors']) && count($result['data']['orderCancel']['userErrors']) > 0) {
//        echo "cancelOrder USER ERRORS:
";
//        // print_r($result['data']['orderCancel']['userErrors']);
//     }
//   } else {
//     echo "cancelOrder ERROR: " . $result['message'] . "
";
//     if (!empty($result['details'])) {
//       // echo "Details: 
";
//       // print_r($result['details']);
//     }
//   }
// } else {
//   echo "cancelOrder UNEXPECTED RESPONSE:
";
//   // print_r($result);
// }
// echo "---\n";
*/

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
/*
// --- DEMO USAGE for getCustomer ---
// Note: Replace placeholder values. Assumes $shopifyStoreUrl, $shopifyAccessToken, $apiVersion are defined.

// $exampleCustomerId = 'gid://shopify/Customer/0345678901234'; // Replace with a real Customer GID
// $customCustomerFields = ['id', 'email', 'firstName', 'lastName', 'numberOfOrders', 'tags'];

// $result = getCustomer($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $exampleCustomerId, $customCustomerFields);

// if (isset($result['status'])) {
//   if ($result['status'] === 'success') {
//     echo "getCustomer SUCCESS:
";
//     // print_r($result['data']); // Uncomment to see full data
//     if (isset($result['data']['customer'])) {
//       echo "Customer Email: " . ($result['data']['customer']['email'] ?? 'N/A') . "
";
//       echo "Customer Name: " . ($result['data']['customer']['firstName'] ?? '') . " " . ($result['data']['customer']['lastName'] ?? '') . "
";
//     }
//   } else {
//     echo "getCustomer ERROR: " . $result['message'] . "
";
//     if (!empty($result['details'])) {
//       // echo "Details: 
";
//       // print_r($result['details']);
//     }
//   }
// } else {
//   echo "getCustomer UNEXPECTED RESPONSE:
";
//   // print_r($result);
// }
// echo "---\n";
*/

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
/*
// --- DEMO USAGE for createCustomer ---
// Note: Replace placeholder values. Assumes $shopifyStoreUrl, $shopifyAccessToken, $apiVersion are defined.

// $newCustomerData = [
//   'firstName' => 'Demo',
//   'lastName' => 'User-' . time(),
//   'email' => 'demo.user.' . time() . '@example.com',
//   'phone' => '+15550001122',
//   'acceptsMarketing' => false,
//   'tags' => ['php_client_demo', 'test_account']
// ];

// $result = createCustomer($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $newCustomerData);

// if (isset($result['status'])) {
//   if ($result['status'] === 'success') {
//     echo "createCustomer SUCCESS:
";
//     // print_r($result['data']); // Uncomment to see full data
//     if (isset($result['data']['customerCreate']['customer']['id'])) {
//       echo "Created Customer ID: " . $result['data']['customerCreate']['customer']['id'] . "
";
//       echo "Email: " . $result['data']['customerCreate']['customer']['email'] . "
";
//     } elseif (isset($result['data']['customerCreate']['userErrors']) && count($result['data']['customerCreate']['userErrors']) > 0) {
//        echo "createCustomer USER ERRORS:
";
//        // print_r($result['data']['customerCreate']['userErrors']);
//     }
//   } else {
//     echo "createCustomer ERROR: " . $result['message'] . "
";
//     if (!empty($result['details'])) {
//       // echo "Details: 
";
//       // print_r($result['details']);
//     }
//   }
// } else {
//   echo "createCustomer UNEXPECTED RESPONSE:
";
//   // print_r($result);
// }
// echo "---\n";
*/

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
/*
// --- DEMO USAGE for updateCustomer ---
// Note: Replace placeholder values. Assumes $shopifyStoreUrl, $shopifyAccessToken, $apiVersion are defined.
// Important: You need a REAL customer GID that exists in your store for this to work.

// $existingCustomerId = 'gid://shopify/Customer/0345678901234'; // Replace with a real Customer GID
// $customerUpdateData = [
//   'id' => $existingCustomerId,
//   'note' => 'Customer preference updated on ' . date('Y-m-d'),
//   'tags' => ['updated_via_api', 'priority_support']
// ];

// $result = updateCustomer($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $existingCustomerId, $customerUpdateData);

// if (isset($result['status'])) {
//   if ($result['status'] === 'success') {
//     echo "updateCustomer SUCCESS:
";
//     // print_r($result['data']); // Uncomment to see full data
//     if (isset($result['data']['customerUpdate']['customer']['id'])) {
//       echo "Updated Customer ID: " . $result['data']['customerUpdate']['customer']['id'] . "
";
//       echo "New Tags: " . implode(', ', $result['data']['customerUpdate']['customer']['tags'] ?? []) . "
";
//     } elseif (isset($result['data']['customerUpdate']['userErrors']) && count($result['data']['customerUpdate']['userErrors']) > 0) {
//        echo "updateCustomer USER ERRORS:
";
//        // print_r($result['data']['customerUpdate']['userErrors']);
//     }
//   } else {
//     echo "updateCustomer ERROR: " . $result['message'] . "
";
//     if (!empty($result['details'])) {
//       // echo "Details: 
";
//       // print_r($result['details']);
//     }
//   }
// } else {
//   echo "updateCustomer UNEXPECTED RESPONSE:
";
//   // print_r($result);
// }
// echo "---\n";
*/

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
/*
// --- DEMO USAGE for deleteCustomer ---
// Note: Replace placeholder values. Assumes $shopifyStoreUrl, $shopifyAccessToken, $apiVersion are defined.
// WARNING: This will permanently delete the customer. Use with caution, preferably with a test customer GID.

// $customerGidToDelete = 'gid://shopify/Customer/0345678901234'; // Replace with a GID of a customer you want to delete

// $result = deleteCustomer($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $customerGidToDelete);

// if (isset($result['status'])) {
//   if ($result['status'] === 'success') {
//     echo "deleteCustomer SUCCESS:
";
//     // print_r($result['data']); // Uncomment to see full data
//     if (isset($result['data']['customerDelete']['deletedCustomerId'])) {
//       echo "Deleted Customer ID: " . $result['data']['customerDelete']['deletedCustomerId'] . "
";
//     } elseif (isset($result['data']['customerDelete']['userErrors']) && count($result['data']['customerDelete']['userErrors']) > 0) {
//        echo "deleteCustomer USER ERRORS:
";
//        // print_r($result['data']['customerDelete']['userErrors']);
//     }
//   } else {
//     echo "deleteCustomer ERROR: " . $result['message'] . "
";
//     if (!empty($result['details'])) {
//       // echo "Details: 
";
//       // print_r($result['details']);
//     }
//   }
// } else {
//   echo "deleteCustomer UNEXPECTED RESPONSE:
";
//   // print_r($result);
// }
// echo "---\n";
*/

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
/*
// --- DEMO USAGE for getInventoryLevels ---
// Note: Replace placeholder values. Assumes $shopifyStoreUrl, $shopifyAccessToken, $apiVersion are defined.

// $exampleInventoryItemId = 'gid://shopify/InventoryItem/0456789012345'; // Replace with a real InventoryItem GID

// $result = getInventoryLevels($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $exampleInventoryItemId);

// if (isset($result['status'])) {
//   if ($result['status'] === 'success') {
//     echo "getInventoryLevels SUCCESS:
";
//     // print_r($result['data']); // Uncomment to see full data
//     if (isset($result['data']['inventoryItem']['inventoryLevels']['edges'])) {
//       echo "Inventory Levels for Item SKU: " . ($result['data']['inventoryItem']['sku'] ?? 'N/A') . "
";
//       foreach ($result['data']['inventoryItem']['inventoryLevels']['edges'] as $edge) {
//         // echo "Location: " . ($edge['node']['location']['name'] ?? 'N/A') . ", Available: " . ($edge['node']['available'] ?? 'N/A') . "
";
//       }
//     }
//   } else {
//     echo "getInventoryLevels ERROR: " . $result['message'] . "
";
//     if (!empty($result['details'])) {
//       // echo "Details: 
";
//       // print_r($result['details']);
//     }
//   }
// } else {
//   echo "getInventoryLevels UNEXPECTED RESPONSE:
";
//   // print_r($result);
// }
// echo "---\n";
*/

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
/*
// --- DEMO USAGE for adjustInventoryLevel ---
// Note: Replace placeholder values. Assumes $shopifyStoreUrl, $shopifyAccessToken, $apiVersion are defined.
// Important: You need a REAL InventoryLevel GID for this to work.

// $inventoryLevelGid = 'gid://shopify/InventoryLevel/0567890123456?inventory_item_id=0456789012345'; // Replace with a real InventoryLevel GID
// $quantityChange = -2; // Decrease quantity by 2. Use positive for increase.

// $result = adjustInventoryLevel($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $inventoryLevelGid, $quantityChange);

// if (isset($result['status'])) {
//   if ($result['status'] === 'success') {
//     echo "adjustInventoryLevel SUCCESS:
";
//     // print_r($result['data']); // Uncomment to see full data
//     if (isset($result['data']['inventoryAdjustQuantity']['inventoryLevel'])) {
//       echo "Inventory Level ID: " . $result['data']['inventoryAdjustQuantity']['inventoryLevel']['id'] . "
";
//       echo "New Available Quantity: " . ($result['data']['inventoryAdjustQuantity']['inventoryLevel']['available'] ?? 'N/A') . "
";
//     } elseif (isset($result['data']['inventoryAdjustQuantity']['userErrors']) && count($result['data']['inventoryAdjustQuantity']['userErrors']) > 0) {
//        echo "adjustInventoryLevel USER ERRORS:
";
//        // print_r($result['data']['inventoryAdjustQuantity']['userErrors']);
//     }
//   } else {
//     echo "adjustInventoryLevel ERROR: " . $result['message'] . "
";
//     if (!empty($result['details'])) {
//       // echo "Details: 
";
//       // print_r($result['details']);
//     }
//   }
// } else {
//   echo "adjustInventoryLevel UNEXPECTED RESPONSE:
";
//   // print_r($result);
// }
// echo "---\n";
*/

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
/*
// --- DEMO USAGE for getCollection ---
// Note: Replace placeholder values. Assumes $shopifyStoreUrl, $shopifyAccessToken, $apiVersion are defined.

// $exampleCollectionId = 'gid://shopify/Collection/0678901234567'; // Replace with a real Collection GID
// $customCollectionFields = ['id', 'title', 'handle', 'descriptionHtml', 'productsCount'];

// $result = getCollection($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $exampleCollectionId, $customCollectionFields);

// if (isset($result['status'])) {
//   if ($result['status'] === 'success') {
//     echo "getCollection SUCCESS:
";
//     // print_r($result['data']); // Uncomment to see full data
//     if (isset($result['data']['collection'])) {
//       echo "Collection Title: " . ($result['data']['collection']['title'] ?? 'N/A') . "
";
//       echo "Products Count: " . ($result['data']['collection']['productsCount'] ?? 'N/A') . "
";
//     }
//   } else {
//     echo "getCollection ERROR: " . $result['message'] . "
";
//     if (!empty($result['details'])) {
//       // echo "Details: 
";
//       // print_r($result['details']);
//     }
//   }
// } else {
//   echo "getCollection UNEXPECTED RESPONSE:
";
//   // print_r($result);
// }
// echo "---\n";
*/

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
/*
// --- DEMO USAGE for createCollection ---
// Note: Replace placeholder values. Assumes $shopifyStoreUrl, $shopifyAccessToken, $apiVersion are defined.

// // Example for a Custom Collection:
// $customCollectionData = [
//   'title' => 'My Custom Collection - ' . time(),
//   'descriptionHtml' => 'A collection of hand-picked items for demo.'
// ];
// $resultCustom = createCollection($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $customCollectionData);
// echo "--- Custom Collection Creation Attempt ---
";
// if (isset($resultCustom['status'])) {
//   if ($resultCustom['status'] === 'success' && isset($resultCustom['data']['collectionCreate']['collection'])) {
//     echo "createCollection (Custom) SUCCESS:
";
//     // print_r($resultCustom['data']['collectionCreate']['collection']);
//     echo "Created Collection ID: " . $resultCustom['data']['collectionCreate']['collection']['id'] . "
";
//   } elseif (isset($resultCustom['data']['collectionCreate']['userErrors']) && count($resultCustom['data']['collectionCreate']['userErrors']) > 0) {
//     echo "createCollection (Custom) USER ERRORS:
";
//     // print_r($resultCustom['data']['collectionCreate']['userErrors']);
//   } else {
//     echo "createCollection (Custom) ERROR: " . $resultCustom['message'] . "
";
//   }
// } else { echo "createCollection (Custom) UNEXPECTED RESPONSE
"; }

// // Example for a Smart (Automated) Collection:
// $smartCollectionData = [
//   'title' => 'Products Under $50 - ' . time(),
//   'ruleSet' => [
//     'appliedDisjunctively' => false,
//     'rules' => [
//       [ 'column' => 'VARIANT_PRICE', 'relation' => 'LESS_THAN', 'condition' => '50.00' ],
//       // [ 'column' => 'TAG', 'relation' => 'EQUALS', 'condition' => 'sale' ] // Example of another rule
//     ]
//   ]
// ];
// $resultSmart = createCollection($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $smartCollectionData);
// echo "--- Smart Collection Creation Attempt ---
";
// if (isset($resultSmart['status'])) {
//   if ($resultSmart['status'] === 'success' && isset($resultSmart['data']['collectionCreate']['collection'])) {
//     echo "createCollection (Smart) SUCCESS:
";
//     // print_r($resultSmart['data']['collectionCreate']['collection']);
//     echo "Created Collection ID: " . $resultSmart['data']['collectionCreate']['collection']['id'] . "
";
//   } elseif (isset($resultSmart['data']['collectionCreate']['userErrors']) && count($resultSmart['data']['collectionCreate']['userErrors']) > 0) {
//     echo "createCollection (Smart) USER ERRORS:
";
//     // print_r($resultSmart['data']['collectionCreate']['userErrors']);
//   } else {
//     echo "createCollection (Smart) ERROR: " . $resultSmart['message'] . "
";
//   }
// } else { echo "createCollection (Smart) UNEXPECTED RESPONSE
"; }
// echo "---\n";
*/

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
/*
// --- DEMO USAGE for updateCollection ---
// Note: Replace placeholder values. Assumes $shopifyStoreUrl, $shopifyAccessToken, $apiVersion are defined.
// Important: You need a REAL collection GID that exists in your store for this to work.

// $existingCollectionId = 'gid://shopify/Collection/0678901234567'; // Replace with a real Collection GID
// $collectionUpdateData = [
//   'id' => $existingCollectionId,
//   'title' => 'Updated Collection Title - ' . date('Y-m-d H:i:s'),
//   'descriptionHtml' => '<p>This collection description has been updated via API.</p>'
//   // For smart collections, you might update ruleSet here if needed.
// ];

// $result = updateCollection($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $existingCollectionId, $collectionUpdateData);

// if (isset($result['status'])) {
//   if ($result['status'] === 'success') {
//     echo "updateCollection SUCCESS:
";
//     // print_r($result['data']); // Uncomment to see full data
//     if (isset($result['data']['collectionUpdate']['collection']['id'])) {
//       echo "Updated Collection ID: " . $result['data']['collectionUpdate']['collection']['id'] . "
";
//       echo "New Title: " . ($result['data']['collectionUpdate']['collection']['title'] ?? 'N/A') . "
";
//     } elseif (isset($result['data']['collectionUpdate']['userErrors']) && count($result['data']['collectionUpdate']['userErrors']) > 0) {
//        echo "updateCollection USER ERRORS:
";
//        // print_r($result['data']['collectionUpdate']['userErrors']);
//     }
//   } else {
//     echo "updateCollection ERROR: " . $result['message'] . "
";
//     if (!empty($result['details'])) {
//       // echo "Details: 
";
//       // print_r($result['details']);
//     }
//   }
// } else {
//   echo "updateCollection UNEXPECTED RESPONSE:
";
//   // print_r($result);
// }
// echo "---\n";
*/

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
/*
// --- DEMO USAGE for deleteCollection ---
// Note: Replace placeholder values. Assumes $shopifyStoreUrl, $shopifyAccessToken, $apiVersion are defined.
// WARNING: This will permanently delete the collection. Use with caution, preferably with a test collection GID.

// $collectionGidToDelete = 'gid://shopify/Collection/0678901234567'; // Replace with a GID of a collection you want to delete

// $result = deleteCollection($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $collectionGidToDelete);

// if (isset($result['status'])) {
//   if ($result['status'] === 'success') {
//     echo "deleteCollection SUCCESS:
";
//     // print_r($result['data']); // Uncomment to see full data
//     if (isset($result['data']['collectionDelete']['deletedCollectionId'])) {
//       echo "Deleted Collection ID: " . $result['data']['collectionDelete']['deletedCollectionId'] . "
";
//     } elseif (isset($result['data']['collectionDelete']['userErrors']) && count($result['data']['collectionDelete']['userErrors']) > 0) {
//        echo "deleteCollection USER ERRORS:
";
//        // print_r($result['data']['collectionDelete']['userErrors']);
//     }
//   } else {
//     echo "deleteCollection ERROR: " . $result['message'] . "
";
//     if (!empty($result['details'])) {
//       // echo "Details: 
";
//       // print_r($result['details']);
//     }
//   }
// } else {
//   echo "deleteCollection UNEXPECTED RESPONSE:
";
//   // print_r($result);
// }
// echo "---\n";
*/

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
/*
// --- DEMO USAGE for addProductToCollection ---
// Note: Replace placeholder values. Assumes $shopifyStoreUrl, $shopifyAccessToken, $apiVersion are defined.
// This operation is for Custom Collections. Smart Collections are managed by rules.

// $targetCollectionId = 'gid://shopify/Collection/0678901234567'; // Replace with a real Custom Collection GID
// $productToAddGid = 'gid://shopify/Product/0123456789123';   // Replace with a real Product GID

// $result = addProductToCollection($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $targetCollectionId, $productToAddGid);

// if (isset($result['status'])) {
//   if ($result['status'] === 'success') {
//     echo "addProductToCollection SUCCESS:
";
//     // print_r($result['data']); // Uncomment to see full data
//     if (isset($result['data']['collectionAddProducts']['collection'])) {
//       echo "Collection '" . ($result['data']['collectionAddProducts']['collection']['title'] ?? 'N/A') . "' now has " . ($result['data']['collectionAddProducts']['collection']['productCount'] ?? 'N/A') . " products.
";
//     } elseif (isset($result['data']['collectionAddProducts']['userErrors']) && count($result['data']['collectionAddProducts']['userErrors']) > 0) {
//        echo "addProductToCollection USER ERRORS:
";
//        // print_r($result['data']['collectionAddProducts']['userErrors']);
//     }
//   } else {
//     echo "addProductToCollection ERROR: " . $result['message'] . "
";
//     if (!empty($result['details'])) {
//       // echo "Details: 
";
//       // print_r($result['details']);
//     }
//   }
// } else {
//   echo "addProductToCollection UNEXPECTED RESPONSE:
";
//   // print_r($result);
// }
// echo "---\n";
*/

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
/*
// --- DEMO USAGE for removeProductFromCollection ---
// Note: Replace placeholder values. Assumes $shopifyStoreUrl, $shopifyAccessToken, $apiVersion are defined.
// This operation is for Custom Collections.

// $sourceCollectionId = 'gid://shopify/Collection/0678901234567'; // Replace with a real Custom Collection GID
// $productToRemoveGid = 'gid://shopify/Product/0123456789123';  // Replace with a real Product GID currently in the collection

// $result = removeProductFromCollection($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $sourceCollectionId, $productToRemoveGid);

// if (isset($result['status'])) {
//   if ($result['status'] === 'success') {
//     echo "removeProductFromCollection SUCCESS:
";
//     // print_r($result['data']); // Uncomment to see full data
//     if (isset($result['data']['collectionRemoveProducts']['collection'])) {
//       echo "Collection '" . ($result['data']['collectionRemoveProducts']['collection']['title'] ?? 'N/A') . "' now has " . ($result['data']['collectionRemoveProducts']['collection']['productCount'] ?? 'N/A') . " products.
";
//     } elseif (isset($result['data']['collectionRemoveProducts']['userErrors']) && count($result['data']['collectionRemoveProducts']['userErrors']) > 0) {
//        echo "removeProductFromCollection USER ERRORS:
";
//        // print_r($result['data']['collectionRemoveProducts']['userErrors']);
//     }
//   } else {
//     echo "removeProductFromCollection ERROR: " . $result['message'] . "
";
//     if (!empty($result['details'])) {
//       // echo "Details: 
";
//       // print_r($result['details']);
//     }
//   }
// } else {
//   echo "removeProductFromCollection UNEXPECTED RESPONSE:
";
//   // print_r($result);
// }
// echo "---\n";
*/

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
/*
// --- DEMO USAGE for startBulkQuery ---
// Note: Replace placeholder values. Assumes $shopifyStoreUrl, $shopifyAccessToken, $apiVersion are defined.

// $myBulkGraphqlQuery = <<<GRAPHQL
// {
//   products(first: 100, query: "status:active") {
//     edges {
//       node {
//         id
//         title
//         handle
//         status
//         variants(first: 5) {
//           edges {
//             node {
//               id
//               sku
//               price
//             }
//           }
//         }
//       }
//     }
//   }
// }
// GRAPHQL;

// $result = startBulkQuery($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $myBulkGraphqlQuery);

// if (isset($result['status'])) {
//   if ($result['status'] === 'success') {
//     echo "startBulkQuery SUCCESS:
";
//     // print_r($result['data']); // Uncomment to see full data
//     if (isset($result['data']['bulkOperationRunQuery']['bulkOperation'])) {
//       $op = $result['data']['bulkOperationRunQuery']['bulkOperation'];
//       echo "Bulk Operation ID: " . ($op['id'] ?? 'N/A') . "
";
//       echo "Status: " . ($op['status'] ?? 'N/A') . "
";
//     } elseif (isset($result['data']['bulkOperationRunQuery']['userErrors']) && count($result['data']['bulkOperationRunQuery']['userErrors']) > 0) {
//        echo "startBulkQuery USER ERRORS:
";
//        // print_r($result['data']['bulkOperationRunQuery']['userErrors']);
//     }
//   } else {
//     echo "startBulkQuery ERROR: " . $result['message'] . "
";
//     if (!empty($result['details'])) {
//       // echo "Details: 
";
//       // print_r($result['details']);
//     }
//   }
// } else {
//   echo "startBulkQuery UNEXPECTED RESPONSE:
";
//   // print_r($result);
// }
// echo "---\n";
*/

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
/*
// --- DEMO USAGE for getBulkOperationStatus ---
// Note: Replace placeholder values. Assumes $shopifyStoreUrl, $shopifyAccessToken, $apiVersion are defined.
// You need a real BulkOperation GID, typically obtained from startBulkQuery.

// $bulkOpId = 'gid://shopify/BulkOperation/0789012345678'; // Replace with a real BulkOperation GID

// $result = getBulkOperationStatus($shopifyStoreUrl, $shopifyAccessToken, $apiVersion, $bulkOpId);

// if (isset($result['status'])) {
//   if ($result['status'] === 'success') {
//     echo "getBulkOperationStatus SUCCESS:
";
//     // print_r($result['data']); // Uncomment to see full data
//     if (isset($result['data']['node'])) {
//       $opStatus = $result['data']['node'];
//       echo "Operation ID: " . ($opStatus['id'] ?? 'N/A') . "
";
//       echo "Current Status: " . ($opStatus['status'] ?? 'N/A') . "
";
//       if (($opStatus['status'] ?? '') === 'COMPLETED') {
//         echo "Download URL: " . ($opStatus['url'] ?? 'Not available') . "
";
//       } elseif (($opStatus['status'] ?? '') === 'FAILED') {
//         echo "Error Code: " . ($opStatus['errorCode'] ?? 'N/A') . "
";
//       }
//     }
//   } else {
//     echo "getBulkOperationStatus ERROR: " . $result['message'] . "
";
//     if (!empty($result['details'])) {
//       // echo "Details: 
";
//       // print_r($result['details']);
//     }
//   }
// } else {
//   echo "getBulkOperationStatus UNEXPECTED RESPONSE:
";
//   // print_r($result);
// }
// echo "---\n";
*/

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
/*
// --- DEMO USAGE for downloadBulkQueryResult ---
// Note: Replace placeholder values. Assumes $shopifyStoreUrl, $shopifyAccessToken, $apiVersion are defined.
// You need a real download URL from a COMPLETED bulk operation.

// $resultFileUrl = 'https://shopify-typed-node-api.s3.amazonaws.com/SOME_VERY_LONG_AND_UNIQUE_URL_PATH_TO_FILE.jsonl?X-Amz-Algorithm=...'; // Replace with actual URL
// $localSavePath = __DIR__ . '/my_downloaded_results.jsonl'; // Ensure this directory is writable

// $result = downloadBulkQueryResult($resultFileUrl, $localSavePath);

// if (isset($result['status'])) {
//   if ($result['status'] === 'success') {
//     echo "downloadBulkQueryResult SUCCESS: " . $result['message'] . "
";
//   } else {
//     echo "downloadBulkQueryResult ERROR: " . $result['message'] . "
";
//   }
// } else {
//   echo "downloadBulkQueryResult UNEXPECTED RESPONSE:
";
//   // print_r($result);
// }
// echo "---\n";
*/

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
/*
// --- DEMO USAGE for exportAllProducts ---
// Note: Replace placeholder values. Assumes $shopifyStoreUrl, $shopifyAccessToken, $apiVersion are defined.

// $productsExportPath = __DIR__ . '/all_my_products_export.jsonl'; // Ensure this directory is writable
// $productExportFilter = "status:active AND published_status:published"; // Example filter
// $productExportFields = ['id', 'title', 'handle', 'vendor', 'status', 'tags'];

// $result = exportAllProducts(
//   $shopifyStoreUrl,
//   $shopifyAccessToken,
//   $apiVersion,
//   $productsExportPath,
//   $productExportFilter,
//   $productExportFields,
//   10, // polling interval: 10 seconds
//   30  // max attempts: 30 (5 minutes total)
// );

// if (isset($result['status'])) {
//   if ($result['status'] === 'success') {
//     echo "exportAllProducts SUCCESS: " . $result['message'] . "
";
//     if (isset($result['downloadPath'])) {
//       echo "Exported file: " . $result['downloadPath'] . "
";
//       echo "File size: " . ($result['fileSize'] ?? 'N/A') . " bytes
";
//     }
//     echo "Bulk Operation ID: " . ($result['bulkOperationId'] ?? 'N/A') . "
";
//   } else {
//     echo "exportAllProducts ERROR: " . $result['message'] . "
";
//     if (!empty($result['details'])) {
//       // echo "Details: 
";
//       // print_r($result['details']);
//     }
//   }
// } else {
//   echo "exportAllProducts UNEXPECTED RESPONSE:
";
//   // print_r($result);
// }
// echo "---\n";
*/

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
/*
// --- DEMO USAGE for exportAllCustomers ---
// Note: Replace placeholder values. Assumes $shopifyStoreUrl, $shopifyAccessToken, $apiVersion are defined.

// $customersExportPath = __DIR__ . '/all_my_customers_export.jsonl'; // Ensure this directory is writable
// $customerExportFilter = "email_marketing_consent: { consent_state: SUBSCRIBED }"; // Example: customers who accept marketing
// $customerExportFields = ['id', 'firstName', 'lastName', 'email', 'phone', 'acceptsMarketing', 'tags', 'ordersCount'];

// $result = exportAllCustomers(
//   $shopifyStoreUrl,
//   $shopifyAccessToken,
//   $apiVersion,
//   $customersExportPath,
//   $customerExportFilter,
//   $customerExportFields,
//   8, // polling interval
//   40  // max attempts
// );

// if (isset($result['status'])) {
//   if ($result['status'] === 'success') {
//     echo "exportAllCustomers SUCCESS: " . $result['message'] . "
";
//     if (isset($result['downloadPath'])) {
//       echo "Exported file: " . $result['downloadPath'] . "
";
//       echo "File size: " . ($result['fileSize'] ?? 'N/A') . " bytes
";
//     }
//     echo "Bulk Operation ID: " . ($result['bulkOperationId'] ?? 'N/A') . "
";
//   } else {
//     echo "exportAllCustomers ERROR: " . $result['message'] . "
";
//     if (!empty($result['details'])) {
//       // echo "Details: 
";
//       // print_r($result['details']);
//     }
//   }
// } else {
//   echo "exportAllCustomers UNEXPECTED RESPONSE:
";
//   // print_r($result);
// }
// echo "---\n";
*/

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
/*
// --- DEMO USAGE for exportAllOrders ---
// Note: Replace placeholder values. Assumes $shopifyStoreUrl, $shopifyAccessToken, $apiVersion are defined.

// $ordersExportPath = __DIR__ . '/all_my_orders_export.jsonl'; // Ensure this directory is writable
// $orderExportFilter = "created_at:>=2023-01-01 AND financial_status:paid"; // Example filter
// $orderExportFields = ['id', 'name', 'email', 'processedAt', 'totalPriceSet { shopMoney { amount currencyCode } }', 'customer { id email }', 'lineItems(first:3){edges{node{title quantity}}}'];

// $result = exportAllOrders(
//   $shopifyStoreUrl,
//   $shopifyAccessToken,
//   $apiVersion,
//   $ordersExportPath,
//   $orderExportFilter,
//   $orderExportFields,
//   15, // polling interval: 15 seconds
//   40  // max attempts: 40 (10 minutes total)
// );

// if (isset($result['status'])) {
//   if ($result['status'] === 'success') {
//     echo "exportAllOrders SUCCESS: " . $result['message'] . "
";
//     if (isset($result['downloadPath'])) {
//       echo "Exported file: " . $result['downloadPath'] . "
";
//       echo "File size: " . ($result['fileSize'] ?? 'N/A') . " bytes
";
//     }
//     echo "Bulk Operation ID: " . ($result['bulkOperationId'] ?? 'N/A') . "
";
//   } else {
//     echo "exportAllOrders ERROR: " . $result['message'] . "
";
//     if (!empty($result['details'])) {
//       // echo "Details: 
";
//       // print_r($result['details']);
//     }
//   }
// } else {
//   echo "exportAllOrders UNEXPECTED RESPONSE:
";
//   // print_r($result);
// }
// echo "---\n";
*/

/**
 * Calculates the Cartesian product of multiple arrays.
 * Helper function for generating variant combinations.
 *
 * @param array<array<string|int|float>> $arrays An array of arrays, where each inner array contains option values.
 * @return array<array<string|int|float>> An array of arrays, where each inner array is a combination of option values.
 */
function _shopify_calculate_cartesian_product(array $arrays): array
{
    if (empty($arrays)) {
        return [[]];
    }

    $result = [[]];
    foreach ($arrays as $key => $values) {
        if (empty($values)) { // If any option set is empty, the cartesian product is empty.
            return [];
        }
        $append = [];
        foreach ($result as $product) {
            foreach ($values as $item) {
                $product[$key] = $item; // Use key to maintain order of options
                $append[] = $product;
            }
        }
        $result = $append;
    }

    // Ensure the option order in combinations matches the input option order
    // This is implicitly handled by iterating through $arrays with its original keys
    // and assigning to $product[$key].
    return $result;
}

/**
 * Creates a new product with multiple options and automatically generated variants.
 *
 * @param string $shopifyUrl The Shopify store URL.
 * @param string $accessToken The Admin API access token.
 * @param string $apiVersion The API version.
 * @param string $title Product title.
 * @param string $bodyHtml Product description (HTML).
 * @param string $vendor Product vendor.
 * @param array<string, array<string>> $optionsInput Associative array where keys are option names (e.g., "Size")
 *                                                 and values are arrays of option values (e.g., ["Small", "Medium"]). Max 3 options.
 * @param ?array<string, array<string, mixed>> $variantOverrides Optional. Associative array to override default variant properties.
 *                                           Keys are combination strings (e.g., "Small / Red").
 *                                           Values are arrays with keys like 'price', 'sku', 'inventoryPolicy', 'inventoryQuantities'.
 * @param string $status Product status (DRAFT, ACTIVE, ARCHIVED). Defaults to 'DRAFT'.
 * @param string $defaultPrice Default price for variants if not overridden.
 * @param string $defaultSkuPrefix Default SKU prefix for auto-generated SKUs.
 * @return array<string, mixed> Result from sendShopifyGraphQLRequest, containing product data or userErrors.
 *
 * @example
 * // $options = [
 * //     'Size' => ['Small', 'Medium', 'Large'],
 * //     'Color' => ['Red', 'Blue']
 * // ];
 * // $overrides = [
 * //     'Small / Red' => ['price' => '25.00', 'sku' => 'TS-SM-RD', 'inventoryQuantities' => [['availableQuantity' => 10, 'locationId' => 'gid://shopify/Location/YOUR_LOCATION_ID']]],
 * //     'Large / Blue' => ['price' => '30.00', 'inventoryPolicy' => 'CONTINUE']
 * // ];
 * // $result = createProductWithOptionsAndVariants(
 * //     $shopifyStoreUrl, $shopifyAccessToken, $apiVersion,
 * //     'Awesome T-Shirt with Options', '<p>Super comfy!</p>', 'My Brand',
 * //     $options, $overrides, 'ACTIVE', '22.50', 'TSHIRT'
 * // );
 * // if ($result['status'] === 'success' && isset($result['data']['productCreate']['product'])) {
 * //   // echo "Product created successfully: " . $result['data']['productCreate']['product']['id'] . "
";
 * //   // print_r($result['data']['productCreate']['product']['variants']);
 * // } else {
 * //   // echo "Error: " . ($result['message'] ?? 'Unknown error') . "
";
 * //   // if(isset($result['details'])) print_r($result['details']);
 * // }
 */
function createProductWithOptionsAndVariants(
    string $shopifyUrl,
    string $accessToken,
    string $apiVersion,
    string $title,
    string $bodyHtml,
    string $vendor,
    array $optionsInput,
    ?array $variantOverrides = null,
    string $status = 'DRAFT',
    string $defaultPrice = '10.00',
    string $defaultSkuPrefix = 'SKU'
): array {
    $optionCount = count($optionsInput);
    if ($optionCount < 1 || $optionCount > 3) {
        return ['status' => 'error', 'message' => 'Product must have 1 to 3 options.'];
    }

    $optionNames = array_keys($optionsInput);
    $optionValueArrays = [];
    foreach ($optionsInput as $name => $values) {
        if (empty($values)) {
            return ['status' => 'error', 'message' => "Option '{$name}' must have at least one value."];
        }
        $optionValueArrays[] = $values;
    }

    $variantCombinations = _shopify_calculate_cartesian_product($optionValueArrays);

    if (empty($variantCombinations) && $optionCount > 0) {
         return ['status' => 'error', 'message' => 'No variant combinations could be generated from the provided options. Ensure option value arrays are not empty.'];
    }


    $variantsInput = [];
    foreach ($variantCombinations as $combination) {
        $variantInput = ['options' => $combination];
        $overrideKey = implode(' / ', $combination);

        $overrideData = $variantOverrides[$overrideKey] ?? [];

        $variantInput['price'] = $overrideData['price'] ?? $defaultPrice;
        
        $skuValueParts = array_map(fn($val) => preg_replace('/[^a-zA-Z0-9]+/', '-', (string)$val), $combination);
        $variantInput['sku'] = $overrideData['sku'] ?? strtoupper($defaultSkuPrefix . '-' . implode('-', $skuValueParts));
        
        $variantInput['inventoryPolicy'] = $overrideData['inventoryPolicy'] ?? 'DENY'; // DENY or CONTINUE

        if (isset($overrideData['inventoryQuantities'])) {
            $variantInput['inventoryQuantities'] = $overrideData['inventoryQuantities'];
        } elseif (isset($overrideData['availableQuantity']) && isset($overrideData['locationId'])) {
            // Simplified override for single location quantity
            $variantInput['inventoryQuantities'] = [
                ['availableQuantity' => (int)$overrideData['availableQuantity'], 'locationId' => (string)$overrideData['locationId']]
            ];
        }


        $variantsInput[] = $variantInput;
    }

    $productInputForMutation = [
        'title' => $title,
        'bodyHtml' => $bodyHtml,
        'vendor' => $vendor,
        'status' => $status,
        'options' => $optionNames,
        'variants' => $variantsInput,
    ];

    $mutation = <<<GRAPHQL
    mutation productCreate(\$input: ProductInput!) {
      productCreate(input: \$input) {
        product {
          id
          title
          handle
          status
          options {
            name
            values
          }
          variants(first: 250) { # Fetch up to 250 variants
            edges {
              node {
                id
                title
                sku
                price
                availableForSale
                inventoryQuantity
                selectedOptions {
                  name
                  value
                }
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

    return sendShopifyGraphQLRequest($shopifyUrl, $accessToken, $apiVersion, $mutation, ['input' => $productInputForMutation]);
}
/*
// --- DEMO USAGE for createProductWithOptionsAndVariants ---
// Note: Replace placeholder values. Assumes $shopifyStoreUrl, $shopifyAccessToken, $apiVersion are defined globally.
// You'll need a valid $exampleLocationGid if using inventoryQuantities.

// // Example 1: Product with Size and Color options
// $optionsInput1 = [
//     'Size' => ['Small', 'Medium'],
//     'Color' => ['Black', 'White']
// ];
// $variantOverrides1 = [
//     'Small / Black' => ['price' => '25.00', 'sku' => 'SB001'],
//     'Medium / White' => ['price' => '27.00', 'sku' => 'MW001']
// ];
// $result1 = createProductWithOptionsAndVariants(
//     $shopifyStoreUrl,
//     $shopifyAccessToken,
//     $apiVersion,
//     'Demo T-Shirt - ' . date('Y-m-d H:i:s'),
//     '<p>Comfortable demo t-shirt with options.</p>',
//     'Demo Brand',
//     $optionsInput1,
//     $variantOverrides1,
//     'ACTIVE', // status
//     '22.00',  // defaultPrice
//     'DEMO-TS' // defaultSkuPrefix
// );

// echo "Result for Product with 2 Options:
";
// if (isset($result1['status'])) {
//   if ($result1['status'] === 'success') {
//     echo "createProductWithOptionsAndVariants SUCCESS:
";
//     // print_r($result1['data']); // Uncomment for full data
//     if(isset($result1['data']['productCreate']['product']['id'])) {
//        echo "Created Product ID: " . $result1['data']['productCreate']['product']['id'] . "
";
//     } else if (!empty($result1['data']['productCreate']['userErrors'])) {
//        echo "User Errors: 
";
//        // print_r($result1['data']['productCreate']['userErrors']);
//     }
//   } else {
//     echo "createProductWithOptionsAndVariants ERROR: " . $result1['message'] . "
";
//     // if (!empty($result1['details'])) print_r($result1['details']);
//   }
// } else {
//   echo "createProductWithOptionsAndVariants UNEXPECTED RESPONSE:
";
//   // print_r($result1);
// }
// echo "---\n";

// // Example 2: Product with only Size option
// $optionsInput2 = ['Size' => ['Large', 'X-Large']];
// $result2 = createProductWithOptionsAndVariants(
//     $shopifyStoreUrl,
//     $shopifyAccessToken,
//     $apiVersion,
//     'Demo Hoodie - Size Only - ' . date('Y-m-d H:i:s'),
//     '<p>Warm demo hoodie.</p>',
//     'Demo Brand',
//     $optionsInput2,
//     null, // No specific overrides
//     'DRAFT'
// );
// echo "Result for Product with 1 Option:
";
// if (isset($result2['status'])) {
//   if ($result2['status'] === 'success') {
//     echo "createProductWithOptionsAndVariants SUCCESS:
";
//     if(isset($result2['data']['productCreate']['product']['id'])) {
//        echo "Created Product ID: " . $result2['data']['productCreate']['product']['id'] . "
";
//     } else if (!empty($result2['data']['productCreate']['userErrors'])) {
//        echo "User Errors: 
";
//        // print_r($result2['data']['productCreate']['userErrors']);
//     }
//   } else {
//     echo "createProductWithOptionsAndVariants ERROR: " . $result2['message'] . "
";
//   }
// } else {
//   echo "createProductWithOptionsAndVariants UNEXPECTED RESPONSE:
";
//   // print_r($result2);
// }
// echo "---\n";

// // Example 3: Product with Size, Color, and Material options
// // IMPORTANT: Replace 'gid://shopify/Location/0123456789' with a *REAL* Location GID from your store if testing inventory.
// $exampleLocationGid = 'gid://shopify/Location/0123456789'; // !!! REPLACE THIS with a valid Location GID !!!

// $optionsInput3 = [
//     'Size' => ['Small', 'Medium'],
//     'Color' => ['Blue'],
//     'Material' => ['Cotton', 'Organic Cotton']
// ];
// $variantOverrides3 = [
//     'Small / Blue / Organic Cotton' => [
//         'price' => '35.00', 
//         'sku' => 'SBOC001', 
//         'inventoryPolicy' => 'DENY', // Or 'CONTINUE'
//         'inventoryQuantities' => [['availableQuantity' => 5, 'locationId' => $exampleLocationGid]]
//     ]
// ];
// $result3 = createProductWithOptionsAndVariants(
//     $shopifyStoreUrl,
//     $shopifyAccessToken,
//     $apiVersion,
//     'Demo Premium Tee - ' . date('Y-m-d H:i:s'),
//     '<p>Premium demo tee with three options.</p>',
//     'Premium Demo',
//     $optionsInput3,
//     $variantOverrides3,
//     'ACTIVE'
// );
// echo "Result for Product with 3 Options:
";
// if (isset($result3['status'])) {
//   if ($result3['status'] === 'success') {
//     echo "createProductWithOptionsAndVariants SUCCESS:
";
//     if(isset($result3['data']['productCreate']['product']['id'])) {
//        echo "Created Product ID: " . $result3['data']['productCreate']['product']['id'] . "
";
//     } else if (!empty($result3['data']['productCreate']['userErrors'])) {
//        echo "User Errors: 
";
//        // print_r($result3['data']['productCreate']['userErrors']);
//     }
//   } else {
//     echo "createProductWithOptionsAndVariants ERROR: " . $result3['message'] . "
";
//   }
// } else {
//   echo "createProductWithOptionsAndVariants UNEXPECTED RESPONSE:
";
//   // print_r($result3);
// }
// echo "---\n";
*/

?>

[end of shopify_graphql_client.php]
