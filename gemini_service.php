<?php declare(strict_types=1);

// --- Gemini API Configuration ---
// IMPORTANT: Store your API Key securely, preferably as an environment variable.
// Example: define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: 'YOUR_GEMINI_API_KEY_HERE');
define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: 'YOUR_GEMINI_API_KEY_HERE'); // Replace with your actual Gemini API Key

// Example endpoint for a specific model (e.g., gemini-pro for text generation)
// Please verify the correct endpoint from the official Google AI Gemini API documentation.
define('GEMINI_API_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent');

/**
 * Class GeminiService
 *
 * A simple service class to interact with the Google Gemini API (generative models).
 * This class provides a method to get text generations based on a given prompt.
 */
class GeminiService
{
    /**
     * Gets a response from the Gemini API for a given text prompt.
     *
     * Makes a POST request to the Gemini API endpoint with the provided prompt and
     * optional generation configuration. Parses the response to extract the generated text.
     *
     * Expected Gemini API Request Structure (for gemini-pro model):
     * POST https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent?key=YOUR_API_KEY
     * Content-Type: application/json
     * {
     *   "contents": [{
     *     "parts": [{"text": "YOUR_PROMPT_HERE"}]
     *   }],
     *   "generationConfig": { // Optional
     *     "temperature": 0.7,
     *     "maxOutputTokens": 1024
     *   }
     * }
     *
     * Expected Gemini API Response Structure (successful text generation):
     * {
     *   "candidates": [
     *     {
     *       "content": {
     *         "parts": [
     *           { "text": "GENERATED_TEXT_CONTENT" }
     *         ],
     *         "role": "model"
     *       },
     *       "finishReason": "STOP", // or "MAX_TOKENS", "SAFETY", etc.
     *       "index": 0,
     *       "safetyRatings": [ ... ]
     *     }
     *   ],
     *   "promptFeedback": { ... } // Optional
     * }
     *
     * @param string $prompt The text prompt to send to the Gemini API.
     * @param array $generationConfig (Optional) Associative array for content generation configuration.
     *                                Example: ['temperature' => 0.7, 'maxOutputTokens' => 1024, 'topK' => 40, 'topP' => 0.95].
     *                                Refer to official Gemini API documentation for all available options and their effects.
     * @return string|null The generated text content from Gemini on success, or null on failure (e.g., API error, network issue, decoding error).
     * @throws Exception If cURL initialization fails (a critical, unrecoverable error for this function).
     */
    public static function getGeminiResponse(string $prompt, array $generationConfig = []): ?string
    {
        if (GEMINI_API_KEY === 'YOUR_GEMINI_API_KEY_HERE' || empty(GEMINI_API_KEY)) {
            error_log("GeminiService Error: API Key is not configured or is empty. Please define GEMINI_API_KEY.");
            return null;
        }

        $apiKey = GEMINI_API_KEY;
        $apiUrl = GEMINI_API_ENDPOINT . '?key=' . $apiKey;

        $payload = ['contents' => [['parts' => [['text' => $prompt]]]]];

        if (!empty($generationConfig)) {
            $payload['generationConfig'] = $generationConfig;
        }

        $jsonData = json_encode($payload);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("GeminiService Error: Failed to encode JSON payload. Error: " . json_last_error_msg() . ". Payload: " . print_r($payload, true));
            return null;
        }

        $ch = curl_init();
        if ($ch === false) {
            error_log("GeminiService Error: Failed to initialize cURL session.");
            throw new Exception("Failed to initialize cURL for GeminiService."); // Critical failure
        }

        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            // Some Google APIs prefer 'x-goog-api-key' header, but for Gemini generative models,
            // the key in URL parameter is standard as of documentation in early 2024.
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90); // Increased timeout for potentially longer AI responses
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15); // Connection timeout

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrorNo = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlErrorNo) {
            error_log("GeminiService cURL Error ({$curlErrorNo}): {$curlError}. URL: {$apiUrl}");
            return null;
        }

        if ($httpCode >= 400) {
            error_log("GeminiService API HTTP Error: Status Code {$httpCode}. Response: " . substr($response, 0, 1000)); // Log more of the response for HTTP errors
            return null;
        }

        $responseData = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("GeminiService Error: Failed to decode API JSON response. Error: " . json_last_error_msg() . ". Response snippet: " . substr($response, 0, 500));
            return null;
        }

        // Standard path for successful text generation in Gemini Pro as of early 2024
        if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
            return $responseData['candidates'][0]['content']['parts'][0]['text'];
        } elseif (isset($responseData['error'])) {
            $errorMessage = $responseData['error']['message'] ?? 'Unknown API error structure';
            $errorCode = $responseData['error']['code'] ?? 'N/A';
            $errorStatus = $responseData['error']['status'] ?? 'N/A';
            error_log("GeminiService API Error Response: Code {$errorCode}, Status {$errorStatus} - {$errorMessage}. Full error: " . json_encode($responseData['error']));
            return null;
        } else {
            // This case might occur if the response structure is unexpected or if there are no candidates (e.g., due to safety filters with no fallback)
            error_log("GeminiService Error: Generated text not found in the expected path, or no candidates returned. Response: " . substr($response, 0, 1000));
            return null;
        }
    }
}

/*
// --- Example Usage (Commented Out) ---

// require_once 'gemini_service.php'; // If you're running this separately

// // IMPORTANT: Replace 'YOUR_GEMINI_API_KEY_HERE' in the define statement at the top of the file,
// // or set the GEMINI_API_KEY environment variable.
// if (GEMINI_API_KEY === 'YOUR_GEMINI_API_KEY_HERE' || empty(GEMINI_API_KEY)) {
//     echo "Please set your Gemini API Key in gemini_service.php (or as an environment variable GEMINI_API_KEY) before running the example.\n";
// } else {
//     $prompt = "Explain what a language model is in simple terms, suitable for a non-technical audience.";
//     echo "Sending prompt to Gemini: \"{$prompt}\"\n";

//     try {
//         $response = GeminiService::getGeminiResponse($prompt);

//         if ($response !== null) {
//             echo "\nGemini's Response:\n--------------------\n";
//             echo $response;
//             echo "\n--------------------\n";
//         } else {
//             echo "\nFailed to get a response from Gemini API. Check error logs (e.g., PHP error log, web server error log) for details.\n";
//         }
//     } catch (Exception $e) {
//         echo "An exception occurred: " . $e->getMessage() . "\n";
//     }

//     // Example with generationConfig:
//     $promptConfig = "Write a very short, optimistic tweet about the future of AI.";
//     $config = [
//         'temperature' => 0.9,
//         'maxOutputTokens' => 60, // Tweets are short
//         'topK' => 1, // For less randomness in this short example
//         'topP' => 0.9,
//         // 'candidateCount' => 1, // Default is 1, check API docs if you need more candidates
//     ];
//     echo "\nSending prompt to Gemini with config: \"{$promptConfig}\"\n";
//     try {
//         $responseConfig = GeminiService::getGeminiResponse($promptConfig, $config);
//         if ($responseConfig !== null) {
//             echo "\nGemini's Response (with config):\n--------------------\n";
//             echo $responseConfig;
//             echo "\n--------------------\n";
//         } else {
//             echo "\nFailed to get a response from Gemini API with config. Check error logs.\n";
//         }
//     } catch (Exception $e) {
//         echo "An exception occurred: " . $e->getMessage() . "\n";
//     }
// }

*/

?>
