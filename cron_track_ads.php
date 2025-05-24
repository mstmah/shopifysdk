<?php declare(strict_types=1);
/**
 * Cron Job Script: Automated Ad Tracking and Performance Management.
 *
 * This script is designed to be run periodically (e.g., daily) via a cron job.
 * Its primary responsibilities are:
 * 1. Synchronize ad campaign entities (campaigns, ad sets) from the Meta Marketing API
 *    to the local database using `AdManagerService::fetchAndStoreAdCampaignEntities()`.
 * 2. Fetch and store recent performance insights (e.g., last N days as per DAYS_TO_EVALUATE) 
 *    for these campaigns into the local database using `AdManagerService::fetchAndStoreAdPerformanceInsights()`.
 * 3. Analyze the performance data stored locally for active campaigns:
 *    - Calculates aggregate spend, conversions, impressions, and clicks over an evaluation period.
 *    - Calculates average Click-Through Rate (CTR).
 * 4. Implements decision-making logic based on predefined thresholds:
 *    - Pauses campaigns with high spend and no conversions.
 *    - Logs warnings for campaigns with low CTR and significant spend.
 *    (Further decision logic can be added, such as for good performance or other metrics).
 * 5. Uses `meta_api_functions::updateAdCampaign()` to change the status of campaigns on the Meta platform.
 * 6. Updates the status of campaign entities in the local database to reflect changes made via API.
 * 7. Logs its actions and any errors encountered to the PHP error log (or a configured log file).
 *
 * Setup as a Cron Job (Example):
 * ```bash
 * # Run daily at 3:00 AM server time
 * # Ensure environment variables like META_AD_ACCOUNT_ID, META_USER_ACCESS_TOKEN, etc., are available to the cron execution scope.
 * 0 3 * * * /usr/bin/php /path/to/your/project/cron_track_ads.php >> /path/to/your/project/logs/cron_track_ads.log 2>&1
 * ```
 * Ensure that the paths to PHP executable and the script are correct.
 * It's recommended to redirect both stdout and stderr to a log file for monitoring.
 * Environment variables (META_AD_ACCOUNT_ID, META_USER_ACCESS_TOKEN, etc.) should be set
 * in the environment where the cron job runs, or managed via a secure configuration method
 * (e.g., .env file loaded by the script, though direct getenv() is used here for simplicity).
 */

require_once __DIR__ . '/db_manager.php';
require_once __DIR__ . '/meta_api_functions.php';
require_once __DIR__ . '/ad_manager_service.php';

// --- Configuration ---
// IMPORTANT: In a production cron job, set these via environment variables or secure configuration management.
// These values are examples and should be tuned based on actual advertising strategies and goals.
define('CRON_AD_ACCOUNT_ID', getenv('META_AD_ACCOUNT_ID') ?: 'act_YOUR_AD_ACCOUNT_ID_HERE'); 
define('CRON_USER_ACCESS_TOKEN', getenv('META_USER_ACCESS_TOKEN') ?: 'YOUR_USER_ACCESS_TOKEN_HERE'); 

// Performance Evaluation Criteria
define('LOW_CTR_THRESHOLD', 0.01); // Example: CTR is considered low if below 1%.
define('MIN_SPEND_FOR_CTR_EVAL', 50.00); // Minimum spend (e.g., $50) before CTR is evaluated for pausing.
define('NO_CONVERSION_SPEND_THRESHOLD', 100.00); // Amount spent (e.g., $100) with 0 conversions to trigger pause.
define('DAYS_TO_EVALUATE', 7); // Number of past days to use for performance aggregation and evaluation.

/**
 * Main function for the cron job.
 */
function main() {
    error_log("Cron job cron_track_ads.php started for Ad Account: " . CRON_AD_ACCOUNT_ID);

    // Validate essential configuration
    if (CRON_AD_ACCOUNT_ID === 'act_YOUR_AD_ACCOUNT_ID_HERE' || empty(CRON_AD_ACCOUNT_ID) ||
        CRON_USER_ACCESS_TOKEN === 'YOUR_USER_ACCESS_TOKEN_HERE' || empty(CRON_USER_ACCESS_TOKEN)) {
        error_log("Cron job error: AD_ACCOUNT_ID or USER_ACCESS_TOKEN is not configured. Please set them as environment variables (META_AD_ACCOUNT_ID, META_USER_ACCESS_TOKEN) or update placeholders in the script.");
        // TODO: ALERT - Critical: Cron job configuration missing for Ad Account tracking.
        exit(1); // Exit with an error code
    }

    $adAccountId = CRON_AD_ACCOUNT_ID;
    $userAccessToken = CRON_USER_ACCESS_TOKEN;

    try {
        // Step 1: Fetch and Store Latest Ad Campaign Entities (Campaigns & Ad Sets)
        error_log("Cron: Starting entity synchronization for account {$adAccountId}...");
        $entityResults = AdManagerService::fetchAndStoreAdCampaignEntities($userAccessToken, $adAccountId);
        error_log("Cron: Entity sync complete. Campaigns - Processed: {$entityResults['campaigns']['processed']}, Inserted: {$entityResults['campaigns']['inserted']}, Updated: {$entityResults['campaigns']['updated']}, Failed: {$entityResults['campaigns']['failed']}");
        error_log("Cron: Entity sync complete. Ad Sets - Processed: {$entityResults['ad_sets']['processed']}, Inserted: {$entityResults['ad_sets']['inserted']}, Updated: {$entityResults['ad_sets']['updated']}, Failed: {$entityResults['ad_sets']['failed']}");
        if ($entityResults['campaigns']['failed'] > 0 || $entityResults['ad_sets']['failed'] > 0) {
            // TODO: ALERT - Entity synchronization encountered failures for Ad Account {$adAccountId}.
        }

        // Step 2: Fetch and Store Latest Performance Insights
        // Using a date preset that covers the evaluation window.
        $insightPreset = 'last_' . DAYS_TO_EVALUATE . 'd'; 
        error_log("Cron: Starting performance insights synchronization for account {$adAccountId} (preset: {$insightPreset})...");
        $insightResults = AdManagerService::fetchAndStoreAdPerformanceInsights($userAccessToken, $adAccountId, $insightPreset);
        error_log("Cron: Insight sync complete. Processed: {$insightResults['processed']}, Inserted/Updated: {$insightResults['inserted_or_updated']}, Failed: {$insightResults['failed']}");
        if ($insightResults['failed'] > 0) {
            // TODO: ALERT - Insight synchronization encountered failures for Ad Account {$adAccountId}.
        }

        // Step 3: Analyze Performance and Make Decisions
        error_log("Cron: Starting campaign performance analysis for account {$adAccountId}...");
        
        // Fetch active campaign entities from local DB (these are rows where report_date IS NULL, and adset/ad IDs are NULL)
        $activeCampaignsSql = "SELECT platform_campaign_id, campaign_name FROM ad_campaign_performance 
                               WHERE ad_account_id = :ad_account_id 
                                 AND status = 'ACTIVE' 
                                 AND report_date IS NULL 
                                 AND platform_adset_id IS NULL 
                                 AND platform_ad_id IS NULL";
        $activeCampaigns = DBManager::fetchAll($activeCampaignsSql, [':ad_account_id' => $adAccountId]);

        if (empty($activeCampaigns)) {
            error_log("Cron: No active campaigns found in local DB for account {$adAccountId} to analyze.");
        } else {
            error_log("Cron: Found " . count($activeCampaigns) . " active campaigns for analysis in account {$adAccountId}.");
        }

        foreach ($activeCampaigns as $campaign) {
            $platformCampaignId = $campaign['platform_campaign_id'];
            $campaignName = $campaign['campaign_name'];
            error_log("Cron: Analyzing campaign '{$campaignName}' (ID: {$platformCampaignId}).");

            // Define date range for performance data query based on DAYS_TO_EVALUATE
            $endDateForPerf = date('Y-m-d', strtotime('-1 day')); // Data up to yesterday
            $startDateForPerf = date('Y-m-d', strtotime("-{$DAYS_TO_EVALUATE} days", strtotime($endDateForPerf)));
            
            // Fetch aggregated performance data for this campaign over the evaluation period from local DB
            $aggregatedPerf = AdManagerService::getAggregatedCampaignPerformance(
                [$platformCampaignId], // Filter by current campaign ID
                ['start' => $startDateForPerf, 'end' => $endDateForPerf]
            );

            if (empty($aggregatedPerf) || !isset($aggregatedPerf[0])) {
                error_log("Cron: No aggregated performance data found for campaign '{$campaignName}' (ID: {$platformCampaignId}) in the last " . DAYS_TO_EVALUATE . " days.");
                continue; // Skip to next campaign
            }
            $performanceMetrics = $aggregatedPerf[0]; // Expecting one row due to grouping by campaign_id

            $totalSpend = (float)($performanceMetrics['total_spend'] ?? 0.0);
            $totalConversions = (int)($performanceMetrics['total_conversions'] ?? 0);
            $avgCtr = (float)($performanceMetrics['calculated_ctr'] ?? 0.0); // Using calculated_ctr from aggregation

            error_log("Cron: Campaign '{$campaignName}' (ID: {$platformCampaignId}) - Evaluated Period ({$startDateForPerf} to {$endDateForPerf}): Spend: \${$totalSpend}, Conversions: {$totalConversions}, Avg CTR: " . round($avgCtr * 100, 2) . "%");

            // Decision Logic 1: High Spend, No Conversions
            if ($totalSpend >= NO_CONVERSION_SPEND_THRESHOLD && $totalConversions === 0) {
                error_log("Cron: ACTION - Campaign '{$campaignName}' (ID: {$platformCampaignId}) met High Spend, No Conversion criteria (Spend: \${$totalSpend}). Attempting to pause.");
                // TODO: ALERT - Campaign [ID] being paused due to high spend without conversions.
                $updateResult = updateAdCampaign($userAccessToken, $platformCampaignId, ['status' => 'PAUSED']);
                if (isset($updateResult['success']) && $updateResult['success']) {
                    error_log("Cron: Successfully PAUSED campaign {$platformCampaignId} via API.");
                    // Update local status for the campaign entity row (where report_date is NULL)
                    $updateLocalSql = "UPDATE ad_campaign_performance SET status = 'PAUSED', updated_at = NOW() 
                                       WHERE platform_campaign_id = :platform_campaign_id AND report_date IS NULL AND platform_adset_id IS NULL AND platform_ad_id IS NULL";
                    DBManager::executeNonQuery($updateLocalSql, [':platform_campaign_id' => $platformCampaignId]);
                } else {
                    error_log("Cron: FAILED to pause campaign {$platformCampaignId} via API. Response: " . json_encode($updateResult));
                    // TODO: ALERT - API call to PAUSE campaign {$platformCampaignId} failed. Requires manual intervention.
                }
                continue; // Campaign paused, skip other checks for this campaign for this cron run
            }

            // Decision Logic 2: Low CTR with Sufficient Spend
            if ($avgCtr < LOW_CTR_THRESHOLD && $totalSpend >= MIN_SPEND_FOR_CTR_EVAL) {
                error_log("Cron: WARNING - Campaign '{$campaignName}' (ID: {$platformCampaignId}) has Low CTR (" . round($avgCtr * 100, 2) . "%) with significant spend (\${$totalSpend}). Consider review or automated pausing if more aggressive rules are set.");
                // For this example, we only log a warning. A more aggressive strategy might pause it:
                // if ($avgCtr < (LOW_CTR_THRESHOLD / 2)) { // Example of a more aggressive pause
                //     error_log("Cron: ACTION - Campaign '{$campaignName}' (ID: {$platformCampaignId}) has critically low CTR. Pausing campaign.");
                //     // $updateResult = updateAdCampaign($userAccessToken, $platformCampaignId, ['status' => 'PAUSED']);
                //     // if (isset($updateResult['success']) && $updateResult['success']) { ... update local DB ... } else { ... log failure ... }
                //     // TODO: ALERT - Campaign [ID] automatically paused due to critically low CTR.
                // }
            }
            
            // TODO: Add more decision logic (e.g., for good performance, or scaling successful campaigns).

        } // End foreach activeCampaign

    } catch (PDOException $e) { // Catch database-specific errors
        error_log("Cron job DB error: " . $e->getMessage() . " Trace: " . $e->getTraceAsString());
        // TODO: ALERT - Critical: Cron job terminated due to DB error.
    } catch (Exception $e) { // Catch other general errors (e.g., from API calls if not caught internally by service methods)
        error_log("Cron job general error: " . $e->getMessage() . " Trace: " . $e->getTraceAsString());
        // TODO: ALERT - Critical: Cron job terminated due to a general error.
    }

    error_log("Cron job cron_track_ads.php finished for Ad Account: " . CRON_AD_ACCOUNT_ID);
}

// Execute the main function
main();

?>
