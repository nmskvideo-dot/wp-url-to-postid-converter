# URL to Post ID Advanced Manager

A powerful WordPress admin plugin for analyzing URLs, resolving them to WordPress content, checking HTTP status, exporting selected posts to XML, changing post statuses in bulk, and creating 301 redirects through the Redirection plugin.

## Features

- Analyze a list of URLs line by line
- Resolve URLs to WordPress post IDs using WordPress core logic
- Check HTTP status for each submitted URL and display it with color-coded badges
- Show post title, type, status, categories, tags, and source URL for each matched result
- Export only the selected posts and related media attachments to a filtered WordPress XML file
- Change the status of matched posts in bulk (Trash, Draft, Private, Pending, Publish)
- Create homepage 301 redirect rules through the Redirection plugin when it is active
- Skip duplicate redirect rules automatically to avoid repeated entries
- Generate WP-CLI fallback commands for manual export workflows
- Restrict access to administrators with WordPress nonces and capability checks

## Requirements

- WordPress 5.0+
- PHP 7.0+
- Administrator access
- Optional: Redirection plugin for redirect rule generation

## Installation

1. Upload the plugin folder to your WordPress installation under `wp-content/plugins/`.
2. Activate the plugin from the WordPress admin panel.
3. Open the plugin from the admin menu named “URL Manager Pro”.

## Usage

1. Open the plugin page in the WordPress admin.
2. Paste one URL per line into the input field.
3. Click “Analyze URLs”.
4. Review the matched content and choose one of the available actions:
   - Change post status in bulk
   - Download a filtered XML export file
   - Generate 301 redirect rules in Redirection
   - Use the WP-CLI fallback command for export

## How it works

The plugin uses WordPress core URL resolution through `url_to_postid()` to identify matching posts from the submitted URLs. It also checks each URL response and builds a detailed analysis table for the found content.

## Warning

Bulk status changes and redirect creation are operational actions. Review the selected content carefully before applying them.

## Security

- Uses WordPress nonces for form validation
- Restricts access to users with the `manage_options` capability

## License

This project is distributed under the MIT License.
