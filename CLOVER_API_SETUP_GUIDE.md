# Clover API Setup Guide

This guide will walk you through the process of setting up your Clover Developer Account and obtaining the necessary API credentials. These steps are essential for utilizing the PHP functions that will be developed to interact with the Clover platform.

## Why is this setup necessary?

To integrate your PHP application with Clover's services, you need to authenticate your requests. This is done using API keys or OAuth credentials, which are unique identifiers for your application. Setting up a developer account and a test environment allows you to build and test your integration without affecting live merchant data.

## Step-by-Step Guide

Follow these steps to get your Clover Developer Account and API credentials:

### 1. Create a Clover Developer Account

*   You'll need a Clover developer account to access the necessary tools and resources.
*   Clover offers a global platform for developers. You can sign up or log in here:
    *   [Clover Developer Portal](https://docs.clover.com/docs/clover-platform) (Referenced by links [64], [65])

### 2. Set up a Sandbox Environment and Test Merchant

*   A sandbox environment provides a safe space to test your integration without impacting real transactions or merchant data.
*   Within your developer account, you'll need to create a test merchant.
*   For guidance on managing test merchant accounts on the global platform, refer to:
    *   [Manage Test Merchant Accounts](https://docs.clover.com/docs/clover-test-merchants) (Referenced by link [75])

### 3. Create a New Application

*   Once your developer account and sandbox are ready, you need to create an application within the Clover Developer Dashboard. This app will represent your PHP integration.
*   Detailed instructions for creating an app can be found here:
    *   [Creating Your App](https://docs.clover.com/docs/creating-your-app) (Referenced by link [67])

### 4. Obtain API Credentials

Depending on the specific Clover API you intend to use, you will need different types of credentials:

*   **For the Clover Ecommerce API (Pay Connect API):**
    *   This API typically uses public and private key pairs for authentication.
    *   Information on generating these keys can be found under the Ecommerce API documentation:
        *   [Ecommerce API Keys](https://docs.clover.com/docs/ecommerce-api-credentials) (Referenced by link [115])

*   **For the Clover REST API (General Purpose):**
    *   The REST API generally uses OAuth 2.0 for authentication. This involves an App ID and an App Secret.
    *   You can find more information on REST API authentication and obtaining these credentials here:
        *   [REST API OAuth Overview](https://docs.clover.com/docs/oauth-20) (Referenced by link [197])
        *   [Building Public Apps (OAuth)](https://docs.clover.com/docs/building-public-apps-oauth) (Referenced by link [204])
        *   [Working with OAuth 2.0](https://docs.clover.com/docs/working-with-oauth-20) (Referenced by link [205])

    *During the app creation process (Step 3), you will be guided to generate these credentials.*

## Important: Secure Your Credentials

Your API keys, App ID, and App Secret are sensitive pieces of information. Treat them like passwords.

*   **Do not** embed them directly in your client-side code.
*   **Do not** commit them to version control (e.g., Git).
*   Store them securely, for example, using environment variables or a secure secrets management system.

## Using Your Credentials

The credentials you obtain through this setup process will be required as inputs for the PHP functions that will be developed to interact with the Clover API. Make sure you have them readily available.

By following these steps, you'll be well-equipped to start developing and testing your PHP integration with the Clover platform. Always refer to the official Clover documentation for the most up-to-date information.
