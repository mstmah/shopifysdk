<?php declare(strict_types=1);
/**
 * Webhook handler for incoming Messenger messages and WhatsApp messages (via Meta Cloud API).
 * 
 * Responsibilities:
 * - Verifies webhook GET requests from Meta (for initial setup).
 * - Verifies the signature of incoming POST request payloads to ensure authenticity.
 * - Logs all incoming raw webhook events to the `webhook_logs` table for audit and debugging.
 * - Differentiates between 'messenger' and 'whatsapp' platform events based on the payload structure.
 * - For text messages from either platform:
 *   - Parses relevant identifiers (user ID, page/business ID, message ID, text, timestamp).
 *   - Stores the incoming message into the `conversations` table with appropriate platform context.
 *   - Calls the `GeminiService` to generate an AI-powered reply suggestion.
 *   - Logs the prompt sent to Gemini and its raw response to the `gemini_interactions_log` table,
 *     linking it to the stored conversation entry.
 *   - Sends the AI-generated reply back to the user via the appropriate Meta API function 
 *     (`sendTextMessage` for Messenger, `sendWhatsAppTextMessage` for WhatsApp).
 *   - Stores the outgoing AI reply in the `conversations` table, linking it to the original message
 *     and the Gemini interaction log.
 * - Responds with HTTP 200 to Meta platform for all valid POST events after processing (or attempting to).
 * - Responds with appropriate HTTP error codes for verification failures, signature mismatches, or bad requests.
 * 
 * This script is intended to be a single endpoint for both Messenger and WhatsApp Cloud API webhooks
 * if they share the same App Secret and webhook URL.
 */

require_once 'db_manager.php';
require_once 'gemini_service.php';
require_once 'meta_api_functions.php'; // For sending replies

// --- Configuration Variables ---
// It's crucial to manage these securely, ideally via environment variables.
// For Verify Tokens, generate strong, unique strings for each webhook setup in the Meta App Dashboard.
// For APP_SECRET, this is your main Facebook App Secret.
// For PAGE_ACCESS_TOKEN, its role depends on the platform:
//   - Messenger: It's the Page Access Token for the specific Facebook Page.
//   - WhatsApp: It should be a System User Access Token for your WhatsApp Business Account (WABA) 
//               with 'whatsapp_business_messaging' permission.
// For WHATSAPP_PHONE_NUMBER_ID, this is the ID of the phone number associated with your WABA.

define('MESSENGER_VERIFY_TOKEN', getenv('MESSENGER_VERIFY_TOKEN') ?: 'your_messenger_verify_token_here_12345'); 
define('APP_SECRET', getenv('META_APP_SECRET') ?: 'your_facebook_app_secret_here'); 
define('PAGE_ACCESS_TOKEN', getenv('META_PAGE_ACCESS_TOKEN') ?: 'YOUR_MULTI_PURPOSE_OR_MESSENGER_PAGE_ACCESS_TOKEN_HERE'); 
define('WHATSAPP_PHONE_NUMBER_ID', getenv('WHATSAPP_PHONE_NUMBER_ID') ?: 'YOUR_WABA_PHONE_NUMBER_ID_HERE');

// Handle Webhook Verification (GET request)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = $_GET['hub_mode'] ?? null;
    $token = $_GET['hub_verify_token'] ?? null;
    $challenge = $_GET['hub_challenge'] ?? null;

    // MESSENGER_VERIFY_TOKEN is used here as a generic token for this endpoint.
    // If handling multiple platforms on the same endpoint with different verify tokens,
    // you might need more sophisticated logic or separate endpoints.
    if ($mode === 'subscribe' && $token === MESSENGER_VERIFY_TOKEN) {
        http_response_code(200);
        echo $challenge;
        error_log("Webhook verified successfully (Platform determined by POST payload). Token: " . $token);
        exit;
    } else {
        error_log("Webhook verification failed. Mode: " . ($mode ?? 'null') . " Received Token: " . ($token ?? 'null') . " Expected: " . MESSENGER_VERIFY_TOKEN);
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
        error_log("Webhook error: Signature header (X-Hub-Signature-256) missing.");
        http_response_code(400); // Bad Request
        exit;
    }
    list($algo, $hash) = explode('=', $signatureHeader, 2);
    if ($algo !== 'sha256') {
        error_log("Webhook error: Invalid signature algorithm. Expected sha256, got " . $algo);
        http_response_code(400); 
        exit;
    }
    $expectedSignature = hash_hmac('sha256', $payload, APP_SECRET);
    if (!hash_equals($expectedSignature, $hash)) {
        error_log("Webhook error: Signature verification failed. Ensure APP_SECRET is correctly set.");
        // TODO: ALERT - Webhook signature verification failed. Potential security event.
        http_response_code(403); // Forbidden
        exit;
    }

    // Step 2: Decode JSON payload
    $data = json_decode($payload, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Webhook error: Invalid JSON payload. Error: " . json_last_error_msg() . ". Payload snippet: " . substr($payload, 0, 500));
        http_response_code(400); // Bad Request
        exit;
    }

    // Step 3: Platform identification
    $platform = 'unknown';
    $initialEventType = $data['object'] ?? 'unknown_event'; // e.g., 'page' for Messenger, 'whatsapp_business_account' for WhatsApp

    if ($initialEventType === 'whatsapp_business_account') {
        $platform = 'whatsapp';
    } elseif ($initialEventType === 'page') {
        $platform = 'messenger'; 
    }
    // TODO: Consider adding an 'instagram' platform block if this webhook is also subscribed to Instagram direct message events sharing this endpoint.

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
        error_log("Webhook DB (initial log) error for platform {$platform}: " . $e->getMessage() . ". Payload snippet: " . substr($payload, 0, 500));
        // Processing continues, but this failure should be monitored.
        // TODO: ALERT - Critical: Failed to log incoming webhook to DB.
    }

    /*
    Conceptual WhatsApp Payload Structure (for text message):
    {
        "object": "whatsapp_business_account",
        "entry": [{
            "id": "WABA_ID", // WhatsApp Business Account ID
            "changes": [{
                "field": "messages",
                "value": {
                    "messaging_product": "whatsapp",
                    "metadata": {
                        "display_phone_number": "BUSINESS_PHONE_DISPLAY",
                        "phone_number_id": "BUSINESS_PHONE_NUMBER_ID" 
                    },
                    "contacts": [{ "profile": { "name": "USER_NAME" }, "wa_id": "USER_PHONE_NUMBER_AS_ID" }],
                    "messages": [{
                        "from": "USER_PHONE_NUMBER_AS_ID", // User's WhatsApp ID (phone number)
                        "id": "WAMID", // WhatsApp Message ID
                        "timestamp": "MESSAGE_TIMESTAMP", // Unix timestamp (seconds)
                        "text": { "body": "MESSAGE_TEXT" },
                        "type": "text"
                        // Other types: "image", "audio", "document", "sticker", "location", "contacts", "interactive" (list_reply, button_reply), "template" (for user reply to template)
                    }]
                }
            }]
        }]
    }
    */
    // Step 5: Process WhatsApp Messages
    if ($platform === 'whatsapp') {
        foreach ($data['entry'] as $entry) {
            if (!empty($entry['changes'])) {
                foreach ($entry['changes'] as $change) {
                    // Check for WhatsApp messages
                    if ($change['field'] === 'messages' && isset($change['value']['messages'][0])) {
                        $messageData = $change['value']['messages'][0];
                        
                        // Process only text messages for AI reply in this version
                        if ($messageData['type'] === 'text') { 
                            $userWabId = $messageData['from'] ?? null; 
                            $businessPhoneNumberId = $change['value']['metadata']['phone_number_id'] ?? null;
                            $messageText = $messageData['text']['body'] ?? null;
                            $platformMessageId = $messageData['id'] ?? null; 
                            $messageTimestamp = isset($messageData['timestamp']) ? (int)$messageData['timestamp'] : time();

                            if (!$userWabId || !$businessPhoneNumberId || !$messageText || !$platformMessageId) {
                                error_log("WhatsApp webhook: Missing essential data for text message. WABID: {$userWabId}, BusinessPhoneID: {$businessPhoneNumberId}, MsgID: {$platformMessageId}. Payload snippet: ". substr(json_encode($messageData), 0, 300));
                                continue; // Skip this message
                            }

                            $conversationId = null;
                            try {
                                // 5.1: Store incoming WhatsApp message
                                $insertMessageSql = "INSERT INTO conversations (platform, platform_message_id, page_scoped_user_id, page_id, message_text, direction, message_timestamp, created_at, updated_at)
                                                     VALUES (:platform, :platform_message_id, :psid, :page_id, :message_text, 'incoming', FROM_UNIXTIME(:message_timestamp), NOW(), NOW())";
                                DBManager::executeNonQuery($insertMessageSql, [
                                    ':platform' => 'whatsapp',
                                    ':platform_message_id' => $platformMessageId,
                                    ':psid' => $userWabId, // User's WhatsApp Number (WABID)
                                    ':page_id' => $businessPhoneNumberId, // Business's WhatsApp Phone Number ID
                                    ':message_text' => $messageText,
                                    ':message_timestamp' => $messageTimestamp
                                ]);
                                $conversationId = DBManager::getLastInsertId();
                                error_log("WhatsApp: Stored incoming message {$conversationId} (WAMID: {$platformMessageId}) from WABID {$userWabId}");

                                // 5.2: Prepare prompt and call Gemini
                                $prompt = "User from WhatsApp said: \"{$messageText}\". Provide a helpful and concise response. Format for WhatsApp (e.g., use Markdown like *bold*, _italic_).";
                                $aiResponseText = GeminiService::getGeminiResponse($prompt);
                                $geminiLogId = null;

                                if ($aiResponseText !== null) {
                                    error_log("WhatsApp: Gemini response for WABID {$userWabId}: \"{$aiResponseText}\"");
                                    // 5.3: Log Gemini interaction
                                    $logGeminiSql = "INSERT INTO gemini_interactions_log (related_item_id, item_type, prompt_text, raw_response, created_at)
                                                     VALUES (:related_item_id, 'whatsapp_conversation', :prompt, :response, NOW())";
                                    DBManager::executeNonQuery($logGeminiSql, [
                                        ':related_item_id' => $conversationId,
                                        ':prompt' => $prompt,
                                        ':response' => $aiResponseText
                                    ]);
                                    $geminiLogId = DBManager::getLastInsertId();

                                    // 5.4: Send reply via WhatsApp API
                                    // Ensure PAGE_ACCESS_TOKEN is a WABA System User Token and WHATSAPP_PHONE_NUMBER_ID is correctly set.
                                    if (PAGE_ACCESS_TOKEN !== 'YOUR_MULTI_PURPOSE_OR_MESSENGER_PAGE_ACCESS_TOKEN_HERE' && WHATSAPP_PHONE_NUMBER_ID !== 'YOUR_WABA_PHONE_NUMBER_ID_HERE') {
                                        $replyResult = sendWhatsAppTextMessage(PAGE_ACCESS_TOKEN, WHATSAPP_PHONE_NUMBER_ID, $userWabId, $aiResponseText);
                                        if (isset($replyResult['messages'][0]['id'])) {
                                            $replyMessageId = $replyResult['messages'][0]['id'];
                                            error_log("WhatsApp: Successfully sent AI reply to WABID {$userWabId}. Reply WAMID: {$replyMessageId}");
                                            // 5.5: Store outgoing AI response
                                            $outgoingMsgSql = "INSERT INTO conversations (platform, platform_message_id, page_scoped_user_id, page_id, message_text, direction, message_timestamp, related_incoming_id, gemini_log_id, created_at, updated_at)
                                                               VALUES (:platform, :platform_message_id, :psid, :page_id, :message_text, 'outgoing', NOW(), :related_incoming_id, :gemini_log_id, NOW(), NOW())";
                                            DBManager::executeNonQuery($outgoingMsgSql, [
                                                ':platform' => 'whatsapp',
                                                ':platform_message_id' => $replyMessageId,
                                                ':psid' => $userWabId,
                                                ':page_id' => $businessPhoneNumberId,
                                                ':message_text' => $aiResponseText,
                                                ':related_incoming_id' => $conversationId,
                                                ':gemini_log_id' => $geminiLogId
                                            ]);
                                        } else {
                                            error_log("WhatsApp: Failed to send AI reply to WABID {$userWabId}. Response: " . json_encode($replyResult));
                                            // TODO: ALERT - WhatsApp reply failed for WABID {$userWabId}.
                                        }
                                    } else {
                                        error_log("WhatsApp: PAGE_ACCESS_TOKEN or WHATSAPP_PHONE_NUMBER_ID is a placeholder. Cannot send reply to WABID {$userWabId}.");
                                    }
                                } else { // Gemini failed
                                    error_log("WhatsApp: Gemini returned null for WABID {$userWabId}. Prompt: {$prompt}");
                                    // Log Gemini interaction failure
                                    $logGeminiFailureSql = "INSERT INTO gemini_interactions_log (related_item_id, item_type, prompt_text, raw_response, status, created_at)
                                                             VALUES (:related_item_id, 'whatsapp_conversation', :prompt, :response, 'failed', NOW())";
                                    DBManager::executeNonQuery($logGeminiFailureSql, [
                                        ':related_item_id' => $conversationId,
                                        ':prompt' => $prompt,
                                        ':response' => 'Gemini returned null or empty.'
                                    ]);
                                    // TODO: ALERT - Gemini failed to generate response for WhatsApp WABID {$userWabId}.
                                }
                            } catch (PDOException $e) { // Catch DB errors during message processing
                                error_log("WhatsApp webhook DB error processing message: " . $e->getMessage() . " - Event: " . json_encode($messageData));
                                // TODO: ALERT - Critical DB error processing WhatsApp message.
                            } catch (Exception $e) { // Catch other general errors
                                error_log("WhatsApp webhook general error processing message: " . $e->getMessage() . " - Event: " . json_encode($messageData));
                            }
                        } // End if text message
                        // TODO: Implement parsing and handling for other WhatsApp message types (image, audio, document, location, contacts, interactive messages like list_reply, button_reply, etc.)
                    } // End if field is messages
                } // End foreach changes
            } // End if !empty entry changes
        } // End foreach entry
    } // --- End WhatsApp Processing ---
    
    // --- Messenger Message Processing ---
    // Conceptual Messenger Payload Structure (for text message):
    // { "object": "page", "entry": [ { "id": "PAGE_ID", "time": ..., "messaging": [ { 
    //   "sender": { "id": "PSID" }, "recipient": { "id": "PAGE_ID" }, "timestamp": ..., 
    //   "message": { "mid": "MESSAGE_ID", "text": "MESSAGE_TEXT", "is_echo": false (optional) } } ] } ] }
    elseif ($platform === 'messenger') { 
        foreach ($data['entry'] as $entry) {
            $pageId = $entry['id'] ?? null; // Facebook Page ID that received the message
            foreach ($entry['messaging'] as $messagingEvent) {
                // Check if it's a new text message and not an echo from the page itself
                if (isset($messagingEvent['message']['text']) && !isset($messagingEvent['message']['is_echo'])) {
                    $senderPsid = $messagingEvent['sender']['id'] ?? null;
                    $recipientPageId = $messagingEvent['recipient']['id'] ?? null; // Should match $pageId
                    $messageText = $messagingEvent['message']['text'];
                    $platformMessageId = $messagingEvent['message']['mid'] ?? null;
                    $messageTimestamp = isset($messagingEvent['timestamp']) ? (int)($messagingEvent['timestamp'] / 1000) : time();

                    if (!$senderPsid || !$recipientPageId || !$platformMessageId) {
                        error_log("Messenger webhook: Missing essential data. PSID: {$senderPsid}, PageID: {$recipientPageId}, MsgID: {$platformMessageId}. Event: " . json_encode($messagingEvent));
                        continue; // Skip this message
                    }
                    
                    $conversationId = null;
                    try {
                        // 5.1 (Messenger): Store incoming message
                        $insertMessageSql = "INSERT INTO conversations (platform, platform_message_id, page_scoped_user_id, page_id, message_text, direction, message_timestamp, created_at, updated_at)
                                             VALUES (:platform, :platform_message_id, :psid, :page_id, :message_text, 'incoming', FROM_UNIXTIME(:message_timestamp), NOW(), NOW())";
                        DBManager::executeNonQuery($insertMessageSql, [
                            ':platform' => 'messenger', 
                            ':platform_message_id' => $platformMessageId,
                            ':psid' => $senderPsid,
                            ':page_id' => $recipientPageId,
                            ':message_text' => $messageText,
                            ':message_timestamp' => $messageTimestamp
                        ]);
                        $conversationId = DBManager::getLastInsertId();
                        error_log("Messenger: Stored incoming message {$conversationId} (MsgID: {$platformMessageId}) from PSID {$senderPsid}");

                        // 5.2 (Messenger): Prepare prompt and call Gemini
                        $prompt = "User from Messenger said: \"{$messageText}\". Provide a helpful and concise response.";
                        $aiResponseText = GeminiService::getGeminiResponse($prompt);
                        $geminiLogId = null;

                        if ($aiResponseText !== null) {
                            error_log("Messenger: Gemini response for PSID {$senderPsid}: \"{$aiResponseText}\"");
                            // 5.3 (Messenger): Log Gemini interaction
                            $logGeminiSql = "INSERT INTO gemini_interactions_log (related_item_id, item_type, prompt_text, raw_response, created_at)
                                             VALUES (:related_item_id, 'messenger_conversation', :prompt, :response, NOW())";
                            DBManager::executeNonQuery($logGeminiSql, [
                                ':related_item_id' => $conversationId,
                                ':prompt' => $prompt,
                                ':response' => $aiResponseText
                            ]);
                            $geminiLogId = DBManager::getLastInsertId();
                            
                            // 5.4 (Messenger): Send response via Messenger API
                            if (PAGE_ACCESS_TOKEN !== 'YOUR_MULTI_PURPOSE_OR_MESSENGER_PAGE_ACCESS_TOKEN_HERE') {
                                // This uses the sendTextMessage which is for Messenger.
                                $replyResult = sendTextMessage(PAGE_ACCESS_TOKEN, $senderPsid, $aiResponseText);
                                if (isset($replyResult['message_id'])) {
                                    $replyMessageId = $replyResult['message_id'];
                                    error_log("Messenger: Successfully sent AI reply to PSID {$senderPsid}. Message ID: {$replyMessageId}");
                                    // 5.5 (Messenger): Store outgoing AI response
                                    $outgoingMsgSql = "INSERT INTO conversations (platform, platform_message_id, page_scoped_user_id, page_id, message_text, direction, message_timestamp, related_incoming_id, gemini_log_id, created_at, updated_at)
                                                       VALUES (:platform, :platform_message_id, :psid, :page_id, :message_text, 'outgoing', NOW(), :related_incoming_id, :gemini_log_id, NOW(), NOW())";
                                    DBManager::executeNonQuery($outgoingMsgSql, [
                                        ':platform' => 'messenger',
                                        ':platform_message_id' => $replyMessageId,
                                        ':psid' => $senderPsid,
                                        ':page_id' => $recipientPageId,
                                        ':message_text' => $aiResponseText,
                                        ':related_incoming_id' => $conversationId,
                                        ':gemini_log_id' => $geminiLogId
                                    ]);
                                } else {
                                    error_log("Messenger: Failed to send AI reply to PSID {$senderPsid}. Response: " . json_encode($replyResult));
                                    // TODO: ALERT - Messenger reply failed for PSID {$senderPsid}.
                                }
                            } else {
                                error_log("Messenger: PAGE_ACCESS_TOKEN is a placeholder. Cannot send reply to PSID {$senderPsid}.");
                            }
                        } else { // Gemini failed
                            error_log("Messenger: Gemini returned null for PSID {$senderPsid}. Prompt: {$prompt}");
                             $logGeminiFailureSql = "INSERT INTO gemini_interactions_log (related_item_id, item_type, prompt_text, raw_response, status, created_at)
                                             VALUES (:related_item_id, 'messenger_conversation', :prompt, :response, 'failed', NOW())";
                            DBManager::executeNonQuery($logGeminiFailureSql, [
                                ':related_item_id' => $conversationId,
                                ':prompt' => $prompt,
                                ':response' => 'Gemini returned null or empty.'
                            ]);
                             // TODO: ALERT - Gemini failed to generate response for Messenger PSID {$senderPsid}.
                        }
                    } catch (PDOException $e) { // Catch DB errors
                        error_log("Messenger webhook DB error processing message: " . $e->getMessage() . " - Event: " . json_encode($messagingEvent));
                        // TODO: ALERT - Critical DB error processing Messenger message.
                    } catch (Exception $e) { // Catch other general errors
                        error_log("Messenger webhook general error processing message: " . $e->getMessage() . " - Event: " . json_encode($messagingEvent));
                    }
                }
                // TODO: Handle other Messenger event types: postbacks, attachments, optins, referral, etc.
            }
        }
    } // --- End Messenger Processing ---

    // Always respond 200 OK to Facebook/Meta platform to acknowledge receipt of the webhook.
    // Actual processing errors should be handled and logged internally.
    http_response_code(200); 
    exit;

} else {
    error_log("Webhook (Messenger/WhatsApp) received non-GET/POST request: " . $_SERVER['REQUEST_METHOD']);
    http_response_code(405); // Method Not Allowed
    exit;
}

?>
