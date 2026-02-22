<?php

class MD_RSS_Importer
{

    const OPTION_FEED_URL = 'rtp_feed_url';
    const CRON_HOOK = 'rtp_cron_import';
    const OPTION_CATEGORY = 'rtp_import_category';

    // Define the standard "ID" for media
    const MRSS_namespace = 'http://search.yahoo.com/mrss/';


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
            $this->create_post_from_item($item, $feed_url);
        }
    }

    private function create_post_from_item($item, $feed_url)
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
        $category_id = (int) get_option(self::OPTION_CATEGORY);
        $post_data = [
            'post_title' => wp_strip_all_tags($item->get_title()),
            'post_content' => $item->get_content(),
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_author' => 1,
        ];
        if ($category_id) {
            $post_data['post_category'] = [$category_id];
        }
        $post_id = wp_insert_post($post_data);

        if (!is_wp_error($post_id)) {
            add_post_meta($post_id, 'rtp_rss_guid', $guid);
            add_post_meta($post_id, 'rtp_rss_source', esc_url_raw($item->get_permalink()));
            // 🔍 Try to extract image URL
            // $image_url = $this->get_image_url_from_item($item);
            $image_url = $this->get_image_url_from_item($item, $feed_url);

            if ($image_url) {
                $this->attach_featured_image($image_url, $post_id);
            }
        }
    }

    private function get_image_url_from_item1($item)
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
    private function get_image_url_from_item($item, $feed_url = '')
    {

        $log_prefix = '[MD_RSS] ';
        $images = $this->collect_images_from_item($item, $feed_url);

        if (empty($images)) {
            return false;
        }

        $best = $this->select_best_image($images);

        return $best ? $best['url'] : false;
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
    private function collect_images_from_item($item, $feed_url)
    {
        $images = [];
        $log_prefix = '[MD_RSS] ';

        // 1️⃣ media:content
        // it is because yahoo made this rss spec
        //  This specific URL is the standard identifier 
        //  for Media RSS. When you see a tag in a feed 
        //  like <media:content>, the media prefix refers to this URL.
        $media = $item->get_item_tags(
            self::MRSS_namespace,
            'content'
        );

        if (!empty($media)) {
            foreach ($media as $m) {
                $attrs = $m['attribs'][''] ?? [];
                if (empty($attrs['url']))
                    continue;

                $images[] = [
                    'url' => $this->normalize_url($attrs['url'], $feed_url),
                    'width' => (int) ($attrs['width'] ?? 0),
                    'height' => (int) ($attrs['height'] ?? 0),
                    'type' => $attrs['type'] ?? '',
                    'source' => 'media:content',
                ];
            }
            error_log($log_prefix . 'Found media:content URL: ' . $media[0]['attribs']['']['url']);
        }

        // 2️⃣ media:thumbnail
        $thumbs = $item->get_item_tags(
            self::MRSS_namespace,
            'thumbnail'
        );

        if (!empty($thumbs)) {
            foreach ($thumbs as $t) {
                $attrs = $t['attribs'][''] ?? [];
                if (empty($attrs['url']))
                    continue;

                $images[] = [
                    'url' => $this->normalize_url($attrs['url'], $feed_url),
                    'width' => (int) ($attrs['width'] ?? 0),
                    'height' => (int) ($attrs['height'] ?? 0),
                    'type' => '',
                    'source' => 'media:thumbnail',
                ];
            }
            error_log($log_prefix . 'Found media:thumbnail URL: ' . $thumbs[0]['attribs']['']['url']);
        }

        // 3️⃣ enclosure
        $enclosure = $item->get_enclosure();
        if ($enclosure) {
            $type = $enclosure->get_type();
            $link = $enclosure->get_link();
            $images[] = [
                'url' => $this->normalize_url($enclosure->get_link(), $feed_url),
                'width' => 0,
                'height' => 0,
                'type' => $type,
                'source' => 'enclosure',
            ];
            error_log($log_prefix . 'Found enclosure thumbnail URL: ' . $enclosure->get_thumbnail());

        }

        // 4️⃣ <img> داخل المحتوى
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/', $item->get_content(), $m)) {
            $images[] = [
                'url' => $this->normalize_url($m[1], $feed_url),
                'width' => 0,
                'height' => 0,
                'type' => '',
                'source' => 'content',
            ];
            error_log($log_prefix . 'Found content image URL: ' . $m[1]);
        }
        if (empty($images)) {
            error_log($log_prefix . 'No images found in feed item.');
        }

        return $images;
    }
    private function normalize_url($url, $feed_url)
    {
        if (wp_http_validate_url($url)) {
            return esc_url_raw($url);
        }

        $base = wp_parse_url($feed_url);
        if (empty($base['scheme']) || empty($base['host'])) {
            return '';
        }
        if ($url) {
            return esc_url_raw(
                $base['scheme'] . '://' . $base['host'] . '/' . ltrim($url, '/')
            );
        }

    }
    private function score_image($img)
    {
        $score = 0;

        switch ($img['source']) {
            case 'media:content':
                $score += 40;
                break;
            case 'media:thumbnail':
                $score += 20;
                break;
            case 'enclosure':
                $score += 15;
                break;
            case 'content':
                $score += 10;
                break;
        }

        if ($img['width'] >= 1200) {
            $score += 30;
        } elseif ($img['width'] >= 800) {
            $score += 20;
        } elseif ($img['width'] >= 400) {
            $score += 10;
        } else {
            $score -= 30;
        }

        if (isset($img['type']) && strpos($img['type'], 'image/') === 0) {
            $score += 5;
        }

        if (preg_match('/logo|icon|avatar/i', $img['url'])) {
            $score -= 50;
        }

        return apply_filters(
            'md_rss_image_score',
            $score,
            $img
        );
    }
    private function select_best_image($images)
    {
        $best = null;
        $best_score = -INF;

        foreach ($images as $img) {
            if (empty($img['url']))
                continue;

            $score = $this->score_image($img);

            if ($score > $best_score) {
                $best_score = $score;
                $best = $img;
            }
        }

        // return ($best_score > 0) ? $best : null;
        return $best;
    }

}