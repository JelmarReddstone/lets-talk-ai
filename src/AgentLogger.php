<?php

namespace AgentReady;

/**
 * Logs AI agent crawl requests and provides analytics.
 * Stores logs as JSON files for zero-dependency operation.
 */
class AgentLogger
{
    private Config $config;
    private string $logDir;

    public function __construct(Config $config, string $logDir = '')
    {
        $this->config = $config;
        $this->logDir = $logDir ?: (sys_get_temp_dir() . '/agentready/logs');
    }

    /**
     * Log a crawl request.
     */
    public function log(array $entry): void
    {
        $this->ensureLogDir();

        $record = [
            'timestamp' => gmdate('c'),
            'date' => gmdate('Y-m-d'),
            'agent_name' => $entry['agent_name'] ?? 'unknown',
            'agent_vendor' => $entry['agent_vendor'] ?? 'unknown',
            'agent_category' => $entry['agent_category'] ?? 'unknown',
            'url' => $entry['url'] ?? '',
            'post_id' => $entry['post_id'] ?? 0,
            'post_title' => $entry['post_title'] ?? '',
            'post_type' => $entry['post_type'] ?? '',
            'format' => $entry['format'] ?? 'markdown',
            'response_size' => $entry['response_size'] ?? 0,
            'ip_hash' => hash('sha256', ($entry['ip'] ?? '') . 'agentready-salt'),
            'user_agent' => $entry['user_agent'] ?? '',
        ];

        // Append to daily log file
        $file = $this->logDir . '/crawl-' . gmdate('Y-m-d') . '.json';
        $this->appendToLog($file, $record);

        // Update aggregate stats
        $this->updateStats($record);
    }

    /**
     * Get aggregate statistics.
     */
    public function getStats(): array
    {
        $file = $this->logDir . '/stats.json';
        if (!file_exists($file)) {
            return $this->emptyStats();
        }

        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : $this->emptyStats();
    }

    /**
     * Get recent crawl log entries.
     */
    public function getRecentLogs(int $limit = 100, int $days = 7): array
    {
        $entries = [];
        $date = new \DateTime('now', new \DateTimeZone('UTC'));

        for ($i = 0; $i < $days; $i++) {
            $file = $this->logDir . '/crawl-' . $date->format('Y-m-d') . '.json';

            if (file_exists($file)) {
                $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if ($lines) {
                    foreach (array_reverse($lines) as $line) {
                        $record = json_decode($line, true);
                        if ($record) {
                            $entries[] = $record;
                            if (count($entries) >= $limit) break 2;
                        }
                    }
                }
            }

            $date->modify('-1 day');
        }

        return $entries;
    }

    /**
     * Get daily crawl counts for charting.
     */
    public function getDailyStats(int $days = 30): array
    {
        $daily = [];
        $date = new \DateTime('now', new \DateTimeZone('UTC'));

        for ($i = 0; $i < $days; $i++) {
            $dateStr = $date->format('Y-m-d');
            $file = $this->logDir . '/crawl-' . $dateStr . '.json';
            $count = 0;

            if (file_exists($file)) {
                $count = count(file($file, FILE_SKIP_EMPTY_LINES));
            }

            $daily[] = [
                'date' => $dateStr,
                'count' => $count,
            ];

            $date->modify('-1 day');
        }

        return array_reverse($daily);
    }

    /**
     * Get top pages by crawl count.
     */
    public function getTopPages(int $limit = 20): array
    {
        $stats = $this->getStats();
        $pages = $stats['pages'] ?? [];

        uasort($pages, fn($a, $b) => ($b['count'] ?? 0) - ($a['count'] ?? 0));

        return array_slice($pages, 0, $limit, true);
    }

    /**
     * Get vendor breakdown.
     */
    public function getVendorBreakdown(): array
    {
        $stats = $this->getStats();
        $vendors = $stats['vendors'] ?? [];

        arsort($vendors);
        return $vendors;
    }

    /**
     * Reset all statistics and logs.
     */
    public function reset(): void
    {
        if (!is_dir($this->logDir)) return;

        $files = glob($this->logDir . '/*.json');
        if ($files) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
    }

    // ── Private helpers ─────────────────────────────────

    private function appendToLog(string $file, array $record): void
    {
        $line = json_encode($record, JSON_UNESCAPED_SLASHES) . "\n";
        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    private function updateStats(array $record): void
    {
        $file = $this->logDir . '/stats.json';
        $stats = file_exists($file) ? json_decode(file_get_contents($file), true) : null;
        if (!is_array($stats)) {
            $stats = $this->emptyStats();
        }

        // Total count
        $stats['total_crawls'] = ($stats['total_crawls'] ?? 0) + 1;
        $stats['last_crawl'] = $record['timestamp'];

        // Vendor counts
        $vendor = $record['agent_vendor'];
        $stats['vendors'][$vendor] = ($stats['vendors'][$vendor] ?? 0) + 1;

        // Agent counts
        $agent = $record['agent_name'];
        $stats['agents'][$agent] = ($stats['agents'][$agent] ?? 0) + 1;

        // Page counts
        $postId = (string)$record['post_id'];
        if ($postId && $postId !== '0') {
            if (!isset($stats['pages'][$postId])) {
                $stats['pages'][$postId] = [
                    'title' => $record['post_title'],
                    'url' => $record['url'],
                    'type' => $record['post_type'],
                    'count' => 0,
                    'last_crawl' => '',
                    'agents' => [],
                ];
            }
            $stats['pages'][$postId]['count']++;
            $stats['pages'][$postId]['last_crawl'] = $record['timestamp'];
            if (!in_array($agent, $stats['pages'][$postId]['agents'])) {
                $stats['pages'][$postId]['agents'][] = $agent;
            }
        }

        // Format counts
        $format = $record['format'];
        $stats['formats'][$format] = ($stats['formats'][$format] ?? 0) + 1;

        // Daily count
        $date = $record['date'];
        $stats['daily'][$date] = ($stats['daily'][$date] ?? 0) + 1;

        // Trim daily to last 90 days
        if (count($stats['daily']) > 90) {
            $stats['daily'] = array_slice($stats['daily'], -90, null, true);
        }

        file_put_contents($file, json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private function emptyStats(): array
    {
        return [
            'total_crawls' => 0,
            'last_crawl' => null,
            'vendors' => [],
            'agents' => [],
            'pages' => [],
            'formats' => [],
            'daily' => [],
        ];
    }

    private function ensureLogDir(): void
    {
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }
}
