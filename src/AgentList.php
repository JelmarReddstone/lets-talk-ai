<?php

namespace AgentReady;

/**
 * Manages the list of known AI agents / crawlers.
 *
 * Fetches from remote sources (Dark Visitors, ai-robots-txt), caches locally,
 * and falls back to the bundled known-agents.json.
 */
class AgentList
{
    private Config $config;
    private ?array $agents = null;

    /** Default remote source URLs */
    private const REMOTE_SOURCES = [
        'dark_visitors' => 'https://api.darkvisitors.com/robots-txts',
    ];

    /** Cache TTL in seconds (24 hours) */
    private const CACHE_TTL = 86400;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Get the full list of known agents.
     * Returns array of ['pattern' => ..., 'vendor' => ..., 'category' => ...].
     */
    public function getAgents(): array
    {
        if ($this->agents !== null) {
            return $this->agents;
        }

        // Try cached version first
        $cached = $this->loadCache();
        if ($cached !== null) {
            $this->agents = $cached;
            return $this->agents;
        }

        // Try remote fetch
        $remote = $this->fetchRemote();
        if ($remote !== null) {
            $this->agents = $remote;
            $this->saveCache($remote);
            return $this->agents;
        }

        // Fall back to bundled file
        $this->agents = $this->loadBundled();
        return $this->agents;
    }

    /**
     * Get a flat map of pattern => vendor for quick matching.
     */
    public function getPatternMap(): array
    {
        $map = [];
        foreach ($this->getAgents() as $agent) {
            $map[$agent['pattern']] = $agent['vendor'];
        }
        return $map;
    }

    /**
     * Match a user-agent string against the known list.
     * Returns the matched agent entry or null.
     */
    public function match(string $userAgent): ?array
    {
        foreach ($this->getAgents() as $agent) {
            if (stripos($userAgent, $agent['pattern']) !== false) {
                return $agent;
            }
        }
        return null;
    }

    /**
     * Force a refresh from the remote source.
     * Returns true if the list was updated.
     */
    public function refresh(): bool
    {
        $remote = $this->fetchRemote();
        if ($remote !== null) {
            $this->agents = $remote;
            $this->saveCache($remote);
            return true;
        }
        return false;
    }

    /**
     * Get the path to the cache file.
     */
    private function getCachePath(): string
    {
        $cacheDir = $this->config->get('agent_list.cache_dir', '');
        if (empty($cacheDir)) {
            $cacheDir = sys_get_temp_dir() . '/agentready';
        }
        return $cacheDir . '/known-agents-cache.json';
    }

    /**
     * Load agents from cache if it exists and is fresh.
     */
    private function loadCache(): ?array
    {
        $path = $this->getCachePath();

        if (!file_exists($path)) {
            return null;
        }

        $ttl = $this->config->get('agent_list.cache_ttl', self::CACHE_TTL);
        if ((time() - filemtime($path)) > $ttl) {
            return null;
        }

        $json = file_get_contents($path);
        $data = json_decode($json, true);

        if (!is_array($data) || empty($data)) {
            return null;
        }

        return $data;
    }

    /**
     * Save agents list to cache.
     */
    private function saveCache(array $agents): void
    {
        $path = $this->getCachePath();
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $json = json_encode($agents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($path, $json, LOCK_EX);
    }

    /**
     * Fetch from a remote source.
     * Tries Dark Visitors API, then falls back to GitHub raw file.
     */
    private function fetchRemote(): ?array
    {
        $customUrl = $this->config->get('agent_list.remote_url', '');

        // Try custom URL first if configured
        if (!empty($customUrl)) {
            $result = $this->fetchUrl($customUrl);
            if ($result !== null) {
                return $this->normalizeRemoteData($result, 'custom');
            }
        }

        // Try Dark Visitors API — requires API key
        $apiKey = $this->config->get('agent_list.dark_visitors_api_key', '');
        if (!empty($apiKey)) {
            $result = $this->fetchDarkVisitors($apiKey);
            if ($result !== null) {
                return $result;
            }
        }

        // Try the ai-robots-txt community list on GitHub
        $result = $this->fetchUrl(
            'https://raw.githubusercontent.com/ai-robots-txt/ai.robots.txt/main/robots.json'
        );
        if ($result !== null) {
            return $this->normalizeRemoteData($result, 'ai-robots-txt');
        }

        return null;
    }

    /**
     * Fetch and parse the Dark Visitors API.
     */
    private function fetchDarkVisitors(string $apiKey): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json',
                ]),
                'content' => json_encode([
                    'agent_types' => [
                        'AI Assistant',
                        'AI Data Collector',
                        'AI Search Crawler',
                        'Undocumented AI Agent',
                    ],
                    'disallow' => '/',
                ]),
                'timeout' => 5,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
            ],
        ]);

        $response = @file_get_contents(self::REMOTE_SOURCES['dark_visitors'], false, $context);
        if ($response === false) {
            return null;
        }

        // The API returns a robots.txt-style response — parse the user-agent lines
        $agents = [];
        $lines = explode("\n", $response);
        $currentAgent = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if (stripos($line, 'User-agent:') === 0) {
                $currentAgent = trim(substr($line, 11));
                if ($currentAgent !== '*' && !empty($currentAgent)) {
                    $agents[] = [
                        'pattern' => $currentAgent,
                        'vendor' => $this->guessVendor($currentAgent),
                        'category' => 'ai_crawler',
                        'url' => '',
                        'source' => 'dark_visitors',
                    ];
                }
            }
        }

        return !empty($agents) ? $agents : null;
    }

    /**
     * Fetch a URL and return decoded JSON.
     */
    private function fetchUrl(string $url): ?array
    {
        // Only allow HTTPS URLs
        if (strpos($url, 'https://') !== 0) {
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
                'header' => 'User-Agent: AgentReady/1.0',
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return null;
        }

        return $data;
    }

    /**
     * Normalize data from various remote formats into our standard format.
     */
    private function normalizeRemoteData(array $data, string $source): ?array
    {
        $agents = [];

        // Handle ai-robots-txt format (array of objects with "pattern" key)
        if (isset($data[0]['pattern'])) {
            foreach ($data as $entry) {
                if (empty($entry['pattern'])) continue;

                // Only include AI-related bots
                $instances = $entry['instances'] ?? [];
                $isAi = false;

                // Check if any URL/description hints at AI
                foreach ($instances as $instance) {
                    if (isset($instance['url']) && (
                        stripos($instance['url'], 'ai') !== false ||
                        stripos($instance['url'], 'gpt') !== false ||
                        stripos($instance['url'], 'llm') !== false ||
                        stripos($instance['url'], 'bot') !== false
                    )) {
                        $isAi = true;
                        break;
                    }
                }

                $agents[] = [
                    'pattern' => $entry['pattern'],
                    'vendor' => $this->guessVendor($entry['pattern']),
                    'category' => $isAi ? 'ai_crawler' : 'crawler',
                    'url' => $entry['url'] ?? '',
                    'source' => $source,
                ];
            }
        }

        // Handle our own format (agents array)
        if (isset($data['agents'])) {
            foreach ($data['agents'] as $entry) {
                $agents[] = [
                    'pattern' => $entry['pattern'],
                    'vendor' => $entry['vendor'] ?? $this->guessVendor($entry['pattern']),
                    'category' => $entry['category'] ?? 'ai_crawler',
                    'url' => $entry['url'] ?? '',
                    'source' => $source,
                ];
            }
        }

        return !empty($agents) ? $agents : null;
    }

    /**
     * Load the bundled known-agents.json fallback.
     */
    private function loadBundled(): array
    {
        $path = dirname(__DIR__) . '/data/known-agents.json';

        if (!file_exists($path)) {
            // Absolute last resort: return hardcoded minimum list
            return $this->getHardcodedMinimum();
        }

        $json = file_get_contents($path);
        $data = json_decode($json, true);

        if (!is_array($data) || !isset($data['agents'])) {
            return $this->getHardcodedMinimum();
        }

        return $data['agents'];
    }

    /**
     * Hardcoded minimum list as ultimate fallback.
     */
    private function getHardcodedMinimum(): array
    {
        return [
            ['pattern' => 'GPTBot', 'vendor' => 'openai', 'category' => 'ai_crawler'],
            ['pattern' => 'ChatGPT-User', 'vendor' => 'openai', 'category' => 'ai_assistant'],
            ['pattern' => 'ClaudeBot', 'vendor' => 'anthropic', 'category' => 'ai_crawler'],
            ['pattern' => 'Claude-Web', 'vendor' => 'anthropic', 'category' => 'ai_assistant'],
            ['pattern' => 'Google-Extended', 'vendor' => 'google', 'category' => 'ai_crawler'],
            ['pattern' => 'PerplexityBot', 'vendor' => 'perplexity', 'category' => 'ai_search'],
            ['pattern' => 'Amazonbot', 'vendor' => 'amazon', 'category' => 'ai_crawler'],
            ['pattern' => 'Meta-ExternalAgent', 'vendor' => 'meta', 'category' => 'ai_crawler'],
            ['pattern' => 'Bytespider', 'vendor' => 'bytedance', 'category' => 'ai_crawler'],
            ['pattern' => 'Applebot-Extended', 'vendor' => 'apple', 'category' => 'ai_crawler'],
            ['pattern' => 'cohere-ai', 'vendor' => 'cohere', 'category' => 'ai_crawler'],
        ];
    }

    /**
     * Best-effort vendor guess from a user-agent pattern name.
     */
    private function guessVendor(string $pattern): string
    {
        $map = [
            'gpt' => 'openai', 'oai' => 'openai', 'chatgpt' => 'openai',
            'claude' => 'anthropic', 'anthropic' => 'anthropic',
            'google' => 'google', 'bard' => 'google', 'gemini' => 'google',
            'bing' => 'microsoft', 'microsoft' => 'microsoft',
            'meta' => 'meta', 'facebook' => 'meta',
            'amazon' => 'amazon',
            'apple' => 'apple',
            'perplexity' => 'perplexity',
            'bytespider' => 'bytedance', 'bytedance' => 'bytedance',
            'cohere' => 'cohere',
            'diffbot' => 'diffbot',
            'you' => 'you.com',
            'petal' => 'huawei',
            'semrush' => 'semrush',
            'ahrefs' => 'ahrefs',
            'phind' => 'phind',
            'jina' => 'jina',
            'firecrawl' => 'firecrawl',
        ];

        $lower = strtolower($pattern);
        foreach ($map as $keyword => $vendor) {
            if (strpos($lower, $keyword) !== false) {
                return $vendor;
            }
        }

        return 'unknown';
    }
}
