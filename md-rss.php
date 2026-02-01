<?php
/**
 * xxxPlugin Name: MD RSS Importer
 * Description: Imports RSS feeds and creates WordPress posts.
 * Version: 1.0.0
 * Author: Muhammad Medhat
 */

if (!defined('ABSPATH')) {
    exit;
}
function rtp_fetch_feed_items()
{

    $feed_url = get_option('rtp_feed_url');

    if (empty($feed_url)) {
        return;
    }

    include_once ABSPATH . WPINC . '/feed.php';

    $rss = fetch_feed($feed_url);

    if (is_wp_error($rss)) {
        return;
    }

    $maxitems = $rss->get_item_quantity(10);
    $items = $rss->get_items(0, $maxitems);

    foreach ($items as $item) {
        rtp_create_post_from_item($item);
    }
}
function rtp_create_post_from_item($item)
{

    $guid = $item->get_id();

    // Prevent duplicates
    $existing = get_posts([
        'meta_key' => 'rtp_rss_guid',
        'meta_value' => $guid,
        'post_type' => 'post',
        'post_status' => 'any',
        'fields' => 'ids'
    ]);

    if (!empty($existing)) {
        return;
    }

    $post_data = [
        'post_title' => wp_strip_all_tags($item->get_title()),
        'post_content' => $item->get_content(),
        'post_status' => 'publish',
        'post_author' => 1,
        'post_type' => 'post',
    ];

    $post_id = wp_insert_post($post_data);

    if (!is_wp_error($post_id)) {
        add_post_meta($post_id, 'rtp_rss_guid', $guid);
        add_post_meta($post_id, 'rtp_rss_source', esc_url_raw($item->get_permalink()));
    }
}
register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled('rtp_cron_import')) {
        wp_schedule_event(time(), 'hourly', 'rtp_cron_import');
    }
});
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('rtp_cron_import');
});
add_action('rtp_cron_import', 'rtp_fetch_feed_items');
add_action('admin_menu', function () {
    add_options_page(
        'RSS to Posts',
        'RSS to Posts',
        'manage_options',
        'rss-to-posts',
        'rtp_settings_page'
    );
});
function rtp_settings_page()
{
    ?>
<div class="wrap">
    <h1>RSS to Posts Importer</h1>

    <form method="post" action="options.php">
        <?php
            settings_fields('rtp_settings');
            do_settings_sections('rtp_settings');
            ?>

        <table class="form-table">
            <tr>
                <th scope="row">RSS Feed URL</th>
                <td>
                    <input type="url" name="rtp_feed_url" value="<?php echo esc_attr(get_option('rtp_feed_url')); ?>"
                        class="regular-text">
                </td>
            </tr>
        </table>

        <?php submit_button(); ?>
    </form>
</div>
<?php
}
add_action('admin_init', function () {
    register_setting('rtp_settings', 'rtp_feed_url', 'esc_url_raw');
});