<?php
/*
Plugin Name: URL to Post ID Converter & Exporter
Description: Converts a list of URLs to post IDs, generates WP-CLI commands, and exports chosen posts safely to XML.
Version: 1.3
Author: Ranked - Roman P
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

    // Apply filter only during our custom export process when IDs are provided
    if (!empty($url_to_id_target_posts) && strpos($query, "SELECT ID FROM {$wpdb->posts}") !== false && strpos($query, "auto-draft") !== false) {
        $ids_string = implode(',', array_map('intval', $url_to_id_target_posts));
        
        // Rewrite query to fetch only requested posts OR attachments belonging to them
        $query = "SELECT ID FROM {$wpdb->posts} WHERE (ID IN ($ids_string) OR (post_type = 'attachment' AND post_parent IN ($ids_string))) AND post_status != 'auto-draft'";
    }
    return $query;
}

// Handle native WordPress export if requested before any HTML output
add_action('admin_init', 'url_to_postid_handle_export');
function url_to_postid_handle_export() {
    global $url_to_id_target_posts;

    if (isset($_POST['download_export']) && !empty($_POST['export_ids'])) {
        // Verify nonce for security
        check_admin_referer('url_to_id_export_action', 'url_to_id_nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized access.');
        }

        $post_ids = array_map('intval', explode(',', $_POST['export_ids']));
        $post_ids = array_filter($post_ids);

        if (empty($post_ids)) {
            return;
        }

        // Set global IDs for the query hook to intercept
        $url_to_id_target_posts = $post_ids;

        // Include WordPress export API
        require_once ABSPATH . 'wp-admin/includes/export.php';

        $filename = 'wordpress-filtered-export-' . date('Y-m-d-H-i-s') . '.xml';

        header('Content-Description: File Transfer');
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Content-Type: text/xml; charset=' . get_option('blog_charset'), true);

        // Run export; the query filter above will now restrict it strictly to our IDs
        export_wp(array('content' => 'all'));
        exit;
    }
}

// Settings page layout
function url_to_postid_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized access.');
    }

    $post_ids = array();
    $urls_input = '';

    // Handle form submission safely
    if (isset($_POST['submit']) && isset($_POST['url_to_id_convert_nonce'])) {
        // Verify nonce for conversion form
        if (wp_verify_nonce($_POST['url_to_id_convert_nonce'], 'url_to_id_convert_action')) {
            $urls_input = esc_textarea($_POST['urls']);
            $urls = explode("\n", $_POST['urls']);
            
            foreach ($urls as $url) {
                $url = trim($url);
                if (empty($url)) {
                    continue;
                }

                // Sanitize URL before passing to url_to_postid
                $sanitized_url = esc_url_raw($url);
                $post_id = url_to_postid($sanitized_url);
                
                if ($post_id) {
                    $post_ids[] = intval($post_id);
                }
            }
            
            // Remove duplicates and filter empty values
            $post_ids = array_unique(array_filter($post_ids));
        }
    }

    // Automatically detect server root path for WP-CLI
    $wp_path = rtrim(ABSPATH, '/');
    $export_dir = dirname($wp_path) . '/tmp/'; // Fallback to sibling tmp directory
    if (!is_dir($export_dir)) {
        $export_dir = $wp_path . '/wp-content/uploads/'; // Fallback if general tmp is unavailable
    }
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
            <h2>Results</h2>
            
            <div class="notice notice-success inline">
                <p><strong>Found Post IDs (Total: <?php echo count($post_ids); ?>):</strong></p>
                <p><code><?php echo esc_html(implode(', ', $post_ids)); ?></code></p>
            </div>

            <!-- Native WP Export Button -->
            <div class="card">
                <h3>Option 1: Direct XML Export</h3>
                <p>Click the button below to download a standard WordPress export file containing ONLY the identified posts and their media.</p>
                <form method="post" action="">
                    <?php wp_nonce_field('url_to_id_export_action', 'url_to_id_nonce'); ?>
                    <input type="hidden" name="export_ids" value="<?php echo esc_attr(implode(',', $post_ids)); ?>">
                    <input type="submit" name="download_export" class="button button-secondary" value="Download .xml Export File">
                </form>
            </div>

            <!-- WP-CLI Commands Section with Auto-detected Paths -->
            <div class="card">
                <h3>Option 2: WP-CLI Commands</h3>
                <p>If you prefer running this via terminal, use the dynamically generated commands below:</p>
                
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