<?php
/*
Plugin Name: URL to Post ID Advanced Manager
Description: Convert URLs to IDs, check HTTP status, mass update post statuses, export selectively, and manage 301 redirects.
Version: 2.2
Author: Ranked
*/

namespace UrlToIdExporter;

// Prohibit direct access to the file
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Class UrlResolver
 * Responsible for resolving URLs to Post IDs, checking HTTP status codes, and fetching taxonomies.
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
            $post_id       = url_to_postid($sanitized_url);

            if ($post_id) {
                $post = get_post($post_id);
                if ($post) {
                    // Fetch categories and tags as plain text lists
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
 * Handles bulk status modifications (Trash, Draft, Private, Pending, Publish).
 */
class PostManager {
    public function update_statuses(array $post_ids, $new_status) {
        $allowed_statuses = array('trash', 'draft', 'private', 'pending', 'publish');
        if (!in_array($new_status, $allowed_statuses, true)) {
            return 0;
        }

        $updated_count = 0;
        foreach ($post_ids as $id) {
            if ($new_status === 'trash') {
                if (get_post_status($id) !== 'trash') {
                    wp_trash_post($id);
                    $updated_count++;
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
 * Integrates with John Godley's Redirection plugin.
 */
class RedirectManager {
    public function is_active() {
        return defined('REDIRECTION_FILE');
    }

    public function create_hompage_redirects(array $post_ids) {
        if (!$this->is_active()) {
            return 0;
        }

        if (!class_exists('Redirection_Item')) {
            require_once dirname(REDIRECTION_FILE) . '/models/redirect.php';
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

            $result = \Redirection_Item::create(array(
                'url'         => $url_path,
                'action_data' => array('url' => '/'),
                'action_type' => 'url',
                'action_code' => 301,
                'group_id'    => 1,
                'match_type'  => 'url',
            ));

            if (!is_wp_error($result)) {
                $redirects_created++;
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
            set_transient('url_pro_msg', sprintf('%d posts status changed to "%s".', $count, $status), 45);
            wp_redirect(admin_url('admin.php?page=url-manager-pro'));
            exit;
        }

        // Handle Redirects
        if (isset($_POST['create_redirects']) && !empty($_POST['action_ids'])) {
            check_admin_referer('url_to_id_bulk_action', 'url_to_id_nonce');
            $ids = array_map('intval', explode(',', $_POST['action_ids']));
            
            $count = $this->redirect_manager->create_hompage_redirects($ids);
            set_transient('url_pro_msg', sprintf('%d redirect rules to homepage created.', $count), 45);
            wp_redirect(admin_url('admin.php?page=url-manager-pro'));
            exit;
        }
    }

    public function render_page() {
        $urls_input = '';
        $results = null;

        if (isset($_POST['submit_urls']) && isset($_POST['url_to_id_convert_nonce'])) {
            if (wp_verify_nonce($_POST['url_to_id_convert_nonce'], 'url_to_id_convert_action')) {
                $urls_input = esc_textarea($_POST['urls']);
                $urls_array = explode("\n", $_POST['urls']);
                $results    = $this->resolver->resolve_urls($urls_array);
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
                <h2>Analysis Results</h2>

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
                                <th style="width: 60px;">HTTP</th>
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
                                        <span class="badge" style="background: <?php echo $post_data['http_status'] == 200 ? '#e7f4e4' : '#fcf0f0'; ?>; color: <?php echo $post_data['http_status'] == 200 ? '#2e7d32' : '#c62828'; ?>; padding: 3px 6px; border-radius: 3px; font-weight: bold;">
                                            <?php echo esc_html($post_data['http_status']); ?>
                                        </span>
                                    </td>
                                    <td><small><?php echo esc_html($post_data['categories']); ?></small></td>
                                    <td><small><?php echo esc_html($post_data['tags']); ?></small></td>
                                    <td><a href="<?php echo get_edit_post_link($post_data['id']); ?>" target="_blank"><strong><?php echo esc_html($post_data['title']); ?></strong></a></td>
                                    <td><small style="color:#666;"><?php echo esc_html($post_data['url']); ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <h3 style="margin-top: 30px;">Available Actions</h3>
                    
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
                                <p style="color: #d63638; font-weight: bold; margin-bottom: 12px;">⚠️ WARNING: This rule maps paths STRICTLY onto the homepage root structure (/). Modification of destinations requires internal adjustment via Redirection Tools later.</p>
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
                                            <span style="color: #d63638; font-weight: bold;">
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