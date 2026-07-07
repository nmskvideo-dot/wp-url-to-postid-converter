# URL to Post ID Converter & Exporter

A lightweight WordPress admin plugin that converts a list of URLs into post IDs, generates WP-CLI commands, and exports selected posts to a standard WordPress XML export file.

## Features

- Convert one or more URLs into WordPress post IDs
- Process bulk input with one URL per line
- Generate WP-CLI export and trash commands
- Download a custom XML export containing only the selected posts
- Restrict access to administrators with WordPress nonce protection

## Requirements

- WordPress 5.0+
- PHP 7.0+
- Administrator access

## Installation

1. Upload the plugin folder to your WordPress installation under `wp-content/plugins/`.
2. Activate the plugin from the WordPress admin panel.
3. Open the plugin from the admin menu named “URL to Post ID”.

## Usage

1. Open the plugin page in the WordPress admin.
2. Paste one URL per line into the text area.
3. Click “Convert URLs”.
4. Use one of the available actions:
   - Download an XML export file
   - Copy the generated WP-CLI commands

## Security

- Uses WordPress nonces for form validation
- Restricts access to users with the `manage_options` capability

## Notes

The plugin uses WordPress core URL resolution through `url_to_postid()` to identify matching posts from provided URLs.

## License

This project is distributed under the MIT License.
