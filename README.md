# URL to Post ID Converter, Exporter & Trash Manager

A lightweight WordPress admin plugin that converts a list of URLs into post IDs, exports the selected posts to a filtered WordPress XML file, moves them to Trash, and can create Redirection rules for them.

## Features

- Convert one or more URLs into WordPress post IDs
- Process bulk input with one URL per line
- Download a filtered XML export containing only the selected posts and their media attachments
- Move all matched posts directly to the WordPress Trash
- Create 301 redirect rules to the homepage through the Redirection plugin (when available)
- Generate WP-CLI fallback commands for export and trash actions
- Restrict access to administrators with WordPress nonce protection

## Requirements

- WordPress 5.0+
- PHP 7.0+
- Administrator access
- Optional: Redirection plugin for redirect rule creation

## Installation

1. Upload the plugin folder to your WordPress installation under `wp-content/plugins/`.
2. Activate the plugin from the WordPress admin panel.
3. Open the plugin from the admin menu named “URL to Post ID”.

## Usage

1. Open the plugin page in the WordPress admin.
2. Paste one URL per line into the text area.
3. Click “Convert URLs”.
4. Choose one of the available actions:
   - Download a filtered XML export file
   - Move the matched posts to Trash
   - Create 301 redirects in Redirection (if the plugin is active)
   - Copy the generated WP-CLI fallback commands

## How it works

The plugin uses WordPress core URL resolution through `url_to_postid()` to identify matching posts from the provided URLs. When you export the results, the plugin narrows the export query to the selected post IDs and their attachments so the export stays focused on the intended content.

## Warning

The Trash and Redirection actions are destructive or redirect-related operations. Please review the selected posts carefully before using them.

## Security

- Uses WordPress nonces for form validation
- Restricts access to users with the `manage_options` capability

## License

This project is distributed under the MIT License.
