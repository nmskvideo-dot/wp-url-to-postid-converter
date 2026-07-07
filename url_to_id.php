<?php
/*
Plugin Name: URL to Post ID Converter & Exporter
Description: Converts a list of URLs to post IDs, generates WP-CLI commands, and exports chosen posts to XML.
Version: 1.2
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

// Handle native WordPress export if requested before any HTML output
add_action('admin_init', 'url_to_postid_handle_export');
function url_to_postid_handle_export() {
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

        // Include WordPress export API
        require_once ABSPATH . 'wp-admin/includes/export.php';

        $filename = 'wordpress-custom-export-' . date('Y-m-d-H-i-s') . '.xml';

        header('Content-Description: File Transfer');
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Content-Type: text/xml; charset=' . get_option('blog_charset'), true);

        // Run native WP export filtered by post IDs
        export_wp(array(
            'post__in' => $post_ids,
            'content'  => 'all'
        ));
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

            <div class="card">
                <h3>Option 1: Direct XML Export</h3>
                <p>Click the button below to download a standard WordPress export file containing only the identified posts.</p>
                <form method="post" action="">
                    <?php wp_nonce_field('url_to_id_export_action', 'url_to_id_nonce'); ?>
                    <input type="hidden" name="export_ids" value="<?php echo esc_attr(implode(',', $post_ids)); ?>">
                    <input type="submit" name="download_export" class="button button-secondary" value="Download .xml Export File">
                </form>
            </div>

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