<?php
/**
 * Plugin Name: Heavenhold Helpers
 * Description: Plugin to create custom database tables with WPGraphQL
 * Version: 1.0
 * Author: Sumit Kukreja
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'includes/graphql-team-votes.php';
require_once plugin_dir_path(__FILE__) . 'includes/graphql-hero-votes.php';