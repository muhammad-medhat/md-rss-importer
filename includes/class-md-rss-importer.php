<?php

class MD_RSS_Importer
{

    const OPTION_FEED_URL = 'rtp_feed_url';
    const CRON_HOOK = 'rtp_cron_import';

    public function init()
    {
        // Cron job
        add_action(self::CRON_HOOK, [$this, 'import_feed']);

        // Activation/deactivation hooks
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        // Admin
        add_action('admin_init', ['MD_RSS_Importer_Admin', 'register_settings']);
        add_action('admin_menu', ['MD_RSS_Importer_Admin', 'add_settings_page']);
    }

    public function activate()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'hourly', self::CRON_HOOK);
        }
    }

    public function deactivate()
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public function import_feed()
    {
        $feed_url = get_option(self::OPTION_FEED_URL);

        if (empty($feed_url)) {
            return;
        }

        include_once ABSPATH . WPINC . '/feed.php';
        $rss = fetch_feed($feed_url);

        if (is_wp_error($rss)) {
            // Optional: log error
            return;
        }

        $items = $rss->get_items(0, 10);

        foreach ($items as $item) {
            $this->create_post_from_item($item);
        }
    }

    private function create_post_from_item($item)
    {
        $guid = $item->get_id();

        // Check for duplicates
        $existing = get_posts([
            'post_type' => 'post',
            'post_status' => 'any',
            'meta_key' => 'rtp_rss_guid',
            'meta_value' => $guid,
            'fields' => 'ids',
        ]);

        if (!empty($existing)) {
            return;
        }

        $post_data = [
            'post_title' => wp_strip_all_tags($item->get_title()),
            'post_content' => $item->get_content(),
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_author' => 1,
        ];

        $post_id = wp_insert_post($post_data);

        if (!is_wp_error($post_id)) {
            add_post_meta($post_id, 'rtp_rss_guid', $guid);
            add_post_meta($post_id, 'rtp_rss_source', esc_url_raw($item->get_permalink()));
            // 🔍 Try to extract image URL
            $image_url = $this->get_image_url_from_item($item);

            if ($image_url) {
                $this->attach_featured_image($image_url, $post_id);
            }
        }
    }

    private function get_image_url_from_item($item)
    {
        $log_prefix = '[MD_RSS] ';

        // 1. Check <media:content>
        $media = $item->get_item_tags('', 'media:content');
        if (!empty($media[0]['attribs']['']) && !empty($media[0]['attribs']['']['url'])) {
            error_log($log_prefix . 'Found media:content URL: ' . $media[0]['attribs']['']['url']);
            return esc_url_raw($media[0]['attribs']['']['url']);
        }

        // 2. Check <enclosure>
        $enclosure = $item->get_enclosure();
        if ($enclosure && $enclosure->get_thumbnail()) {
            error_log($log_prefix . 'Found enclosure thumbnail URL: ' . $enclosure->get_thumbnail());
            return esc_url_raw($enclosure->get_thumbnail());
        }
        // 3. <media:thumbnail>
        $thumbnail = $item->get_item_tags('', 'media:thumbnail');
        if (!empty($thumbnail[0]['attribs']['']) && !empty($thumbnail[0]['attribs']['']['url'])) {
            $url = esc_url_raw($thumbnail[0]['attribs']['']['url']);
            error_log($log_prefix . 'Found media:thumbnail URL: ' . $url);
            return $url;
        }

        // 4. Try to parse first image from content
        if (preg_match('/<img.*?src=["\'](.*?)["\']/', $item->get_content(), $matches)) {
            error_log($log_prefix . 'Found img in content: ' . $matches[1]);
            return esc_url_raw($matches[1]);
        }

        error_log($log_prefix . 'No image found in feed item.');
        return false;
    }
    private function attach_featured_image($image_url, $post_id)
    {
        // Load necessary files for sideloading
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Download image and attach
        $image_id = media_sideload_image($image_url, $post_id, null, 'id');

        if (!is_wp_error($image_id)) {
            set_post_thumbnail($post_id, $image_id);
        }
    }
}