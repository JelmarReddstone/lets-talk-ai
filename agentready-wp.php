<?php
/**
 * Plugin Name: AgentReady
 * Plugin URI:  https://github.com/reddstone/agentready
 * Description: Make your WordPress site AI-agent friendly. Serves clean markdown, JSON-LD, and llms.txt to AI crawlers so your content gets recommended by AI assistants.
 * Version:     2.0.0
 * Author:      Reddstone
 * License:     MIT
 * Text Domain: agentready
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AGENTREADY_VERSION', '2.0.0');
define('AGENTREADY_DIR', plugin_dir_path(__FILE__));
define('AGENTREADY_URL', plugin_dir_url(__FILE__));

require_once AGENTREADY_DIR . 'autoload.php';

use AgentReady\Config;
use AgentReady\AgentDetector;
use AgentReady\AgentList;
use AgentReady\AgentLogger;
use AgentReady\ContentExtractor;
use AgentReady\StructuredData;
use AgentReady\LlmsTxt;

class AgentReady_Plugin
{
    private Config $config;
    private AgentDetector $detector;
    private AgentLogger $logger;
    private ?AgentList $agentList = null;

    const META_KEY = '_agentready_override';
    const META_ENABLED = '_agentready_enabled';

    public function __construct()
    {
        $this->config = $this->loadConfig();
        $this->detector = new AgentDetector($this->config);
        $this->logger = new AgentLogger($this->config, $this->getLogDir());
    }

    public function init(): void
    {
        // ── Core ────────────────────────────────────────
        add_action('template_redirect', [$this, 'handleAgentRequest'], 1);
        add_action('wp_head', [$this, 'injectStructuredData'], 5);
        add_action('init', [$this, 'registerRewriteRules']);
        add_action('parse_request', [$this, 'handleCustomEndpoints']);
        add_action('rest_api_init', [$this, 'registerRestRoutes']);

        // ── Admin ───────────────────────────────────────
        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('add_meta_boxes', [$this, 'addMetaBox']);
        add_action('save_post', [$this, 'saveMetaBox']);

        // ── AJAX ────────────────────────────────────────
        add_action('wp_ajax_agentready_preview', [$this, 'ajaxPreview']);
        add_action('wp_ajax_agentready_reset_page', [$this, 'ajaxResetPage']);
        add_action('wp_ajax_agentready_toggle_page', [$this, 'ajaxTogglePage']);
        add_action('wp_ajax_agentready_stats', [$this, 'ajaxStats']);
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    //  AGENT REQUEST HANDLING + LOGGING
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function handleAgentRequest(): void
    {
        if (!$this->detector->isAgent() && !$this->detector->isAgentRequest()) {
            return;
        }

        if (is_singular()) {
            global $post;
            $enabled = get_post_meta($post->ID, self::META_ENABLED, true);
            if ($enabled === '0') {
                return;
            }
        }

        ob_start();

        add_action('shutdown', function () {
            $html = ob_get_clean();

            if (empty($html)) {
                return;
            }

            $extractor = new ContentExtractor($this->config);
            $pageData = $this->getAutoDetectedPageData();
            $extracted = $extractor->extract($html, $pageData);
            $format = $this->detector->getRequestedFormat();

            if (is_singular()) {
                global $post;
                $extracted = $this->applyPageOverrides($post->ID, $extracted);
            }

            if ($format === 'json') {
                $output = json_encode($extractor->toJson($extracted, [
                    'url' => $this->getCurrentUrl(),
                    'generator' => 'AgentReady/' . AGENTREADY_VERSION . ' WordPress',
                ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                header('Content-Type: application/json; charset=utf-8');
            } else {
                $output = $extractor->toMarkdown($extracted, [
                    'url' => $this->getCurrentUrl(),
                    'generator' => 'AgentReady/' . AGENTREADY_VERSION,
                ]);
                header('Content-Type: text/markdown; charset=utf-8');
            }

            header('X-AgentReady: ' . AGENTREADY_VERSION);
            $this->logCrawl($format, strlen($output));

            echo $output;
            exit;
        }, 0);
    }

    private function logCrawl(string $format, int $responseSize): void
    {
        $agent = $this->detector->identify();
        $postId = 0;
        $postTitle = '';
        $postType = '';

        if (is_singular()) {
            global $post;
            $postId = $post->ID;
            $postTitle = $post->post_title;
            $postType = $post->post_type;
        }

        $this->logger->log([
            'agent_name' => $agent['name'] ?? 'unknown',
            'agent_vendor' => $agent['vendor'] ?? 'unknown',
            'url' => $this->getCurrentUrl(),
            'post_id' => $postId,
            'post_title' => $postTitle,
            'post_type' => $postType,
            'format' => $format,
            'response_size' => $responseSize,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ]);
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    //  AUTO-DETECTION OF CONTENT & METADATA
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    private function getAutoDetectedPageData(): array
    {
        $data = [];

        if (is_singular()) {
            global $post;
            $data['title'] = get_the_title($post);
            $data['description'] = get_the_excerpt($post) ?: wp_trim_words(strip_tags($post->post_content), 30);
            $data['metadata'] = $this->autoDetectMetadata($post);
        } elseif (is_archive()) {
            $data['title'] = wp_strip_all_tags(get_the_archive_title());
            $data['description'] = get_the_archive_description();
        } elseif (is_home()) {
            $data['title'] = get_bloginfo('name');
            $data['description'] = get_bloginfo('description');
        } elseif (is_search()) {
            $data['title'] = 'Search: ' . get_search_query();
            $data['description'] = 'Search results for "' . get_search_query() . '"';
        }

        return $data;
    }

    private function autoDetectMetadata(\WP_Post $post): array
    {
        $meta = [];

        $meta['post_type'] = $post->post_type;
        $meta['published'] = get_the_date('c', $post);
        $meta['modified'] = get_the_modified_date('c', $post);
        $meta['author'] = get_the_author_meta('display_name', $post->post_author);

        $thumb = get_the_post_thumbnail_url($post, 'large');
        if ($thumb) {
            $meta['featured_image'] = $thumb;
        }

        $cats = get_the_category($post->ID);
        if (!empty($cats)) {
            $meta['categories'] = implode(', ', array_map(fn($c) => $c->name, $cats));
        }
        $tags = get_the_tags($post->ID);
        if (!empty($tags)) {
            $meta['tags'] = implode(', ', array_map(fn($t) => $t->name, $tags));
        }

        // Yoast SEO
        $yoast_title = get_post_meta($post->ID, '_yoast_wpseo_title', true);
        $yoast_desc = get_post_meta($post->ID, '_yoast_wpseo_metadesc', true);
        if ($yoast_title) $meta['seo_title'] = $yoast_title;
        if ($yoast_desc) $meta['seo_description'] = $yoast_desc;

        // RankMath
        $rm_title = get_post_meta($post->ID, 'rank_math_title', true);
        $rm_desc = get_post_meta($post->ID, 'rank_math_description', true);
        if ($rm_title) $meta['seo_title'] = $rm_title;
        if ($rm_desc) $meta['seo_description'] = $rm_desc;

        // ACF fields
        if (function_exists('get_fields')) {
            $acf = get_fields($post->ID);
            if (is_array($acf)) {
                foreach ($acf as $key => $value) {
                    if (is_scalar($value) && !empty($value) && !str_starts_with($key, '_')) {
                        $meta['acf_' . $key] = $value;
                    }
                }
            }
        }

        // Public custom post meta
        $allMeta = get_post_meta($post->ID);
        $skipPrefixes = ['_', 'agentready', 'rank_math', 'yoast', 'wpseo'];
        if (is_array($allMeta)) {
            foreach ($allMeta as $key => $values) {
                $skip = false;
                foreach ($skipPrefixes as $prefix) {
                    if (str_starts_with($key, $prefix)) { $skip = true; break; }
                }
                if ($skip) continue;

                $val = $values[0] ?? '';
                if (is_string($val) && !empty($val) && strlen($val) < 500) {
                    $meta[$key] = $val;
                }
            }
        }

        // WooCommerce
        if ($post->post_type === 'product' && function_exists('wc_get_product')) {
            $product = wc_get_product($post->ID);
            if ($product) {
                $meta['price'] = $product->get_price();
                $meta['currency'] = get_woocommerce_currency();
                $meta['sku'] = $product->get_sku();
                $meta['stock_status'] = $product->get_stock_status();
                $meta['weight'] = $product->get_weight();
            }
        }

        return array_filter($meta, fn($v) => $v !== '' && $v !== null);
    }

    private function applyPageOverrides(int $postId, array $extracted): array
    {
        $override = get_post_meta($postId, self::META_KEY, true);
        if (empty($override)) {
            return $extracted;
        }

        $data = json_decode($override, true);
        if (!is_array($data)) {
            return $extracted;
        }

        if (!empty($data['title'])) {
            $extracted['title'] = $data['title'];
        }
        if (!empty($data['description'])) {
            $extracted['description'] = $data['description'];
        }
        if (!empty($data['metadata']) && is_array($data['metadata'])) {
            $extracted['metadata'] = array_merge($extracted['metadata'] ?? [], $data['metadata']);
        }
        if (!empty($data['content'])) {
            $extracted['content'] = $data['content'];
        }

        return $extracted;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    //  STRUCTURED DATA INJECTION
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function injectStructuredData(): void
    {
        if (!$this->config->get('structured_data.enabled', true)) {
            return;
        }

        if (is_singular()) {
            global $post;
            $enabled = get_post_meta($post->ID, self::META_ENABLED, true);
            if ($enabled === '0') return;
        }

        $sd = new StructuredData($this->config);
        $schemas = [];
        $schemas[] = $sd->buildOrganization();

        if (is_singular()) {
            global $post;
            $meta = $this->autoDetectMetadata($post);
            $extracted = [
                'title' => get_the_title($post),
                'description' => get_the_excerpt($post) ?: wp_trim_words(strip_tags($post->post_content), 30),
                'images' => [],
            ];

            if (!empty($meta['featured_image'])) {
                $extracted['images'][] = ['src' => $meta['featured_image'], 'alt' => get_the_title($post)];
            }

            $schemas[] = $sd->buildWebPage($extracted, [
                'url' => get_permalink($post),
                'datePublished' => get_the_date('c', $post),
                'dateModified' => get_the_modified_date('c', $post),
            ]);

            $breadcrumbs = [['name' => 'Home', 'url' => home_url('/')]];
            $cats = get_the_category($post->ID);
            if (!empty($cats)) {
                $breadcrumbs[] = ['name' => $cats[0]->name, 'url' => get_category_link($cats[0]->term_id)];
            }
            $breadcrumbs[] = ['name' => get_the_title($post), 'url' => get_permalink($post)];
            $schemas[] = $sd->buildBreadcrumbs($breadcrumbs);
        }

        echo $sd->toHtml($sd->merge(...$schemas)) . "\n";
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    //  REWRITE RULES & ENDPOINTS
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function registerRewriteRules(): void
    {
        add_rewrite_rule('^llms\.txt$', 'index.php?agentready_endpoint=llms', 'top');
        add_rewrite_rule('^llms-full\.txt$', 'index.php?agentready_endpoint=llms-full', 'top');

        $feedPath = ltrim($this->config->get('content_feed.path', '/agentready-feed.json'), '/');
        add_rewrite_rule('^' . preg_quote($feedPath, '/') . '$', 'index.php?agentready_endpoint=feed', 'top');

        add_filter('query_vars', function ($vars) {
            $vars[] = 'agentready_endpoint';
            return $vars;
        });
    }

    public function handleCustomEndpoints(\WP $wp): void
    {
        if (empty($wp->query_vars['agentready_endpoint'])) {
            return;
        }

        $endpoint = $wp->query_vars['agentready_endpoint'];
        $llms = new LlmsTxt($this->config);
        $pages = $this->getSitePages();

        switch ($endpoint) {
            case 'llms':
                $llms->serve($llms->generate($pages));
                exit;
            case 'llms-full':
                $llms->serve($llms->generateFull($pages));
                exit;
            case 'feed':
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: public, max-age=3600');
                echo json_encode($this->buildContentFeed(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                exit;
        }
    }

    public function registerRestRoutes(): void
    {
        register_rest_route('agentready/v1', '/feed', [
            'methods' => 'GET',
            'callback' => fn() => new \WP_REST_Response($this->buildContentFeed(), 200),
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('agentready/v1', '/page', [
            'methods' => 'GET',
            'callback' => function (\WP_REST_Request $request) {
                $postId = (int)$request->get_param('id');
                $url = $request->get_param('url');

                if ($postId) {
                    $post = get_post($postId);
                } elseif ($url) {
                    $postId = url_to_postid($url);
                    $post = $postId ? get_post($postId) : null;
                } else {
                    return new \WP_REST_Response(['error' => 'Provide id or url parameter'], 400);
                }

                if (!$post || $post->post_status !== 'publish') {
                    return new \WP_REST_Response(['error' => 'Not found'], 404);
                }

                $extractor = new ContentExtractor($this->config);
                $html = apply_filters('the_content', $post->post_content);
                $extracted = $extractor->extract($html, [
                    'title' => $post->post_title,
                    'description' => get_the_excerpt($post),
                    'metadata' => $this->autoDetectMetadata($post),
                ]);
                $extracted = $this->applyPageOverrides($post->ID, $extracted);

                return new \WP_REST_Response($extractor->toJson($extracted, [
                    'url' => get_permalink($post),
                    'datePublished' => get_the_date('c', $post),
                    'dateModified' => get_the_modified_date('c', $post),
                ]), 200);
            },
            'permission_callback' => '__return_true',
            'args' => [
                'id' => ['type' => 'integer', 'sanitize_callback' => 'absint'],
                'url' => ['type' => 'string', 'sanitize_callback' => 'esc_url_raw'],
            ],
        ]);
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    //  PER-PAGE META BOX
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function addMetaBox(): void
    {
        $postTypes = get_post_types(['public' => true]);
        foreach ($postTypes as $type) {
            add_meta_box(
                'agentready_meta',
                '🤖 AgentReady — AI Agent Content',
                [$this, 'renderMetaBox'],
                $type,
                'normal',
                'high'
            );
        }
    }

    public function renderMetaBox(\WP_Post $post): void
    {
        wp_nonce_field('agentready_meta', 'agentready_meta_nonce');

        $enabled = get_post_meta($post->ID, self::META_ENABLED, true);
        $override = get_post_meta($post->ID, self::META_KEY, true);
        $overrideData = $override ? json_decode($override, true) : [];
        $isOverridden = !empty($override);
        $autoMeta = $this->autoDetectMetadata($post);
        ?>
        <div id="agentready-metabox">
            <style>
                #agentready-metabox { font-size: 13px; }
                .ar-row { display: flex; gap: 16px; margin-bottom: 12px; }
                .ar-col { flex: 1; }
                .ar-label { font-weight: 600; margin-bottom: 4px; display: block; color: #1d2327; }
                .ar-input { width: 100%; padding: 6px 8px; border: 1px solid #8c8f94; border-radius: 4px; }
                .ar-textarea { width: 100%; min-height: 80px; padding: 6px 8px; border: 1px solid #8c8f94; border-radius: 4px; font-family: monospace; font-size: 12px; }
                .ar-status { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 100px; font-size: 12px; font-weight: 500; }
                .ar-status-auto { background: #e6f7ee; color: #0a6629; }
                .ar-status-manual { background: #fef3e2; color: #9a6700; }
                .ar-status-disabled { background: #fee; color: #a00; }
                .ar-meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 12px; padding: 8px; background: #f6f7f7; border-radius: 4px; font-size: 12px; max-height: 200px; overflow-y: auto; }
                .ar-meta-key { color: #666; }
                .ar-meta-val { color: #1d2327; word-break: break-all; }
                .ar-toggle { margin-bottom: 16px; padding: 12px; background: #f0f6fc; border: 1px solid #c3d5e6; border-radius: 4px; }
                .ar-section { border: 1px solid #ddd; border-radius: 4px; padding: 16px; margin-bottom: 12px; }
                .ar-section h4 { margin: 0 0 8px; font-size: 13px; }
                .ar-btn-reset { color: #b32d2e; border-color: #b32d2e; }
                .ar-btn-reset:hover { background: #b32d2e; color: #fff; }
            </style>

            <div class="ar-toggle">
                <label>
                    <input type="checkbox" name="agentready_enabled" value="1"
                        <?php checked($enabled !== '0'); ?>>
                    <strong>Enable AgentReady for this page</strong>
                </label>
                <span class="ar-status <?php echo $enabled === '0' ? 'ar-status-disabled' : ($isOverridden ? 'ar-status-manual' : 'ar-status-auto'); ?>">
                    <?php echo $enabled === '0' ? '⛔ Disabled' : ($isOverridden ? '✏️ Manual Override' : '✅ Auto-detected'); ?>
                </span>
            </div>

            <div class="ar-section">
                <h4>Auto-Detected Metadata <small>(from WP, ACF, Yoast, RankMath, WooCommerce)</small></h4>
                <div class="ar-meta-grid">
                    <?php foreach ($autoMeta as $key => $value): ?>
                        <div class="ar-meta-key"><?php echo esc_html($key); ?></div>
                        <div class="ar-meta-val"><?php echo esc_html(is_string($value) ? mb_substr($value, 0, 120) : $value); ?></div>
                    <?php endforeach; ?>
                    <?php if (empty($autoMeta)): ?>
                        <div class="ar-meta-key" style="grid-column: span 2; color: #999;">No metadata detected</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="ar-section">
                <h4>Override AI Content <small>(leave empty to use auto-detected values)</small></h4>

                <div class="ar-row">
                    <div class="ar-col">
                        <label class="ar-label">Title</label>
                        <input type="text" name="agentready_title" class="ar-input"
                            value="<?php echo esc_attr($overrideData['title'] ?? ''); ?>"
                            placeholder="<?php echo esc_attr(get_the_title($post)); ?>">
                    </div>
                </div>

                <div class="ar-row">
                    <div class="ar-col">
                        <label class="ar-label">Description</label>
                        <textarea name="agentready_description" class="ar-input" rows="2"
                            placeholder="<?php echo esc_attr(wp_trim_words(strip_tags($post->post_content), 30)); ?>"
                        ><?php echo esc_textarea($overrideData['description'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="ar-row">
                    <div class="ar-col">
                        <label class="ar-label">Custom Content (Markdown)</label>
                        <textarea name="agentready_content" class="ar-textarea"
                            placeholder="Leave empty to auto-extract from page HTML"
                        ><?php echo esc_textarea($overrideData['content'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="ar-row">
                    <div class="ar-col">
                        <label class="ar-label">Extra Metadata (JSON)</label>
                        <textarea name="agentready_extra_meta" class="ar-textarea"
                            placeholder='{"price": "€475,000", "bedrooms": 3}'
                        ><?php echo esc_textarea(!empty($overrideData['metadata']) ? json_encode($overrideData['metadata'], JSON_PRETTY_PRINT) : ''); ?></textarea>
                    </div>
                </div>

                <?php if ($isOverridden): ?>
                    <button type="button" class="button ar-btn-reset" onclick="agentreadyReset(<?php echo $post->ID; ?>)">
                        Reset to Auto-Detect
                    </button>
                <?php endif; ?>
            </div>

            <p>
                <a href="<?php echo esc_url(get_permalink($post) . '?agentready=1'); ?>" target="_blank" class="button">
                    Preview as Markdown
                </a>
                <a href="<?php echo esc_url(get_permalink($post) . '?agentready=json'); ?>" target="_blank" class="button">
                    Preview as JSON
                </a>
            </p>
        </div>

        <script>
        function agentreadyReset(postId) {
            if (!confirm('Reset all manual overrides for this page? It will go back to auto-detection.')) return;
            jQuery.post(ajaxurl, {
                action: 'agentready_reset_page',
                post_id: postId,
                _wpnonce: '<?php echo wp_create_nonce('agentready_reset'); ?>'
            }, function() { location.reload(); });
        }
        </script>
        <?php
    }

    public function saveMetaBox(int $postId): void
    {
        if (!isset($_POST['agentready_meta_nonce']) ||
            !wp_verify_nonce($_POST['agentready_meta_nonce'], 'agentready_meta')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $postId)) return;

        $enabled = isset($_POST['agentready_enabled']) ? '1' : '0';
        update_post_meta($postId, self::META_ENABLED, $enabled);

        $override = [];

        $title = sanitize_text_field($_POST['agentready_title'] ?? '');
        if (!empty($title)) $override['title'] = $title;

        $desc = sanitize_textarea_field($_POST['agentready_description'] ?? '');
        if (!empty($desc)) $override['description'] = $desc;

        $content = sanitize_textarea_field($_POST['agentready_content'] ?? '');
        if (!empty($content)) $override['content'] = $content;

        $extraMeta = $_POST['agentready_extra_meta'] ?? '';
        if (!empty($extraMeta)) {
            $decoded = json_decode($extraMeta, true);
            if (is_array($decoded)) {
                $override['metadata'] = array_map('sanitize_text_field', $decoded);
            }
        }

        if (!empty($override)) {
            update_post_meta($postId, self::META_KEY, wp_json_encode($override));
        } else {
            delete_post_meta($postId, self::META_KEY);
        }
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    //  ADMIN PAGES
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function addAdminMenu(): void
    {
        add_menu_page(
            'AgentReady',
            'AgentReady',
            'manage_options',
            'agentready',
            [$this, 'renderDashboard'],
            'dashicons-superhero-alt',
            30
        );

        add_submenu_page('agentready', 'Dashboard', 'Dashboard', 'manage_options', 'agentready', [$this, 'renderDashboard']);
        add_submenu_page('agentready', 'Pages', 'Pages', 'manage_options', 'agentready-pages', [$this, 'renderPagesAdmin']);
        add_submenu_page('agentready', 'Analytics', 'Analytics', 'manage_options', 'agentready-analytics', [$this, 'renderAnalytics']);
        add_submenu_page('agentready', 'Settings', 'Settings', 'manage_options', 'agentready-settings', [$this, 'renderSettingsPage']);
    }

    public function registerSettings(): void
    {
        register_setting('agentready_settings', 'agentready_config', [
            'type' => 'string',
            'sanitize_callback' => function ($input) {
                $decoded = json_decode($input, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    add_settings_error('agentready_config', 'invalid_json', 'Invalid JSON configuration.');
                    return get_option('agentready_config', '{}');
                }
                return $input;
            },
        ]);
    }

    // ── Dashboard ───────────────────────────────────────

    public function renderDashboard(): void
    {
        $stats = $this->logger->getStats();
        $recentLogs = $this->logger->getRecentLogs(10);
        $dailyStats = $this->logger->getDailyStats(14);
        $vendorBreakdown = $this->logger->getVendorBreakdown();
        $topPages = $this->logger->getTopPages(5);
        $agentList = new AgentList($this->config);
        ?>
        <div class="wrap">
            <h1>🤖 AgentReady Dashboard</h1>

            <style>
                .ar-dash { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin: 20px 0; }
                .ar-stat-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 8px; padding: 20px; text-align: center; }
                .ar-stat-num { font-size: 36px; font-weight: 700; color: #1d2327; line-height: 1.2; }
                .ar-stat-label { font-size: 13px; color: #646970; margin-top: 4px; }
                .ar-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 20px; }
                .ar-panel { background: #fff; border: 1px solid #c3c4c7; border-radius: 8px; padding: 20px; }
                .ar-panel h3 { margin-top: 0; font-size: 14px; color: #1d2327; }
                .ar-chart { display: flex; align-items: flex-end; gap: 3px; height: 100px; padding: 8px 0; }
                .ar-bar { flex: 1; background: #2271b1; border-radius: 2px 2px 0 0; min-height: 2px; position: relative; transition: background 0.2s; }
                .ar-bar:hover { background: #135e96; }
                .ar-bar-tip { display: none; position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%); background: #1d2327; color: #fff; font-size: 11px; padding: 2px 6px; border-radius: 3px; white-space: nowrap; }
                .ar-bar:hover .ar-bar-tip { display: block; }
                .ar-vendor-list { list-style: none; padding: 0; margin: 0; }
                .ar-vendor-list li { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-bottom: 1px solid #f0f0f1; font-size: 13px; }
                .ar-vendor-bar { height: 6px; background: #2271b1; border-radius: 3px; margin-top: 2px; }
                .ar-log-table { width: 100%; border-collapse: collapse; font-size: 12px; }
                .ar-log-table th, .ar-log-table td { padding: 6px 8px; text-align: left; border-bottom: 1px solid #f0f0f1; }
                .ar-log-table th { font-weight: 600; color: #646970; }
                .ar-badge { display: inline-block; padding: 1px 8px; border-radius: 100px; font-size: 11px; font-weight: 500; }
                .ar-badge-blue { background: #e6f0f7; color: #2271b1; }
                .ar-badge-green { background: #e6f7ee; color: #0a6629; }
                .ar-badge-orange { background: #fef3e2; color: #9a6700; }
            </style>

            <div class="ar-dash">
                <div class="ar-stat-card">
                    <div class="ar-stat-num"><?php echo number_format($stats['total_crawls'] ?? 0); ?></div>
                    <div class="ar-stat-label">Total AI Crawls</div>
                </div>
                <div class="ar-stat-card">
                    <div class="ar-stat-num"><?php echo count($stats['agents'] ?? []); ?></div>
                    <div class="ar-stat-label">Unique Agents Seen</div>
                </div>
                <div class="ar-stat-card">
                    <div class="ar-stat-num"><?php echo count($stats['pages'] ?? []); ?></div>
                    <div class="ar-stat-label">Pages Crawled</div>
                </div>
                <div class="ar-stat-card">
                    <div class="ar-stat-num"><?php echo count($stats['vendors'] ?? []); ?></div>
                    <div class="ar-stat-label">AI Vendors</div>
                </div>
            </div>

            <div class="ar-grid">
                <div class="ar-panel">
                    <h3>📈 Crawls — Last 14 Days</h3>
                    <?php $maxDaily = max(array_column($dailyStats, 'count') ?: [1]); ?>
                    <div class="ar-chart">
                        <?php foreach ($dailyStats as $day): ?>
                            <?php $h = $maxDaily > 0 ? ($day['count'] / $maxDaily) * 100 : 0; ?>
                            <div class="ar-bar" style="height: <?php echo max($h, 2); ?>%">
                                <span class="ar-bar-tip"><?php echo esc_html($day['date'] . ': ' . $day['count']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="ar-panel">
                    <h3>🏢 Crawls by AI Vendor</h3>
                    <?php $maxVendor = max($vendorBreakdown ?: [1]); ?>
                    <ul class="ar-vendor-list">
                        <?php foreach (array_slice($vendorBreakdown, 0, 8, true) as $vendor => $count): ?>
                            <li>
                                <span><?php echo esc_html(ucfirst($vendor)); ?></span>
                                <span><strong><?php echo $count; ?></strong></span>
                            </li>
                            <li style="border: none; padding-top: 0;">
                                <div class="ar-vendor-bar" style="width: <?php echo ($count / $maxVendor) * 100; ?>%"></div>
                            </li>
                        <?php endforeach; ?>
                        <?php if (empty($vendorBreakdown)): ?>
                            <li style="color: #999;">No crawl data yet. AI bots will appear here.</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <div class="ar-grid" style="margin-top: 16px;">
                <div class="ar-panel">
                    <h3>🏆 Most Crawled Pages</h3>
                    <table class="ar-log-table">
                        <thead><tr><th>Page</th><th>Crawls</th><th>Agents</th></tr></thead>
                        <tbody>
                        <?php foreach ($topPages as $p): ?>
                            <tr>
                                <td><a href="<?php echo esc_url($p['url'] ?? '#'); ?>"><?php echo esc_html($p['title']); ?></a></td>
                                <td><strong><?php echo $p['count']; ?></strong></td>
                                <td><?php echo esc_html(implode(', ', array_slice($p['agents'] ?? [], 0, 3))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($topPages)): ?>
                            <tr><td colspan="3" style="color: #999;">No pages crawled yet</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="ar-panel">
                    <h3>🕐 Recent Crawls</h3>
                    <table class="ar-log-table">
                        <thead><tr><th>Time</th><th>Agent</th><th>Page</th><th>Format</th></tr></thead>
                        <tbody>
                        <?php foreach ($recentLogs as $log): ?>
                            <tr>
                                <td><?php echo esc_html(date('M j H:i', strtotime($log['timestamp']))); ?></td>
                                <td><span class="ar-badge ar-badge-blue"><?php echo esc_html($log['agent_name']); ?></span></td>
                                <td><?php echo esc_html(mb_substr($log['post_title'] ?: $log['url'], 0, 40)); ?></td>
                                <td><span class="ar-badge ar-badge-green"><?php echo esc_html($log['format']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recentLogs)): ?>
                            <tr><td colspan="4" style="color: #999;">No crawls logged yet</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <p style="margin-top: 16px; color: #646970; font-size: 12px;">
                Last crawl: <?php echo $stats['last_crawl'] ? esc_html(date('M j, Y H:i:s', strtotime($stats['last_crawl']))) : 'Never'; ?>
                — <?php echo count($agentList->getAgents()); ?> agents in detection database
            </p>
        </div>
        <?php
    }

    // ── Pages Management ────────────────────────────────

    public function renderPagesAdmin(): void
    {
        $stats = $this->logger->getStats();
        $crawledPages = $stats['pages'] ?? [];

        $posts = get_posts([
            'post_type' => get_post_types(['public' => true]),
            'post_status' => 'publish',
            'posts_per_page' => 200,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
        ?>
        <div class="wrap">
            <h1>🤖 AgentReady — Page Management</h1>
            <p>Control which pages serve AI-optimized content. Toggle pages on/off and review auto-detected metadata.</p>

            <style>
                .ar-pages-table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #c3c4c7; border-radius: 8px; overflow: hidden; }
                .ar-pages-table th, .ar-pages-table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #f0f0f1; font-size: 13px; }
                .ar-pages-table th { background: #f6f7f7; font-weight: 600; color: #1d2327; }
                .ar-pages-table tr:hover { background: #f6f7f7; }
                .ar-toggle-switch { position: relative; display: inline-block; width: 36px; height: 20px; }
                .ar-toggle-switch input { opacity: 0; width: 0; height: 0; }
                .ar-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background: #c3c4c7; border-radius: 20px; transition: 0.2s; }
                .ar-slider:before { content: ""; position: absolute; height: 14px; width: 14px; left: 3px; bottom: 3px; background: #fff; border-radius: 50%; transition: 0.2s; }
                .ar-toggle-switch input:checked + .ar-slider { background: #2271b1; }
                .ar-toggle-switch input:checked + .ar-slider:before { transform: translateX(16px); }
                .ar-tag { display: inline-block; padding: 1px 8px; border-radius: 3px; font-size: 11px; margin-right: 4px; }
                .ar-tag-auto { background: #e6f7ee; color: #0a6629; }
                .ar-tag-manual { background: #fef3e2; color: #9a6700; }
                .ar-tag-type { background: #e6f0f7; color: #2271b1; }
                .ar-crawl-count { font-weight: 700; color: #1d2327; }
                .ar-agents-cell { font-size: 11px; color: #646970; max-width: 200px; }
            </style>

            <table class="ar-pages-table">
                <thead>
                    <tr>
                        <th style="width: 40px;">Active</th>
                        <th>Page</th>
                        <th>Type</th>
                        <th>Mode</th>
                        <th>Crawls</th>
                        <th>Last Crawled By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($posts as $post):
                    $enabled = get_post_meta($post->ID, self::META_ENABLED, true);
                    $isEnabled = $enabled !== '0';
                    $hasOverride = !empty(get_post_meta($post->ID, self::META_KEY, true));
                    $pageStats = $crawledPages[(string)$post->ID] ?? null;
                    $crawlCount = $pageStats['count'] ?? 0;
                    $lastAgents = $pageStats['agents'] ?? [];
                ?>
                    <tr>
                        <td>
                            <label class="ar-toggle-switch">
                                <input type="checkbox" <?php checked($isEnabled); ?>
                                    onchange="agentreadyToggle(<?php echo $post->ID; ?>, this.checked)">
                                <span class="ar-slider"></span>
                            </label>
                        </td>
                        <td>
                            <a href="<?php echo get_edit_post_link($post->ID); ?>"><strong><?php echo esc_html($post->post_title); ?></strong></a><br>
                            <small style="color: #999;"><?php echo esc_html(get_permalink($post)); ?></small>
                        </td>
                        <td><span class="ar-tag ar-tag-type"><?php echo esc_html($post->post_type); ?></span></td>
                        <td>
                            <?php if ($hasOverride): ?>
                                <span class="ar-tag ar-tag-manual">✏️ Manual</span>
                            <?php else: ?>
                                <span class="ar-tag ar-tag-auto">🔄 Auto</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="ar-crawl-count"><?php echo $crawlCount; ?></span></td>
                        <td class="ar-agents-cell"><?php echo esc_html(implode(', ', array_slice($lastAgents, -3))); ?></td>
                        <td>
                            <a href="<?php echo esc_url(get_permalink($post) . '?agentready=1'); ?>" target="_blank" class="button button-small">Preview</a>
                            <?php if ($hasOverride): ?>
                                <button class="button button-small" style="color: #a00;" onclick="agentreadyResetPage(<?php echo $post->ID; ?>)">Reset</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <script>
        function agentreadyToggle(postId, enabled) {
            jQuery.post(ajaxurl, {
                action: 'agentready_toggle_page',
                post_id: postId,
                enabled: enabled ? 1 : 0,
                _wpnonce: '<?php echo wp_create_nonce('agentready_toggle'); ?>'
            });
        }
        function agentreadyResetPage(postId) {
            if (!confirm('Reset manual overrides for this page?')) return;
            jQuery.post(ajaxurl, {
                action: 'agentready_reset_page',
                post_id: postId,
                _wpnonce: '<?php echo wp_create_nonce('agentready_reset'); ?>'
            }, function() { location.reload(); });
        }
        </script>
        <?php
    }

    // ── Analytics ───────────────────────────────────────

    public function renderAnalytics(): void
    {
        $stats = $this->logger->getStats();
        $recentLogs = $this->logger->getRecentLogs(50, 30);
        $dailyStats = $this->logger->getDailyStats(30);
        $vendorBreakdown = $this->logger->getVendorBreakdown();
        $agentBreakdown = $stats['agents'] ?? [];
        arsort($agentBreakdown);
        $formatBreakdown = $stats['formats'] ?? [];
        $topPages = $this->logger->getTopPages(20);

        // Handle stats reset
        if (isset($_POST['agentready_reset_stats']) && wp_verify_nonce($_POST['agentready_admin_nonce'] ?? '', 'agentready_admin')) {
            $this->logger->reset();
            echo '<div class="notice notice-success"><p>Statistics reset.</p></div>';
            // Refresh data
            $stats = $this->logger->getStats();
            $recentLogs = [];
            $dailyStats = [];
            $vendorBreakdown = [];
            $agentBreakdown = [];
            $formatBreakdown = [];
            $topPages = [];
        }
        ?>
        <div class="wrap">
            <h1>🤖 AgentReady — Analytics</h1>
            <p>Detailed analytics of how AI agents interact with your site.</p>

            <style>
                .ar-analytics { margin-top: 20px; }
                .ar-a-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 16px; margin-bottom: 16px; }
                .ar-a-panel { background: #fff; border: 1px solid #c3c4c7; border-radius: 8px; padding: 20px; }
                .ar-a-panel h3 { margin-top: 0; }
                .ar-a-chart { display: flex; align-items: flex-end; gap: 2px; height: 140px; padding: 8px 0; }
                .ar-a-bar { flex: 1; background: linear-gradient(to top, #2271b1, #72aee6); border-radius: 2px 2px 0 0; min-height: 2px; position: relative; }
                .ar-a-bar:hover { background: linear-gradient(to top, #135e96, #2271b1); }
                .ar-a-bar .tip { display: none; position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%); background: #1d2327; color: #fff; font-size: 11px; padding: 2px 8px; border-radius: 3px; white-space: nowrap; margin-bottom: 4px; }
                .ar-a-bar:hover .tip { display: block; }
                .ar-a-dates { display: flex; justify-content: space-between; font-size: 11px; color: #999; margin-top: 4px; }
                .ar-a-ring { width: 140px; height: 140px; border-radius: 50%; position: relative; margin: 0 auto; }
                .ar-a-ring-center { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-size: 22px; font-weight: 700; color: #1d2327; }
                .ar-a-legend { list-style: none; padding: 0; margin: 16px 0 0; font-size: 12px; }
                .ar-a-legend li { display: flex; align-items: center; gap: 8px; padding: 3px 0; }
                .ar-a-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
                .ar-full-log { width: 100%; border-collapse: collapse; font-size: 12px; }
                .ar-full-log th, .ar-full-log td { padding: 6px 10px; text-align: left; border-bottom: 1px solid #f0f0f1; }
                .ar-full-log th { background: #f6f7f7; font-weight: 600; position: sticky; top: 0; }
                .ar-log-scroll { max-height: 400px; overflow-y: auto; border: 1px solid #c3c4c7; border-radius: 8px; }
                .ar-badge { display: inline-block; padding: 1px 8px; border-radius: 100px; font-size: 11px; }
                .ar-badge-blue { background: #e6f0f7; color: #2271b1; }
                .ar-badge-green { background: #e6f7ee; color: #0a6629; }
                .ar-badge-orange { background: #fef3e2; color: #9a6700; }
            </style>

            <div class="ar-analytics">
                <div class="ar-a-grid">
                    <div class="ar-a-panel">
                        <h3>📈 Daily Crawls — Last 30 Days</h3>
                        <?php $maxD = max(array_column($dailyStats, 'count') ?: [1]); ?>
                        <div class="ar-a-chart">
                            <?php foreach ($dailyStats as $day): ?>
                                <?php $h = $maxD > 0 ? ($day['count'] / $maxD) * 100 : 0; ?>
                                <div class="ar-a-bar" style="height: <?php echo max($h, 2); ?>%">
                                    <span class="tip"><?php echo esc_html($day['date'] . ': ' . $day['count'] . ' crawls'); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="ar-a-dates">
                            <span><?php echo esc_html($dailyStats[0]['date'] ?? ''); ?></span>
                            <span><?php echo esc_html(end($dailyStats)['date'] ?? ''); ?></span>
                        </div>
                    </div>

                    <div class="ar-a-panel">
                        <h3>🏢 By Vendor</h3>
                        <?php
                        $vendorColors = ['#2271b1', '#00a32a', '#dba617', '#d63638', '#8c5ddb', '#3582c4', '#e86d22', '#50575e'];
                        $totalCrawls = array_sum($vendorBreakdown ?: [0]);
                        ?>
                        <div style="text-align: center; margin: 16px 0;">
                            <div class="ar-a-ring" style="background: conic-gradient(<?php
                                $offset = 0;
                                $i = 0;
                                foreach ($vendorBreakdown as $v => $c) {
                                    $pct = $totalCrawls > 0 ? ($c / $totalCrawls) * 100 : 0;
                                    $color = $vendorColors[$i % count($vendorColors)];
                                    echo "{$color} {$offset}% " . ($offset + $pct) . '%';
                                    $offset += $pct;
                                    if ($offset < 100) echo ', ';
                                    $i++;
                                }
                                if ($offset < 100) echo "#f0f0f1 {$offset}% 100%";
                            ?>);">
                                <span class="ar-a-ring-center"><?php echo $totalCrawls; ?></span>
                            </div>
                        </div>
                        <ul class="ar-a-legend">
                            <?php $i = 0; foreach (array_slice($vendorBreakdown, 0, 6, true) as $v => $c): ?>
                                <li>
                                    <span class="ar-a-dot" style="background: <?php echo $vendorColors[$i % count($vendorColors)]; ?>"></span>
                                    <?php echo esc_html(ucfirst($v)); ?> <strong style="margin-left: auto;"><?php echo $c; ?></strong>
                                </li>
                            <?php $i++; endforeach; ?>
                        </ul>
                    </div>
                </div>

                <div class="ar-a-grid">
                    <div class="ar-a-panel">
                        <h3>🤖 By Agent</h3>
                        <table class="ar-full-log">
                            <thead><tr><th>Agent</th><th>Crawls</th><th>Share</th></tr></thead>
                            <tbody>
                            <?php foreach (array_slice($agentBreakdown, 0, 15, true) as $agent => $count): ?>
                                <?php $pct = $totalCrawls > 0 ? round(($count / $totalCrawls) * 100, 1) : 0; ?>
                                <tr>
                                    <td><strong><?php echo esc_html($agent); ?></strong></td>
                                    <td><?php echo $count; ?></td>
                                    <td><?php echo $pct; ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="ar-a-panel">
                        <h3>📦 Format Requested</h3>
                        <ul class="ar-a-legend" style="font-size: 14px;">
                            <?php foreach ($formatBreakdown as $fmt => $count): ?>
                                <li>
                                    <span class="ar-badge <?php echo $fmt === 'json' ? 'ar-badge-green' : 'ar-badge-orange'; ?>"><?php echo esc_html($fmt); ?></span>
                                    <strong style="margin-left: auto;"><?php echo $count; ?></strong>
                                </li>
                            <?php endforeach; ?>
                            <?php if (empty($formatBreakdown)): ?>
                                <li style="color: #999;">No data yet</li>
                            <?php endif; ?>
                        </ul>

                        <h3 style="margin-top: 24px;">🏆 Top Crawled Pages</h3>
                        <?php foreach (array_slice($topPages, 0, 5, true) as $p): ?>
                            <div style="margin-bottom: 8px;">
                                <div style="display: flex; justify-content: space-between; font-size: 12px;">
                                    <span><?php echo esc_html(mb_substr($p['title'], 0, 35)); ?></span>
                                    <strong><?php echo $p['count']; ?></strong>
                                </div>
                                <div style="height: 4px; background: #f0f0f1; border-radius: 2px; margin-top: 2px;">
                                    <?php $maxP = max(array_column($topPages, 'count') ?: [1]); ?>
                                    <div style="height: 100%; width: <?php echo ($p['count'] / $maxP) * 100; ?>%; background: #2271b1; border-radius: 2px;"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="ar-a-panel" style="margin-top: 16px;">
                    <h3>📋 Crawl Log — Last 30 Days</h3>
                    <div class="ar-log-scroll">
                        <table class="ar-full-log">
                            <thead>
                                <tr><th>Timestamp</th><th>Agent</th><th>Vendor</th><th>Page</th><th>Format</th><th>Size</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recentLogs as $log): ?>
                                <tr>
                                    <td><?php echo esc_html(date('Y-m-d H:i', strtotime($log['timestamp']))); ?></td>
                                    <td><span class="ar-badge ar-badge-blue"><?php echo esc_html($log['agent_name']); ?></span></td>
                                    <td><?php echo esc_html($log['agent_vendor']); ?></td>
                                    <td><?php echo esc_html(mb_substr($log['post_title'] ?: parse_url($log['url'] ?? '', PHP_URL_PATH), 0, 40)); ?></td>
                                    <td><span class="ar-badge ar-badge-green"><?php echo esc_html($log['format']); ?></span></td>
                                    <td><?php echo number_format(($log['response_size'] ?? 0) / 1024, 1); ?> KB</td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($recentLogs)): ?>
                                <tr><td colspan="6" style="color: #999; text-align: center; padding: 24px;">No crawl data yet. When AI bots visit your site, their activity will appear here.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <p style="margin-top: 12px;">
                    <form method="post">
                        <?php wp_nonce_field('agentready_admin', 'agentready_admin_nonce'); ?>
                        <button type="submit" name="agentready_reset_stats" class="button" style="color: #a00;" onclick="return confirm('Reset all statistics? This cannot be undone.')">Reset Statistics</button>
                    </form>
                </p>
            </div>
        </div>
        <?php
    }

    // ── Settings ────────────────────────────────────────

    public function renderSettingsPage(): void
    {
        $currentConfig = get_option('agentready_config', '');
        if (empty($currentConfig)) {
            $currentConfig = json_encode($this->defaultWordPressConfig(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        $agentList = new AgentList($this->config);
        $agents = $agentList->getAgents();
        ?>
        <div class="wrap">
            <h1>🤖 AgentReady — Settings</h1>

            <div style="display: grid; grid-template-columns: 1fr 340px; gap: 24px; margin-top: 20px;">
                <div>
                    <form method="post" action="options.php">
                        <?php settings_fields('agentready_settings'); ?>
                        <table class="form-table">
                            <tr>
                                <th scope="row">Configuration (JSON)</th>
                                <td>
                                    <textarea name="agentready_config" rows="30" class="large-text code" style="font-family: monospace; font-size: 13px;"><?php echo esc_textarea($currentConfig); ?></textarea>
                                    <p class="description">Edit your AgentReady configuration in JSON format.</p>
                                </td>
                            </tr>
                        </table>
                        <?php submit_button('Save Configuration'); ?>
                    </form>

                    <hr>
                    <h2>Test Endpoints</h2>
                    <ul>
                        <li><a href="<?php echo home_url('/llms.txt'); ?>" target="_blank">/llms.txt</a></li>
                        <li><a href="<?php echo home_url('/llms-full.txt'); ?>" target="_blank">/llms-full.txt</a></li>
                        <li><a href="<?php echo home_url('/?agentready=1'); ?>" target="_blank">Homepage as Markdown</a></li>
                        <li><a href="<?php echo home_url('/?agentready=json'); ?>" target="_blank">Homepage as JSON</a></li>
                        <li><a href="<?php echo rest_url('agentready/v1/feed'); ?>" target="_blank">Content Feed</a></li>
                    </ul>

                    <hr>
                    <h2>Quick Actions</h2>
                    <form method="post">
                        <?php wp_nonce_field('agentready_flush', 'agentready_nonce'); ?>
                        <button type="submit" name="agentready_flush_rewrites" class="button">Flush Rewrite Rules</button>
                        <button type="submit" name="agentready_refresh_agents" class="button">Refresh Agent List</button>
                    </form>
                    <?php
                    if (isset($_POST['agentready_flush_rewrites']) && wp_verify_nonce($_POST['agentready_nonce'] ?? '', 'agentready_flush')) {
                        flush_rewrite_rules();
                        echo '<div class="notice notice-success"><p>Rewrite rules flushed.</p></div>';
                    }
                    if (isset($_POST['agentready_refresh_agents']) && wp_verify_nonce($_POST['agentready_nonce'] ?? '', 'agentready_flush')) {
                        $agentList->refresh();
                        echo '<div class="notice notice-success"><p>Agent list refreshed.</p></div>';
                    }
                    ?>
                </div>

                <div>
                    <div style="background: #f0f0f1; border: 1px solid #c3c4c7; border-radius: 4px; padding: 16px;">
                        <h3 style="margin-top: 0;">🤖 Detection Database (<?php echo count($agents); ?>)</h3>
                        <div style="max-height: 400px; overflow-y: auto; font-size: 12px; font-family: monospace;">
                            <?php foreach ($agents as $agent): ?>
                                <div style="padding: 3px 0; border-bottom: 1px solid #ddd;">
                                    <strong><?php echo esc_html($agent['pattern']); ?></strong>
                                    <span style="color: #666;"> — <?php echo esc_html($agent['vendor'] ?? ''); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    //  AJAX HANDLERS
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    public function ajaxTogglePage(): void
    {
        check_ajax_referer('agentready_toggle');

        $postId = (int)($_POST['post_id'] ?? 0);
        $enabled = ($_POST['enabled'] ?? '1') === '1' ? '1' : '0';

        if ($postId && current_user_can('edit_post', $postId)) {
            update_post_meta($postId, self::META_ENABLED, $enabled);
            wp_send_json_success();
        }

        wp_send_json_error();
    }

    public function ajaxResetPage(): void
    {
        check_ajax_referer('agentready_reset');

        $postId = (int)($_POST['post_id'] ?? 0);

        if ($postId && current_user_can('edit_post', $postId)) {
            delete_post_meta($postId, self::META_KEY);
            wp_send_json_success();
        }

        wp_send_json_error();
    }

    public function ajaxPreview(): void
    {
        check_ajax_referer('agentready_preview');

        $postId = (int)($_POST['post_id'] ?? 0);
        if (!$postId || !current_user_can('edit_post', $postId)) {
            wp_send_json_error();
        }

        $post = get_post($postId);
        $extractor = new ContentExtractor($this->config);
        $html = apply_filters('the_content', $post->post_content);
        $extracted = $extractor->extract($html, [
            'title' => $post->post_title,
            'description' => get_the_excerpt($post),
            'metadata' => $this->autoDetectMetadata($post),
        ]);
        $extracted = $this->applyPageOverrides($postId, $extracted);

        wp_send_json_success([
            'markdown' => $extractor->toMarkdown($extracted),
            'json' => $extractor->toJson($extracted),
        ]);
    }

    public function ajaxStats(): void
    {
        check_ajax_referer('agentready_stats');

        if (!current_user_can('manage_options')) {
            wp_send_json_error();
        }

        wp_send_json_success([
            'stats' => $this->logger->getStats(),
            'daily' => $this->logger->getDailyStats(30),
            'recent' => $this->logger->getRecentLogs(20),
        ]);
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    //  PRIVATE HELPERS
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    private function getLogDir(): string
    {
        $upload = wp_upload_dir();
        return $upload['basedir'] . '/agentready-logs';
    }

    private function loadConfig(): Config
    {
        $wpConfig = get_option('agentready_config', '');

        if (!empty($wpConfig)) {
            $data = json_decode($wpConfig, true);
            if (is_array($data)) {
                return Config::fromArray($this->mergeWithSiteDefaults($data));
            }
        }

        $configFile = AGENTREADY_DIR . 'config.json';
        if (file_exists($configFile)) {
            $config = Config::fromFile($configFile);
            return Config::fromArray($this->mergeWithSiteDefaults($config->all()));
        }

        return Config::fromArray($this->defaultWordPressConfig());
    }

    private function mergeWithSiteDefaults(array $data): array
    {
        if (empty($data['site']['name'])) $data['site']['name'] = get_bloginfo('name');
        if (empty($data['site']['url'])) $data['site']['url'] = home_url();
        if (empty($data['site']['description'])) $data['site']['description'] = get_bloginfo('description');
        if (empty($data['site']['language'])) $data['site']['language'] = substr(get_locale(), 0, 2);
        if (empty($data['structured_data']['organization']['name'])) $data['structured_data']['organization']['name'] = get_bloginfo('name');
        if (empty($data['structured_data']['organization']['url'])) $data['structured_data']['organization']['url'] = home_url();
        return $data;
    }

    private function defaultWordPressConfig(): array
    {
        return $this->mergeWithSiteDefaults([
            'site' => [],
            'llms_txt' => ['enabled' => true, 'sections' => ['about' => get_bloginfo('description'), 'topics' => [], 'capabilities' => []]],
            'structured_data' => ['enabled' => true, 'organization' => []],
            'agent_endpoint' => ['enabled' => true, 'param' => 'agentready', 'accept_headers' => ['text/markdown', 'application/agent+json']],
            'content_feed' => ['enabled' => true, 'path' => '/agentready-feed.json', 'max_items' => 1000],
            'content_selectors' => [
                'title' => 'h1, .entry-title, .page-title',
                'main_content' => 'main, article, .entry-content, .page-content',
                'exclude' => 'nav, footer, header, .sidebar, .widget-area, .menu, .cookie-notice, script, style, .advertisement, .social-share, .comments, #comments, .nav-links',
                'metadata' => '',
            ],
        ]);
    }

    private function getCurrentUrl(): string
    {
        $protocol = is_ssl() ? 'https' : 'http';
        return $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
    }

    private function getSitePages(): array
    {
        $pages = [];
        $query = new \WP_Query([
            'post_type' => ['page', 'post'],
            'post_status' => 'publish',
            'posts_per_page' => 50,
            'orderby' => 'menu_order date',
            'order' => 'ASC',
            'meta_query' => [
                'relation' => 'OR',
                ['key' => self::META_ENABLED, 'compare' => 'NOT EXISTS'],
                ['key' => self::META_ENABLED, 'value' => '0', 'compare' => '!='],
            ],
        ]);

        foreach ($query->posts as $post) {
            $pages[] = [
                'title' => $post->post_title,
                'url' => get_permalink($post),
                'description' => get_the_excerpt($post) ?: wp_trim_words(strip_tags($post->post_content), 20),
            ];
        }

        wp_reset_postdata();
        return $pages;
    }

    private function buildContentFeed(): array
    {
        $maxItems = $this->config->get('content_feed.max_items', 1000);
        $extractor = new ContentExtractor($this->config);
        $items = [];

        $query = new \WP_Query([
            'post_type' => ['post', 'page'],
            'post_status' => 'publish',
            'posts_per_page' => $maxItems,
            'orderby' => 'modified',
            'order' => 'DESC',
            'meta_query' => [
                'relation' => 'OR',
                ['key' => self::META_ENABLED, 'compare' => 'NOT EXISTS'],
                ['key' => self::META_ENABLED, 'value' => '0', 'compare' => '!='],
            ],
        ]);

        foreach ($query->posts as $post) {
            $html = apply_filters('the_content', $post->post_content);
            $autoMeta = $this->autoDetectMetadata($post);
            $extracted = $extractor->extract($html, [
                'title' => $post->post_title,
                'description' => get_the_excerpt($post),
                'metadata' => $autoMeta,
            ]);
            $extracted = $this->applyPageOverrides($post->ID, $extracted);

            $items[] = [
                'id' => $post->ID,
                'title' => $extracted['title'],
                'url' => get_permalink($post),
                'description' => $extracted['description'],
                'content' => $extracted['content'],
                'metadata' => $extracted['metadata'],
                'datePublished' => get_the_date('c', $post),
                'dateModified' => get_the_modified_date('c', $post),
                'type' => $post->post_type,
            ];
        }

        wp_reset_postdata();

        return [
            '@context' => 'https://agentready.dev/feed',
            'generator' => 'AgentReady/' . AGENTREADY_VERSION . ' WordPress',
            'site' => [
                'name' => $this->config->get('site.name'),
                'url' => $this->config->get('site.url'),
                'description' => $this->config->get('site.description'),
            ],
            'totalItems' => count($items),
            'items' => $items,
        ];
    }
}

// ── Activation / Deactivation ───────────────────────────

register_activation_hook(__FILE__, function () {
    $plugin = new AgentReady_Plugin();
    $plugin->registerRewriteRules();
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});

// ── Boot ────────────────────────────────────────────────

add_action('plugins_loaded', function () {
    $plugin = new AgentReady_Plugin();
    $plugin->init();
});
