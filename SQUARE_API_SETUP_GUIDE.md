# Square API Setup Guide

This guide will walk you through the process of setting up your Square Developer Account and obtaining the necessary API credentials. These steps are essential for using the Square PHP SDK and interacting with the Square platform, especially for testing your PHP integration in a safe sandbox environment.

## Why is this setup necessary?

To integrate your PHP application with Square's services, your application needs to authenticate its API requests. This is done using an Access Token. A Location ID is also required for most API calls to specify which of your business locations the API call applies to. Setting up a developer account and using the sandbox allows you to build and test your integration without affecting your live Square account or actual transaction data.

## Step-by-Step Guide

Follow these steps to get your Square Developer Account and Sandbox API credentials:

### 1. Create a Square Developer Account

*   You'll need a Square developer account to access the Developer Dashboard, create applications, and manage your API credentials.
*   If you don't have one already, sign up or log in here:
    *   [Square Developer Portal](https://developer.squareup.com/)

### 2. Create an Application and Access the Developer Dashboard

*   Once logged in, you'll typically be directed to the Square Developer Dashboard. This is where you manage your applications.
*   An application represents your integration. If you don't have one for this project, you'll need to create it.
*   For detailed steps on creating an account and your first application, refer to:
    *   [Get Started: Create an Account and Application](https://developer.squareup.com/docs/get-started/create-account-and-application) (Link [23])

### 3. Obtain Your Sandbox Access Token

*   Each application you create in the Developer Dashboard will have its own set of credentials for both Sandbox (testing) and Production (live) environments.
*   For testing, you need the **Sandbox Access Token**.
*   Navigate to your application in the Developer Dashboard (usually by clicking on the application name from the list at [https://developer.squareup.com/apps](https://developer.squareup.com/apps)).
*   In the application's dashboard, look for a section or tab named "Credentials" or similar. Here you will find your Sandbox Access Token.
*   For more information on access tokens:
    *   [Access Tokens Overview](https://developer.squareup.com/docs/build-basics/access-tokens) (Link [31])
    *   *Note: The token for the Sandbox environment will typically be clearly labeled as "Sandbox Access Token".*

### 4. Find Your Sandbox Location ID(s)

*   Most Square API calls that involve transactions, orders, inventory, etc., require a `Location ID` to specify which business location the operation pertains to.
*   When your Square account is created, a default business location is usually set up. In the Sandbox environment, your application will have associated test locations.
*   To find your Sandbox Location ID(s):
    *   Go to your application in the Developer Dashboard: [https://developer.squareup.com/apps](https://developer.squareup.com/apps) (Link [246])
    *   Select the application you are working with.
    *   Look for a "Locations" tab or section on the left-hand side menu or within the application's details page. This section will list your Sandbox test locations and their corresponding Location IDs.
    *   You will need at least one of these Location IDs for making API calls.

## Important: Secure Your Credentials

Your Access Tokens (both Sandbox and especially Production) are sensitive. Treat them like passwords:

*   **Do not** embed them directly in client-side code.
*   **Do not** commit them to version control (e.g., Git), especially production tokens.
*   Store them securely using environment variables, secure configuration files, or a secrets management system.

## Using Your Credentials

The **Sandbox Access Token** and a **Sandbox Location ID** you obtain through this setup process will be required to initialize the Square PHP SDK client and make test API calls. Ensure you have them readily available for configuring your PHP application.

By following these steps, you'll be ready to start developing and testing your PHP integration with the Square platform. Always refer to the official Square Developer documentation for the most current information.
