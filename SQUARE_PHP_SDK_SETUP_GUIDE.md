# Square PHP SDK Setup Guide

This guide explains how to install the official Square PHP SDK and initialize the Square API client, which are necessary steps before you can use the custom PHP wrapper functions for interacting with Square services.

## 1. Introduction

For robust and reliable interaction with the Square API in PHP, using the official Square PHP SDK is highly recommended. The SDK handles low-level details like HTTP requests, authentication, and response parsing, allowing you to focus on the business logic of your integration.

## 2. Installation via Composer

The Square PHP SDK is typically installed using Composer, a dependency manager for PHP. If you're not familiar with Composer, you can learn more and download it from [getcomposer.org](https://getcomposer.org/).

To install the Square PHP SDK, run the following command in your project's root directory:

```bash
composer require square/square
```

This command will download the SDK and its dependencies into your project's `vendor/` directory and set up an autoloader.

## 3. Initializing the Square Client (`Square\SquareClient`)

Once the SDK is installed, you need to initialize the `SquareClient`. This client object is your primary interface for making calls to various Square APIs (like Payments, Orders, etc.).

Here's a PHP code snippet demonstrating how to set up and initialize the client for the Sandbox environment:

```php
<?php

// Include Composer's autoloader
// This makes all the SDK classes available.
// Adjust the path if your script is not in the project root.
require_once 'vendor/autoload.php';

// Use the Square SDK's namespace
use Square\SquareClient;
use Square\Environment;
use Square\Exceptions\ApiException;

// --- Configuration ---
// Replace 'YOUR_SANDBOX_ACCESS_TOKEN' with the actual Sandbox Access Token
// you obtained from the Square Developer Dashboard.
$sandboxAccessToken = 'YOUR_SANDBOX_ACCESS_TOKEN';

// Initialize the Square Client
try {
    $squareClient = new SquareClient([
        'accessToken' => $sandboxAccessToken,
        'environment' => Environment::SANDBOX, // Use Environment::SANDBOX for testing
                                               // Use Environment::PRODUCTION for live transactions
    ]);

    echo "Square SDK Client initialized successfully for Sandbox environment.\n";

} catch (ApiException $e) {
    // Handle API exceptions (e.g., configuration errors)
    echo "Failed to initialize Square Client: " . $e->getMessage() . "\n";
    // You might want to log more details from $e->getHttpResponse() or $e->getErrors()
    exit(1);
} catch (Exception $e) {
    // Handle other general exceptions
    echo "An unexpected error occurred: " . $e->getMessage() . "\n";
    exit(1);
}

// The $squareClient is now ready to be used to make API calls.
// For example, to get the Payments API client:
// $paymentsApi = $squareClient->getPaymentsApi();

?>
```

**Key points from the code snippet:**

*   **`require_once 'vendor/autoload.php';`**: This line is crucial. It loads Composer's autoloader, which handles loading the Square SDK classes when they are needed.
*   **`use Square\SquareClient;`** and **`use Square\Environment;`**: These lines import the necessary classes from the Square SDK namespace.
*   **`$sandboxAccessToken = 'YOUR_SANDBOX_ACCESS_TOKEN';`**: **Important:** Replace `'YOUR_SANDBOX_ACCESS_TOKEN'` with the actual Sandbox Access Token you obtained from your Square Developer Dashboard (as described in the `SQUARE_API_SETUP_GUIDE.md`).
*   **`new SquareClient([...])`**: This creates an instance of the main client.
    *   **`accessToken`**: This is where you provide your access token.
    *   **`environment`**: This tells the SDK which Square environment to target.
        *   `Environment::SANDBOX`: For testing and development against the Square Sandbox.
        *   `Environment::PRODUCTION`: For live transactions with your actual Square account. **Always use `Environment::SANDBOX` for testing.**
*   **`$squareClient`**: This object is your gateway to all Square APIs. For example, to interact with the Payments API, you would use `$squareClient->getPaymentsApi()`.

## 4. Next Steps

The initialized `$squareClient` object (configured for the Sandbox environment) will be passed as a dependency to the custom Square PHP wrapper functions that will be developed in subsequent steps. These wrapper functions will use this client to make specific API calls (e.g., creating payments, refunding payments, creating orders).

By following these steps, you have successfully installed the Square PHP SDK and prepared the main client for testing your integration. Always ensure your `accessToken` and `environment` settings are correct for the task at hand (Sandbox for testing, Production for live).
