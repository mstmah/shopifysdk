<?php declare(strict_types=1);
/**
 * Webhook handler for incoming Instagram comments via the Meta Graph API (Instagram Graph API).
 * 
 * Responsibilities:
 * - Verifies webhook GET requests from Meta (for initial setup).
 * - Verifies the signature of incoming POST request payloads to ensure authenticity.
 * - Logs all incoming raw webhook events to the `webhook_logs` table.
 * - Parses new comments on Instagram media.
 * - Stores parsed comments into the `instagram_post_comments` table.
 * - Calls the `GeminiService` to generate an AI-powered reply suggestion for the comment.
 * - Logs the Gemini interaction to the `gemini_interactions_log` table.
 * - Sends the AI-generated reply back to the Instagram comment using the `replyToInstagramComment` function.
 * - Responds with HTTP 200 to Meta platform for all valid POST events.
 * - Responds with appropriate HTTP error codes for failures.
 *
 * Note: This webhook specifically handles Instagram 'comments' field changes. 
 * It uses a Facebook Page Access Token that is linked to the Instagram Business Account and has
 * necessary permissions (instagram_basic, instagram_manage_comments, pages_show_list).
 */

require_once 'db_manager.php';
require_once 'gemini_service.php';
require_once 'meta_api_functions.php'; // For sending replies

// --- Configuration Variables ---
// It's crucial to manage these securely, ideally via environment variables.
define('INSTAGRAM_VERIFY_TOKEN', getenv('INSTAGRAM_VERIFY_TOKEN') ?: 'your_instagram_verify_token_here_67890'); 
define('APP_SECRET', getenv('META_APP_SECRET') ?: 'your_facebook_app_secret_here'); 
// This Page Access Token must be for a Facebook Page linked to the Instagram Business Account.
// Required permissions: instagram_basic, instagram_manage_comments, pages_show_list.
define('PAGE_ACCESS_TOKEN', getenv('META_PAGE_ACCESS_TOKEN_FOR_INSTAGRAM') ?: 'YOUR_FACEBOOK_PAGE_TOKEN_LINKED_TO_INSTAGRAM_HERE'); 

// Handle Webhook Verification (GET request)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = $_GET['hub_mode'] ?? null;
    $token = $_GET['hub_verify_token'] ?? null;
    $challenge = $_GET['hub_challenge'] ?? null;

    if ($mode === 'subscribe' && $token === INSTAGRAM_VERIFY_TOKEN) {
        http_response_code(200);
        echo $challenge;
        error_log("Instagram webhook verified successfully. Token: " . $token);
        exit;
    } else {
        error_log("Instagram webhook verification failed. Mode: " . ($mode ?? 'null') . " Received Token: " . ($token ?? 'null') . " Expected: " . INSTAGRAM_VERIFY_TOKEN);
        http_response_code(403); // Forbidden
        exit;
    }
}

// Handle Event Data (POST request)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = file_get_contents('php://input');
    $signatureHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

    // Step 1: Verify payload signature
    if (empty($signatureHeader)) {
        error_log("Instagram webhook error: Signature header (X-Hub-Signature-256) missing.");
        http_response_code(400); // Bad Request
        exit;
    }
    list($algo, $hash) = explode('=', $signatureHeader, 2);
    if ($algo !== 'sha256') {
        error_log("Instagram webhook error: Invalid signature algorithm. Expected sha256, got " . $algo);
        http_response_code(400); 
        exit;
    }
    $expectedSignature = hash_hmac('sha256', $payload, APP_SECRET);
    if (!hash_equals($expectedSignature, $hash)) {
        error_log("Instagram webhook error: Signature verification failed. Ensure APP_SECRET is correctly set for Instagram webhooks.");
        // TODO: ALERT - Instagram webhook signature verification failed. Potential security event.
        http_response_code(403); // Forbidden
        exit;
    }

    // Step 2: Decode JSON payload
    $data = json_decode($payload, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Instagram webhook error: Invalid JSON payload. Error: " . json_last_error_msg() . ". Payload snippet: " . substr($payload, 0, 500));
        http_response_code(400); // Bad Request
        exit;
    }

    // Step 3: Platform identification and initial logging
    $platform = 'instagram'; // Hardcoded for this specific webhook file
    $initialEventType = $data['object'] ?? 'unknown_event'; // Should be 'instagram'

    // Step 4: Log the raw incoming event
    try {
        $logSql = "INSERT INTO webhook_logs (platform, event_type, raw_data, processing_status, received_at) 
                   VALUES (:platform, :event_type, :raw_data, 'received', NOW())";
        DBManager::executeNonQuery($logSql, [
            ':platform' => $platform,
            ':event_type' => $initialEventType, 
            ':raw_data' => $payload
        ]);
    } catch (PDOException $e) {
        error_log("Instagram webhook DB (initial log) error: " . $e->getMessage() . ". Payload snippet: " . substr($payload, 0, 500));
        // TODO: ALERT - Critical: Failed to log incoming Instagram webhook to DB.
    }
    
    /* 
    Conceptual Instagram Comment Webhook Payload Structure:
    {
        "object": "instagram",
        "entry": [{
            "id": "INSTAGRAM_BUSINESS_ACCOUNT_ID", 
            "time": UNIX_TIMESTAMP, // Timestamp of the event
            "changes": [{
                "field": "comments", // Can also be 'mentions' for story mentions, 'story_insights', etc.
                "value": {
                    "id": "PLATFORM_COMMENT_ID", // The ID of the comment itself
                    "media": {
                        "id": "MEDIA_ID_OF_THE_POST", // The ID of the media object (post) the comment is on
                        "ig_id": "LEGACY_IG_MEDIA_ID" // (Optional) Legacy ID for the media
                        // "owner_id": "MEDIA_OWNER_IG_BUSINESS_ACCOUNT_ID" (Sometimes present)
                    },
                    "text": "COMMENT_TEXT",
                    "from": {
                        "id": "INSTAGRAM_USER_ID_OF_COMMENTER", // Numeric IG User ID of the commenter
                        "username": "COMMENTER_USERNAME"
                    },
                    // "timestamp": "ISO_8601_DATETIME" (Not always present in this part of payload for comments, rely on entry time or NOW())
                    // "parent_id": "PARENT_COMMENT_ID" (If it's a reply to another comment - not handled for AI replies in this version)
                }
            }]
        }]
    }
    */
    // Step 5: Process Instagram Comment Events
    if ($initialEventType === 'instagram') {
        foreach ($data['entry'] as $entry) {
            $instagramAccountId = $entry['id'] ?? null; // This is the Instagram Business Account ID that received the event
            foreach ($entry['changes'] as $change) {
                // Check if the change is for the 'comments' field and it's a new comment
                if ($change['field'] === 'comments' && isset($change['value']['id'])) {
                    $commentData = $change['value'];
                    $platformCommentId = $commentData['id'] ?? null;
                    $mediaId = $commentData['media']['id'] ?? null;
                    $commentText = $commentData['text'] ?? '';
                    $commenterId = $commentData['from']['id'] ?? null; // Instagram User ID of the commenter
                    $commenterUsername = $commentData['from']['username'] ?? null;
                    // Instagram comment webhooks might not provide a 'created_time' for the comment directly in 'value'.
                    // Using the 'time' from the 'entry' object (timestamp of the event) as a reliable source.
                    $commentTimestamp = $entry['time'] ?? time(); 

                    if (!$platformCommentId || !$mediaId || !$commenterId || !$instagramAccountId) {
                        error_log("Instagram webhook: Missing essential comment data. CommentID: {$platformCommentId}, MediaID: {$mediaId}, CommenterID: {$commenterId}, IGAccountID: {$instagramAccountId}. Data: " . json_encode($commentData));
                        continue; // Skip this comment
                    }
                    
                    $dbCommentEntryId = null;
                    try {
                        // 5.1: Store incoming Instagram comment
                        $insertCommentSql = "INSERT INTO instagram_post_comments 
                                             (instagram_account_id, media_id, platform_comment_id, commenter_id, commenter_username, comment_text, comment_timestamp, is_hidden, created_at, updated_at)
                                             VALUES (:ig_account_id, :media_id, :platform_comment_id, :commenter_id, :commenter_username, :comment_text, FROM_UNIXTIME(:comment_timestamp), 0, NOW(), NOW())";
                        DBManager::executeNonQuery($insertCommentSql, [
                            ':ig_account_id' => $instagramAccountId,
                            ':media_id' => $mediaId,
                            ':platform_comment_id' => $platformCommentId,
                            ':commenter_id' => $commenterId,
                            ':commenter_username' => $commenterUsername,
                            ':comment_text' => $commentText,
                            ':comment_timestamp' => $commentTimestamp
                        ]);
                        $dbCommentEntryId = DBManager::getLastInsertId();
                        error_log("Instagram: Stored comment {$dbCommentEntryId} (Platform Comment ID: {$platformCommentId}) for media {$mediaId}");

                        // 5.2: Prepare prompt and call Gemini
                        $prompt = "User '{$commenterUsername}' commented on an Instagram post: \"{$commentText}\". Provide a helpful and concise reply for a social media manager.";
                        $aiResponseText = GeminiService::getGeminiResponse($prompt);
                        $geminiLogId = null;
                        
                        if ($aiResponseText !== null) {
                            error_log("Instagram: Gemini response for comment {$platformCommentId}: \"{$aiResponseText}\"");
                            // 5.3: Log Gemini interaction
                            $logGeminiSql = "INSERT INTO gemini_interactions_log (related_item_id, item_type, prompt_text, raw_response, created_at)
                                             VALUES (:related_item_id, 'instagram_comment', :prompt, :response, NOW())";
                            DBManager::executeNonQuery($logGeminiSql, [
                                ':related_item_id' => $dbCommentEntryId,
                                ':prompt' => $prompt,
                                ':response' => $aiResponseText
                            ]);
                            $geminiLogId = DBManager::getLastInsertId();

                            // 5.4: Send reply via Instagram API using the Page Access Token linked to the IG account
                            if (PAGE_ACCESS_TOKEN !== 'YOUR_FACEBOOK_PAGE_TOKEN_LINKED_TO_INSTAGRAM_HERE') {
                                $replyResult = replyToInstagramComment(PAGE_ACCESS_TOKEN, $platformCommentId, $aiResponseText);
                                if (isset($replyResult['id'])) { // Instagram reply success usually returns the ID of the reply comment
                                    error_log("Instagram: Successfully sent AI reply to comment {$platformCommentId}. Reply ID: " . $replyResult['id']);
                                    // Optionally, store this reply ID back into instagram_post_comments or a new table for replies
                                    // For now, we just log it. This could be a future enhancement.
                                } else {
                                    error_log("Instagram: Failed to send AI reply to comment {$platformCommentId}. Response: " . json_encode($replyResult));
                                    // TODO: ALERT - Instagram reply failed for comment {$platformCommentId}.
                                }
                            } else {
                                error_log("Instagram: PAGE_ACCESS_TOKEN is a placeholder. Cannot send reply to comment {$platformCommentId}.");
                            }
                        } else { // Gemini failed
                            error_log("Instagram: Gemini returned null for comment {$platformCommentId}. Prompt: {$prompt}");
                            // Log Gemini interaction failure
                            $logGeminiFailureSql = "INSERT INTO gemini_interactions_log (related_item_id, item_type, prompt_text, raw_response, status, created_at)
                                             VALUES (:related_item_id, 'instagram_comment', :prompt, :response, 'failed', NOW())";
                            DBManager::executeNonQuery($logGeminiFailureSql, [
                                ':related_item_id' => $dbCommentEntryId,
                                ':prompt' => $prompt,
                                ':response' => 'Gemini returned null or empty.'
                            ]);
                            // TODO: ALERT - Gemini failed to generate response for Instagram comment {$platformCommentId}.
                        }

                    } catch (PDOException $e) { // Catch DB errors during comment processing
                        error_log("Instagram webhook DB error processing comment: " . $e->getMessage() . " - Data: " . json_encode($commentData));
                        // TODO: ALERT - Critical DB error processing Instagram comment.
                    } catch (Exception $e) { // Catch other general errors
                        error_log("Instagram webhook general error processing comment: " . $e->getMessage() . " - Data: " . json_encode($commentData));
                    }
                } // End if field is 'comments'
                // TODO: Handle other Instagram 'changes' fields like 'mentions' if needed.
            } // End foreach changes
        } // End foreach entry
    } // --- End Instagram Processing ---

    // Always respond 200 OK to Meta platform to acknowledge receipt.
    http_response_code(200);
    exit;

} else {
    error_log("Webhook (Instagram) received non-GET/POST request: " . $_SERVER['REQUEST_METHOD']);
    http_response_code(405); // Method Not Allowed
    exit;
}

?>
