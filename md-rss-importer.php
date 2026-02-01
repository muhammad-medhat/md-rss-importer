<?php
/**
 * Plugin Name: MD RSS Importer
 * Description: Imports RSS feeds and creates WordPress posts.
 * Version: 1.1.0
 * Author: Muhammad Medhat
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MD_RSS_IMPORTER_PATH', plugin_dir_path(__FILE__));
define('MD_RSS_IMPORTER_URL', plugin_dir_url(__FILE__));

require_once MD_RSS_IMPORTER_PATH . 'includes/class-md-rss-importer.php';
require_once MD_RSS_IMPORTER_PATH . 'includes/class-md-rss-importer-admin.php';

function md_rss_importer_init()
{
    $importer = new MD_RSS_Importer();
    $importer->init();
}
add_action('plugins_loaded', 'md_rss_importer_init');