<?php declare(strict_types=1);

// --- Best Practices and Important Considerations ---
/*
This script provides a collection of functions for interacting with various Meta APIs.
When integrating these into a production application, consider the following:

1.  Error Handling:
    *   Always check the return values of these functions. They are designed to return an array
      which will contain an ['error'] key if an API error occurred or if the function
      itself encountered an issue (e.g., file not found for uploads).
    *   Inspect the full API JSON response, especially for an `error` key, which will contain
      details like `message`, `type`, `code`, and `error_subcode`.
    *   Log errors comprehensively (see point 7) and handle them gracefully in your application
      to avoid exposing raw error messages to users or breaking your application flow.

2.  Access Token Security:
    *   Never hardcode access tokens, App IDs, App Secrets, or other sensitive credentials
      directly in your code, especially if it's client-accessible or committed to version control.
    *   Use environment variables (e.g., via `$_ENV`, `getenv()`, or libraries like `vlucas/phpdotenv`)
      to store and access these credentials.
    *   Store access tokens securely, preferably on the server-side. For User Access Tokens,
      especially long-lived ones, consider encrypting them at rest.
    *   Page Access Tokens obtained via User Tokens can expire if the authorizing User Token
      expires or if the user revokes the permissions granted to your app. Regularly check
      token validity and implement re-authorization flows.
    *   App Access Tokens (typically `YOUR_APP_ID|YOUR_APP_SECRET`) are powerful and should be
      used with extreme caution, only from secure server environments.

3.  API Permissions (Scopes):
    *   Each API endpoint and specific fields require appropriate permissions (scopes) granted
      by the user during the OAuth 2.0 authorization process.
    *   Refer to the official Meta API documentation for the exact permissions needed for each
      function/endpoint you intend to use.
    *   The `getOAuthUrl()` function in this script includes a `$scope` parameter. Ensure you
      request only the necessary permissions. Requesting excessive permissions can lead to
      user distrust and app rejection during review.

4.  API Versioning:
    *   This script uses Graph API v19.0 (as of its creation in early 2024).
    *   Meta updates its API versions regularly (typically with new versions released quarterly
      and older versions deprecated after about 2 years).
    *   Be prepared to update API endpoint URLs (e.g., `/v19.0/` to `/v20.0/`) and adapt to
      potential breaking changes by consulting the Meta Developer documentation and changelogs.
      Plan for periodic reviews of your API integration.

5.  Rate Limiting:
    *   Meta imposes rate limits on API calls to ensure fair usage and platform stability.
      Limits can be applied at the app, user, Page, and ad account levels.
    *   Check response headers like `X-App-Usage`, `X-Page-Usage`, `X-Ad-Account-Usage`,
      and `X-Business-Use-Case-Usage` for current usage percentages and call counts.
    *   If rate limits are hit (often indicated by an error with code 4, 17, 32, 341, or 8000x family),
      implement robust retry mechanisms with exponential backoff.
    *   For batch operations or high-volume needs, explore Meta's Batch API.
    *   Refer to Meta's documentation on "Rate Limiting" for detailed information:
      https://developers.facebook.com/docs/graph-api/overview/rate-limiting/

6.  Input Validation and Sanitization:
    *   While these functions provide a layer, always validate and sanitize any user-provided
      data or data from external sources before using it in API requests or your application.
    *   This is crucial for preventing errors, security vulnerabilities (like XSS if displaying
      data, or issues if data is used in database queries), and ensuring data integrity.

7.  Logging:
    *   Implement comprehensive logging for API requests (excluding sensitive data like full
      access tokens – perhaps log only the type or a portion if necessary for debugging)
      and the full responses.
    *   This is invaluable for debugging issues, monitoring API call success/failure rates,
      and understanding how your application interacts with Meta's services.
    *   Use a capable logging library (e.g., Monolog) for structured logging.

8.  Modularity for Larger Applications:
    *   For complex applications, consider refactoring this monolithic script into smaller,
      more manageable classes or services, potentially organized by API (e.g., GraphAPIService,
      MessengerService, InstagramService, MarketingAPIService).
    *   This improves code organization, testability, and maintainability.

9.  PHP Version & cURL:
    *   This script is designed with PHP 8.x features in mind (e.g., `declare(strict_types=1)`,
      `nullsafe operator` if used, `match expressions` if used).
    *   It relies on the PHP cURL extension for making HTTP requests. Ensure this extension
      is installed and enabled in your PHP environment.

10. Graph API Explorer:
    *   Leverage the Graph API Explorer tool available in the Meta Developer Portal.
    *   It's an indispensable tool for testing API queries, permissions (scopes), generating
      access tokens for testing, and discovering available data fields and endpoints.

11. Webhook Security:
    *   For webhook implementations (e.g., for Messenger, Instagram mentions), always verify
      the request signature (`X-Hub-Signature`) using your App Secret to ensure the
      request is genuinely from Meta and not a malicious actor.
    *   Respond to webhook verification requests (`hub.verify_token`) correctly.
    *   Process webhook notifications asynchronously if they involve long-running tasks to
      avoid timeouts and ensure a quick 200 OK response to Meta.

12. Data Privacy and Compliance:
    *   Be mindful of user privacy and comply with all relevant data protection regulations
      (e.g., GDPR, CCPA) and Meta's Platform Terms and Developer Policies.
    *   Only request and store data that is necessary for your application's functionality.
    *   Provide users with transparency and control over their data.
*/

// App ID and App Secret can be found in the Meta Developer Portal:
// https://developers.facebook.com/apps/

/**
 * Generates the URL to redirect the user to for authorization.
 *
 * @param string $appId The App ID.
 * @param string $redirectUri The redirect URI.
 * @param array $scope The requested permissions.
 * @return string The authorization URL.
 */
function getOAuthUrl(string $appId, string $redirectUri, array $scope): string
{
    $params = [
        'client_id' => $appId,
        'redirect_uri' => $redirectUri,
        'scope' => implode(',', $scope),
        'response_type' => 'code',
        'state' => bin2hex(random_bytes(16)) // For CSRF protection
    ];
    return 'https://www.facebook.com/v19.0/dialog/oauth?' . http_build_query($params);
}
/*
// Example for getOAuthUrl:
// $appId = 'YOUR_APP_ID';
// $redirectUri = 'https://yourdomain.com/callback.php';
// $scopes = ['public_profile', 'email', 'pages_read_engagement'];
// $authUrl = getOAuthUrl($appId, $redirectUri, $scopes);
// echo "Authorization URL: " . $authUrl;
*/

/**
 * Exchanges the authorization code for a short-lived user access token.
 *
 * @param string $appId The App ID.
 * @param string $appSecret The App Secret.
 * @param string $redirectUri The redirect URI.
 * @param string $code The authorization code.
 * @return array The API response containing the access token or an error.
 */
function getAccessToken(string $appId, string $appSecret, string $redirectUri, string $code): array
{
    $url = 'https://graph.facebook.com/v19.0/oauth/access_token';
    $params = [
        'client_id' => $appId,
        'client_secret' => $appSecret,
        'redirect_uri' => $redirectUri,
        'code' => $code
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return ['error' => $data['error']['message']];
    }

    if (!isset($data['access_token'])) {
        return ['error' => 'Access token not found in response.'];
    }

    return $data;
}
/*
// Example for getAccessToken (typically used in your redirect URI script):
// $appId = 'YOUR_APP_ID';
// $appSecret = 'YOUR_APP_SECRET';
// $redirectUri = 'https://yourdomain.com/callback.php';
// $authorizationCode = $_GET['code'] ?? null;
// if ($authorizationCode) {
//     $tokenData = getAccessToken($appId, $appSecret, $redirectUri, $authorizationCode);
//     if (isset($tokenData['access_token'])) {
//         echo "Access Token: " . $tokenData['access_token'];
//     } else {
//         echo "Error: " . ($tokenData['error'] ?? 'Unknown error');
//     }
// }
*/

/**
 * Exchanges a short-lived user access token for a long-lived one.
 *
 * @param string $appId The App ID.
 * @param string $appSecret The App Secret.
 * @param string $shortLivedAccessToken The short-lived access token.
 * @return array The API response containing the long-lived access token or an error.
 */
function getLongLivedUserAccessToken(string $appId, string $appSecret, string $shortLivedAccessToken): array
{
    $url = 'https://graph.facebook.com/v19.0/oauth/access_token';
    $params = [
        'grant_type' => 'fb_exchange_token',
        'client_id' => $appId,
        'client_secret' => $appSecret,
        'fb_exchange_token' => $shortLivedAccessToken
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return ['error' => $data['error']['message']];
    }

    if (!isset($data['access_token'])) {
        return ['error' => 'Long-lived access token not found in response.'];
    }

    return $data;
}
/*
// Example for getLongLivedUserAccessToken:
// $appId = 'YOUR_APP_ID';
// $appSecret = 'YOUR_APP_SECRET';
// $shortLivedToken = 'USER_SHORT_LIVED_ACCESS_TOKEN'; // From getAccessToken
// $longLivedTokenData = getLongLivedUserAccessToken($appId, $appSecret, $shortLivedToken);
// if (isset($longLivedTokenData['access_token'])) {
//     echo "Long-Lived Access Token: " . $longLivedTokenData['access_token'];
// } else {
//     echo "Error: " . ($longLivedTokenData['error'] ?? 'Unknown error');
// }
*/

/**
 * Gets a page access token using a user access token.
 *
 * @param string $userAccessToken The user access token (long-lived recommended).
 * @param string $pageId The Page ID.
 * @return array The API response containing the page access token or an error.
 */
function getPageAccessToken(string $userAccessToken, string $pageId): array
{
    $url = "https://graph.facebook.com/v19.0/{$pageId}";
    $params = [
        'fields' => 'access_token',
        'access_token' => $userAccessToken
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return ['error' => $data['error']['message']];
    }

    if (!isset($data['access_token'])) {
        return ['error' => 'Page access token not found in response.'];
    }
    return ['page_access_token' => $data['access_token'], 'page_id' => $pageId];
}
/*
// Example for getPageAccessToken:
// $userAccessToken = 'LONG_LIVED_USER_ACCESS_TOKEN';
// $pageId = 'YOUR_FACEBOOK_PAGE_ID';
// $pageAccessTokenData = getPageAccessToken($userAccessToken, $pageId);
// if (isset($pageAccessTokenData['page_access_token'])) {
//     echo "Page Access Token: " . $pageAccessTokenData['page_access_token'];
// } else {
//     echo "Error: " . ($pageAccessTokenData['error'] ?? 'Unknown error');
// }
*/

// --- User Data ---

/**
 * Fetches a user's profile information.
 * Defaults to 'me' for the current user.
 *
 * @param string $accessToken User access token.
 * @param string $userId User ID or 'me'.
 * @param array $fields Array of fields to retrieve (e.g., ['id', 'name', 'email', 'picture']).
 * @return array API response with user profile data or error.
 */
function getUserProfile(string $accessToken, string $userId = 'me', array $fields = ['id', 'name', 'email']): array
{
    $url = "https://graph.facebook.com/v19.0/{$userId}";
    $params = [
        'fields' => implode(',', $fields),
        'access_token' => $accessToken
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return ['error' => $data['error']['message']];
    }
    return $data;
}
/*
// Example for getUserProfile:
// $userToken = 'VALID_USER_ACCESS_TOKEN';
// $profile = getUserProfile($userToken, 'me', ['id', 'name', 'picture']);
// print_r($profile);
*/

/**
 * Retrieves a user's feed/posts.
 *
 * @param string $accessToken User access token.
 * @param string $userId User ID or 'me'.
 * @param int $limit Maximum number of posts to retrieve.
 * @return array API response with user feed data or error.
 */
function getUserFeed(string $accessToken, string $userId = 'me', int $limit = 10): array
{
    $url = "https://graph.facebook.com/v19.0/{$userId}/feed";
    $params = [
        'limit' => $limit,
        'access_token' => $accessToken
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return ['error' => $data['error']['message']];
    }
    return $data;
}
/*
// Example for getUserFeed:
// $userToken = 'VALID_USER_ACCESS_TOKEN_WITH_USER_POSTS_PERMISSION';
// $feed = getUserFeed($userToken, 'me', 5);
// print_r($feed);
*/

// --- Page Management ---

/**
 * Gets information about a Facebook Page.
 *
 * @param string $accessToken Page or User access token with appropriate permissions.
 * @param string $pageId The ID of the Facebook Page.
 * @param array $fields Array of fields to retrieve (e.g., ['id', 'name', 'about', 'fan_count', 'cover']).
 * @return array API response with Page information or error.
 */
function getPageInfo(string $accessToken, string $pageId, array $fields = ['id', 'name', 'about', 'likes']): array
{
    // Note: 'likes' is deprecated and replaced by 'fan_count'. Using 'fan_count' for better compatibility.
    if (in_array('likes', $fields)) {
        $fields = array_diff($fields, ['likes']);
        $fields[] = 'fan_count';
        $fields = array_unique($fields); // Ensure 'fan_count' is not duplicated if already present
    }

    $url = "https://graph.facebook.com/v19.0/{$pageId}";
    $params = [
        'fields' => implode(',', $fields),
        'access_token' => $accessToken
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return ['error' => $data['error']['message']];
    }
    return $data;
}
/*
// Example for getPageInfo:
// $pageAccessToken = 'VALID_PAGE_ACCESS_TOKEN';
// $pageId = 'YOUR_FACEBOOK_PAGE_ID';
// $info = getPageInfo($pageAccessToken, $pageId, ['id', 'name', 'fan_count', 'link']);
// print_r($info);
*/

/**
 * Retrieves the feed/posts of a Facebook Page.
 *
 * @param string $accessToken Page or User access token with appropriate permissions.
 * @param string $pageId The ID of the Facebook Page.
 * @param int $limit Maximum number of posts to retrieve.
 * @return array API response with Page feed data or error.
 */
function getPageFeed(string $accessToken, string $pageId, int $limit = 10): array
{
    $url = "https://graph.facebook.com/v19.0/{$pageId}/feed";
    $params = [
        'limit' => $limit,
        'access_token' => $accessToken
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return ['error' => $data['error']['message']];
    }
    return $data;
}
/*
// Example for getPageFeed:
// $pageAccessToken = 'VALID_PAGE_ACCESS_TOKEN';
// $pageId = 'YOUR_FACEBOOK_PAGE_ID';
// $pageFeed = getPageFeed($pageAccessToken, $pageId, 5);
// print_r($pageFeed);
*/

/**
 * Publishes a post to a Facebook Page.
 *
 * @param string $accessToken Page access token with 'pages_manage_posts' permission.
 * @param string $pageId The ID of the Facebook Page.
 * @param string $message The message content of the post.
 * @param ?string $link (Optional) A URL to attach to the post.
 * @return array API response with post ID or error.
 */
function postToPage(string $accessToken, string $pageId, string $message, ?string $link = null): array
{
    $url = "https://graph.facebook.com/v19.0/{$pageId}/feed";
    $params = [
        'message' => $message,
        'access_token' => $accessToken
    ];
    if ($link !== null) {
        $params['link'] = $link;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return ['error' => $data['error']['message']];
    }
    return $data; // Contains ID of the new post if successful
}
/*
// Example for postToPage:
// $pageAccessToken = 'VALID_PAGE_ACCESS_TOKEN_WITH_PAGES_MANAGE_POSTS';
// $pageId = 'YOUR_FACEBOOK_PAGE_ID';
// $postResult = postToPage($pageAccessToken, $pageId, "Hello World from PHP! Time: " . time(), "https://developers.facebook.com");
// print_r($postResult);
*/

/**
 * Uploads a photo to a Page.
 *
 * @param string $accessToken Page access token with 'pages_manage_posts' permission.
 * @param string $pageId The ID of the Facebook Page.
 * @param string $photoPath Local path to the photo file.
 * @param string $caption (Optional) Caption for the photo.
 * @return array API response with photo ID and post ID or error.
 */
function uploadPhotoToPage(string $accessToken, string $pageId, string $photoPath, string $caption = ''): array
{
    if (!file_exists($photoPath)) {
        return ['error' => "Photo file not found at path: {$photoPath}"];
    }
    if (!is_readable($photoPath)) {
        return ['error' => "Photo file is not readable at path: {$photoPath}"];
    }

    $url = "https://graph.facebook.com/v19.0/{$pageId}/photos";
    $params = [
        'caption' => $caption,
        'access_token' => $accessToken,
        'source' => curl_file_create($photoPath)
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params); 
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $data = json_decode($response, true);

    if ($httpCode >= 400 || isset($data['error'])) {
        if (isset($data['error'])) {
            return ['error' => $data['error']['message'], 'details' => $data['error']];
        }
        return ['error' => "Failed to upload photo. HTTP Status: {$httpCode}", 'response_body' => $response];
    }
    return $data; 
}
/*
// Example for uploadPhotoToPage:
// $pageAccessToken = 'VALID_PAGE_ACCESS_TOKEN_WITH_PAGES_MANAGE_POSTS';
// $pageId = 'YOUR_FACEBOOK_PAGE_ID';
// $filePath = 'path/to/your/image.jpg'; // Ensure this image exists
// if (file_exists($filePath)) {
//    $uploadResult = uploadPhotoToPage($pageAccessToken, $pageId, $filePath, "My beautiful photo uploaded via API!");
//    print_r($uploadResult);
// } else {
//    echo "Photo for upload not found at: " . $filePath;
// }
*/

/**
 * Uploads a video to a Page.
 *
 * @param string $accessToken Page access token with 'pages_manage_posts' permission.
 * @param string $pageId The ID of the Facebook Page.
 * @param string $videoPath Local path to the video file.
 * @param string $description (Optional) Description for the video.
 * @return array API response with video ID or error.
 */
function uploadVideoToPage(string $accessToken, string $pageId, string $videoPath, string $description = ''): array
{
    if (!file_exists($videoPath)) {
        return ['error' => "Video file not found at path: {$videoPath}"];
    }
     if (!is_readable($videoPath)) {
        return ['error' => "Video file is not readable at path: {$videoPath}"];
    }
    
    $url = "https://graph-video.facebook.com/v19.0/{$pageId}/videos"; 
    $params = [
        'description' => $description,
        'access_token' => $accessToken,
        'source' => curl_file_create($videoPath)
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300); 
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60); 

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);

    if ($httpCode >= 400 || isset($data['error'])) {
         if (isset($data['error'])) {
            return ['error' => $data['error']['message'], 'details' => $data['error']];
        }
        return ['error' => "Failed to upload video. HTTP Status: {$httpCode}", 'response_body' => $response];
    }
    return $data; 
}
/*
// Example for uploadVideoToPage:
// $pageAccessToken = 'VALID_PAGE_ACCESS_TOKEN_WITH_PAGES_MANAGE_POSTS_AND_PUBLISH_VIDEO';
// $pageId = 'YOUR_FACEBOOK_PAGE_ID';
// $filePath = 'path/to/your/video.mp4'; // Ensure this video exists
// if (file_exists($filePath)) {
//    $uploadResult = uploadVideoToPage($pageAccessToken, $pageId, $filePath, "My awesome video uploaded via API!");
//    print_r($uploadResult);
// } else {
//    echo "Video for upload not found at: " . $filePath;
// }
*/

// --- Content Engagement ---

/**
 * Likes a post on behalf of the user or page associated with the access token.
 *
 * @param string $accessToken User or Page access token.
 * @param string $postId The ID of the post to like.
 * @return array API response (typically success true or error).
 */
function likePost(string $accessToken, string $postId): array
{
    $url = "https://graph.facebook.com/v19.0/{$postId}/likes";
    $params = [
        'access_token' => $accessToken
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return ['error' => $data['error']['message']];
    }
    if (!isset($data['success']) || $data['success'] !== true) {
        return $data; 
    }
    return $data;
}
/*
// Example for likePost:
// $pageAccessToken = 'VALID_PAGE_ACCESS_TOKEN_WITH_PAGES_MANAGE_ENGAGEMENT';
// $postIdToLike = 'PAGE_ID_POST_ID_TO_LIKE';
// $likeResult = likePost($pageAccessToken, $postIdToLike);
// print_r($likeResult);
*/

/**
 * Adds a comment to a post.
 *
 * @param string $accessToken User or Page access token with appropriate permissions.
 * @param string $postId The ID of the post to comment on.
 * @param string $message The comment message.
 * @return array API response with comment ID or error.
 */
function commentOnPost(string $accessToken, string $postId, string $message): array
{
    $url = "https://graph.facebook.com/v19.0/{$postId}/comments";
    $params = [
        'message' => $message,
        'access_token' => $accessToken
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return ['error' => $data['error']['message']];
    }
    return $data; 
}
/*
// Example for commentOnPost:
// $pageAccessToken = 'VALID_PAGE_ACCESS_TOKEN_WITH_PAGES_MANAGE_POSTS'; // Or user token with publish_actions
// $postIdToComment = 'PAGE_ID_POST_ID_TO_COMMENT';
// $commentResult = commentOnPost($pageAccessToken, $postIdToComment, "Great post!");
// print_r($commentResult);
*/

/**
 * Shares a post to the current user's feed or a page they manage.
 *
 * @param string $accessToken User or Page access token.
 * @param string $postId The ID of the post to share.
 * @param string $targetUserId The ID of the user or page to share on (defaults to 'me').
 * @return array API response with shared post ID or error.
 */
function sharePost(string $accessToken, string $postId, string $targetUserId = 'me'): array
{
    $postDetailsUrl = "https://graph.facebook.com/v19.0/{$postId}?fields=permalink_url&access_token={$accessToken}";
    $ch = curl_init($postDetailsUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $postDetailsResponse = curl_exec($ch);
    curl_close($ch);
    $postDetailsData = json_decode($postDetailsResponse, true);

    if (isset($postDetailsData['error'])) {
        return ['error' => "Could not retrieve post details for sharing: " . $postDetailsData['error']['message']];
    }
    if (!isset($postDetailsData['permalink_url'])) {
        return ['error' => "Could not retrieve permalink_url for post {$postId}"];
    }
    $linkToShare = $postDetailsData['permalink_url'];

    $url = "https://graph.facebook.com/v19.0/{$targetUserId}/feed";
    $params = [
        'link' => $linkToShare, 
        'access_token' => $accessToken
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return ['error' => $data['error']['message']];
    }
    return $data; 
}
/*
// Example for sharePost:
// $userAccessToken = 'VALID_USER_ACCESS_TOKEN_WITH_APPROPRIATE_PERMISSIONS';
// $postIdToShare = 'PUBLIC_POST_ID_TO_SHARE'; // e.g., from another page
// $shareResult = sharePost($userAccessToken, $postIdToShare, 'me'); // Shares to the user's own feed
// print_r($shareResult);
*/


// --- Group Management (Basic) ---

/**
 * Retrieves posts from a group.
 *
 * @param string $accessToken User access token with group permissions.
 * @param string $groupId The ID of the group.
 * @param int $limit Maximum number of posts to retrieve.
 * @return array API response with group feed data or error.
 */
function getGroupFeed(string $accessToken, string $groupId, int $limit = 10): array
{
    $url = "https://graph.facebook.com/v19.0/{$groupId}/feed";
    $params = [
        'limit' => $limit,
        'access_token' => $accessToken
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return ['error' => $data['error']['message']];
    }
    return $data;
}
/*
// Example for getGroupFeed:
// $userAccessToken = 'VALID_USER_ACCESS_TOKEN_WITH_GROUP_PERMISSIONS';
// $groupId = 'YOUR_GROUP_ID';
// $groupFeed = getGroupFeed($userAccessToken, $groupId, 5);
// print_r($groupFeed);
*/

// --- Event Management (Basic) ---

/**
 * Retrieves information about an event.
 *
 * @param string $accessToken User or Page access token.
 * @param string $eventId The ID of the event.
 * @param array $fields Array of fields to retrieve.
 * @return array API response with event information or error.
 */
function getEventInfo(string $accessToken, string $eventId, array $fields = ['id', 'name', 'description', 'start_time', 'end_time', 'place']): array
{
    $url = "https://graph.facebook.com/v19.0/{$eventId}";
    $params = [
        'fields' => implode(',', $fields),
        'access_token' => $accessToken
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return ['error' => $data['error']['message']];
    }
    return $data;
}
/*
// Example for getEventInfo:
// $userAccessToken = 'VALID_USER_OR_PAGE_ACCESS_TOKEN';
// $eventId = 'YOUR_EVENT_ID';
// $eventInfo = getEventInfo($userAccessToken, $eventId, ['id', 'name', 'start_time', 'cover']);
// print_r($eventInfo);
*/

// --- MARKETING API FUNCTIONS START HERE ---

/**
 * Helper function to ensure Ad Account ID has 'act_' prefix.
 * @param string $adAccountId The Ad Account ID.
 * @return string Prefixed Ad Account ID.
 */
function formatAdAccountId(string $adAccountId): string
{
    if (strpos($adAccountId, 'act_') !== 0) {
        return 'act_' . $adAccountId;
    }
    return $adAccountId;
}

// --- Campaign Management ---

/**
 * Creates an ad campaign.
 *
 * @param string $accessToken User access token with ads_management permission.
 * @param string $adAccountId Your Ad Account ID.
 * @param string $name Name of the campaign.
 * @param string $objective Campaign objective.
 * @param string $status Status of the campaign. Defaults to PAUSED.
 * @param array $specialAdCategories Special ad categories if applicable. Defaults to empty.
 * @return array API response with campaign ID or error.
 */
function createAdCampaign(
    string $accessToken,
    string $adAccountId,
    string $name,
    string $objective,
    string $status = 'PAUSED',
    array $specialAdCategories = []
): array {
    $formattedAdAccountId = formatAdAccountId($adAccountId);
    $url = "https://graph.facebook.com/v19.0/{$formattedAdAccountId}/campaigns";
    $params = [
        'name' => $name,
        'objective' => $objective,
        'status' => $status,
        'access_token' => $accessToken,
    ];
    if (!empty($specialAdCategories)) {
         $params['special_ad_categories'] = json_encode($specialAdCategories);
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return ['error' => $data['error']['message'], 'details' => $data['error'] ?? null];
    }
    return $data; 
}
/*
// Example for createAdCampaign:
// $userTokenWithAdsPerms = 'VALID_USER_TOKEN_WITH_ADS_MANAGEMENT';
// $adAccountId = 'act_YOUR_AD_ACCOUNT_ID';
// $campaign = createAdCampaign($userTokenWithAdsPerms, $adAccountId, "My API Campaign", "LINK_CLICKS", "PAUSED", []);
// print_r($campaign);
*/

/**
 * Retrieves ad campaigns from an ad account.
 *
 * @param string $accessToken User access token.
 * @param string $adAccountId Your Ad Account ID.
 * @param array $fields Array of fields to retrieve.
 * @return array API response with list of campaigns or error.
 */
function getAdCampaigns(string $accessToken, string $adAccountId, array $fields = ['id', 'name', 'objective', 'status']): array
{
    $formattedAdAccountId = formatAdAccountId($adAccountId);
    $url = "https://graph.facebook.com/v19.0/{$formattedAdAccountId}/campaigns";
    $params = [
        'fields' => implode(',', $fields),
        'access_token' => $accessToken
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return ['error' => $data['error']['message']];
    }
    return $data; 
}
/*
// Example for getAdCampaigns:
// $userTokenWithAdsPerms = 'VALID_USER_TOKEN_WITH_ADS_READ';
// $adAccountId = 'act_YOUR_AD_ACCOUNT_ID';
// $campaigns = getAdCampaigns($userTokenWithAdsPerms, $adAccountId);
// print_r($campaigns);
*/

/**
 * Retrieves ad sets within a campaign.
 *
 * @param string $accessToken User access token.
 * @param string $campaignId The ID of the campaign.
 * @param array $fields Array of fields to retrieve for ad sets.
 * @return array API response with list of ad sets or error.
 */
function getAdSets(string $accessToken, string $campaignId, array $fields = ['id', 'name', 'status', 'daily_budget', 'bid_amount']): array
{
    $url = "https://graph.facebook.com/v19.0/{$campaignId}/adsets";
    $params = [
        'fields' => implode(',', $fields),
        'access_token' => $accessToken
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return ['error' => $data['error']['message']];
    }
    return $data; 
}
/*
// Example for getAdSets:
// $userTokenWithAdsPerms = 'VALID_USER_TOKEN_WITH_ADS_READ';
// $campaignId = 'CAMPAIGN_ID_FROM_GET_AD_CAMPAIGNS';
// $adSets = getAdSets($userTokenWithAdsPerms, $campaignId);
// print_r($adSets);
*/

/**
 * Creates an ad set within a campaign.
 *
 * @param string $accessToken User access token.
 * @param string $campaignId The ID of the campaign for this ad set.
 * @param string $name Name of the ad set.
 * @param array $targeting Targeting specifications.
 * @param string $dailyBudget Daily budget in currency units.
 * @param string $billingEvent Event that triggers billing.
 * @param string $optimizationGoal Optimization goal for ad delivery.
 * @param string $status Status of the ad set. Defaults to PAUSED.
 * @param ?string $bidAmount (Optional) Bid amount.
 * @return array API response with ad set ID or error.
 */
function createAdSet(
    string $accessToken,
    string $campaignId,
    string $name,
    array $targeting,
    string $dailyBudget, 
    string $billingEvent = 'IMPRESSIONS',
    string $optimizationGoal = 'REACH', 
    string $status = 'PAUSED',
    ?string $bidAmount = null 
): array {
    $url = "https://graph.facebook.com/v19.0/{$campaignId}/adsets"; 
    $params = [
        'campaign_id' => $campaignId, 
        'name' => $name,
        'targeting' => json_encode($targeting), 
        'daily_budget' => $dailyBudget,
        'billing_event' => $billingEvent,
        'optimization_goal' => $optimizationGoal,
        'status' => $status,
        'access_token' => $accessToken
    ];

    if ($bidAmount !== null) {
        $params['bid_amount'] = $bidAmount;
    }
    $params['campaign_id'] = $campaignId;


    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return ['error' => $data['error']['message'], 'details' => $data['error'] ?? null];
    }
    return $data; 
}
/*
// Example for createAdSet:
// $userTokenWithAdsPerms = 'VALID_USER_TOKEN_WITH_ADS_MANAGEMENT';
// $campaignId = 'CAMPAIGN_ID_FROM_CREATE_AD_CAMPAIGN';
// $targetingSpec = [
//     'geo_locations' => ['countries' => ['US']],
//     'age_min' => 20,
//     'age_max' => 45,
//     'publisher_platforms' => ['facebook']
// ];
// $adSet = createAdSet($userTokenWithAdsPerms, $campaignId, "My API Ad Set", $targetingSpec, "5000", "IMPRESSIONS", "REACH");
// print_r($adSet);
*/

/**
 * Creates an ad creative.
 *
 * @param string $accessToken User access token.
 * @param string $adAccountId Your Ad Account ID.
 * @param string $name Name for the ad creative.
 * @param array $objectStorySpec Creative specification.
 * @return array API response with creative ID or error.
 */
function createAdCreative(string $accessToken, string $adAccountId, string $name, array $objectStorySpec): array
{
    $formattedAdAccountId = formatAdAccountId($adAccountId);
    $url = "https://graph.facebook.com/v19.0/{$formattedAdAccountId}/adcreatives";
    $params = [
        'name' => $name,
        'object_story_spec' => json_encode($objectStorySpec), 
        'access_token' => $accessToken
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return ['error' => $data['error']['message'], 'details' => $data['error'] ?? null];
    }
    return $data; 
}
/*
// Example for createAdCreative:
// $userTokenWithAdsPerms = 'VALID_USER_TOKEN_WITH_ADS_MANAGEMENT';
// $adAccountId = 'act_YOUR_AD_ACCOUNT_ID';
// $pageId = 'YOUR_PAGE_ID_FOR_THE_AD';
// $imageHash = 'YOUR_UPLOADED_IMAGE_HASH'; // Upload an image to Ad Library or via API first
// $creativeSpec = [
//     'page_id' => $pageId,
//     'link_data' => [
//         'message' => 'My awesome product!',
//         'link' => 'https://www.example.com/product',
//         'image_hash' => $imageHash
//     ]
// ];
// $creative = createAdCreative($userTokenWithAdsPerms, $adAccountId, "My API Creative", $creativeSpec);
// print_r($creative);
*/

/**
 * Creates an ad.
 *
 * @param string $accessToken User access token.
 * @param string $adSetId The ID of the ad set this ad belongs to.
 * @param string $name Name of the ad.
 * @param string $creativeId The ID of the ad creative to use.
 * @param string $status Status of the ad. Defaults to PAUSED.
 * @param string $adAccountId Ad Account ID.
 * @return array API response with ad ID or error.
 */
function createAd(string $accessToken, string $adSetId, string $name, string $creativeId, string $status = 'PAUSED', string $adAccountId): array
{
    $formattedAdAccountId = formatAdAccountId($adAccountId);
    $url = "https://graph.facebook.com/v19.0/{$formattedAdAccountId}/ads";

    $params = [
        'adset_id' => $adSetId,
        'name' => $name,
        'creative' => json_encode(['creative_id' => $creativeId]), 
        'status' => $status,
        'access_token' => $accessToken
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return ['error' => $data['error']['message'], 'details' => $data['error'] ?? null];
    }
    return $data; 
}
/*
// Example for createAd:
// $userTokenWithAdsPerms = 'VALID_USER_TOKEN_WITH_ADS_MANAGEMENT';
// $adSetId = 'AD_SET_ID_FROM_CREATE_AD_SET';
// $creativeId = 'CREATIVE_ID_FROM_CREATE_AD_CREATIVE';
// $adAccountId = 'act_YOUR_AD_ACCOUNT_ID';
// $ad = createAd($userTokenWithAdsPerms, $adSetId, "My API Ad", $creativeId, "PAUSED", $adAccountId);
// print_r($ad);
*/

// --- Audience Targeting (Conceptual Explanation) ---
/*
Conceptual Explanation of Audience Targeting in Meta Marketing API:
... (content as before) ...
*/

// --- Performance Monitoring ---

/**
 * Retrieves insights (performance data) for an ad campaign.
 *
 * @param string $accessToken User access token.
 * @param string $campaignId The ID of the campaign.
 * @param string $datePreset Date range preset.
 * @param array $fields Array of insight metrics to retrieve.
 * @param string $level 'campaign', 'adset', or 'ad'. Defaults to 'campaign'.
 * @param int $limit Max number of results.
 * @return array API response with insights data or error.
 */
function getAdCampaignInsights(
    string $accessToken,
    string $campaignId, 
    string $datePreset = 'last_7d',
    array $fields = ['impressions', 'clicks', 'spend', 'cpc', 'ctr', 'actions'],
    string $level = 'campaign', 
    int $limit = 50
): array {
    $url = "https://graph.facebook.com/v19.0/{$campaignId}/insights";
    $params = [
        'date_preset' => $datePreset,
        'fields' => implode(',', $fields),
        'level' => $level,
        'limit' => $limit,
        'access_token' => $accessToken
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return ['error' => $data['error']['message']];
    }
    return $data; 
}
/*
// Example for getAdCampaignInsights:
// $userTokenWithAdsPerms = 'VALID_USER_TOKEN_WITH_ADS_READ';
// $campaignIdForInsights = 'CAMPAIGN_ID_TO_GET_INSIGHTS_FOR';
// $insights = getAdCampaignInsights($userTokenWithAdsPerms, $campaignIdForInsights, 'last_28d');
// print_r($insights);
*/

// --- MESSENGER PLATFORM API FUNCTIONS START HERE ---

/**
 * Sends a text message via the Messenger Platform.
 *
 * @param string $pageAccessToken Page Access Token.
 * @param string $recipientId PSID (Page-Scoped ID) of the recipient.
 * @param string $messageText Text of the message.
 * @param string $messagingType Messaging type. Defaults to RESPONSE.
 * @return array API response (e.g., message_id) or error.
 */
function sendTextMessage(string $pageAccessToken, string $recipientId, string $messageText, string $messagingType = 'RESPONSE'): array
{
    $url = "https://graph.facebook.com/v19.0/me/messages?access_token=" . urlencode($pageAccessToken);
    $payload = [
        'recipient' => ['id' => $recipientId],
        'message' => ['text' => $messageText],
        'messaging_type' => $messagingType
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return ['error' => $data['error']['message'], 'details' => $data['error'] ?? null];
    }
    return $data; 
}
/*
// Example for sendTextMessage:
// $pageAccessToken = 'VALID_PAGE_ACCESS_TOKEN_WITH_PAGES_MESSAGING';
// $recipientPsid = 'USER_PSID_FROM_WEBHOOK'; // Page-Scoped ID of the user
// $sendResult = sendTextMessage($pageAccessToken, $recipientPsid, "Hello from your Page!");
// print_r($sendResult);
*/

/**
 * Sends an image message via the Messenger Platform using a URL.
 *
 * @param string $pageAccessToken Page Access Token.
 * @param string $recipientId PSID of the recipient.
 * @param string $imageUrl URL of the image.
 * @param string $messagingType Messaging type. Defaults to RESPONSE.
 * @return array API response or error.
 */
function sendImageMessage(string $pageAccessToken, string $recipientId, string $imageUrl, string $messagingType = 'RESPONSE'): array
{
    $url = "https://graph.facebook.com/v19.0/me/messages?access_token=" . urlencode($pageAccessToken);
    $payload = [
        'recipient' => ['id' => $recipientId],
        'message' => [
            'attachment' => [
                'type' => 'image',
                'payload' => ['url' => $imageUrl, 'is_reusable' => false] 
            ]
        ],
        'messaging_type' => $messagingType
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return ['error' => $data['error']['message'], 'details' => $data['error'] ?? null];
    }
    return $data;
}
/*
// Example for sendImageMessage:
// $pageAccessToken = 'VALID_PAGE_ACCESS_TOKEN_WITH_PAGES_MESSAGING';
// $recipientPsid = 'USER_PSID_FROM_WEBHOOK';
// $publicImageUrl = 'https://www.example.com/image.jpg'; // Must be a public URL
// $sendResult = sendImageMessage($pageAccessToken, $recipientPsid, $publicImageUrl);
// print_r($sendResult);
*/

/**
 * Sends a button template message via the Messenger Platform.
 *
 * @param string $pageAccessToken Page Access Token.
 * @param string $recipientId PSID of the recipient.
 * @param string $text Text to display with the buttons.
 * @param array $buttons Array of button objects.
 * @param string $messagingType Messaging type. Defaults to RESPONSE.
 * @return array API response or error.
 */
function sendButtonTemplateMessage(string $pageAccessToken, string $recipientId, string $text, array $buttons, string $messagingType = 'RESPONSE'): array
{
    if (count($buttons) > 3) {
        return ['error' => 'Button template supports a maximum of 3 buttons.'];
    }

    $url = "https://graph.facebook.com/v19.0/me/messages?access_token=" . urlencode($pageAccessToken);
    $payload = [
        'recipient' => ['id' => $recipientId],
        'message' => [
            'attachment' => [
                'type' => 'template',
                'payload' => [
                    'template_type' => 'button',
                    'text' => $text,
                    'buttons' => $buttons
                ]
            ]
        ],
        'messaging_type' => $messagingType
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return ['error' => $data['error']['message'], 'details' => $data['error'] ?? null];
    }
    return $data;
}
/*
// Example for sendButtonTemplateMessage:
// $pageAccessToken = 'VALID_PAGE_ACCESS_TOKEN_WITH_PAGES_MESSAGING';
// $recipientPsid = 'USER_PSID_FROM_WEBHOOK';
// $buttons = [
//     ['type' => 'web_url', 'url' => 'https://www.example.com', 'title' => 'Visit Website'],
//     ['type' => 'postback', 'title' => 'Learn More', 'payload' => 'LEARN_MORE_PAYLOAD']
// ];
// $sendResult = sendButtonTemplateMessage($pageAccessToken, $recipientPsid, "What would you like to do?", $buttons);
// print_r($sendResult);
*/

// --- Setting Up Messenger Webhooks ---
/*
Setting Up Messenger Webhooks:
... (content as before) ...
*/

// --- Implementing a Messenger Chatbot (Conceptual) ---
/*
Implementing a Messenger Chatbot (Conceptual):
... (content as before) ...
*/

// --- INSTAGRAM GRAPH API FUNCTIONS START HERE ---

/*
Prerequisites Note:
... (content as before) ...
*/

// --- Content Publishing ---

/**
 * Publishes a photo directly to an Instagram account.
 *
 * @param string $accessToken User access token.
 * @param string $instagramAccountId Instagram Business Account ID.
 * @param string $imageUrl Publicly accessible URL of the photo.
 * @param string $caption (Optional) Caption for the photo.
 * @param ?string $userTags (Optional) JSON string of user tags.
 * @return array API response with media ID or error.
 */
function publishInstagramPhoto(string $accessToken, string $instagramAccountId, string $imageUrl, string $caption = '', ?string $userTags = null): array
{
    $url = "https://graph.facebook.com/v19.0/{$instagramAccountId}/media";
    $params = [
        'image_url' => $imageUrl,
        'caption' => $caption,
        'access_token' => $accessToken
    ];
    if ($userTags !== null) {
        $params['user_tags'] = $userTags;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return ['error' => $data['error']['message'], 'details' => $data['error'] ?? null];
    }

    if (!isset($data['id'])) {
        return ['error' => 'Failed to create media container or ID not returned.', 'response' => $data];
    }
    $creationId = $data['id'];
    return publishInstagramMediaContainer($accessToken, $instagramAccountId, $creationId);
}
/*
// Example for publishInstagramPhoto:
// $userTokenWithInstaPerms = 'VALID_USER_TOKEN_WITH_INSTAGRAM_CONTENT_PUBLISH';
// $instagramAccountId = 'YOUR_INSTAGRAM_BUSINESS_ACCOUNT_ID';
// $publicImageUrl = 'https://www.example.com/your-image.jpg';
// $photoResult = publishInstagramPhoto($userTokenWithInstaPerms, $instagramAccountId, $publicImageUrl, "My awesome Instagram photo!");
// print_r($photoResult);
*/

/**
 * Creates a media container for photos or videos on Instagram.
 *
 * @param string $accessToken User access token.
 * @param string $instagramAccountId Instagram Business Account ID.
 * @param ?string $imageUrl Publicly accessible URL of the photo (if photo).
 * @param ?string $videoUrl Publicly accessible URL of the video (if video).
 * @param ?string $caption (Optional) Caption for the media.
 * @param ?string $userTags (Optional) JSON string of user tags for photos.
 * @param bool $isVideo Indicates if it's a video container.
 * @param ?int $thumbOffset (Optional) Integer representing the timestamp of the video frame (in milliseconds) to be used as thumbnail.
 * @param ?string $locationId (Optional) Page ID of the location associated with the media.
 * @return array API response with creation ID or error.
 */
function createInstagramMediaContainer(
    string $accessToken,
    string $instagramAccountId,
    ?string $imageUrl = null,
    ?string $videoUrl = null,
    ?string $caption = null,
    ?string $userTags = null,
    bool $isVideo = false,
    ?int $thumbOffset = null,
    ?string $locationId = null
): array {
    $url = "https://graph.facebook.com/v19.0/{$instagramAccountId}/media";
    $params = ['access_token' => $accessToken];

    if ($isVideo) {
        if ($videoUrl === null) return ['error' => 'Video URL is required for video container.'];
        $params['media_type'] = 'VIDEO';
        $params['video_url'] = $videoUrl;
        if ($thumbOffset !== null) $params['thumb_offset'] = $thumbOffset;
    } else {
        if ($imageUrl === null) return ['error' => 'Image URL is required for photo container.'];
        $params['image_url'] = $imageUrl;
        if ($userTags !== null) $params['user_tags'] = $userTags;
    }

    if ($caption !== null) $params['caption'] = $caption;
    if ($locationId !== null) $params['location_id'] = $locationId;


    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return ['error' => $data['error']['message'], 'details' => $data['error'] ?? null];
    }
    if (!isset($data['id'])) {
         return ['error' => 'Media container creation did not return an ID.', 'response' => $data];
    }
    return $data; 
}
/*
// Example for createInstagramMediaContainer (photo):
// $userTokenWithInstaPerms = 'VALID_USER_TOKEN_WITH_INSTAGRAM_CONTENT_PUBLISH';
// $instagramAccountId = 'YOUR_INSTAGRAM_BUSINESS_ACCOUNT_ID';
// $publicImageUrl = 'https://www.example.com/your-image.jpg';
// $container = createInstagramMediaContainer($userTokenWithInstaPerms, $instagramAccountId, $publicImageUrl, null, "Caption for container");
// print_r($container);
*/

/**
 * Publishes a previously created media container to Instagram.
 *
 * @param string $accessToken User access token.
 * @param string $instagramAccountId Instagram Business Account ID.
 * @param string $creationId The ID of the media container (creation_id).
 * @return array API response with media ID or error.
 */
function publishInstagramMediaContainer(string $accessToken, string $instagramAccountId, string $creationId): array
{
    $url = "https://graph.facebook.com/v19.0/{$instagramAccountId}/media_publish";
    $params = [
        'creation_id' => $creationId,
        'access_token' => $accessToken
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        if (isset($data['error']['code']) && $data['error']['code'] === 9007) { 
            return ['status' => 'PENDING', 'message' => 'Media publishing is in progress.', 'details' => $data['error']];
        }
        return ['error' => $data['error']['message'], 'details' => $data['error'] ?? null];
    }
    return $data; 
}
/*
// Example for publishInstagramMediaContainer:
// $userTokenWithInstaPerms = 'VALID_USER_TOKEN_WITH_INSTAGRAM_CONTENT_PUBLISH';
// $instagramAccountId = 'YOUR_INSTAGRAM_BUSINESS_ACCOUNT_ID';
// $creationIdFromPreviousStep = 'CREATION_ID_FROM_CREATE_CONTAINER';
// $publishResult = publishInstagramMediaContainer($userTokenWithInstaPerms, $instagramAccountId, $creationIdFromPreviousStep);
// print_r($publishResult);
*/

/**
 * Publishes a video to an Instagram account (uses two-step process).
 *
 * @param string $accessToken User access token.
 * @param string $instagramAccountId Instagram Business Account ID.
 * @param string $videoUrl Publicly accessible URL of the video.
 * @param string $caption (Optional) Caption for the video.
 * @param ?int $thumbOffset (Optional) Timestamp for video thumbnail in ms.
 * @return array API response with media ID or error.
 */
function publishInstagramVideo(string $accessToken, string $instagramAccountId, string $videoUrl, string $caption = '', ?int $thumbOffset = null): array
{
    $containerResponse = createInstagramMediaContainer($accessToken, $instagramAccountId, null, $videoUrl, $caption, null, true, $thumbOffset);
    if (isset($containerResponse['error'])) {
        return $containerResponse;
    }
    if (!isset($containerResponse['id'])) {
        return ['error' => 'Failed to create video container or creation ID not found.', 'response' => $containerResponse];
    }
    $creationId = $containerResponse['id'];

    sleep(5); 

    return publishInstagramMediaContainer($accessToken, $instagramAccountId, $creationId);
}
/*
// Example for publishInstagramVideo:
// $userTokenWithInstaPerms = 'VALID_USER_TOKEN_WITH_INSTAGRAM_CONTENT_PUBLISH';
// $instagramAccountId = 'YOUR_INSTAGRAM_BUSINESS_ACCOUNT_ID';
// $publicVideoUrl = 'https://www.example.com/your-video.mp4';
// $videoResult = publishInstagramVideo($userTokenWithInstaPerms, $instagramAccountId, $publicVideoUrl, "My awesome Instagram video!");
// print_r($videoResult);
*/

// --- Media Retrieval ---

/**
 * Retrieves media for an Instagram account.
 *
 * @param string $accessToken User access token.
 * @param string $instagramAccountId Instagram Business Account ID.
 * @param string $mediaType Comma-separated string of media types.
 * @param int $limit Maximum number of media items to retrieve.
 * @param array $fields Array of fields to retrieve for each media item.
 * @return array API response with list of media or error.
 */
function getInstagramUserMedia(
    string $accessToken,
    string $instagramAccountId,
    string $mediaType = 'IMAGE,VIDEO,CAROUSEL_ALBUM',
    int $limit = 25,
    array $fields = ['id', 'caption', 'media_type', 'media_url', 'permalink', 'thumbnail_url', 'timestamp', 'username', 'like_count', 'comments_count']
): array {
    $url = "https://graph.facebook.com/v19.0/{$instagramAccountId}/media";
    $params = [
        'fields' => implode(',', $fields) . ",media_product_type", 
        'limit' => $limit,
        'access_token' => $accessToken
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return ['error' => $data['error']['message'], 'details' => $data['error'] ?? null];
    }
    return $data; 
}
/*
// Example for getInstagramUserMedia:
// $userTokenWithInstaPerms = 'VALID_USER_TOKEN_WITH_INSTAGRAM_BASIC';
// $instagramAccountId = 'YOUR_INSTAGRAM_BUSINESS_ACCOUNT_ID';
// $media = getInstagramUserMedia($userTokenWithInstaPerms, $instagramAccountId, 'IMAGE', 10);
// print_r($media);
*/

/**
 * Retrieves information about a specific Instagram media item.
 *
 * @param string $accessToken User access token.
 * @param string $mediaId ID of the Instagram media item.
 * @param array $fields Array of fields to retrieve.
 * @return array API response with media information or error.
 */
function getInstagramMediaInfo(string $accessToken, string $mediaId, array $fields = ['id', 'caption', 'media_type', 'media_url', 'permalink', 'like_count', 'comments_count', 'owner', 'timestamp', 'username']): array
{
    $url = "https://graph.facebook.com/v19.0/{$mediaId}";
    $params = [
        'fields' => implode(',', $fields),
        'access_token' => $accessToken
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return ['error' => $data['error']['message'], 'details' => $data['error'] ?? null];
    }
    return $data;
}
/*
// Example for getInstagramMediaInfo:
// $userTokenWithInstaPerms = 'VALID_USER_TOKEN_WITH_INSTAGRAM_BASIC';
// $instaMediaId = 'INSTAGRAM_MEDIA_ID_TO_QUERY';
// $mediaDetails = getInstagramMediaInfo($userTokenWithInstaPerms, $instaMediaId);
// print_r($mediaDetails);
*/

// --- Audience Engagement ---

/**
 * Retrieves comments on an Instagram post.
 *
 * @param string $accessToken User access token.
 * @param string $mediaId ID of the Instagram media item.
 * @param int $limit Maximum number of comments to retrieve.
 * @param array $fields Array of fields to retrieve for each comment.
 * @return array API response with list of comments or error.
 */
function getInstagramPostComments(string $accessToken, string $mediaId, int $limit = 25, array $fields = ['id', 'text', 'username', 'timestamp', 'like_count', 'replies']): array
{
    $url = "https://graph.facebook.com/v19.0/{$mediaId}/comments";
    $params = [
        'fields' => implode(',', $fields),
        'limit' => $limit,
        'access_token' => $accessToken
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return ['error' => $data['error']['message'], 'details' => $data['error'] ?? null];
    }
    return $data; 
}
/*
// Example for getInstagramPostComments:
// $userTokenWithInstaPerms = 'VALID_USER_TOKEN_WITH_INSTAGRAM_MANAGE_COMMENTS';
// $instaMediaIdWithComments = 'INSTAGRAM_MEDIA_ID_WITH_COMMENTS';
// $comments = getInstagramPostComments($userTokenWithInstaPerms, $instaMediaIdWithComments, 10);
// print_r($comments);
*/

/**
 * Replies to an Instagram comment.
 *
 * @param string $accessToken User access token.
 * @param string $commentId ID of the Instagram comment to reply to.
 * @param string $message The reply message.
 * @return array API response with reply ID or error.
 */
function replyToInstagramComment(string $accessToken, string $commentId, string $message): array
{
    $url = "https://graph.facebook.com/v19.0/{$commentId}/replies";
    $params = [
        'message' => $message,
        'access_token' => $accessToken
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return ['error' => $data['error']['message'], 'details' => $data['error'] ?? null];
    }
    return $data; 
}
/*
// Example for replyToInstagramComment:
// $userTokenWithInstaPerms = 'VALID_USER_TOKEN_WITH_INSTAGRAM_MANAGE_COMMENTS';
// $instaCommentIdToReplyTo = 'INSTAGRAM_COMMENT_ID';
// $replyResult = replyToInstagramComment($userTokenWithInstaPerms, $instaCommentIdToReplyTo, "Thanks for your comment!");
// print_r($replyResult);
*/

/**
 * Hides an Instagram comment.
 *
 * @param string $accessToken User access token.
 * @param string $commentId ID of the Instagram comment to hide.
 * @return array API response (e.g., {'success': true}) or error.
 */
function hideInstagramComment(string $accessToken, string $commentId): array
{
    $url = "https://graph.facebook.com/v19.0/{$commentId}";
    $params = [
        'hide' => 'true',
        'access_token' => $accessToken
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return ['error' => $data['error']['message'], 'details' => $data['error'] ?? null];
    }
    return $data;
}
/*
// Example for hideInstagramComment:
// $userTokenWithInstaPerms = 'VALID_USER_TOKEN_WITH_INSTAGRAM_MANAGE_COMMENTS';
// $instaCommentIdToHide = 'INSTAGRAM_COMMENT_ID';
// $hideResult = hideInstagramComment($userTokenWithInstaPerms, $instaCommentIdToHide);
// print_r($hideResult);
*/

/**
 * Unhides an Instagram comment.
 *
 * @param string $accessToken User access token.
 * @param string $commentId ID of the Instagram comment to unhide.
 * @return array API response (e.g., {'success': true}) or error.
 */
function unhideInstagramComment(string $accessToken, string $commentId): array
{
    $url = "https://graph.facebook.com/v19.0/{$commentId}";
    $params = [
        'hide' => 'false',
        'access_token' => $accessToken
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return ['error' => $data['error']['message'], 'details' => $data['error'] ?? null];
    }
    return $data;
}
/*
// Example for unhideInstagramComment:
// $userTokenWithInstaPerms = 'VALID_USER_TOKEN_WITH_INSTAGRAM_MANAGE_COMMENTS';
// $instaCommentIdToUnhide = 'INSTAGRAM_COMMENT_ID';
// $unhideResult = unhideInstagramComment($userTokenWithInstaPerms, $instaCommentIdToUnhide);
// print_r($unhideResult);
*/

// --- Performance Analysis ---

/**
 * Retrieves insights for specific Instagram media.
 *
 * @param string $accessToken User access token.
 * @param string $mediaId ID of the Instagram media item.
 * @param array $metrics Array of insight metrics.
 * @return array API response with insights data or error.
 */
function getInstagramMediaInsights(string $accessToken, string $mediaId, array $metrics = ['engagement', 'impressions', 'reach', 'saved']): array
{
    $url = "https://graph.facebook.com/v19.0/{$mediaId}/insights";
    $params = [
        'metric' => implode(',', $metrics),
        'access_token' => $accessToken
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return ['error' => $data['error']['message'], 'details' => $data['error'] ?? null];
    }
    return $data; 
}
/*
// Example for getInstagramMediaInsights:
// $userTokenWithInstaPerms = 'VALID_USER_TOKEN_WITH_INSTAGRAM_MANAGE_INSIGHTS';
// $instaMediaIdForInsights = 'INSTAGRAM_MEDIA_ID';
// $mediaInsights = getInstagramMediaInsights($userTokenWithInstaPerms, $instaMediaIdForInsights, ['impressions', 'reach', 'likes']);
// print_r($mediaInsights);
*/

/**
 * Retrieves insights for an Instagram user account.
 *
 * @param string $accessToken User access token.
 * @param string $instagramAccountId Instagram Business Account ID.
 * @param array $metrics Array of user-level metrics.
 * @param string $period Aggregation period.
 * @param ?string $since Unix timestamp or parsable date string for start of range.
 * @param ?string $until Unix timestamp or parsable date string for end of range.
 * @return array API response with user insights data or error.
 */
function getInstagramUserInsights(
    string $accessToken,
    string $instagramAccountId,
    array $metrics = ['impressions', 'reach', 'follower_count', 'profile_views'],
    string $period = 'day',
    ?string $since = null,
    ?string $until = null
): array { 
    $url = "https://graph.facebook.com/v19.0/{$instagramAccountId}/insights";
    $params = [
        'metric' => implode(',', $metrics),
        'period' => $period,
        'access_token' => $accessToken
    ];
    if ($since !== null) $params['since'] = $since;
    if ($until !== null) $params['until'] = $until;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return ['error' => $data['error']['message'], 'details' => $data['error'] ?? null];
    }
    return $data; 
}
/*
// Example for getInstagramUserInsights:
// $userTokenWithInstaPerms = 'VALID_USER_TOKEN_WITH_INSTAGRAM_MANAGE_INSIGHTS';
// $instagramAccountId = 'YOUR_INSTAGRAM_BUSINESS_ACCOUNT_ID';
// $userInsights = getInstagramUserInsights($userTokenWithInstaPerms, $instagramAccountId, ['follower_count', 'reach'], 'week');
// print_r($userInsights);
*/

// --- Instagram Story Mentions & Real-time Updates ---
/*
Instagram Story Mentions & Real-time Updates:
... (content as before) ...
*/

// --- GENERAL INSIGHTS API FUNCTIONS (PAGE, POST) START HERE ---

/**
 * Retrieves insights for a Facebook Page.
 *
 * @param string $accessToken Page Access Token.
 * @param string $pageId ID of the Facebook Page.
 * @param array $metrics Array of metric names.
 * @param string $period Aggregation period.
 * @param ?string $since Unix timestamp or parsable date string for start of range.
 * @param ?string $until Unix timestamp or parsable date string for end of range.
 * @return array API response with Page insights data or error.
 */
function getPageInsights(
    string $accessToken,
    string $pageId,
    array $metrics = ['page_impressions', 'page_engaged_users', 'page_fan_adds'],
    string $period = 'day',
    ?string $since = null,
    ?string $until = null
): array {
    $url = "https://graph.facebook.com/v19.0/{$pageId}/insights";
    $params = [
        'metric' => implode(',', $metrics),
        'period' => $period,
        'access_token' => $accessToken
    ];

    if ($since !== null) {
        $params['since'] = $since;
    }
    if ($until !== null) {
        $params['until'] = $until;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return ['error' => $data['error']['message'], 'details' => $data['error'] ?? null];
    }
    return $data; 
}
/*
// Example for getPageInsights:
// $pageAccessToken = 'VALID_PAGE_ACCESS_TOKEN_WITH_READ_INSIGHTS';
// $pageId = 'YOUR_FACEBOOK_PAGE_ID';
// $pageInsights = getPageInsights($pageAccessToken, $pageId, ['page_views_total', 'page_actions_post_reactions_like_total'], 'days_28');
// print_r($pageInsights);
*/

/**
 * Retrieves insights for a specific Facebook Post.
 *
 * @param string $accessToken Page or User Access Token with permissions for the Page owning the post.
 * @param string $postId ID of the Facebook Post (e.g., PAGEID_POSTID).
 * @param array $metrics Array of metric names.
 * @return array API response with Post insights data or error.
 */
function getPostInsights(
    string $accessToken,
    string $postId,
    array $metrics = ['post_impressions', 'post_engaged_users', 'post_clicks']
): array {
    $url = "https://graph.facebook.com/v19.0/{$postId}/insights";
    $params = [
        'metric' => implode(',', $metrics),
        'access_token' => $accessToken
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        return ['error' => $data['error']['message'], 'details' => $data['error'] ?? null];
    }
    return $data; 
}
/*
// Example for getPostInsights:
// $pageAccessToken = 'VALID_PAGE_ACCESS_TOKEN_WITH_READ_INSIGHTS';
// $postId = 'YOUR_PAGE_ID_POST_ID';
// $postInsights = getPostInsights($pageAccessToken, $postId, ['post_impressions_unique', 'post_engaged_fan']);
// print_r($postInsights);
*/

// --- Retrieving Video Insights (Conceptual) ---
/*
Retrieving Video Insights:
... (content as before) ...
*/

// --- Facebook Login (Client-Side Flow Overview) ---
/*
Facebook Login (Client-Side Flow Overview):
... (content as before) ...
*/

// --- Webhooks (General Importance & Mechanism) ---
/*
Webhooks (General Importance & Mechanism):
... (content as before) ...
*/

// --- App Events API (Purpose) ---
/*
App Events API (Purpose):
... (content as before) ...
*/

// --- Product Catalogs (Purpose) ---
/*
Product Catalogs (Purpose):
... (content as before) ...
*/

// --- Consolidated Demo Usage Examples ---
/*
// --- Placeholder Variables ---
$appId = 'YOUR_APP_ID'; // Replace with your App ID
$appSecret = 'YOUR_APP_SECRET'; // Replace with your App Secret
$redirectUri = 'YOUR_REDIRECT_URI'; // e.g., https://yourdomain.com/callback.php, must be configured in App Dashboard
$pageId = 'YOUR_PAGE_ID'; // Replace with a Page ID you manage
$instagramAccountId = 'YOUR_INSTAGRAM_BUSINESS_ACCOUNT_ID'; // Obtain via /me/accounts -> instagram_business_account
$adAccountId = 'act_YOUR_AD_ACCOUNT_ID'; // Remember the 'act_' prefix for Ad Account ID

// These would typically be obtained via the OAuth flow and stored securely
$userAccessToken = 'USER_ACCESS_TOKEN_OBTAINED_VIA_OAUTH'; // Replace with a valid long-lived User Access Token
$pageAccessToken = 'PAGE_ACCESS_TOKEN_OBTAINED_VIA_GET_PAGE_ACCESS_TOKEN'; // Replace with a valid Page Access Token

$examplePhotoPath = __DIR__ . '/test_photo.jpg'; // Ensure this file exists for upload demos
$exampleVideoPath = __DIR__ . '/test_video.mp4'; // Ensure this file exists for upload demos

// Create dummy files if they don't exist for testing (simple text files for demo)
// if (!file_exists($examplePhotoPath)) { @file_put_contents($examplePhotoPath, "dummy photo data"); }
// if (!file_exists($exampleVideoPath)) { @file_put_contents($exampleVideoPath, "dummy video data"); }

// --- 1. Authentication Flow (Conceptual) ---
// Step 1.1: Generate Authorization URL
// $scopesForAuth = [
//     'public_profile', 'email', // Basic user info
//     'pages_show_list', 'pages_read_engagement', 'read_insights', 'pages_manage_posts', 'publish_video', // Facebook Page
//     'instagram_basic', 'instagram_content_publish', 'instagram_manage_comments', 'instagram_manage_insights', // Instagram
//     'ads_management', 'ads_read', // Marketing API
//     'pages_messaging', // Messenger
//     'business_management' // If managing Business assets
// ];
// if ($appId !== 'YOUR_APP_ID' && $redirectUri !== 'YOUR_REDIRECT_URI') {
//    $authUrl = getOAuthUrl($appId, $redirectUri, $scopesForAuth);
//    echo "Authorize here: " . $authUrl . "\n";
//    echo "After authorization, you will be redirected to your redirect URI with a 'code' parameter.\n";
// } else {
//    echo "Skipping Auth URL generation: App ID or Redirect URI is a placeholder.\n";
// }


// Step 1.2: Exchange Code for Access Token (on your redirect URI page - e.g., callback.php)
// $code = $_GET['code'] ?? null; 
// if ($code && $appId !== 'YOUR_APP_ID' && $appSecret !== 'YOUR_APP_SECRET' && $redirectUri !== 'YOUR_REDIRECT_URI') {
//     $tokenData = getAccessToken($appId, $appSecret, $redirectUri, $code);
//     if (isset($tokenData['access_token'])) {
//         $shortLivedUserToken = $tokenData['access_token'];
//         echo "Short-lived User Access Token: " . $shortLivedUserToken . "\n";

//         // Step 1.3: Get Long-Lived User Access Token
//         $longLivedTokenData = getLongLivedUserAccessToken($appId, $appSecret, $shortLivedUserToken);
//         if (isset($longLivedTokenData['access_token'])) {
//             $userAccessToken = $longLivedTokenData['access_token']; // Store this securely!
//             echo "Long-lived User Access Token: " . $userAccessToken . "\n";

//             // Step 1.4: Get Page Access Token (if managing a page)
//             if ($pageId !== 'YOUR_PAGE_ID' && $userAccessToken !== 'USER_ACCESS_TOKEN_OBTAINED_VIA_OAUTH') { 
//                 $pageAccessTokenData = getPageAccessToken($userAccessToken, $pageId);
//                 if (isset($pageAccessTokenData['page_access_token'])) { 
//                     $pageAccessToken = $pageAccessTokenData['page_access_token']; // Store this securely!
//                     echo "Page Access Token: " . $pageAccessToken . "\n";
//                 } else {
//                     echo "Error getting Page Access Token: " . ($pageAccessTokenData['error'] ?? 'Unknown error') . "\n";
//                 }
//             } else {
//                  echo "Skipping getPageAccessToken: pageId or userAccessToken is a placeholder or not yet obtained.\n";
//             }
//         } else {
//             echo "Error getting Long-Lived Token: " . ($longLivedTokenData['error'] ?? 'Unknown error') . "\n";
//         }
//     } else {
//         echo "Error getting Access Token: " . ($tokenData['error'] ?? 'Unknown error') . "\n";
//     }
// } else {
//     // echo "No authorization code received. Please visit the auth URL first or check placeholder variables.\n";
// }

// --- 2. Example API Calls (Assuming you have valid tokens and IDs from the flow above or configuration) ---

// // Example: Get User Profile (using User Access Token)
// if ($userAccessToken !== 'USER_ACCESS_TOKEN_OBTAINED_VIA_OAUTH') {
//    $userProfile = getUserProfile($userAccessToken);
//    echo "\nUser Profile:\n"; print_r($userProfile); echo "\n";
// } else { echo "Skipping getUserProfile: userAccessToken is a placeholder.\n"; }

// // Example: Get Page Info (using Page Access Token or User Access Token if user is admin)
// if ($pageAccessToken !== 'PAGE_ACCESS_TOKEN_OBTAINED_VIA_GET_PAGE_ACCESS_TOKEN' && $pageId !== 'YOUR_PAGE_ID') {
//    $pageInfo = getPageInfo($pageAccessToken, $pageId);
//    echo "\nPage Info:\n"; print_r($pageInfo); echo "\n";
// } else { echo "Skipping getPageInfo: pageAccessToken or pageId is a placeholder.\n"; }

// // Example: Post to Page (using Page Access Token)
// if ($pageAccessToken !== 'PAGE_ACCESS_TOKEN_OBTAINED_VIA_GET_PAGE_ACCESS_TOKEN' && $pageId !== 'YOUR_PAGE_ID') {
//    $postedMessage = postToPage($pageAccessToken, $pageId, "Hello from my PHP script! Timestamp: " . time(), "https://developers.facebook.com");
//    echo "\nPosted to Page:\n"; print_r($postedMessage); echo "\n";
// } else { echo "Skipping postToPage: pageAccessToken or pageId is a placeholder.\n"; }

// // Example: Send a Messenger Text Message (using Page Access Token)
// $recipientPsid = 'USER_PSID_WHO_MESSAGED_YOUR_PAGE'; // Obtain this from a webhook event when a user messages your page
// if ($pageAccessToken !== 'PAGE_ACCESS_TOKEN_OBTAINED_VIA_GET_PAGE_ACCESS_TOKEN' && $recipientPsid !== 'USER_PSID_WHO_MESSAGED_YOUR_PAGE') { 
//    $sentStatus = sendTextMessage($pageAccessToken, $recipientPsid, "Hello from the API!");
//    echo "\nMessenger Send Status:\n"; print_r($sentStatus); echo "\n";
// } else {
//    echo "Skipping Messenger send: pageAccessToken or recipientPsid is a placeholder.\n";
// }

// // Example: Get Instagram User Media (using User Access Token with Instagram permissions)
// if ($userAccessToken !== 'USER_ACCESS_TOKEN_OBTAINED_VIA_OAUTH' && $instagramAccountId !== 'YOUR_INSTAGRAM_BUSINESS_ACCOUNT_ID') {
//    $instagramMedia = getInstagramUserMedia($userAccessToken, $instagramAccountId);
//    echo "\nInstagram Media:\n"; print_r($instagramMedia); echo "\n";
// } else { echo "Skipping getInstagramUserMedia: userAccessToken or instagramAccountId is a placeholder.\n"; }

// // Example: Create Ad Campaign (using User Access Token with Ads permissions)
// if ($userAccessToken !== 'USER_ACCESS_TOKEN_OBTAINED_VIA_OAUTH' && $adAccountId !== 'act_YOUR_AD_ACCOUNT_ID') {
//    $campaignData = createAdCampaign($userAccessToken, $adAccountId, 'My API Test Campaign - ' . time(), 'LINK_CLICKS', 'PAUSED');
//    echo "\nCreated Ad Campaign:\n"; print_r($campaignData); echo "\n";
// } else { echo "Skipping createAdCampaign: userAccessToken or adAccountId is a placeholder.\n"; }


// // Example: Get Page Insights (using Page Access Token)
// if ($pageAccessToken !== 'PAGE_ACCESS_TOKEN_OBTAINED_VIA_GET_PAGE_ACCESS_TOKEN' && $pageId !== 'YOUR_PAGE_ID') {
//    $insights = getPageInsights($pageAccessToken, $pageId, ['page_impressions', 'page_engaged_users'], 'days_28');
//    echo "\nPage Insights:\n"; print_r($insights); echo "\n";
// } else { echo "Skipping getPageInsights: pageAccessToken or pageId is a placeholder.\n"; }
*/

?>
