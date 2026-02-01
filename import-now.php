<?php
require_once('../../../wp-load.php'); // loading the wp lib
do_action('rtp_cron_import');
echo "Import run!";