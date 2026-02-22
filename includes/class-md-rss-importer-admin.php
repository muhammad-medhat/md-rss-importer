<?php

class MD_RSS_Importer_Admin
{

    public static function register_settings()
    {
        register_setting('rtp_settings', MD_RSS_Importer::OPTION_FEED_URL, 'esc_url_raw');
        register_setting('rtp_settings', MD_RSS_Importer::OPTION_CATEGORY);
        register_setting(
            'rtp_settings',
            MD_RSS_Importer::OPTION_FEED_URLS,
            [
                'type' => 'array',
                'sanitize_callback' => [__CLASS__, 'sanitize_feed_urls'],
                'default' => [],
            ]
        );
    }

    public static function add_settings_page()
    {
        add_options_page(
            'RSS to Posts',
            'RSS to Posts',
            'manage_options',
            'rss-to-posts',
            [__CLASS__, 'settings_page']
        );
    }

    public static function settings_page()
    {
        // Handle manual import request
        if (isset($_POST['md_rss_import_now']) && check_admin_referer('md_rss_import_now_action')) {
            do_action('rtp_cron_import');
            add_settings_error('md_rss_importer_messages', 'md_rss_imported', 'Feed imported successfully!', 'updated');
        }

        settings_errors('md_rss_importer_messages');
        ?>
        <div class="wrap">
            <h1>RSS to Posts Importer</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('rtp_settings');
                do_settings_sections('rtp_settings');

                $selected_category = get_option(MD_RSS_Importer::OPTION_CATEGORY);
                $categories = get_categories(['hide_empty' => false]);
                $feeds = get_option(MD_RSS_Importer::OPTION_FEED_URLS, []);
                ?>
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">RSS Feed URL</th>
                        <td>
                            <input type="url" name="<?php echo esc_attr(MD_RSS_Importer::OPTION_FEED_URL); ?>"
                                value="<?php echo esc_attr(get_option(MD_RSS_Importer::OPTION_FEED_URL)); ?>"
                                class="regular-text" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">RSS Feed URLs</th>
                        <td>
                            <!-- <textarea
            name="<?php echo esc_attr(MD_RSS_Importer::OPTION_FEED_URLS); ?>[]" rows="6"
                        cols="50"><?php echo esc_textarea(implode("\n", $feeds)); ?></textarea> -->
                            <textarea name="<?php echo esc_attr(MD_RSS_Importer::OPTION_FEED_URLS); ?>" rows="6"
                                cols="50"><?php echo esc_textarea(implode("\n", $feeds)); ?></textarea>
                            <p class="description">
                                Enter one feed URL per line.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Import Category</th>
                        <td>
                            <select name="<?php echo esc_attr(MD_RSS_Importer::OPTION_CATEGORY); ?>">
                                <option value="">— Select Category —</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo esc_attr($cat->term_id); ?>" <?php selected($selected_category, $cat->term_id); ?>>
                                        <?php echo esc_html($cat->name);
                                        ?>
                                        <!-- <option value="<?php echo esc_attr($cat->term_id); ?>">
                            <?php echo $cat->name ?>
                        </option> -->
                                    <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>

                </table>
                <?php submit_button(); ?>
            </form>
            <hr>

            <!-- Manual Import Now Button -->
            <form method="post">
                <?php wp_nonce_field('md_rss_import_now_action'); ?>
                <?php submit_button('Import Now', 'secondary', 'md_rss_import_now'); ?>
            </form>
        </div>
        <?php
    }
    public function sanitize_feed_urls1($input)
    {
        if (!is_array($input)) {
            return [];
        }

        $clean = [];

        foreach ($input as $url) {
            $url = esc_url_raw(trim($url));

            if (!empty($url)) {
                $clean[] = $url;
            }
        }

        return $clean;
    }
    public static function sanitize_feed_urls($input)
    {
        if (!is_array($input)) {
            $lines = explode("\n", $input);
        } else {
            $lines = $input;
        }

        $clean = [];
        foreach ($lines as $url) {
            $url = esc_url_raw(trim($url));
            if (!empty($url)) {
                $clean[] = $url;
            }
        }

        return $clean;
    }
}