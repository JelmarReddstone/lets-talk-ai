<?php

namespace AgentReady;

/**
 * Configuration loader for AgentReady.
 * Loads from JSON config file and provides typed access.
 */
class Config
{
    private array $data;

    public function __construct(array $data = [])
    {
        $this->data = array_replace_recursive($this->defaults(), $data);
    }

    public static function fromFile(string $path): self
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("AgentReady config file not found: {$path}");
        }

        $json = file_get_contents($path);
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON in AgentReady config: " . json_last_error_msg());
        }

        return new self($data);
    }

    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function get(string $key, $default = null)
    {
        $keys = explode('.', $key);
        $value = $this->data;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    public function set(string $key, $value): void
    {
        $keys = explode('.', $key);
        $ref = &$this->data;

        foreach ($keys as $k) {
            if (!isset($ref[$k]) || !is_array($ref[$k])) {
                $ref[$k] = [];
            }
            $ref = &$ref[$k];
        }

        $ref = $value;
    }

    public function all(): array
    {
        return $this->data;
    }

    private function defaults(): array
    {
        return [
            'site' => [
                'name' => '',
                'url' => '',
                'description' => '',
                'language' => 'en',
                'contact_email' => '',
                'contact_phone' => '',
            ],
            'content_types' => [],
            'llms_txt' => [
                'enabled' => true,
                'sections' => [
                    'about' => '',
                    'topics' => [],
                    'capabilities' => [],
                ],
            ],
            'structured_data' => [
                'enabled' => true,
                'organization' => [
                    'name' => '',
                    'logo' => '',
                    'url' => '',
                ],
            ],
            'agent_endpoint' => [
                'enabled' => true,
                'param' => 'agentready',
                'accept_headers' => ['text/markdown', 'application/agent+json'],
            ],
            'content_feed' => [
                'enabled' => true,
                'path' => '/agentready-feed.json',
                'max_items' => 1000,
            ],
            'content_selectors' => [
                'title' => 'h1, .page-title, .entry-title',
                'main_content' => 'main, article, .content, .entry-content, #content',
                'exclude' => 'nav, footer, header, .sidebar, .menu, .cookie-notice, script, style',
                'metadata' => '',
            ],
            'custom_properties' => [],
        ];
    }
}
