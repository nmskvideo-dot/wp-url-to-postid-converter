<?php
/*
Plugin Name: URL to Post ID Converter, Exporter & Trash Manager
Description: Converts a list of URLs to post IDs, exports them to XML, moves them to trash, and integrates with Redirection plugin.
Version: 1.5
Author: Ranked
*/

// Prohibit direct access to the file
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Add new menu to the admin panel
add_action('admin_menu', 'url_to_postid_menu');
function url_to_postid_menu() {
    add_menu_page(
        'URL to Post ID',
        'URL to Post ID',
        'manage_options',
        'url-to-postid',
        'url_to_postid_page'
    );
}

// Global variable to pass target IDs to the query filter
global $url_to_id_target_posts;
$url_to_id_target_posts = array();

// Filter to restrict export query strictly to selected IDs and their attachments
add_filter('query', 'url_to_id_restrict_export_query');
function url_to_id_restrict_export_query($query) {
    global $wpdb, $url_to_id_target_posts;

    if (!empty($url_to_id_target_posts) && strpos($query, "SELECT ID FROM {$wpdb->posts}") !== false && strpos($query, "auto-draft") !== false) {
        $ids_string = implode(',', array_map('intval', $url_to_id_target_posts));
        $query = "SELECT ID FROM {$wpdb->posts} WHERE (ID IN ($ids_string) OR (post_type = 'attachment' AND post_parent IN ($ids_string))) AND post_status != 'auto-draft'";
    }
    return $query;
}

// Handle native WordPress export and custom actions before any HTML output
add_action('admin_init', 'url_to_postid_handle_actions');
function url_to_postid_handle_actions() {
    global $url_to_id_target_posts;

    // 1. Handle XML Export
    if (isset($_POST['download_export']) && !empty($_POST['export_ids'])) {
        check_admin_referer('url_to_id_export_action', 'url_to_id_nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized access.');
        }

        $post_ids = array_map('intval', explode(',', $_POST['export_ids']));
        $post_ids = array_filter($post_ids);

        if (!empty($post_ids)) {
            $url_to_id_target_posts = $post_ids;
            require_once ABSPATH . 'wp-admin/includes/export.php';

            $filename = 'wordpress-filtered-export-' . date('Y-m-d-H-i-s') . '.xml';
            header('Content-Description: File Transfer');
            header('Content-Disposition: attachment; filename=' . $filename);
            header('Content-Type: text/xml; charset=' . get_option('blog_charset'), true);

            export_wp(array('content' => 'all'));
            exit;
        }
    }

    // 2. Handle Trash action
    if (isset($_POST['trash_all_posts']) && !empty($_POST['action_ids'])) {
        check_admin_referer('url_to_id_trash_action', 'url_to_id_trash_nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized access.');
        }

        $post_ids = array_map('intval', explode(',', $_POST['action_ids']));
        $trashed_count = 0;

        foreach ($post_ids as $id) {
            if (get_post_status($id) !== 'trash') {
                wp_trash_post($id);
                $trashed_count++;
            }
        }

        set_transient('url_to_id_success_msg', sprintf('%d posts successfully moved to Trash.', $trashed_count), 45);
        wp_redirect(add_query_arg(array('page' => 'url-to-postid'), admin_url('admin_page.php')));
        exit;
    }

    // 3. Handle Redirection plugin integration
    if (isset($_POST['create_redirection_rules']) && !empty($_POST['action_ids'])) {
        check_admin_referer('url_to_id_redirect_action', 'url_to_id_redirect_nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized access.');
        }

        // Manually load Redirection models if they are not globally loaded yet
        if (defined('REDIRECTION_FILE') && !class_exists('Redirection_Item')) {
            require_once dirname(REDIRECTION_FILE) . '/models/redirect.php';
        }

        if (!class_exists('Redirection_Item')) {
            wp_die('Redirection API models could not be loaded.');
        }

        $post_ids = array_map('intval', explode(',', $_POST['action_ids']));
        $redirects_created = 0;

        foreach ($post_ids as $id) {
            $permalink = get_permalink($id);
            if (!$permalink) {
                continue;
            }

            // Extract relative URI path for Redirection matching
            $url_path = wp_make_link_relative($permalink);

            // Avoid loop if it's already pointing to home
            if ($url_path === '/' || empty($url_path)) {
                continue;
            }

            // Create redirect rule to the homepage using Redirection API
            $result = Redirection_Item::create(array(
                'url'         => $url_path,
                'action_data' => array('url' => '/'),
                'action_type' => 'url',
                'action_code' => 301,
                'group_id'    => 1, // Default group ID in Redirection plugin
                'match_type'  => 'url',
            ));

            if (!is_wp_error($result)) {
                $redirects_created++;
            }
        }

        set_transient('url_to_id_success_msg', sprintf('%d 301 redirect rules to homepage created in Redirection plugin.', $redirects_created), 45);
        wp_redirect(add_query_arg(array('page' => 'url-to-postid'), admin_url('admin_page.php')));
        exit;
    }
}

// Settings page layout
function url_to_postid_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized access.');
    }

    // Display transient success messages if any
    $success_msg = get_transient('url_to_id_success_msg');
    if ($success_msg) {
        delete_transient('url_to_id_success_msg');
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($success_msg) . '</p></div>';
    }

    $post_ids = array();
    $urls_input = '';

    if (isset($_POST['submit']) && isset($_POST['url_to_id_convert_nonce'])) {
        if (wp_verify_nonce($_POST['url_to_id_convert_nonce'], 'url_to_id_convert_action')) {
            $urls_input = esc_textarea($_POST['urls']);
            $urls = explode("\n", $_POST['urls']);
            
            foreach ($urls as $url) {
                $url = trim($url);
                if (empty($url)) {
                    continue;
                }

                $sanitized_url = esc_url_raw($url);
                $post_id = url_to_postid($sanitized_url);
                
                if ($post_id) {
                    $post_ids[] = intval($post_id);
                }
            }
            $post_ids = array_unique(array_filter($post_ids));
        }
    }

    // WP-CLI dynamic path setup
    $wp_path = rtrim(ABSPATH, '/');
    $export_dir = dirname($wp_path) . '/tmp/';
    if (!is_dir($export_dir)) {
        $export_dir = $wp_path . '/wp-content/uploads/';
    }

    // Check if the Redirection plugin is active via its global constant
    $is_redirection_active = defined('REDIRECTION_FILE');
    ?>
    <div class="wrap">
        <h1>URL to Post ID Converter & Exporter</h1>
        
        <form method="post" action="">
            <?php wp_nonce_field('url_to_id_convert_action', 'url_to_id_convert_nonce'); ?>
            <p><label for="urls">Enter a list of URLs (one per line):</label></p>
            <textarea id="urls" name="urls" rows="10" cols="80" placeholder="https://example.com/post-title/" class="large-text"><?php echo $urls_input; ?></textarea>
            <br>
            <?php submit_button('Convert URLs', 'primary', 'submit'); ?>
        </form>

        <?php if (!empty($post_ids)) : ?>
            <hr>
            <h2>Results & Actions</h2>
            
            <div class="notice notice-success inline">
                <p><strong>Found Post IDs (Total: <?php echo count($post_ids); ?>):</strong></p>
                <p><code><?php echo esc_html(implode(', ', $post_ids)); ?></code></p>
            </div>

            <div style="display: flex; flex-wrap: wrap; gap: 20px; margin-top: 20px;">
                <div class="card" style="flex: 1; min-width: 280px; margin: 0;">
                    <h3>Option 1: Direct XML Export</h3>
                    <p>Download a standard WordPress export file containing ONLY the identified posts and their media.</p>
                    <form method="post" action="">
                        <?php wp_nonce_field('url_to_id_export_action', 'url_to_id_nonce'); ?>
                        <input type="hidden" name="export_ids" value="<?php echo esc_attr(implode(',', $post_ids)); ?>">
                        <input type="submit" name="download_export" class="button button-secondary" value="Download .xml Export File">
                    </form>
                </div>

                <div class="card" style="flex: 1; min-width: 280px; margin: 0;">
                    <h3>Option 2: Bulk Trash Manager</h3>
                    <p>Move all identified posts directly to the WordPress Trash container.</p>
                    <form method="post" action="" onsubmit="return confirm('Are you sure you want to move all these posts to Trash?');">
                        <?php wp_nonce_field('url_to_id_trash_action', 'url_to_id_trash_nonce'); ?>
                        <input type="hidden" name="action_ids" value="<?php echo esc_attr(implode(',', $post_ids)); ?>">
                        <input type="submit" name="trash_all_posts" class="button button-link-delete" style="color: #b32d2e; border: 1px solid #b32d2e; padding: 4px 12px; text-decoration: none; border-radius: 3px;" value="Move All to Trash">
                    </form>
                </div>

                <div class="card" style="flex: 1; min-width: 280px; margin: 0;">
                    <h3>Option 3: Redirection Plugin Integration</h3>
                    <?php if ($is_redirection_active) : ?>
                        <p style="color: #d63638; font-weight: bold; margin-bottom: 15px;">
                            ⚠️ ATTENTION: This action creates permanent 301 redirects pointing STRICTLY to the homepage (/). If you need custom URLs, edit them manually inside Redirection settings later.
                        </p>
                        <form method="post" action="">
                            <?php wp_nonce_field('url_to_id_redirect_action', 'url_to_id_redirect_nonce'); ?>
                            <input type="hidden" name="action_ids" value="<?php echo esc_attr(implode(',', $post_ids)); ?>">
                            <input type="submit" name="create_redirection_rules" class="button button-primary" value="Create 301 Redirects to Home">
                        </form>
                    <?php else : ?>
                        <p style="color: #646970; font-style: italic;">
                            Redirection plugin by John Godley is not detected. Activate it to enable automatic 301 rules generation.
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card" style="margin-top: 20px;">
                <h3>Option 4: WP-CLI Backup Commands</h3>
                <p>Fallback commands for terminal if you prefer manual background execution:</p>
                <h4>Export Command:</h4>
                <pre style="background: #f0f0f0; padding: 10px; overflow-x: auto;"><code>wp export --dir='<?php echo esc_attr($export_dir); ?>' --path='<?php echo esc_attr($wp_path); ?>' --post__in=<?php echo esc_html(implode(',', $post_ids)); ?></code></pre>
                <h4>Trash Command:</h4>
                <pre style="background: #f0f0f0; padding: 10px; overflow-x: auto;"><code>wp post update <?php echo esc_html(implode(' ', $post_ids)); ?> --path='<?php echo esc_attr($wp_path); ?>' --post_status=trash</code></pre>
            </div>
        <?php elseif (isset($_POST['submit'])) : ?>
            <div class="notice notice-error inline">
                <p>No valid Post IDs were found for the provided URLs.</p>
            </div>
        <?php endif; ?>
    </div>
    <?php
}