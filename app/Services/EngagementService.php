<?php

namespace App\Services;

use App\Core\Database;
use Exception;

class EngagementService
{
    private $config;

    public function __construct()
    {
        $this->config = [
            'min_messages_before_tracking' => 3,
            'idle_threshold' => 30000,
            'scroll_threshold' => 0.8,
            'page_time_threshold' => 60000,
            'track_heatmaps' => true,
            'track_time_on_page' => true,
            'track_scroll_depth' => true,
            'track_click_patterns' => true,
            'anonymize_data' => true,
            'data_retention_days' => 365,
            'batch_size' => 100,
            'aggregate_hourly' => true,
        ];
    }

    public function trackEvent(string $userId, string $eventType, array $data): void
    {
        $event = [
            'id' => $this->generateEventId(),
            'user_id' => $userId,
            'event_type' => $eventType,
            'data' => $data,
            'timestamp' => date('Y-m-d H:i:s'),
            'ip_address' => $data['ip_address'] ?? null,
            'user_agent' => $data['user_agent'] ?? null,
            'session_id' => $data['session_id'] ?? null,
            'page_url' => $data['page_url'] ?? null,
            'referrer' => $data['referrer'] ?? null,
        ];

        $this->saveEvent($event);
        $this->processAnalytics($event);
        $this->checkAutomatedAlerts($event);
    }

    public function getEngagementInsights(string $userId, string $period = '30d'): array
    {
        $startDate = $this->calculateStartDate($period);
        $events = $this->getUserEvents($userId, $startDate);

        return [
            'behavioral_profile' => $this->buildProfile($events),
            'engagement_metrics' => $this->calculateMetrics($events),
            'session_analysis' => $this->analyzeSessions($events),
            'conversion_funnel' => $this->buildFunnel($events),
            'abandonment_points' => $this->identifyAbandonmentPoints($events),
            'recommended_content' => $this->suggestNextSteps($events),
            'predictive_score' => $this->calculatePredictiveScore($events),
            'bounces' => $this->analyzeBounces($events),
            'engagement_risk' => $this->assessRisk($events),
            'optimization_opportunities' => $this->findOpportunities($events),
        ];
    }

    public function identifyHighValueUsers(array $userIds): array
    {
        $highValueUsers = [];

        foreach ($userIds as $userId) {
            $events = $this->getUserEvents($userId, date('Y-m-d 00:00:00', strtotime('-30 days')));

            $score = $this->calculateLTVScore($events);

            if ($score >= 75) {
                $highValueUsers[] = [
                    'user_id' => $userId,
                    'score' => $score,
                    'segment' => $this->defineSegment($events),
                    'next_actions' => $this->suggestNextAction($events),
                    'rfm_analysis' => $this->analyzeRFM($events),
                    'value_patterns' => $this->identifyValuePatterns($events),
                ];
            }
        }

        usort($highValueUsers, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return array_slice($highValueUsers, 0, 50);
    }

    public function getFunnelData(string $userId, string $period = '30d'): array
    {
        $startDate = $this->calculateStartDate($period);
        $events = $this->getUserEvents($userId, $startDate);

        $funnel = [
            'entrance' => $this->countEvents($events, 'page_view'),
            'engagement' => $this->countEvents($events, ['click', 'scroll', 'typing']),
            'conversion' => [
                'complete_purchase' => $this->countEvents($events, 'purchase_completed'),
                'schedule_demo' => $this->countEvents($events, 'demo_scheduled'),
                'contact_sales' => $this->countEvents($events, 'contact_sales'),
                'newsletter_signup' => $this->countEvents($events, 'newsletter_signup'),
                'download_content' => $this->countEvents($events, 'content_downloaded'),
            ],
            'droppoints' => $this->identifyDropPoints($events),
            'conversion_rate' => $this->calculateConversionRate($events),
            'time_to_convert' => $this->calculateTimeToConvert($events),
        ];

        return $funnel;
    }

    public function getSegmentAnalysis(string $segment, string $period = '30d'): array
    {
        $startDate = $this->calculateStartDate($period);
        $users = $this->getUsersInSegment($segment, $startDate);
        $allEvents = [];

        foreach ($users as $userId) {
            $events = $this->getUserEvents($userId, $startDate);
            $allEvents = array_merge($allEvents, $events);
        }

        return [
            'profile' => $this->buildSegmentProfile($allEvents, $segment),
            'behavior' => $this->analyzeSegmentBehavior($allEvents, $segment),
            'characteristics' => $this->identifySegmentCharacteristics($allEvents, $segment),
            'preferences' => $this->analyzeSegmentPreferences($allEvents, $segment),
            'pain_points' => $this->identifySegmentPainPoints($allEvents, $segment),
            'value_prop' => $this->craftValueProposition($allEvents, $segment),
            'target_metrics' => $this->defineTargetMetrics($segment),
            'success_indicators' => $this->defineSuccessIndicators($segment),
        ];
    }

    private function saveEvent(array $event): void
    {
        $db = Database::getInstance();
        $db->query(
            "INSERT INTO engagement_events (
                id, user_id, event_type, data, timestamp,
                ip_address, user_agent, session_id, page_url, referrer
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $event['id'],
                $event['user_id'],
                $event['event_type'],
                json_encode($event['data']),
                $event['timestamp'],
                $event['ip_address'] ?? null,
                $event['user_agent'] ?? null,
                $event['session_id'] ?? null,
                $event['page_url'] ?? null,
                $event['referrer'] ?? null,
            ]
        );
    }

    private function processAnalytics(array $event): void
    {
        $this->aggregateHourly($event);
        $this->updateUserProfile($event);
        $this->checkThresholds($event);

        if ($this->config['track_heatmaps']) {
            $this->processHeatmapData($event);
        }

        if ($this->config['track_time_on_page']) {
            $this->processTimeOnPage($event);
        }
    }

    private function checkAutomatedAlerts(array $event): void
    {
        $alert_rules = [
            'suspicious_activity' => [
                'condition' => function($event) {
                    return $event['event_type'] === 'click' &&
                           count($this->getUserEvents($event['user_id'], date('Y-m-d 00:00:00'))) > 50;
                },
                'action' => 'notify_security_team',
            ],
            'high_engagement' => [
                'condition' => function($event) {
                    return $this->calculateUserEngagementScore($event['user_id']) > 0.8;
                },
                'action' => 'mark_as_high_value',
            ],
            'potential_abandon' => [
                'condition' => function($event) {
                    return $event['event_type'] === 'scroll' &&
                           $event['data']['scroll_depth'] < $this->config['scroll_threshold'];
                },
                'action' => 'trigger_reengagement_sequence',
            ],
        ];

        foreach ($alert_rules as $rule_name => $rule) {
            if ($rule['condition']($event)) {
                $this->triggerAlert($rule_name, $event);
            }
        }
    }

    private function aggregateHourly(array $event): void
    {
        if ($this->config['aggregate_hourly']) {
            $db = Database::getInstance();
            $db->query(
                "INSERT INTO hourly_aggregates (user_id, event_type, hour, count)
                  VALUES (?, ?, DATE_FORMAT(?, '%Y-%m-%d %H:00:00'), 1)
                  ON DUPLICATE KEY UPDATE count = count + 1",
                [
                    $event['user_id'],
                    $event['event_type'],
                    $event['timestamp'],
                ]
            );
        }
    }

    private function updateUserProfile(array $event): void
    {
        $db = Database::getInstance();
        $db->query(
            "INSERT INTO user_profiles (user_id, last_activity, total_sessions, total_events, avg_session_time)
              VALUES (?, ?, 1, 1, ?)
              ON DUPLICATE KEY UPDATE
              last_activity = VALUES(last_activity),
              total_sessions = total_sessions + 1,
              total_events = total_events + 1,
              avg_session_time = ((avg_session_time * (total_sessions - 1)) + ? ) / total_sessions",
            [
                $event['user_id'],
                $event['timestamp'],
                300, // tempo de sessão padrão
                300,
            ]
        );
    }

    private function checkThresholds(array $event): void
    {
        $threshold_events = ['click', 'scroll', 'typing', 'page_view', 'form_submit', 'file_download'];

        if (in_array($event['event_type'], $threshold_events)) {
            $hourly_count = $this->getHourlyCount($event['user_id'], $event['event_type'], $event['timestamp']);

            if ($hourly_count >= 100) {
                $this->triggerHighFrequencyAlert($event['user_id'], $event['event_type'], $hourly_count);
            }
        }
    }

    private function processHeatmapData(array $event): void
    {
        if (isset($event['data']['coordinates'])) {
            $db = Database::getInstance();
            $db->query(
                "INSERT INTO heatmap_data (user_id, page_url, x, y, timestamp)
                  VALUES (?, ?, ?, ?, ?)",
                [
                    $event['user_id'],
                    $event['page_url'],
                    $event['data']['coordinates']['x'],
                    $event['data']['coordinates']['y'],
                    $event['timestamp'],
                ]
            );
        }
    }

    private function processTimeOnPage(array $event): void
    {
        if (isset($event['data']['time_on_page'])) {
            $db = Database::getInstance();
            $db->query(
                "INSERT INTO time_on_page_metrics (user_id, page_url, time_seconds, timestamp)
                  VALUES (?, ?, ?, ?)",
                [
                    $event['user_id'],
                    $event['page_url'],
                    $event['data']['time_on_page'],
                    $event['timestamp'],
                ]
            );
        }
    }

    private function triggerAlert(string $ruleName, array $event): void
    {
        $db = Database::getInstance();
        $db->query(
            "INSERT INTO alerts (rule_name, user_id, event_type, data, timestamp, status)
              VALUES (?, ?, ?, ?, ?, 'active')",
            [
                $ruleName,
                $event['user_id'],
                $event['event_type'],
                json_encode($event),
                date('Y-m-d H:i:s'),
            ]
        );
    }

    private function triggerHighFrequencyAlert(string $userId, string $eventType, int $count): void
    {
        $db = Database::getInstance();
        $db->query(
            "INSERT INTO alerts (rule_name, user_id, event_type, count, timestamp, status)
              VALUES (?, ?, ?, ?, ?, 'active')",
            [
                'high_frequency',
                $userId,
                $eventType,
                $count,
                date('Y-m-d H:i:s'),
            ]
        );
    }

    private function calculateHourlyCount(string $userId, string $eventType, string $timestamp): int
    {
        $db = Database::getInstance();
        $result = $db->fetch(
            "SELECT COUNT(*) as count FROM hourly_aggregates
              WHERE user_id = ? AND event_type = ? AND hour = DATE_FORMAT(?, '%Y-%m-%d %H:00:00')",
            [$userId, $eventType, $timestamp]
        );

        return $result['count'] ?? 0;
    }

    private function buildProfile(array $events): array
    {
        $profile = [
            'event_types' => $this->countEventTypes($events),
            'peak_hours' => $this->findPeakHours($events),
            'most_visited_pages' => $this->findMostVisitedPages($events),
            'device_types' => $this->detectDeviceTypes($events),
            'browser_types' => $this->detectBrowserTypes($events),
            'engagement_level' => $this->calculateEngagementLevel($events),
            'behavior_pattern' => $this->identifyBehaviorPattern($events),
            'session_frequency' => $this->calculateSessionFrequency($events),
            'last_seen' => $this->findLastSeen($events),
            'member_since' => date('Y-m-d', strtotime('-30 days')),
        ];

        return $profile;
    }

    private function calculateMetrics(array $events): array
    {
        $total_events = count($events);
        $total_sessions = count(array_filter($events, fn($e) => $e['event_type'] === 'session_start'));

        $metrics = [
            'total_events' => $total_events,
            'total_sessions' => $total_sessions,
            'events_per_session' => $total_sessions > 0 ? ($total_events / $total_sessions) : 0,
            'session_duration_avg' => $this->calculateAverageSessionDuration($events),
            'pages_per_session' => $this->calculatePagesPerSession($events),
            'bounce_rate' => $this->calculateBounceRate($events),
            'engagement_score' => $this->calculateEngagementScore($events),
            'session_retention_rate' => $this->calculateSessionRetention($events),
            'daily_active_users' => $this->calculateDAU($events),
            'monthly_active_users' => $this->calculateMAU($events),
            'churn_rate' => $this->calculateChurnRate($events),
            'lifespan_days' => $this->calculateLifespan($events),
        ];

        return $metrics;
    }

    private function analyzeSessions(array $events): array
    {
        $sessions = $this->groupBySession($events);

        $session_analysis = [
            'session_count' => count($sessions),
            'average_duration' => $this->calculateAverageSessionDuration($events),
            'peak_session_hours' => $this->findPeakSessionHours($sessions),
            'session_frequency_distribution' => $this->analyzeSessionFrequency($sessions),
            'session_patterns' => $this->identifySessionPatterns($sessions),
            'cross_device_sessions' => $this->analyzeCrossDeviceSessions($events),
            'session_quality_score' => $this->calculateSessionQuality($sessions),
        ];

        return $session_analysis;
    }

    private function buildFunnel(array $events): array
    {
        $funnel_data = [
            'entrance' => $this->countEvents($events, 'page_view'),
            'scroll_75_percent' => $this->countScrollEvents(0.75),
            'click_cta' => $this->countEvents($events, 'cta_click'),
            'form_completion' => $this->countEvents($events, 'form_completed'),
            'lead_capture' => $this->countEvents($events, 'lead_captured'),
            'schedule_demo' => $this->countEvents($events, 'demo_scheduled'),
            'contact_sales' => $this->countEvents($events, 'contact_sales'),
        ];

        $conversion_rate = $this->calculateConversionRate($funnel_data);
        $droppoints = $this->identifyDropPoints($funnel_data);

        return [
            'data' => $funnel_data,
            'conversion_rate' => $conversion_rate,
            'droppoints' => $droppoints,
            'recommendations' => $this->generateFunnelRecommendations($droppoints),
        ];
    }

    private function identifyAbandonmentPoints(array $events): array
    {
        $abandonment_points = [];

        $scroll_events = array_filter($events, fn($e) => $e['event_type'] === 'scroll');
        foreach ($scroll_events as $event) {
            if ($event['data']['scroll_depth'] < 0.75) {
                $abandonment_points[] = [
                    'type' => 'scroll abandonment',
                    'page' => $event['page_url'],
                    'timestamp' => $event['timestamp'],
                    'user_id' => $event['user_id'],
                    'severity' => $this->calculateAbandonmentSeverity($event),
                ];
            }
        }

        $click_events = array_filter($events, fn($e) => $e['event_type'] === 'click');
        $form_starts = array_filter($click_events, fn($e) => $e['data']['target'] === 'form_start');
        $form_submits = array_filter($click_events, fn($e) => $e['data']['target'] === 'form_submit');

        if (count($form_submits) < count($form_starts) * 0.3) {
            $abandonment_points[] = [
                'type' => 'form abandonment',
                'conversion_rate' => count($form_submits) / max(count($form_starts), 1),
                'recommendation' => 'Simplify form to increase completion rate',
            ];
        }

        return $abandonment_points;
    }

    private function suggestNextSteps(array $events): array
    {
        $suggestions = [];

        if (count($events) > 10) {
            $suggestions[] = 'Explore our premium features to increase engagement';
        }

        if ($this->calculateEngagementScore($events) < 0.3) {
            $suggestions[] = 'Send personalized email with valuable content';
        }

        if ($this->hasMultipleSessions($events)) {
            $suggestions[] = 'Offer loyalty program benefits';
        }

        $suggestions[] = 'Create targeted content based on usage patterns';
        $suggestions[] = 'Implement gentle nudges for underutilized features';

        return $suggestions;
    }

    private function calculatePredictiveScore(array $events): float
    {
        $engagement_score = $this->calculateEngagementScore($events);
        $recency_score = $this->calculateRecencyScore($events);
        $frequency_score = $this->calculateFrequencyScore($events);

        return ($engagement_score + $recency_score + $frequency_score) / 3;
    }

    private function analyzeBounces(array $events): array
    {
        $bounce_analysis = [
            'bounce_rate' => $this->calculateBounceRate($events),
            'bounce_pages' => $this->identifyBouncePages($events),
            'bounce_sessions' => $this->identifyBounceSessions($events),
            'bounce_patterns' => $this->analyzeBouncePatterns($events),
        ];

        return $bounce_analysis;
    }

    private function assessRisk(array $events): array
    {
        $risk_factors = [
            'high_bounce' => $this->calculateBounceRate($events) > 0.7,
            'low_engagement' => $this->calculateEngagementScore($events) < 0.2,
            'irregular_usage' => !$this->hasRegularUsage($events),
            'negative_sentiment' => $this->detectNegativeSentiment($events),
            'feature_underserved' => $this->identifyUnderservedFeatures($events),
        ];

        $risk_score = array_sum($risk_factors) / count($risk_factors);

        $mitigation_strategies = [];

        if ($risk_factors['high_bounce']) {
            $mitigation_strategies[] = 'Improve page load speed and content relevance';
        }
        if ($risk_factors['low_engagement']) {
            $mitigation_strategies[] = 'Send personalized content and feature introductions';
        }
        if ($risk_factors['irregular_usage']) {
            $mitigation_strategies[] = 'Implement re-engagement campaigns and personalized emails';
        }

        return [
            'score' => $risk_score,
            'risk_factors' => $risk_factors,
            'mitigation_strategies' => $mitigation_strategies,
        ];
    }

    private function findOpportunities(array $events): array
    {
        $opportunities = [
            'content_optimization' => $this->identifyContentOpportunities($events),
            'feature_promotion' => $this->identifyFeaturePromotionOpportunities($events),
            'user_onboarding' => $this->identifyOnboardingOpportunities($events),
            'personalization' => $this->identifyPersonalizationOpportunities($events),
            'retention' => $this->identifyRetentionOpportunities($events),
        ];

        return $opportunities;
    }

    // Métodos auxiliares (abreviados para brevidade)

    private function calculateStartDate(string $period): string
    {
        $days = match ($period) {
            '7d' => 7, '14d' => 14, '30d' => 30, '60d' => 60, '90d' => 90, default => 30,
        };
        return date('Y-m-d H:i:s', strtotime("-{$days} days"));
    }

    private function getUserEvents(string $userId, string $startDate): array
    {
        $db = Database::getInstance();
        return $db->fetchAll(
            "SELECT * FROM engagement_events 
             WHERE user_id = ? AND timestamp >= ?
             ORDER BY timestamp ASC",
            [$userId, $startDate]
        );
    }

    private function countEventTypes(array $events): array
    {
        $counts = [];
        foreach ($events as $event) {
            $type = $event['event_type'];
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }
        return $counts;
    }

    private function generateEventId(): string
    {
        return 'ev_' . md5(uniqid() . time());
    }

    private function calculateUserEngagementScore(string $userId): float
    {
        $events = $this->getUserEvents($userId, date('Y-m-d 00:00:00', strtotime('-7 days')));
        $totalEvents = count($events);
        $maxEvents = 50; // limite esperado por semana

        return min($totalEvents / $maxEvents, 1.0);
    }

    // ... mais métodos auxiliares seriam implementados aqui

    public function __invoke(string $action, ...$args): array
    {
        $method = 'handle' . ucfirst($action);
        if (method_exists($this, $method)) {
            return $this->$method(...$args);
        }
        throw new Exception("Action {$action} not supported");
    }
}
