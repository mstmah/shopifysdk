<?php declare(strict_types=1);

require_once 'db_manager.php';
require_once 'meta_api_functions.php';

// --- Placeholder Configuration (Replace with actual values or secure retrieval methods) ---
// These constants are primarily for example usage within this file.
// For actual cron jobs or operational scripts, prefer environment variables or dedicated config files.
define('AD_MANAGER_SERVICE_DEFAULT_AD_ACCOUNT_ID', getenv('META_AD_ACCOUNT_ID') ?: 'act_YOUR_AD_ACCOUNT_ID_HERE');
define('AD_MANAGER_SERVICE_DEFAULT_USER_ACCESS_TOKEN', getenv('META_USER_ACCESS_TOKEN') ?: 'YOUR_USER_ACCESS_TOKEN_HERE');

/**
 * Class AdManagerService
 *
 * Handles fetching ad campaign/ad set/ad entities and performance insights from the Meta Marketing API,
 * storing them in a local database, and providing methods to query this stored data.
 * It also includes a basic performance extrapolation feature.
 * 
 * Key Responsibilities:
 * - Synchronizing ad account structure (campaigns, ad sets) with the local DB.
 *   (Note: Ad-level entity synchronization could be a future enhancement).
 * - Fetching and storing periodic performance insights for campaigns.
 * - Providing aggregated performance views from the local DB.
 * - Offering daily trend data for specific metrics from the local DB.
 * - Offering a conceptual, naive performance projection.
 */
class AdManagerService
{
    /**
     * Fetches campaign and ad set entities for a given ad account and stores/updates them in the database.
     * This function focuses on ensuring the entities (campaigns, ad sets) are known to the system
     * and their basic identifying information (name, status, objective) are up-to-date in the local database.
     * Entity rows in `ad_campaign_performance` have NULL for `report_date`, `platform_ad_id`.
     * Campaign-level entities also have `platform_adset_id` as NULL.
     * Ad-level entity synchronization could be a future enhancement.
     *
     * @param string $userAccessToken User access token with necessary permissions (ads_read).
     * @param string $adAccountId The Ad Account ID (e.g., 'act_xxxxxxxxxxxxx').
     * @return array An array with counts of processed, inserted, updated, and failed campaigns and ad sets.
     *               Example: ['campaigns' => ['processed' => N, 'inserted' => N, 'updated' => N, 'failed' => N],
     *                         'ad_sets'   => ['processed' => N, 'inserted' => N, 'updated' => N, 'failed' => N]]
     * @throws Exception If critical API errors occur that are not handled by `getAdCampaigns` or `getAdSets`.
     */
    public static function fetchAndStoreAdCampaignEntities(string $userAccessToken, string $adAccountId): array
    {
        $resultsSummary = [
            'campaigns' => ['processed' => 0, 'inserted' => 0, 'updated' => 0, 'failed' => 0],
            'ad_sets'   => ['processed' => 0, 'inserted' => 0, 'updated' => 0, 'failed' => 0],
            // Ads could be added here if processed: 'ads' => [...]
        ];
        $adAccountId = formatAdAccountId($adAccountId); // Ensure 'act_' prefix from meta_api_functions

        try {
            // Fetch all campaigns for the ad account
            $campaignsData = getAdCampaigns($userAccessToken, $adAccountId, ['id', 'name', 'objective', 'status', 'special_ad_categories', 'start_time', 'stop_time', 'created_time', 'updated_time']);
            
            if (isset($campaignsData['error'])) {
                error_log("AdManagerService::fetchAndStoreAdCampaignEntities - Failed to fetch campaigns for account {$adAccountId}. Error: " . $campaignsData['error']);
                // If 'data' might exist even with an error, count them as failed. Otherwise, this is a general failure.
                $resultsSummary['campaigns']['failed'] = isset($campaignsData['data']) ? count($campaignsData['data']) : 1; 
                return $resultsSummary;
            }

            if (empty($campaignsData['data'])) {
                error_log("AdManagerService::fetchAndStoreAdCampaignEntities - No campaigns found for account {$adAccountId}.");
                return $resultsSummary;
            }

            // Process each campaign
            foreach ($campaignsData['data'] as $campaign) {
                $resultsSummary['campaigns']['processed']++;
                $platformCampaignId = $campaign['id'];
                $campaignName = $campaign['name'] ?? 'N/A';
                $objective = $campaign['objective'] ?? null;
                $status = $campaign['status'] ?? null;
                // For entity rows, report_date, adset_id, and ad_id are NULL.

                try {
                    // Check if this campaign entity already exists
                    $checkSql = "SELECT id, campaign_name, objective, status FROM ad_campaign_performance 
                                 WHERE platform_campaign_id = :platform_campaign_id 
                                   AND platform_adset_id IS NULL 
                                   AND platform_ad_id IS NULL 
                                   AND report_date IS NULL"; // Entity row identifier
                    $existingCampaign = DBManager::fetchOne($checkSql, [':platform_campaign_id' => $platformCampaignId]);

                    if ($existingCampaign) {
                        // Update if key fields changed
                        if ($existingCampaign['campaign_name'] !== $campaignName || 
                            $existingCampaign['objective'] !== $objective || 
                            $existingCampaign['status'] !== $status) {
                            $updateSql = "UPDATE ad_campaign_performance SET 
                                            campaign_name = :name, objective = :objective, status = :status, 
                                            updated_at = NOW() 
                                          WHERE id = :id";
                            DBManager::executeNonQuery($updateSql, [
                                ':name' => $campaignName,
                                ':objective' => $objective,
                                ':status' => $status,
                                ':id' => $existingCampaign['id']
                            ]);
                            $resultsSummary['campaigns']['updated']++;
                        }
                    } else {
                        // Insert new campaign entity
                        $insertSql = "INSERT INTO ad_campaign_performance 
                                      (ad_account_id, platform_campaign_id, campaign_name, objective, status, created_at, updated_at) 
                                      VALUES (:ad_account_id, :platform_campaign_id, :name, :objective, :status, NOW(), NOW())";
                        DBManager::executeNonQuery($insertSql, [
                            ':ad_account_id' => $adAccountId,
                            ':platform_campaign_id' => $platformCampaignId,
                            ':name' => $campaignName,
                            ':objective' => $objective,
                            ':status' => $status
                        ]);
                        $resultsSummary['campaigns']['inserted']++;
                    }

                    // Fetch and store ad sets for this campaign
                    $adSetsData = getAdSets($userAccessToken, $platformCampaignId, ['id', 'name', 'status', 'campaign_id', 'created_time', 'updated_time', 'start_time', 'end_time']);
                    if (isset($adSetsData['error'])) {
                        error_log("AdManagerService::fetchAndStoreAdCampaignEntities - Failed to fetch ad sets for campaign {$platformCampaignId}. Error: " . $adSetsData['error']);
                        $resultsSummary['ad_sets']['failed'] += isset($adSetsData['data']) ? count($adSetsData['data']) : 1;
                        continue; // Move to next campaign
                    }

                    if (!empty($adSetsData['data'])) {
                        foreach ($adSetsData['data'] as $adSet) {
                            $resultsSummary['ad_sets']['processed']++;
                            $platformAdSetId = $adSet['id'];
                            $adSetName = $adSet['name'] ?? 'N/A';
                            $adSetStatus = $adSet['status'] ?? null;

                            // Check if this ad set entity already exists
                            $checkAdSetSql = "SELECT id, adset_name, status FROM ad_campaign_performance 
                                              WHERE platform_adset_id = :platform_adset_id 
                                                AND platform_ad_id IS NULL 
                                                AND report_date IS NULL"; // Entity row identifier
                            $existingAdSet = DBManager::fetchOne($checkAdSetSql, [':platform_adset_id' => $platformAdSetId]);
                            
                            if ($existingAdSet) {
                                if ($existingAdSet['adset_name'] !== $adSetName || $existingAdSet['status'] !== $adSetStatus) {
                                    $updateAdSetSql = "UPDATE ad_campaign_performance SET 
                                                       adset_name = :name, status = :status, updated_at = NOW() 
                                                       WHERE id = :id";
                                    DBManager::executeNonQuery($updateAdSetSql, [
                                        ':name' => $adSetName,
                                        ':status' => $adSetStatus,
                                        ':id' => $existingAdSet['id']
                                    ]);
                                    $resultsSummary['ad_sets']['updated']++;
                                }
                            } else {
                                $insertAdSetSql = "INSERT INTO ad_campaign_performance 
                                                   (ad_account_id, platform_campaign_id, campaign_name, platform_adset_id, adset_name, status, objective, created_at, updated_at)
                                                   VALUES (:ad_account_id, :platform_campaign_id, :campaign_name, :platform_adset_id, :adset_name, :status, :objective, NOW(), NOW())";
                                DBManager::executeNonQuery($insertAdSetSql, [
                                    ':ad_account_id' => $adAccountId,
                                    ':platform_campaign_id' => $platformCampaignId, 
                                    ':campaign_name' => $campaignName, 
                                    ':platform_adset_id' => $platformAdSetId,
                                    ':adset_name' => $adSetName,
                                    ':status' => $adSetStatus,
                                    ':objective' => $objective // Inherit campaign objective for context
                                ]);
                                $resultsSummary['ad_sets']['inserted']++;
                            }
                            // TODO: Future enhancement - Fetch and store Ad entities for each Ad Set here if needed.
                            // This would involve calling an equivalent of `getAdsInAdSet()` or `getAdsByCampaignId()`
                            // and then performing similar upsert logic for ad-level entities.
                        }
                    }

                } catch (PDOException $e) {
                    error_log("AdManagerService::fetchAndStoreAdCampaignEntities - DB error processing campaign {$platformCampaignId} or its ad sets. Error: " . $e->getMessage());
                    $resultsSummary['campaigns']['failed']++; 
                }
            }

        } catch (Exception $e) { // Catch broader exceptions, e.g., from meta_api_functions if not PDO related
            error_log("AdManagerService::fetchAndStoreAdCampaignEntities - General error for account {$adAccountId}. Error: " . $e->getMessage());
            // This indicates a more general failure, potentially before iterating campaigns.
        }
        return $resultsSummary;
    }

    /**
     * Fetches campaign-level performance insights for a given ad account and date preset,
     * and stores/updates them in the local database (`ad_campaign_performance` table).
     * Uses an "upsert" approach (INSERT ON DUPLICATE KEY UPDATE).
     *
     * @param string $userAccessToken User access token with ads_read permission.
     * @param string $adAccountId The Ad Account ID (e.g., 'act_xxxxxxxxxxxxx').
     * @param string $datePreset Date preset (e.g., 'last_30d', 'today', 'yesterday'). Refer to Meta API docs for options.
     * @return array Summary of processed, inserted/updated (combined), and failed insight entries.
     *               Example: ['processed' => N, 'inserted_or_updated' => N, 'failed' => N]
     * @throws Exception If critical API errors occur that are not handled by `getAdCampaigns` or `getAdCampaignInsights`.
     */
    public static function fetchAndStoreAdPerformanceInsights(string $userAccessToken, string $adAccountId, string $datePreset = 'last_30d'): array
    {
        $resultsSummary = ['processed' => 0, 'inserted_or_updated' => 0, 'failed' => 0];
        $adAccountId = formatAdAccountId($adAccountId);

        try {
            // Fetch all campaign entities for the account to associate insights with
            $campaignsResponse = getAdCampaigns($userAccessToken, $adAccountId, ['id', 'name', 'objective']);
            if (isset($campaignsResponse['error']) || empty($campaignsResponse['data'])) {
                error_log("AdManagerService::fetchAndStoreAdPerformanceInsights - No campaigns found or error fetching campaigns for account {$adAccountId}. Error: " . ($campaignsResponse['error'] ?? 'No data'));
                $resultsSummary['failed'] = 1; // Indicate a general failure to fetch parent entities
                return $resultsSummary;
            }

            foreach ($campaignsResponse['data'] as $campaignEntity) {
                $platformCampaignId = $campaignEntity['id'];
                $campaignName = $campaignEntity['name'] ?? 'N/A'; 
                $campaignObjective = $campaignEntity['objective'] ?? null;

                // Fetch campaign-level insights
                $insightsData = getAdCampaignInsights(
                    $userAccessToken,
                    $platformCampaignId,
                    $datePreset,
                    // Requesting relevant fields for ad_campaign_performance table
                    ['campaign_id', 'campaign_name', 'impressions', 'clicks', 'spend', 'reach', 'ctr', 'cpc', 'cpp', 'actions', 'objective', 'date_start', 'date_stop'],
                    'campaign' // Level
                );

                if (isset($insightsData['error'])) {
                    error_log("AdManagerService::fetchAndStoreAdPerformanceInsights - Error fetching insights for campaign {$platformCampaignId}. Error: " . $insightsData['error']);
                    $resultsSummary['failed']++; // Count this campaign's insights fetch as failed
                    continue; // Proceed to the next campaign
                }

                if (empty($insightsData['data'])) {
                    error_log("AdManagerService::fetchAndStoreAdPerformanceInsights - No insights data returned for campaign {$platformCampaignId} with date_preset {$datePreset}.");
                    continue; // Proceed to the next campaign
                }

                foreach ($insightsData['data'] as $insight) {
                    $resultsSummary['processed']++;
                    $reportDate = $insight['date_start'] ?? null; 
                    if (!$reportDate) {
                        error_log("AdManagerService::fetchAndStoreAdPerformanceInsights - Insight for campaign {$platformCampaignId} missing date_start. Data: " . json_encode($insight));
                        $resultsSummary['failed']++;
                        continue; // Skip this insight record
                    }

                    // Prepare data for DB upsert
                    $params = [
                        ':ad_account_id' => $adAccountId,
                        ':platform_campaign_id' => $insight['campaign_id'],
                        ':campaign_name' => $insight['campaign_name'] ?? $campaignName, 
                        ':report_date' => $reportDate,
                        ':impressions' => (int)($insight['impressions'] ?? 0),
                        ':clicks' => (int)($insight['clicks'] ?? 0),
                        ':spend' => (float)($insight['spend'] ?? 0.0),
                        ':reach' => (int)($insight['reach'] ?? 0),
                        ':ctr' => (float)($insight['ctr'] ?? 0.0),
                        ':cpc' => (float)($insight['cpc'] ?? 0.0),
                        ':cpp' => (float)($insight['cpp'] ?? 0.0),
                        ':objective' => $insight['objective'] ?? $campaignObjective, 
                        ':conversions' => 0, // Default, updated below
                        ':platform_adset_id' => null, // Campaign-level insight
                        ':adset_name' => null,
                        ':platform_ad_id' => null,
                        ':ad_name' => null,
                        ':status' => null // Status is an entity property, not typically part of daily insights
                    ];

                    // Handle 'actions' for conversions (summing 'omni_purchase' as an example)
                    $totalConversions = 0;
                    if (!empty($insight['actions'])) {
                        foreach ($insight['actions'] as $action) {
                            // Example: count all actions that seem like a purchase/conversion.
                            // This might need refinement based on actual desired conversion action_types.
                            if (isset($action['action_type']) && (strpos($action['action_type'], 'purchase') !== false || strpos($action['action_type'], 'conversion') !== false) ) { 
                                $totalConversions += (int)($action['value'] ?? 0);
                            }
                        }
                    }
                    $params[':conversions'] = $totalConversions;
                    
                    // Upsert logic for MySQL (inserts if new, updates if existing based on composite key)
                    $sql = "INSERT INTO ad_campaign_performance 
                                (ad_account_id, platform_campaign_id, campaign_name, report_date, impressions, clicks, spend, reach, ctr, cpc, cpp, objective, conversions, platform_adset_id, adset_name, platform_ad_id, ad_name, status, created_at, updated_at)
                            VALUES 
                                (:ad_account_id, :platform_campaign_id, :campaign_name, :report_date, :impressions, :clicks, :spend, :reach, :ctr, :cpc, :cpp, :objective, :conversions, :platform_adset_id, :adset_name, :platform_ad_id, :ad_name, :status, NOW(), NOW())
                            ON DUPLICATE KEY UPDATE
                                campaign_name = VALUES(campaign_name), impressions = VALUES(impressions), clicks = VALUES(clicks),
                                spend = VALUES(spend), reach = VALUES(reach), ctr = VALUES(ctr), cpc = VALUES(cpc), cpp = VALUES(cpp),
                                objective = VALUES(objective), conversions = VALUES(conversions), status = VALUES(status), 
                                updated_at = NOW()";
                    try {
                        $affectedRows = DBManager::executeNonQuery($sql, $params);
                        if ($affectedRows > 0) {
                             $resultsSummary['inserted_or_updated']++;
                        }
                    } catch (PDOException $e) {
                        error_log("AdManagerService::fetchAndStoreAdPerformanceInsights - DB error storing insights for campaign {$platformCampaignId}, date {$reportDate}. Error: " . $e->getMessage());
                        $resultsSummary['failed']++;
                    }
                }
            }
        } catch (Exception $e) { // Catch broader exceptions
            error_log("AdManagerService::fetchAndStoreAdPerformanceInsights - General error for account {$adAccountId}. Error: " . $e->getMessage());
            $resultsSummary['failed']++; // General failure indication
        }
        return $resultsSummary;
    }

    /**
     * Retrieves aggregated performance metrics for specified campaigns over a given date range
     * from the local ad_campaign_performance table.
     * This method queries the local database and does not make external API calls.
     *
     * @param array $campaignIds Optional. An array of platform_campaign_ids to filter by. If empty, aggregates for all relevant campaigns.
     * @param array $dateRange Optional. Associative array with 'start' and 'end' keys (YYYY-MM-DD).
     *                         If empty, aggregates data for all dates where report_date is NOT NULL.
     * @param array $extraFilters Optional. Associative array for other filters (e.g., ['objective' => 'CONVERSIONS', 'status' => 'ACTIVE']).
     *                            Allowed keys: 'objective', 'status'. Filters apply to the campaign entity attributes if available on performance rows.
     * @return array An array of associative arrays, each containing campaign identifiers and aggregated metrics.
     *               Metrics include: platform_campaign_id, campaign_name, objective, status (campaign entity status),
     *               total_impressions, total_clicks, total_spend, total_conversions,
     *               calculated_ctr, calculated_cpc, calculated_cpa (Cost Per Action/Acquisition),
     *               first_report_date, last_report_date, reporting_days.
     * @throws PDOException If a database error occurs.
     * @throws InvalidArgumentException If an invalid key is used in $extraFilters.
     */
    public static function getAggregatedCampaignPerformance(
        array $campaignIds = [],
        array $dateRange = [], // Expects ['start' => 'YYYY-MM-DD', 'end' => 'YYYY-MM-DD']
        array $extraFilters = []
    ): array {
        $sqlParams = [];
        // We only want to aggregate rows that actually have performance data (daily entries)
        $whereClauses = ["report_date IS NOT NULL", "platform_adset_id IS NULL", "platform_ad_id IS NULL"]; 

        if (!empty($campaignIds)) {
            $placeholders = implode(',', array_fill(0, count($campaignIds), '?'));
            $whereClauses[] = "platform_campaign_id IN ({$placeholders})";
            foreach ($campaignIds as $id) {
                $sqlParams[] = $id;
            }
        }

        if (!empty($dateRange) && isset($dateRange['start']) && isset($dateRange['end'])) {
            $whereClauses[] = "report_date BETWEEN ? AND ?";
            $sqlParams[] = $dateRange['start'];
            $sqlParams[] = $dateRange['end'];
        }
        
        $allowedFilterKeys = ['objective', 'status']; // Whitelist of allowed filter keys for campaign attributes
        foreach ($extraFilters as $key => $value) {
            if (!in_array(strtolower($key), $allowedFilterKeys)) { // Case-insensitive check for key
                throw new InvalidArgumentException("Invalid filter key: {$key}. Allowed keys: " . implode(', ', $allowedFilterKeys));
            }
            // These filters (objective, status) apply to the campaign attributes stored on the performance rows.
            if ($value !== null) {
                $whereClauses[] = "`{$key}` = ?"; // Use backticks for safety
                $sqlParams[] = $value;
            } else {
                $whereClauses[] = "`{$key}` IS NULL";
            }
        }

        // Calculated CTR, CPC, CPA are derived from SUMs to be accurate for the aggregated period.
        $sql = "SELECT 
                    platform_campaign_id, 
                    campaign_name, 
                    objective,
                    status, /* This status reflects the status stored on the daily performance/campaign entity rows */
                    SUM(impressions) as total_impressions,
                    SUM(clicks) as total_clicks,
                    SUM(spend) as total_spend,
                    SUM(conversions) as total_conversions,
                    IF(SUM(impressions) > 0, SUM(clicks) * 1.0 / SUM(impressions), 0) as calculated_ctr,
                    IF(SUM(clicks) > 0, SUM(spend) * 1.0 / SUM(clicks), 0) as calculated_cpc,
                    IF(SUM(conversions) > 0, SUM(spend) * 1.0 / SUM(conversions), 0) as calculated_cpa, 
                    MIN(report_date) as first_report_date,
                    MAX(report_date) as last_report_date,
                    COUNT(DISTINCT report_date) as reporting_days
                FROM ad_campaign_performance";

        if (!empty($whereClauses)) {
            $sql .= " WHERE " . implode(" AND ", $whereClauses);
        }

        $sql .= " GROUP BY platform_campaign_id, campaign_name, objective, status
                  ORDER BY total_spend DESC";

        try {
            return DBManager::fetchAll($sql, $sqlParams);
        } catch (PDOException $e) {
            error_log("AdManagerService::getAggregatedCampaignPerformance DB error: " . $e->getMessage() . " SQL: " . $sql . " Params: " . json_encode($sqlParams));
            throw $e; 
        }
    }

    /**
     * Retrieves daily values for a specific metric for a given campaign over a date range
     * from the local ad_campaign_performance table.
     * This method queries the local database and does not make external API calls.
     *
     * @param string $platformCampaignId The platform_campaign_id.
     * @param string $startDate 'YYYY-MM-DD'.
     * @param string $endDate 'YYYY-MM-DD'.
     * @param string $metric The metric to fetch daily values for (e.g., 'spend', 'impressions').
     *                       Allowed metrics: 'impressions', 'clicks', 'spend', 'conversions', 'ctr', 'cpc', 'cpp', 'reach'.
     * @return array An array of associative arrays, each with 'report_date' and the metric's value ('metric_value').
     * @throws InvalidArgumentException If the metric is not allowed.
     * @throws PDOException If a database error occurs.
     */
    public static function getDailyPerformanceTrend(
        string $platformCampaignId,
        string $startDate,
        string $endDate,
        string $metric = 'spend'
    ): array {
        $allowedMetrics = ['impressions', 'clicks', 'spend', 'conversions', 'ctr', 'cpc', 'cpp', 'reach'];
        $metricKey = strtolower($metric); // Ensure case-insensitivity for the check

        if (!in_array($metricKey, $allowedMetrics)) {
            error_log("AdManagerService::getDailyPerformanceTrend Invalid metric requested: {$metric}");
            throw new InvalidArgumentException("Invalid metric: {$metric}. Allowed metrics are: " . implode(', ', $allowedMetrics));
        }

        // Safely use $metricKey (which is from a whitelist) in SQL.
        // For CTR, CPC, CPP, these are stored as calculated daily values in the DB per current schema.
        $sql = "SELECT 
                    report_date, 
                    `{$metricKey}` as metric_value 
                FROM ad_campaign_performance
                WHERE platform_campaign_id = :platform_campaign_id
                  AND report_date BETWEEN :start_date AND :end_date
                  AND platform_adset_id IS NULL /* Ensure campaign-level data */
                  AND platform_ad_id IS NULL   /* Ensure campaign-level data */
                ORDER BY report_date ASC";
        
        $params = [
            ':platform_campaign_id' => $platformCampaignId,
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ];

        try {
            return DBManager::fetchAll($sql, $params);
        } catch (PDOException $e) {
            error_log("AdManagerService::getDailyPerformanceTrend DB error: " . $e->getMessage() . " SQL: " . $sql . " Params: " . json_encode($params));
            throw $e; 
        }
    }

    /**
     * Provides a very basic, conceptual extrapolation of future ad performance based on historical daily averages.
     *
     * **Disclaimer:** This is a naive extrapolation based on simple daily averages and NOT a statistically
     * valid forecast or prediction. Actual future performance can vary significantly due to numerous
     * factors not considered here (e.g., seasonality, ad fatigue, budget changes, auction dynamics,
     * creative changes, audience saturation, external events, etc.). Use with extreme caution and
     * primarily for illustrative or very high-level conceptual planning only.
     *
     * @param string $platformCampaignId The platform_campaign_id of the campaign to project.
     * @param int $daysToProject Number of days into the future to project. Default is 7.
     * @param int $historicalDaysToConsider Number of recent past days to use for calculating averages. Default is 14.
     * @return array An associative array containing historical averages and future projections.
     *               Structure:
     *               - platform_campaign_id (string)
     *               - historical_period_days_considered (int)
     *               - projection_period_days (int)
     *               - historical_data_points_found (int) - Actual number of days with data in historical period
     *               - average_daily_spend (float)
     *               - average_daily_impressions (float)
     *               - average_daily_clicks (float)
     *               - average_daily_conversions (float)
     *               - projected_total_spend (float)
     *               - projected_total_impressions (float)
     *               - projected_total_clicks (float)
     *               - projected_total_conversions (float)
     *               - projected_ctr (float)
     *               - projected_cpc (float)
     *               - projected_cpa (float)
     *               - message (string) - Informational message about the projection or data insufficiency.
     * @throws PDOException If a database error occurs.
     */
    public static function getBasicPerformanceExtrapolation(
        string $platformCampaignId,
        int $daysToProject = 7,
        int $historicalDaysToConsider = 14
    ): array {
        $endDate = date('Y-m-d', strtotime('-1 day')); // Use data up to yesterday
        $startDate = date('Y-m-d', strtotime("-{$historicalDaysToConsider} days", strtotime($endDate)));

        $result = [
            'platform_campaign_id' => $platformCampaignId,
            'historical_period_days_considered' => $historicalDaysToConsider,
            'projection_period_days' => $daysToProject,
            'historical_data_points_found' => 0,
            'average_daily_spend' => 0.0,
            'average_daily_impressions' => 0.0,
            'average_daily_clicks' => 0.0,
            'average_daily_conversions' => 0.0,
            'projected_total_spend' => 0.0,
            'projected_total_impressions' => 0.0,
            'projected_total_clicks' => 0.0,
            'projected_total_conversions' => 0.0,
            'projected_ctr' => 0.0,
            'projected_cpc' => 0.0,
            'projected_cpa' => 0.0,
            'message' => ''
        ];

        $sql = "SELECT 
                    COUNT(DISTINCT report_date) as data_days,
                    SUM(spend) as total_spend,
                    SUM(impressions) as total_impressions,
                    SUM(clicks) as total_clicks,
                    SUM(conversions) as total_conversions
                FROM ad_campaign_performance
                WHERE platform_campaign_id = :platform_campaign_id
                  AND report_date BETWEEN :start_date AND :end_date
                  AND platform_adset_id IS NULL /* Campaign-level data */
                  AND platform_ad_id IS NULL   /* Campaign-level data */
                GROUP BY platform_campaign_id"; // Grouping by campaign_id though we filter by one, good practice

        $params = [
            ':platform_campaign_id' => $platformCampaignId,
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ];

        try {
            $historicalData = DBManager::fetchOne($sql, $params);

            // Require at least 3 days of data or more than half the consideration period for a somewhat meaningful average
            $minDataPointsRequired = max(3, floor($historicalDaysToConsider / 2)); 

            if (!$historicalData || ($historicalData['data_days'] ?? 0) < $minDataPointsRequired ) {
                $dataDaysFound = $historicalData['data_days'] ?? 0;
                $result['message'] = "Insufficient historical data (found {$dataDaysFound} days with performance in the last {$historicalDaysToConsider} days, minimum {$minDataPointsRequired} required) for a meaningful projection for campaign {$platformCampaignId}.";
                error_log("AdManagerService::getBasicPerformanceExtrapolation: " . $result['message']);
                $result['historical_data_points_found'] = $dataDaysFound;
                return $result;
            }
            
            $dataDaysFound = (int)$historicalData['data_days'];
            $result['historical_data_points_found'] = $dataDaysFound;

            if ($dataDaysFound > 0) {
                $result['average_daily_spend'] = ((float)($historicalData['total_spend'] ?? 0.0)) / $dataDaysFound;
                $result['average_daily_impressions'] = ((float)($historicalData['total_impressions'] ?? 0.0)) / $dataDaysFound;
                $result['average_daily_clicks'] = ((float)($historicalData['total_clicks'] ?? 0.0)) / $dataDaysFound;
                $result['average_daily_conversions'] = ((float)($historicalData['total_conversions'] ?? 0.0)) / $dataDaysFound;

                $result['projected_total_spend'] = $result['average_daily_spend'] * $daysToProject;
                $result['projected_total_impressions'] = $result['average_daily_impressions'] * $daysToProject;
                $result['projected_total_clicks'] = $result['average_daily_clicks'] * $daysToProject;
                $result['projected_total_conversions'] = $result['average_daily_conversions'] * $daysToProject;

                $result['projected_ctr'] = ($result['projected_total_impressions'] > 0) ? ($result['projected_total_clicks'] / $result['projected_total_impressions']) : 0.0;
                $result['projected_cpc'] = ($result['projected_total_clicks'] > 0) ? ($result['projected_total_spend'] / $result['projected_total_clicks']) : 0.0;
                $result['projected_cpa'] = ($result['projected_total_conversions'] > 0) ? ($result['projected_total_spend'] / $result['projected_total_conversions']) : 0.0;
                
                $result['message'] = "Naive extrapolation based on daily averages from the last {$dataDaysFound} days of data within the {$historicalDaysToConsider}-day historical window. Not a statistically valid forecast.";
            } else {
                 $result['message'] = "No historical data found for campaign {$platformCampaignId} in the last {$historicalDaysToConsider} days.";
            }

        } catch (PDOException $e) {
            error_log("AdManagerService::getBasicPerformanceExtrapolation DB error: " . $e->getMessage() . " SQL: " . $sql . " Params: " . json_encode($params));
            throw $e; // Re-throw
        }
        
        return $result;
    }
}

/*
// --- Example Usage (Commented Out) ---

// // Ensure DB_USER and DB_PASS constants are correctly set in db_manager.php
// // and AD_MANAGER_SERVICE_DEFAULT_AD_ACCOUNT_ID, AD_MANAGER_SERVICE_DEFAULT_USER_ACCESS_TOKEN are set above or passed directly.

// $adAccountIdConfig = AD_MANAGER_SERVICE_DEFAULT_AD_ACCOUNT_ID;
// $userAccessTokenConfig = AD_MANAGER_SERVICE_DEFAULT_USER_ACCESS_TOKEN;

// if ($adAccountIdConfig === 'act_YOUR_AD_ACCOUNT_ID_HERE' || $userAccessTokenConfig === 'YOUR_USER_ACCESS_TOKEN_HERE') {
//     echo "Please configure placeholder constants in ad_manager_service.php before running examples.\n";
// } else {
//     echo "--- Fetching and Storing Ad Campaign Entities (if not done recently by cron) ---\n";
//     try {
//         // $entityResults = AdManagerService::fetchAndStoreAdCampaignEntities($userAccessTokenConfig, $adAccountIdConfig);
//         // echo "Campaigns Processed: " . $entityResults['campaigns']['processed'] . ", Inserted: " . $entityResults['campaigns']['inserted'] . ", Updated: " . $entityResults['campaigns']['updated'] . ", Failed: " . $entityResults['campaigns']['failed'] . "\n";
//         // echo "Ad Sets Processed: " . $entityResults['ad_sets']['processed'] . ", Inserted: " . $entityResults['ad_sets']['inserted'] . ", Updated: " . $entityResults['ad_sets']['updated'] . ", Failed: " . $entityResults['ad_sets']['failed'] . "\n";
//         echo "(Skipping entity sync for this example run, assuming cron_track_ads.php handles it.)\n";
//     } catch (Exception $e) {
//         echo "Error fetching/storing entities: " . $e->getMessage() . "\n";
//     }

//     echo "\n--- Fetching and Storing Ad Performance Insights (if not done recently by cron) ---\n";
//     try {
//         // $insightResults = AdManagerService::fetchAndStoreAdPerformanceInsights($userAccessTokenConfig, $adAccountIdConfig, 'last_7d'); 
//         // echo "Insight Entries Processed: " . $insightResults['processed'] . ", Failed: " . $insightResults['failed'] . "\n";
//         echo "(Skipping insights sync for this example run, assuming cron_track_ads.php handles it.)\n";
//     } catch (Exception $e) {
//         echo "Error fetching/storing insights: " . $e->getMessage() . "\n";
//     }

//     echo "\n--- Example: Get Aggregated Performance for specific campaigns (last 7 days) ---\n";
//     try {
//         // Replace with actual platform_campaign_ids from your 'ad_campaign_performance' table after running the cron or entity sync
//         $sampleCampaignIds = []; // Example: ['123456789012345', '234567890123456']; 
//         $testAdAccountId = $adAccountIdConfig; // Use the configured one

//         // Fetch some campaign IDs from the DB to test with, if not manually specified
//         $campaignEntitiesSql = "SELECT DISTINCT platform_campaign_id FROM ad_campaign_performance 
//                                 WHERE ad_account_id = :ad_account_id AND platform_campaign_id IS NOT NULL 
//                                 AND report_date IS NULL LIMIT 2"; // Entity rows
//         $campaignEntities = DBManager::fetchAll($campaignEntitiesSql, [':ad_account_id' => $testAdAccountId]);
//         if (!empty($campaignEntities)) {
//             $sampleCampaignIds = array_column($campaignEntities, 'platform_campaign_id');
//             echo "Using dynamically fetched campaign IDs for testing: " . implode(', ', $sampleCampaignIds) . "\n";
//         } else if (empty($sampleCampaignIds)) {
//             echo "No sample campaign IDs found in DB and none manually specified. Aggregated performance might be empty or for all campaigns.\n";
//         }
        
//         $today = date('Y-m-d');
//         $sevenDaysAgo = date('Y-m-d', strtotime('-6 days')); // -6 to include today as the 7th day

//         $aggregatedPerformance = AdManagerService::getAggregatedCampaignPerformance(
//             $sampleCampaignIds, // Pass empty to test for all campaigns in the account
//             ['start' => $sevenDaysAgo, 'end' => $today],
//             ['status' => 'ACTIVE'] // Example extra filter for active campaign entities
//         );
        
//         echo "Aggregated Performance (Last 7 Days for selected/all ACTIVE campaigns):\n";
//         if(empty($aggregatedPerformance)) {
//             echo "No aggregated performance data found for the criteria.\n";
//         } else {
//             print_r($aggregatedPerformance);
//         }

//     } catch (InvalidArgumentException $e) {
//         echo "Error (Invalid Argument): " . $e->getMessage() . "\n";
//     } catch (Exception $e) { // Catches PDOException as well
//         echo "Error getting aggregated performance: " . $e->getMessage() . "\n";
//     }

//     echo "\n--- Example: Get Daily Spend Trend for a campaign (last 7 days) ---\n";
//     try {
//         $sampleCampaignIdForTrend = $sampleCampaignIds[0] ?? null; 
        
//         if ($sampleCampaignIdForTrend) {
//             echo "Fetching daily spend trend for campaign ID: {$sampleCampaignIdForTrend}\n";
//             $dailyTrend = AdManagerService::getDailyPerformanceTrend(
//                 $sampleCampaignIdForTrend,
//                 $sevenDaysAgo,
//                 $today,
//                 'spend' // Metric to trend
//             );
//             echo "Daily Spend Trend for Campaign {$sampleCampaignIdForTrend} (Last 7 Days):\n";
//             if(empty($dailyTrend)) {
//                 echo "No daily trend data found for this campaign and period.\n";
//             } else {
//                 print_r($dailyTrend);
//             }
//         } else {
//             echo "No sample campaign ID available for daily trend example. Please run entity/insight sync or provide an ID.\n";
//         }

//     } catch (InvalidArgumentException $e) {
//         echo "Error (Invalid Argument): " . $e->getMessage() . "\n";
//     } catch (Exception $e) { // Catches PDOException
//         echo "Error getting daily performance trend: " . $e->getMessage() . "\n";
//     }

//     echo "\n--- Example: Get Basic Performance Extrapolation ---\n";
//     try {
//         // Replace 'YOUR_CAMPAIGN_ID_1' with an actual platform_campaign_id from your DB that has recent data
//         $sampleCampaignIdForExtrapolation = $sampleCampaignIds[0] ?? 'YOUR_CAMPAIGN_ID_1'; 

//         if ($sampleCampaignIdForExtrapolation === 'YOUR_CAMPAIGN_ID_1' && empty($sampleCampaignIds[0])) {
//              echo "Note: Using a placeholder campaign ID for getBasicPerformanceExtrapolation. This will likely yield 'insufficient data' if the ID doesn't exist or has no recent data.\n";
//         } else {
//             echo "Fetching basic extrapolation for campaign ID: {$sampleCampaignIdForExtrapolation}\n";
//         }
        
//         $extrapolation = AdManagerService::getBasicPerformanceExtrapolation(
//             $sampleCampaignIdForExtrapolation,
//             7,  // Project for next 7 days
//             14  // Use last 14 days of data for averages
//         );
//         echo "Basic Performance Extrapolation:\n";
//         print_r($extrapolation);

//         // Example with a campaign that might have insufficient data
//         // $extrapolationInsufficient = AdManagerService::getBasicPerformanceExtrapolation(
//         // 'NON_EXISTENT_CAMPAIGN_ID_OR_NO_RECENT_DATA', 7, 14);
//         // echo "\nExtrapolation for campaign with likely insufficient data:\n";
//         // print_r($extrapolationInsufficient);
        
//     } catch (Exception $e) {
//         echo "Error getting basic performance extrapolation: " . $e->getMessage() . "\n";
//     }
// }

*/

?>
