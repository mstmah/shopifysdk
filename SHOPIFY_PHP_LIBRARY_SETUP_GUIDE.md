# Shopify PHP Library/Client Setup Guide

This guide provides an overview of how to set up your PHP environment to interact with the Shopify GraphQL Admin API. It covers using community PHP libraries and the conceptual approach of making direct GraphQL queries.

## 1. Introduction

Shopify's Admin API is primarily GraphQL-based, offering a powerful and flexible way to interact with store data. While Shopify provides official SDKs for languages like Ruby and Node.js, PHP developers often rely on well-regarded community-driven libraries or make direct GraphQL queries using HTTP clients. These methods allow you to authenticate and communicate with your Shopify store's Admin API.

## 2. Recommended Community PHP Libraries

Using an existing PHP library can significantly simplify interactions with the Shopify GraphQL Admin API. These libraries often handle authentication, query building, and response parsing.

*   **Popular Libraries:**
    *   **`osiset/php-shopify`**: This is a widely used and comprehensive library (formerly known as `php-shopify/shopify-api-php`). It supports both REST and GraphQL Admin APIs.
    *   **Other Libraries on Packagist:** You can search on [Packagist.org](https://packagist.org/) for other libraries that support the Shopify GraphQL Admin API by searching terms like "shopify api", "shopify graphql". Evaluate them based on community support, recent updates, and features.

*   **General Installation (using Composer):**
    Most PHP libraries are installed via Composer. For example, to install `osiset/php-shopify`:
    ```bash
    composer require osiset/php-shopify
    ```
    Replace `osiset/php-shopify` with the actual package name if you choose a different library.

*   **Library-Specific Setup and Usage:**
    Once a library is installed, **you must consult its official documentation** for specific instructions on:
    1.  **Client Initialization:** How to create and configure the API client instance. This typically involves providing:
        *   Your Shopify store domain (e.g., `your-dev-store-name.myshopify.com`).
        *   The Admin API Access Token (obtained from your custom app in the Shopify Admin).
        *   The Shopify API version you intend to use (e.g., `2023-10`, `2024-01`).
    2.  **Making GraphQL Queries:** How to structure and send your GraphQL queries using the library's methods.
    3.  **Handling Responses:** How the library returns data and handles errors.

## 3. Alternative: Direct GraphQL Queries (Conceptual)

It's also possible to interact with the Shopify GraphQL Admin API directly using a standard PHP HTTP client like Guzzle or even PHP's built-in cURL functions. This approach gives you full control but requires more manual setup.

*   **Conceptual Steps:**
    1.  **Construct the GraphQL Query:**
        *   Write your GraphQL query string (e.g., `query { shop { name } }`).
        *   This query string will typically be sent in the `query` field of a JSON-encoded request body. Variables, if any, would be sent in a `variables` field.
    2.  **Set the Shopify API Endpoint URL:**
        *   The URL format is: `https://{your-shop-name}.myshopify.com/admin/api/{api-version}/graphql.json`
        *   Replace `{your-shop-name}` with your development store's domain (e.g., `your-dev-store-name`).
        *   Replace `{api-version}` with your target API version (e.g., `2023-10`).
    3.  **Set HTTP Headers:**
        *   `X-Shopify-Access-Token`: Set this to your Admin API Access Token.
        *   `Content-Type`: `application/json` (if sending a JSON body) or `application/graphql` (if sending the query directly as the body, less common for POST with variables).
    4.  **Make a POST Request:**
        *   Send a POST request to the endpoint URL with the JSON-encoded body containing your query (and variables, if any).
    5.  **Handle the JSON Response:**
        *   Decode the JSON response from Shopify.
        *   The response will contain a `data` field for successful queries or an `errors` field if issues occurred.

*   **Note:** This method requires careful manual construction of requests and handling of responses, including error states and potential pagination. Libraries often abstract these complexities.

## 4. Shopify PHP App Template

Shopify provides official application templates for various languages, including PHP. These templates can serve as excellent references for:

*   A Shopify-endorsed project structure.
*   Authentication patterns (though often geared towards public apps using OAuth 2.0, the API interaction parts can still be insightful).
*   Examples of interacting with the API.

*   You can find information and links to these templates on the Shopify Developer documentation site. A good starting point is often the main API documentation page: [Shopify.dev API Docs](https://shopify.dev/docs/api) (Link [70] from the prompt context refers to this broader documentation area, which often links to app templates and tools). Look for sections related to "Build an app" or "Tools".

## 5. Key Configuration Details (Reiteration)

Regardless of whether you choose a library or direct queries, you will always need the following details, which you should have obtained from the `SHOPIFY_API_SETUP_GUIDE.md`:

*   **Your Shopify Store Domain:** e.g., `your-dev-store-name.myshopify.com`
*   **Admin API Access Token:** The token from your custom app.
*   **Desired API Version:** e.g., `2023-10`, `2024-01`. (Shopify updates its API version quarterly).

## 6. Next Steps

The PHP wrapper functions to be developed will utilize one of these methods (likely a chosen community library for robustness and convenience) to interact with the Shopify GraphQL Admin API. The setup performed here is foundational for those subsequent implementation steps.

Ensure you have chosen a library or are prepared to handle direct HTTP requests, and have your API credentials and store information ready.
