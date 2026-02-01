<?php

class MD_RSS_Importer_Admin
{

    public static function register_settings()
    {
        register_setting('rtp_settings', 'rtp_feed_url', 'esc_url_raw');
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
                ?>
        <table class="form-table">
            <tr>
                <th scope="row">RSS Feed URL</th>
                <td>
                    <input type="url" name="rtp_feed_url" value="<?php echo esc_attr(get_option('rtp_feed_url')); ?>"
                        class="regular-text" required>
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
}