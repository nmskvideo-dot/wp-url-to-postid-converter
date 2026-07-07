<?php
/*
Plugin Name: URL to Post ID Advanced Manager
Description: Convert URLs to IDs, check HTTP status, mass update post statuses, export selectively, and manage 301 redirects using bulletproof Redirection 5.8+ native Red_Item engine with extensive logging.
Version: 4.0
Author: Ranked - Roman P
*/

namespace UrlToIdExporter;

// Prohibit direct access to the file
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Class UrlResolver
 * Responsible for resolving URLs to Post IDs (excluding drafts/trash/404 statuses), checking HTTP codes, and fetching taxonomies.
 */
class UrlResolver {
    public function resolve_urls(array $urls) {
        $resolved = array();
        $not_found = array();

        foreach ($urls as $url) {
            $url = trim($url);
            if (empty($url)) {
                continue;
            }

            $sanitized_url = esc_url_raw($url);
            $http_status   = $this->check_http_status($sanitized_url);
            
            // Step 1: Standard WordPress URL parsing
            $post_id = url_to_postid($sanitized_url);

            // Step 2: Advanced Fallback if standard routing fails (e.g., post is trashed, draft, or causes 404)
            if (!$post_id) {
                $post_id = $this->fallback_resolve_by_slug($sanitized_url);
            }

            if ($post_id) {
                $post = get_post($post_id);
                if ($post) {
                    $categories = get_the_term_list($post_id, 'category', '', ', ', '');
                    $tags       = get_the_term_list($post_id, 'post_tag', '', ', ', '');

                    $resolved[$post_id] = array(
                        'id'          => $post_id,
                        'title'       => $post->post_title,
                        'type'        => $post->post_type,
                        'status'      => $post->post_status,
                        'url'         => $sanitized_url,
                        'http_status' => $http_status,
                        'categories'  => $categories ? strip_tags($categories) : '—',
                        'tags'        => $tags ? strip_tags($tags) : '—'
                    );
                    continue;
                }
            }
            
            $not_found[] = array(
                'url'         => $sanitized_url,
                'http_status' => $http_status
            );
        }

        return array(
            'found'     => $resolved,
            'not_found' => $not_found
        );
    }

    private function check_http_status($url) {
        $response = wp_remote_head($url, array('timeout' => 3, 'sslverify' => false));
        if (is_wp_error($response)) {
            return 'Error';
        }
        return wp_remote_retrieve_response_code($response);
    }

    /**
     * Advanced deep-scan method using path fragments to locate non-public posts.
     * Note: 'attachment' type is strictly skipped to prevent media image collisions.
     */
    private function fallback_resolve_by_slug($url) {
        $path = parse_url($url, PHP_URL_PATH);
        if (empty($path)) {
            return 0;
        }

        $path_segments = array_filter(explode('/', trim($path, '/')));
        if (empty($path_segments)) {
            return 0;
        }
        $slug = end($path_segments);

        $args = array(
            'name'           => $slug,
            'post_type'      => array('post', 'page', 'product'),
            'post_status'    => array('publish', 'pending', 'draft', 'auto-draft', 'future', 'private', 'inherit', 'trash'),
            'posts_per_page' => 1,
            'fields'         => 'ids'
        );

        $custom_types = get_post_types(array('public' => true), 'names');
        if (!empty($custom_types)) {
            if (isset($custom_types['attachment'])) {
                unset($custom_types['attachment']);
            }
            $args['post_type'] = array_values($custom_types);
        }

        $query = new \WP_Query($args);
        if (!empty($query->posts)) {
            return intval($query->posts[0]);
        }

        return 0;
    }

    public function refresh_resolved_posts(array $post_ids) {
        $resolved = array();
        foreach ($post_ids as $id) {
            $post = get_post($id);
            if ($post) {
                $categories = get_the_term_list($id, 'category', '', ', ', '');
                $tags       = get_the_term_list($id, 'post_tag', '', ', ', '');
                $permalink  = get_permalink($id);

                $resolved[$id] = array(
                    'id'          => $id,
                    'title'       => $post->post_title,
                    'type'        => $post->post_type,
                    'status'      => $post->post_status,
                    'url'         => $permalink ? esc_url_raw($permalink) : '—',
                    'http_status' => $this->check_http_status($permalink),
                    'categories'  => $categories ? strip_tags($categories) : '—',
                    'tags'        => $tags ? strip_tags($tags) : '—'
                );
            }
        }
        return $resolved;
    }
}

/**
 * Class ExportManager
 * Handles secure selective XML export using a localized runtime filter.
 */
class ExportManager {
    private $target_ids = array();

    public function __construct() {
        add_filter('query', array($this, 'restrict_export_query'));
    }

    public function trigger_export(array $post_ids) {
        $this->target_ids = $post_ids;
        
        require_once ABSPATH . 'wp-admin/includes/export.php';

        $filename = 'wp-filtered-export-' . date('Y-m-d-H-i-s') . '.xml';
        header('Content-Description: File Transfer');
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Content-Type: text/xml; charset=' . get_option('blog_charset'), true);

        export_wp(array('content' => 'all'));
        exit;
    }

    public function restrict_export_query($query) {
        global $wpdb;
        if (!empty($this->target_ids) && strpos($query, "SELECT ID FROM {$wpdb->posts}") !== false && strpos($query, "auto-draft") !== false) {
            $ids_string = implode(',', array_map('intval', $this->target_ids));
            $query = "SELECT ID FROM {$wpdb->posts} WHERE (ID IN ($ids_string) OR (post_type = 'attachment' AND post_parent IN ($ids_string))) AND post_status != 'auto-draft'";
        }
        return $query;
    }
}

/**
 * Class PostManager
 * Handles bulk status modifications (Trash, Draft, Private, Pending, Publish) with strict validation.
 */
class PostManager {
    public function update_statuses(array $post_ids, $new_status) {
        $allowed_statuses = array('trash', 'draft', 'private', 'pending', 'publish');
        if (!in_array($new_status, $allowed_statuses, true)) {
            return 0;
        }

        $updated_count = 0;
        foreach ($post_ids as $id) {
            $post = get_post($id);
            if (!$post) {
                continue;
            }

            if ($new_status === 'trash') {
                if ($post->post_status !== 'trash') {
                    $result = wp_trash_post($id);
                    if ($result) {
                        $updated_count++;
                    }
                }
            } else {
                $result = wp_update_post(array(
                    'ID'          => $id,
                    'post_status' => $new_status
                ));
                if ($result && !is_wp_error($result)) {
                    $updated_count++;
                }
            }
        }
        return $updated_count;
    }
}

/**
 * Class RedirectManager
 * Direct integration with Redirection 5.8+ native core PHP API using Red_Item.
 */
class RedirectManager {
    public function is_active() {
        return defined('REDIRECTION_FILE');
    }

    /**
     * Creates redirects using native Red_Item::create engine with complete structural diagnostics.
     */
    public function create_homepage_redirects(array $post_ids) {
        if (!$this->is_active()) {
            return 0;
        }

        // Force bootstrap file memory loaders to guarantee availability of Red_Item
        if (function_exists('redirection_init')) {
            redirection_init();
        }

        if (!class_exists('Red_Item')) {
            error_log('URL MANAGER PRO ERROR: Core class Red_Item is not available even after execution boot.');
            return 0;
        }

        // Dynamically locate group id via stable class methods if available, fallback to 1
        $group_id = 1;
        if (class_exists('RE_Group') && method_exists('RE_Group', 'get_all')) {
            $groups = \RE_Group::get_all();
            if (!empty($groups)) {
                $first_group = reset($groups);
                if (isset($first_group->id)) {
                    $group_id = intval($first_group->id);
                } elseif (is_array($first_group) && isset($first_group['id'])) {
                    $group_id = intval($first_group['id']);
                }
            }
        }

        $redirects_created = 0;

        foreach ($post_ids as $id) {
            $permalink = get_permalink($id);
            if (!$permalink) {
                continue;
            }

            $url_path = wp_make_link_relative($permalink);
            if ($url_path === '/' || empty($url_path)) {
                continue;
            }

            // Modern 5.8 data schema matching Red_Item_Sanitize pipeline expectations
            $details = array(
                'url'         => $url_path,
                'action_data' => array('url' => '/'),
                'action_type' => 'url',
                'action_code' => 301,
                'group_id'    => $group_id,
                'match_type'  => 'url',
                'match_data'  => array(
                    'source' => array(
                        'flag_query'    => 'exact',
                        'flag_case'     => false,
                        'flag_trailing' => false,
                        'flag_regex'    => false
                    )
                )
            );

            // Wrap database-level plugin mutation within structured try/catch diagnostic logic
            try {
                $result = \Red_Item::create($details);

                if (is_wp_error($result)) {
                    error_log(sprintf('URL MANAGER PRO WP_ERROR for URL %s: %s', $url_path, $result->get_error_message()));
                } elseif (!$result) {
                    error_log(sprintf('URL MANAGER PRO FAILURE: Red_Item::create returned false or invalid state for URL %s. Sent Details: %s', $url_path, print_r($details, true)));
                } else {
                    $redirects_created++;
                }
            } catch (\Exception $e) {
                error_log(sprintf('URL MANAGER PRO CRITICAL EXCEPTION for URL %s: %s', $url_path, $e->getMessage()));
            }
        }

        return $redirects_created;
    }
}

/**
 * Class AdminPage
 * Main orchestrator for UI rendering and request handling.
 */
class AdminPage {
    private $resolver;
    private $export_manager;
    private $post_manager;
    private $redirect_manager;

    public function __construct() {
        $this->resolver         = new UrlResolver();
        $this->export_manager   = new ExportManager();
        $this->post_manager     = new PostManager();
        $this->redirect_manager = new RedirectManager();

        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_init', array($this, 'handle_actions'));
    }

    public function add_menu() {
        add_menu_page(
            'URL Manager Pro',
            'URL Manager Pro',
            'manage_options',
            'url-manager-pro',
            array($this, 'render_page')
        );
    }

    public function handle_actions() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Handle Clear Session Action
        if (isset($_POST['clear_url_manager_session'])) {
            check_admin_referer('url_to_id_bulk_action', 'url_to_id_nonce');
            delete_transient('url_pro_active_ids');
            delete_transient('url_pro_not_found');
            delete_transient('url_pro_input');
            wp_redirect(admin_url('admin.php?page=url-manager-pro'));
            exit;
        }

        // Handle XML Export
        if (isset($_POST['download_export']) && !empty($_POST['action_ids'])) {
            check_admin_referer('url_to_id_bulk_action', 'url_to_id_nonce');
            $ids = array_map('intval', explode(',', $_POST['action_ids']));
            $this->export_manager->trigger_export($ids);
        }

        // Handle Status Change
        if (isset($_POST['change_status']) && !empty($_POST['action_ids']) && isset($_POST['bulk_status'])) {
            check_admin_referer('url_to_id_bulk_action', 'url_to_id_nonce');
            $ids = array_map('intval', explode(',', $_POST['action_ids']));
            $status = sanitize_key($_POST['bulk_status']);
            
            $count = $this->post_manager->update_statuses($ids, $status);
            set_transient('url_pro_msg', sprintf('%d posts status changed to "%s". State engine preserved.', $count, $status), 45);
            
            wp_redirect(admin_url('admin.php?page=url-manager-pro'));
            exit;
        }

        // Handle Redirects
        if (isset($_POST['create_redirects']) && !empty($_POST['action_ids'])) {
            check_admin_referer('url_to_id_bulk_action', 'url_to_id_nonce');
            $ids = array_map('intval', explode(',', $_POST['action_ids']));
            
            $count = $this->redirect_manager->create_homepage_redirects($ids);
            if ($count > 0) {
                set_transient('url_pro_msg', sprintf('%d redirect rules to homepage processed successfully via native Red_Item engine.', $count), 45);
            } else {
                set_transient('url_pro_msg', 'No new redirects created. Please inspect your wp-content/debug.log file for explicit diagnostic logs.', 45);
            }
            
            wp_redirect(admin_url('admin.php?page=url-manager-pro'));
            exit;
        }
    }

    private function get_http_badge_styles($status) {
        if (!is_numeric($status)) {
            return 'background: #f4f4f5; color: #71717a;';
        }

        $code = intval($status);
        if ($code >= 200 && $code < 300) {
            return 'background: #e7f4e4; color: #2e7d32;';
        }
        if ($code >= 300 && $code < 400) {
            return 'background: #fef3c7; color: #d97706;';
        }
        if ($code >= 400 && $code < 500) {
            return 'background: #fcf0f0; color: #c62828;';
        }
        if ($code >= 500) {
            return 'background: #fee2e2; color: #991b1b;';
        }

        return 'background: #f4f4f5; color: #71717a;';
    }

    public function render_page() {
        $urls_input = get_transient('url_pro_input') ?: '';
        $results = null;

        // Process new analysis request
        if (isset($_POST['submit_urls']) && isset($_POST['url_to_id_convert_nonce'])) {
            if (wp_verify_nonce($_POST['url_to_id_convert_nonce'], 'url_to_id_convert_action')) {
                $urls_input = esc_textarea($_POST['urls']);
                $urls_array = explode("\n", $_POST['urls']);
                $results    = $this->resolver->resolve_urls($urls_array);

                // Cache state inside transient scope
                set_transient('url_pro_input', $_POST['urls'], HOUR_IN_SECONDS);
                set_transient('url_pro_active_ids', array_keys($results['found']), HOUR_IN_SECONDS);
                set_transient('url_pro_not_found', $results['not_found'], HOUR_IN_SECONDS);
            }
        } else {
            // Restore context from previous session action if available
            $active_ids = get_transient('url_pro_active_ids');
            $not_found  = get_transient('url_pro_not_found');

            if (is_array($active_ids)) {
                $results = array(
                    'found'     => $this->resolver->refresh_resolved_posts($active_ids),
                    'not_found' => is_array($not_found) ? $not_found : array()
                );
            }
        }

        $wp_path = rtrim(ABSPATH, '/');
        $msg = get_transient('url_pro_msg');
        if ($msg) {
            delete_transient('url_pro_msg');
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($msg) . '</p></div>';
        }
        ?>
        <div class="wrap">
            <h1>URL to Post ID Advanced Manager</h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('url_to_id_convert_action', 'url_to_id_convert_nonce'); ?>
                <p><label for="urls"><strong>Enter URLs to analyze (one per line):</strong></label></p>
                <textarea id="urls" name="urls" rows="8" cols="80" class="large-text" placeholder="https://example.com/some-page/"><?php echo esc_textarea($urls_input); ?></textarea>
                <br>
                <?php submit_button('Analyze URLs', 'primary', 'submit_urls'); ?>
            </form>

            <?php if ($results !== null) : ?>
                <hr>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h2>Analysis Results</h2>
                    <form method="post" action="">
                        <?php wp_nonce_field('url_to_id_bulk_action', 'url_to_id_nonce'); ?>
                        <input type="submit" name="clear_url_manager_session" class="button button-link-delete" value="✕ Clear Results & Start New Batch" style="color: #d63638; text-decoration: none; font-weight: bold;">
                    </form>
                </div>

                <?php if (!empty($results['found'])) : 
                    $found_ids = array_keys($results['found']);
                    ?>
                    <h3>Identified Content</h3>
                    <table class="wp-list-table widefat fixed striped posts">
                        <thead>
                            <tr>
                                <th style="width: 60px;">ID</th>
                                <th style="width: 80px;">Type</th>
                                <th style="width: 90px;">Status</th>
                                <th style="width: 80px;">HTTP</th>
                                <th style="width: 150px;">Categories</th>
                                <th style="width: 150px;">Tags</th>
                                <th>Title</th>
                                <th>Source URL</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results['found'] as $post_data) : ?>
                                <tr>
                                    <td><strong><?php echo $post_data['id']; ?></strong></td>
                                    <td><span class="post-state"><?php echo esc_html($post_data['type']); ?></span></td>
                                    <td><code><?php echo esc_html($post_data['status']); ?></code></td>
                                    <td>
                                        <span class="badge" style="<?php echo $this->get_http_badge_styles($post_data['http_status']); ?> padding: 3px 6px; border-radius: 3px; font-weight: bold;">
                                            <?php echo esc_html($post_data['http_status']); ?>
                                        </span>
                                    </td>
                                    <td><small><?php echo esc_html($post_data['categories']); ?></small></td>
                                    <td><small><?php echo esc_html($post_data['tags']); ?></small></td>
                                    <td><a href="<?php echo esc_url(get_edit_post_link($post_data['id'])); ?>" target="_blank"><strong><?php echo esc_html($post_data['title']); ?></strong></a></td>
                                    <td><small style="color:#666;"><?php echo esc_html($post_data['url']); ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <h3 style="margin-top: 30px;">Available Actions (Sequential Workflow)</h3>
                    
                    <div style="display: flex; flex-direction: column; gap: 20px;">
                        
                        <div class="card" style="margin: 0; max-width: 100%;">
                            <h3>Option 1: Batch Status Manager</h3>
                            <p>Change the core WordPress post status for all identified entries simultaneously.</p>
                            <form method="post" action="" style="display: flex; gap: 10px; align-items: center;">
                                <?php wp_nonce_field('url_to_id_bulk_action', 'url_to_id_nonce'); ?>
                                <input type="hidden" name="action_ids" value="<?php echo esc_attr(implode(',', $found_ids)); ?>">
                                <select name="bulk_status" required>
                                    <option value="">-- Select Target Status --</option>
                                    <option value="trash">Move to Trash</option>
                                    <option value="draft">Convert to Draft</option>
                                    <option value="private">Set to Private</option>
                                    <option value="pending">Mark as Pending Review</option>
                                    <option value="publish">Publish</option>
                                </select>
                                <input type="submit" name="change_status" class="button button-secondary" value="Apply Status Update">
                            </form>
                        </div>

                        <div class="card" style="margin: 0; max-width: 100%;">
                            <h3>Option 2: Isolated XML Export Engine</h3>
                            <p>Generates and downloads a clean, localized .xml file containing strictly the chosen entries alongside related media files.</p>
                            <form method="post" action="">
                                <?php wp_nonce_field('url_to_id_bulk_action', 'url_to_id_nonce'); ?>
                                <input type="hidden" name="action_ids" value="<?php echo esc_attr(implode(',', $found_ids)); ?>">
                                <input type="submit" name="download_export" class="button button-secondary" value="Download Filtered .xml File">
                            </form>
                        </div>

                        <div class="card" style="margin: 0; max-width: 100%;">
                            <h3>Option 3: 301 Redirection Manager Link</h3>
                            <p>Automate structural URL changes by injecting direct 301 forwarding instructions inside the Redirection ecosystem.</p>
                            <?php if ($this->redirect_manager->is_active()) : ?>
                                <p style="color: #d63638; font-weight: bold; margin-bottom: 12px;">⚠️ WARNING: This rule maps paths STRICTLY onto the homepage root structure (/). Duplicate rules for existing paths will be skipped automatically by Redirection core validation.</p>
                                <form method="post" action="">
                                    <?php wp_nonce_field('url_to_id_bulk_action', 'url_to_id_nonce'); ?>
                                    <input type="hidden" name="action_ids" value="<?php echo esc_attr(implode(',', $found_ids)); ?>">
                                    <input type="submit" name="create_redirects" class="button button-primary" value="Generate Active 301 Home Redirects" onclick="return confirm('Enforce permanent 301 routing to homepage root across the selected batch?');">
                                </form>
                            <?php else : ?>
                                <p style="color: #666; font-style: italic;">John Godley's Redirection module is currently inactive or absent. Enable it to bridge automatic route writing capabilities.</p>
                            <?php endif; ?>
                        </div>

                    </div>
                <?php endif; ?>

                <?php if (!empty($results['not_found'])) : ?>
                    <div class="notice notice-error inline" style="margin-top: 30px; border-left-color: #d63638;">
                        <h3>Unresolved Content / Missing URLs</h3>
                        <p>The following targets could not be matched to any active database Post ID:</p>
                        <table class="widefat fixed striped" style="box-shadow: none; background: transparent;">
                            <thead>
                                <tr>
                                    <th style="width: 100px;">HTTP Status</th>
                                    <th>Target URL</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results['not_found'] as $fail) : ?>
                                    <tr>
                                        <td>
                                            <span class="badge" style="<?php echo $this->get_http_badge_styles($fail['http_status']); ?> padding: 3px 6px; border-radius: 3px; font-weight: bold;">
                                                <?php echo esc_html($fail['http_status']); ?>
                                            </span>
                                        </td>
                                        <td><code><?php echo esc_html($fail['url']); ?></code></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php if (!empty($found_ids)) : ?>
                    <div class="card" style="margin-top: 25px; opacity: 0.85;">
                        <h3>WP-CLI Reference Engine</h3>
                        <p>Dynamically isolated shell tokens for manual terminal execution paths:</p>
                        <pre style="background: #f0f0f0; padding: 8px; overflow-x: auto;"><code>wp export --dir='<?php echo esc_attr(dirname($wp_path) . '/tmp/'); ?>' --path='<?php echo esc_attr($wp_path); ?>' --post__in=<?php echo esc_html(implode(',', $found_ids)); ?></code></pre>
                    </div>
                <?php endif; ?>

            <?php endif; ?>
        </div>
        <?php
    }
}

// Instantiate core controller component context
new AdminPage();