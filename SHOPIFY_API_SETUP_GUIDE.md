# Shopify API Setup Guide

This guide will walk you through setting up your Shopify development environment and obtaining the necessary API credentials. These steps are essential for integrating your PHP application with Shopify's services, focusing on the use of a **Custom App** for straightforward access to the Admin API.

## 1. Introduction

To interact with the Shopify API, you need:

*   A **Shopify Partner Account**: This allows you to create development stores.
*   A **Development Store**: A safe, sandboxed Shopify store where you can test your integration without affecting live businesses.
*   A **Custom App**: Created within your development store, a custom app provides an Admin API Access Token that your PHP application will use to authenticate its requests directly to your store's Admin API.

This setup is ideal for server-to-server integrations where your app needs to perform actions on behalf of the store itself.

## 2. Step-by-Step Guide

Follow these steps to get your Shopify development environment and API credentials:

### a. Sign up for a Shopify Partner Account

*   If you don't already have one, you'll need to create a Shopify Partner account. This is free and gives you access to tools for building and managing Shopify apps and themes.
*   Sign up here: [Shopify Partner Program](https://partners.shopify.com/signup)

### b. Create a Development Store

*   Once logged into your Shopify Partner Dashboard, you'll need a development store. These stores are specifically for testing apps and themes.
*   **Steps:**
    1.  From your Partner Dashboard, navigate to "Stores".
    2.  Click "Add store" and select "Create development store".
    3.  Choose the "Create a store for a client" option (even though it's for your own development, this often provides more flexibility).
    4.  Fill in the store details (store name, login, password).
    5.  Under "Developer preview," you can select the latest developer preview to test upcoming features, or choose the latest stable version.
    6.  Click "Save" or "Create store".

    Your new development store will be created with a unique `.myshopify.com` domain (e.g., `your-dev-store-name.myshopify.com`). This domain is important for API calls.

### c. Create a Custom App in the Development Store

Custom apps are built for a single Shopify store and are not listed on the Shopify App Store. They are the simplest way to get Admin API access for a specific store.

*   **Steps:**
    1.  Log in to your newly created development store's Shopify Admin panel (e.g., `https://your-dev-store-name.myshopify.com/admin`).
    2.  In the left-hand navigation menu, click on **"Apps"**.
    3.  At the top of the Apps page, you should see a button like **"Develop apps for your store"** or similar. Click this. (Shopify's UI can change, but look for options related to app development or private/custom app creation).
    4.  You may be prompted to enable app development if it's the first time. Proceed if asked.
    5.  Click on **"Create a custom app"**.
    6.  Give your app a name (e.g., "My PHP Integration") and assign an App developer (this will likely be your Partner account email).
    7.  Click **"Create app"**.

### d. Obtain Admin API Access Token and API Key/Secret

Once the custom app is created, you need to configure its API access scopes and retrieve the credentials.

*   **Steps:**
    1.  After creating the app, you'll land on its configuration page. If not, navigate back to "Apps" -> "Develop apps for your store", and click on your app's name.
    2.  Go to the **"API credentials"** tab or section.
    3.  Here you will find:
        *   **Admin API access token:** This is the primary credential your PHP application will use. It acts like a password. **Important: This token is shown only once upon its initial reveal. Copy it immediately and store it securely.** If you lose it or don't copy it, you may need to revoke and re-generate it.
        *   **API key:** A public identifier for your app.
        *   **API secret key:** Used in some OAuth scenarios, but for custom apps, the Admin API access token is typically used directly for server-side authentication.
    4.  For this project, the **Admin API access token** is the most crucial piece.

### e. Configure API Permissions (Access Scopes)

Your custom app needs explicit permission to access different parts of your store's data (like orders, products, customers). These permissions are called "access scopes."

*   **Steps:**
    1.  On your custom app's configuration page (where you found the API credentials), look for a section named **"Admin API integration"**, **"Configure Admin API scopes"**, or similar. Click to configure.
    2.  You will see a list of available scopes. For this project, you will likely need at least the following scopes. Select them carefully based on the actual needs of your PHP functions:
        *   `read_orders` and `write_orders` (for accessing and modifying orders)
        *   `read_draft_orders` and `write_draft_orders` (for accessing and modifying draft orders)
        *   `read_products` (for accessing product information)
        *   `write_products` (if you need to modify products)
        *   `read_customers` (for accessing customer information)
        *   `write_customers` (if you need to modify customer information)
        *   *(Refer to the official list of [Admin API Access Scopes](https://shopify.dev/api/usage/access-scopes) for detailed descriptions of each scope.)*
    3.  After selecting the necessary scopes, click **"Save"**.
    4.  If you haven't already, you might need to click an "Install app" button at the top of the app's page to apply these permissions and generate the Admin API access token for the first time (or if scopes were changed after initial reveal).

## 3. Security Note

*   Your **Admin API Access Token** is extremely sensitive. Treat it like a password.
*   Do not embed it directly in client-side code or commit it to version control.
*   Store it securely using environment variables or a secure secrets management system.
*   The API Key and API Secret Key should also be kept confidential.

## 4. Usage

The credentials and information you'll need for your PHP integration are:

*   **Admin API Access Token**: This will be used as the primary authentication token (often as an `X-Shopify-Access-Token` header).
*   **Store's myshopify.com domain**: (e.g., `your-dev-store-name.myshopify.com`). This is used to construct the API endpoint URLs.
*   **API Version**: Shopify versions its API. You'll need to ensure your PHP library targets a supported API version (e.g., `2023-10`).

These will be used to initialize the Shopify PHP SDK or any custom HTTP client logic you develop.

By following these steps, you'll have a functioning development store and the necessary API credentials to start building and testing your Shopify PHP integration. Always refer to the official [Shopify Developer Documentation](https://shopify.dev/) for the most up-to-date information.
