<?php
/**
 * Plugin Name: UM Songs Played Manager
 * Description: Ultimate Member "Songs I Play" field: Select2 search, JSON storage, profile table renderer, seed tools, and SongDrop webhook.
 * Version: 1.0.1
 * Author: Greg Percifield
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

if ( ! defined('FNF_UM_SONGS_PLAYED_VERSION') ) {
    define('FNF_UM_SONGS_PLAYED_VERSION', '1.0.1');
}

if ( ! defined('FNF_UM_SONGS_PLAYED_DIR') ) {
    define('FNF_UM_SONGS_PLAYED_DIR', plugin_dir_path(__FILE__));
}

if ( ! defined('FNF_UM_SONGS_PLAYED_URL') ) {
    define('FNF_UM_SONGS_PLAYED_URL', plugin_dir_url(__FILE__));
}

if ( ! defined('FNF_UM_SONGS_PLAYED_OPTION_VERSION') ) {
    define('FNF_UM_SONGS_PLAYED_OPTION_VERSION', 'fnf_um_songs_played_version');
}

if ( ! defined('FNF_SONGDROP_WEBHOOK_URL') ) {
    define('FNF_SONGDROP_WEBHOOK_URL', '');
}

if ( ! defined('FNF_SONGDROP_WEBHOOK_KEY') ) {
    define('FNF_SONGDROP_WEBHOOK_KEY', '');
}

if ( ! defined('FNF_UM_SONGS_PLAYED_ENVIRONMENT') ) {
    define('FNF_UM_SONGS_PLAYED_ENVIRONMENT', wp_get_environment_type());
}

function fnf_um_songs_played_activate() {
    update_option(FNF_UM_SONGS_PLAYED_OPTION_VERSION, FNF_UM_SONGS_PLAYED_VERSION);
}
register_activation_hook(__FILE__, 'fnf_um_songs_played_activate');

function fnf_um_songs_played_maybe_upgrade() {
    $stored_version = get_option(FNF_UM_SONGS_PLAYED_OPTION_VERSION, '');

    if ($stored_version !== FNF_UM_SONGS_PLAYED_VERSION) {
        update_option(FNF_UM_SONGS_PLAYED_OPTION_VERSION, FNF_UM_SONGS_PLAYED_VERSION);
    }
}
add_action('plugins_loaded', 'fnf_um_songs_played_maybe_upgrade');

function fnf_um_songs_played_um_missing_notice() {
    if (class_exists('UM')) {
        return;
    }

    echo '<div class="notice notice-error"><p>';
    echo esc_html('FNF UM Songs Played requires Ultimate Member to be installed and active.');
    echo '</p></div>';
}
add_action('admin_notices', 'fnf_um_songs_played_um_missing_notice');

$inc = FNF_UM_SONGS_PLAYED_DIR . 'includes/songs-played.php';

if ( file_exists($inc) ) {
    require_once $inc;
} else {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo esc_html('FNF UM Songs Played: Missing file includes/songs-played.php');
        echo '</p></div>';
    });
}
