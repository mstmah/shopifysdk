<?php declare(strict_types=1);
/**
 * Webhook handler for incoming Facebook Page feed comments via the Meta Graph API.
 * 
 * Responsibilities:
 * - Verifies webhook GET requests from Meta (for initial setup).
 * - Verifies the signature of incoming POST request payloads to ensure authenticity.
 * - Logs all incoming raw webhook events to the `webhook_logs` table.
 * - Parses new comments made on Facebook Page posts (specifically 'feed' changes of item 'comment' and verb 'add').
 * - Stores parsed comments into the `page_post_comments` table.
 * - Calls the `GeminiService` to generate an AI-powered reply suggestion for the comment.
 * - Logs the Gemini interaction to the `gemini_interactions_log` table.
 * - Sends the AI-generated reply as a comment reply on the Facebook Page post using `commentOnPost`.
 * - Responds with HTTP 200 to Meta platform for all valid POST events.
 * - Responds with appropriate HTTP error codes for failures.
 *
 * Note: This webhook specifically handles 'feed' changes related to 'comment' items.
 * It uses a Facebook Page Access Token with necessary permissions (pages_manage_posts, pages_read_engagement).
 */

require_once 'db_manager.php';
require_once 'gemini_service.php';
require_once 'meta_api_functions.php'; // For sending replies

// --- Configuration Variables ---
// It's crucial to manage these securely, ideally via environment variables.
define('FACEBOOK_PAGE_VERIFY_TOKEN', getenv('FACEBOOK_PAGE_VERIFY_TOKEN') ?: 'your_facebook_page_verify_token_here_ABCDE'); 
define('APP_SECRET', getenv('META_APP_SECRET') ?: 'your_facebook_app_secret_here'); 
// This Page Access Token must be for the specific Facebook Page being managed.
// Required permissions: pages_manage_posts, pages_read_engagement.
define('PAGE_ACCESS_TOKEN', getenv('META_PAGE_ACCESS_TOKEN_FOR_FACEBOOK') ?: 'YOUR_SPECIFIC_FACEBOOK_PAGE_ACCESS_TOKEN_HERE'); 

// Handle Webhook Verification (GET request)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = $_GET['hub_mode'] ?? null;
    $token = $_GET['hub_verify_token'] ?? null;
    $challenge = $_GET['hub_challenge'] ?? null;

    if ($mode === 'subscribe' && $token === FACEBOOK_PAGE_VERIFY_TOKEN) {
        http_response_code(200);
        echo $challenge;
        error_log("Facebook Page webhook verified successfully. Token: " . $token);
        exit;
    } else {
        error_log("Facebook Page webhook verification failed. Mode: " . ($mode ?? 'null') . " Received Token: " . ($token ?? 'null') . " Expected: " . FACEBOOK_PAGE_VERIFY_TOKEN);
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
        error_log("Facebook Page webhook error: Signature header (X-Hub-Signature-256) missing.");
        http_response_code(400); // Bad Request
        exit;
    }
    list($algo, $hash) = explode('=', $signatureHeader, 2);
     if ($algo !== 'sha256') {
        error_log("Facebook Page webhook error: Invalid signature algorithm. Expected sha256, got " . $algo);
        http_response_code(400); 
        exit;
    }
    $expectedSignature = hash_hmac('sha256', $payload, APP_SECRET);
    if (!hash_equals($expectedSignature, $hash)) {
        error_log("Facebook Page webhook error: Signature verification failed. Ensure APP_SECRET is correctly set.");
        // TODO: ALERT - Facebook Page webhook signature verification failed. Potential security event.
        http_response_code(403); // Forbidden
        exit;
    }

    // Step 2: Decode JSON payload
    $data = json_decode($payload, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Facebook Page webhook error: Invalid JSON payload. Error: " . json_last_error_msg() . ". Payload snippet: " . substr($payload, 0, 500));
        http_response_code(400); // Bad Request
        exit;
    }

    // Step 3: Platform identification and initial logging
    $platform = 'facebook_page'; // Hardcoded for this specific webhook file
    $initialEventType = $data['object'] ?? 'unknown_event'; // Should be 'page' for Facebook Page events

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
        error_log("Facebook Page webhook DB (initial log) error: " . $e->getMessage() . ". Payload snippet: " . substr($payload, 0, 500));
        // TODO: ALERT - Critical: Failed to log incoming Facebook Page webhook to DB.
    }
    
    /* 
    Conceptual Facebook Page Comment Webhook Payload Structure (for a new comment on a post):
    {
        "object": "page",
        "entry": [{
            "id": "PAGE_ID", // ID of the Facebook Page
            "time": UNIX_TIMESTAMP, // Timestamp of the event
            "changes": [{
                "field": "feed",
                "value": {
                    "item": "comment",
                    "verb": "add", // Can be "edited", "deleted"
                    "comment_id": "PLATFORM_COMMENT_ID", // Full comment ID (postid_commentid or pageid_commentid)
                    "post_id": "PAGEID_POSTID", // Full post ID
                    "message": "COMMENT_TEXT",
                    "from": {
                        "id": "USER_ID_OF_COMMENTER", // Page-Scoped User ID (PSID) or global User ID
                        "name": "COMMENTER_NAME"
                    },
                    "created_time": UNIX_TIMESTAMP_FOR_COMMENT 
                    // "parent_id": "PARENT_COMMENT_ID" (If it's a reply to another comment - not handled for AI replies in this version)
                }
            }]
        }]
    }
    */
    // Step 5: Process Facebook Page Feed Comment Events
    if ($initialEventType === 'page') {
        foreach ($data['entry'] as $entry) {
            $pageIdFromEntry = $entry['id'] ?? null; // Page ID that received the event

            foreach ($entry['changes'] as $change) {
                // Check if the change is for the 'feed' and specifically a new 'comment' with 'add' verb
                if ($change['field'] === 'feed' && isset($change['value']['item']) && $change['value']['item'] === 'comment' && isset($change['value']['verb']) && $change['value']['verb'] === 'add') {
                    
                    $feedItem = $change['value'];
                    $platformCommentId = $feedItem['comment_id'] ?? null; // This is the key for replying
                    $postId = $feedItem['post_id'] ?? null;
                    $commentMessage = $feedItem['message'] ?? '';
                    $commenterId = $feedItem['from']['id'] ?? null; // PSID or User ID
                    $commenterName = $feedItem['from']['name'] ?? null;
                    // Timestamp for feed comments is usually 'created_time' in the value node
                    $commentTimestamp = $feedItem['created_time'] ?? time();

                    if (!$platformCommentId || !$postId || !$commenterId || !$pageIdFromEntry || $commentMessage === '') {
                        error_log("Facebook Page webhook: Missing essential comment data or empty message. CommentID: {$platformCommentId}, PostID: {$postId}, PageID: {$pageIdFromEntry}. Data: " . json_encode($feedItem));
                        continue; // Skip this comment if essential data is missing or message is empty
                    }
                    
                    $dbCommentEntryId = null;
                    try {
                        // 5.1: Store incoming Facebook Page comment
                        $insertCommentSql = "INSERT INTO page_post_comments 
                                             (page_id, post_id, platform_comment_id, commenter_id, commenter_name, comment_text, comment_timestamp, is_hidden, created_at, updated_at)
                                             VALUES (:page_id, :post_id, :platform_comment_id, :commenter_id, :commenter_name, :comment_text, FROM_UNIXTIME(:comment_timestamp), 0, NOW(), NOW())";
                        DBManager::executeNonQuery($insertCommentSql, [
                            ':page_id' => $pageIdFromEntry,
                            ':post_id' => $postId,
                            ':platform_comment_id' => $platformCommentId,
                            ':commenter_id' => $commenterId,
                            ':commenter_name' => $commenterName,
                            ':comment_text' => $commentMessage,
                            ':comment_timestamp' => $commentTimestamp
                        ]);
                        $dbCommentEntryId = DBManager::getLastInsertId();
                        error_log("Facebook Page: Stored comment {$dbCommentEntryId} (Platform Comment ID: {$platformCommentId}) for post {$postId}");

                        // 5.2: Prepare prompt and call Gemini
                        $prompt = "User '{$commenterName}' commented on a Facebook Page post: \"{$commentMessage}\". Provide a helpful and concise reply for a social media manager.";
                        $aiResponseText = GeminiService::getGeminiResponse($prompt);
                        $geminiLogId = null;
                        
                        if ($aiResponseText !== null) {
                            error_log("Facebook Page: Gemini response for comment {$platformCommentId}: \"{$aiResponseText}\"");
                            // 5.3: Log Gemini interaction
                            $logGeminiSql = "INSERT INTO gemini_interactions_log (related_item_id, item_type, prompt_text, raw_response, created_at)
                                             VALUES (:related_item_id, 'facebook_page_comment', :prompt, :response, NOW())";
                            DBManager::executeNonQuery($logGeminiSql, [
                                ':related_item_id' => $dbCommentEntryId,
                                ':prompt' => $prompt,
                                ':response' => $aiResponseText
                            ]);
                            $geminiLogId = DBManager::getLastInsertId();

                            // 5.4: Send reply via Facebook Page API (replying to the comment)
                            if (PAGE_ACCESS_TOKEN !== 'YOUR_SPECIFIC_FACEBOOK_PAGE_ACCESS_TOKEN_HERE') {
                                // The commentOnPost function is used to reply to an existing comment by using the platform_comment_id as the target object_id.
                                $replyResult = commentOnPost(PAGE_ACCESS_TOKEN, $platformCommentId, $aiResponseText); 
                                if (isset($replyResult['id'])) { // Successful reply returns the ID of the new reply comment
                                    error_log("Facebook Page: Successfully sent AI reply to comment {$platformCommentId}. Reply ID: " . $replyResult['id']);
                                    // Optionally, store this reply ID back into page_post_comments or a new table for replies.
                                    // This can be a future enhancement if tracking of bot replies is needed.
                                } else {
                                    error_log("Facebook Page: Failed to send AI reply to comment {$platformCommentId}. Response: " . json_encode($replyResult));
                                    // TODO: ALERT - Facebook Page reply failed for comment {$platformCommentId}.
                                }
                            } else {
                                error_log("Facebook Page: PAGE_ACCESS_TOKEN is a placeholder. Cannot send reply to comment {$platformCommentId}.");
                            }
                        } else { // Gemini failed
                            error_log("Facebook Page: Gemini returned null for comment {$platformCommentId}. Prompt: {$prompt}");
                            // Log Gemini interaction failure
                            $logGeminiFailureSql = "INSERT INTO gemini_interactions_log (related_item_id, item_type, prompt_text, raw_response, status, created_at)
                                             VALUES (:related_item_id, 'facebook_page_comment', :prompt, :response, 'failed', NOW())";
                            DBManager::executeNonQuery($logGeminiFailureSql, [
                                ':related_item_id' => $dbCommentEntryId,
                                ':prompt' => $prompt,
                                ':response' => 'Gemini returned null or empty.'
                            ]);
                            // TODO: ALERT - Gemini failed to generate response for Facebook Page comment {$platformCommentId}.
                        }

                    } catch (PDOException $e) { // Catch DB errors during comment processing
                        error_log("Facebook Page webhook DB error processing comment: " . $e->getMessage() . " - Data: " . json_encode($feedItem));
                        // TODO: ALERT - Critical DB error processing Facebook Page comment.
                    } catch (Exception $e) { // Catch other general errors
                        error_log("Facebook Page webhook general error processing comment: " . $e->getMessage() . " - Data: " . json_encode($feedItem));
                    }
                } // End if item is 'comment' and verb is 'add'
                // TODO: Handle other 'feed' changes if necessary (e.g., new posts, reactions, comment edits/deletions).
            } // End foreach changes
        } // End foreach entry
    } // --- End Facebook Page Processing ---

    // Always respond 200 OK to Meta platform to acknowledge receipt.
    http_response_code(200);
    exit;

} else {
    error_log("Webhook (Facebook Page) received non-GET/POST request: " . $_SERVER['REQUEST_METHOD']);
    http_response_code(405); // Method Not Allowed
    exit;
}

?>
