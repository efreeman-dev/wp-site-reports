<?php
/**
 * Plugin Name: WP Site Reports
 * Description: Logs all plugin, theme, and core updates (including Smart Plugin Manager / WP Engine)
 *              and generates a formatted monthly client email report with optional auto-send.
 * Version:     2.0.6
 * Author:      EF
 * License:     GPL2
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Test 2.0.6
// ─────────────────────────────────────────────
// 1. CONSTANTS 
// ─────────────────────────────────────────────
define( 'WPUP_TABLE',           $GLOBALS['wpdb']->prefix . 'update_reporter_log' );
define( 'WPUP_SNAPSHOT',        'wpup_version_snapshot' );
define( 'WPUP_CRON',            'wpup_daily_snapshot_check' );
define( 'WPUP_SEND_CRON',       'wpup_monthly_send_check' );
define( 'WPUP_EMAIL_LOG_TABLE', $GLOBALS['wpdb']->prefix . 'update_reporter_email_log' );

// ─────────────────────────────────────────────
// 2. ACTIVATION / DEACTIVATION
// ─────────────────────────────────────────────
register_activation_hook( __FILE__, 'wpup_activate' );
function wpup_activate() {
    global $wpdb;
    wpup_create_table();
    wpup_create_email_log_table();
    wpup_save_snapshot();
    wpup_schedule_cron();
    wpup_schedule_send_cron();
    // Remove any previously logged entries for this plugin itself
    $wpdb->delete( WPUP_TABLE, array( 'slug' => 'wp-site-reports' ), array( '%s' ) );
}

register_deactivation_hook( __FILE__, 'wpup_deactivate' );
function wpup_deactivate() {
    foreach ( array( WPUP_CRON, WPUP_SEND_CRON ) as $hook ) {
        $ts = wp_next_scheduled( $hook );
        if ( $ts ) wp_unschedule_event( $ts, $hook );
    }
}

// ─────────────────────────────────────────────
// 3. TABLE CREATION
// ─────────────────────────────────────────────
function wpup_create_table() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS " . WPUP_TABLE . " (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        update_type VARCHAR(20)  NOT NULL,
        action_type VARCHAR(20)  NOT NULL,
        name        VARCHAR(255) NOT NULL,
        slug        VARCHAR(255) NOT NULL,
        old_version VARCHAR(50)  DEFAULT '',
        new_version VARCHAR(50)  DEFAULT '',
        updated_by  BIGINT       DEFAULT 0,
        updated_at  DATETIME     NOT NULL,
        source      VARCHAR(50)  DEFAULT 'hook',
        PRIMARY KEY (id)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

function wpup_create_email_log_table() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS " . WPUP_EMAIL_LOG_TABLE . " (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        sent_at      DATETIME     NOT NULL,
        trigger_type VARCHAR(20)  NOT NULL,
        status       VARCHAR(20)  NOT NULL,
        sender_name  VARCHAR(255) DEFAULT '',
        sender_email VARCHAR(255) DEFAULT '',
        recipients   TEXT         DEFAULT '',
        report_from  DATE         DEFAULT NULL,
        report_to    DATE         DEFAULT NULL,
        PRIMARY KEY (id)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

add_filter( 'cron_schedules', 'wpup_add_12hour_schedule' );
function wpup_add_12hour_schedule( $schedules ) {
    $schedules['twicedaily_wpup'] = array(
        'interval' => 12 * HOUR_IN_SECONDS,
        'display'  => __( 'Every 12 Hours (WP Site Reports)' ),
    );
    return $schedules;
}

function wpup_next_6am() {
    $tz_string = get_option( 'timezone_string' ) ?: 'UTC';
    $tz        = new DateTimeZone( $tz_string );
    $now       = new DateTime( 'now', $tz );
    $target    = new DateTime( 'today 06:00:00', $tz );
    if ( $now >= $target ) $target->modify( '+1 day' );
    return $target->getTimestamp();
}

function wpup_next_8am() {
    $tz_string = get_option( 'timezone_string' ) ?: 'UTC';
    $tz        = new DateTimeZone( $tz_string );
    $now       = new DateTime( 'now', $tz );
    $target    = new DateTime( 'today 08:00:00', $tz );
    if ( $now >= $target ) $target->modify( '+1 day' );
    return $target->getTimestamp();
}

function wpup_schedule_cron() {
    if ( ! wp_next_scheduled( WPUP_CRON ) )
        wp_schedule_event( wpup_next_6am(), 'daily', WPUP_CRON );
}

function wpup_schedule_send_cron() {
    if ( ! wp_next_scheduled( WPUP_SEND_CRON ) )
        wp_schedule_event( wpup_next_8am(), 'daily', WPUP_SEND_CRON );
}

add_action( 'init', 'wpup_schedule_cron' );
add_action( 'init', 'wpup_schedule_send_cron' );
add_action( 'init', 'wpup_maybe_create_email_log_table' );
function wpup_maybe_create_email_log_table() {
    if ( get_option( 'wpup_email_log_table_created' ) ) return;
    wpup_create_email_log_table();
    update_option( 'wpup_email_log_table_created', '1' );
}

add_action( WPUP_CRON,      'wpup_snapshot_check' );
add_action( WPUP_SEND_CRON, 'wpup_maybe_send_report' );

// ─────────────────────────────────────────────
// 4. SNAPSHOT
// ─────────────────────────────────────────────
function wpup_build_snapshot() {
    if ( ! function_exists( 'get_plugins' ) )
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    global $wp_version;
    $snapshot = array( 'plugins' => array(), 'themes' => array(), 'core' => $wp_version );
    foreach ( get_plugins() as $file => $data ) {
        $parts = explode( '/', $file );
        $slug  = $parts[0];
        $snapshot['plugins'][ $slug ] = array( 'name' => $data['Name'], 'version' => $data['Version'] );
    }
    foreach ( wp_get_themes() as $slug => $theme )
        $snapshot['themes'][ $slug ] = array( 'name' => $theme->get('Name'), 'version' => $theme->get('Version') );
    return $snapshot;
}

function wpup_save_snapshot() {
    // autoload=false: snapshot can be large; no need to load it on every WP request
    update_option( WPUP_SNAPSHOT, wpup_build_snapshot(), false );
}

function wpup_snapshot_check() {
    $old = get_option( WPUP_SNAPSHOT, array() );
    $new = wpup_build_snapshot();
    if ( empty( $old ) ) { wpup_save_snapshot(); return; }
    foreach ( $new['plugins'] as $slug => $data ) {
        $old_ver = isset( $old['plugins'][ $slug ] ) ? $old['plugins'][ $slug ]['version'] : null;
        if ( is_null( $old_ver ) )
            wpup_insert_log( array( 'update_type' => 'plugin', 'action_type' => 'install', 'name' => $data['name'], 'slug' => $slug, 'old_version' => '', 'new_version' => $data['version'], 'source' => 'snapshot' ) );
        elseif ( version_compare( $data['version'], $old_ver, '>' ) )
            wpup_insert_log( array( 'update_type' => 'plugin', 'action_type' => 'update', 'name' => $data['name'], 'slug' => $slug, 'old_version' => $old_ver, 'new_version' => $data['version'], 'source' => 'snapshot' ) );
    }
    foreach ( $new['themes'] as $slug => $data ) {
        $old_ver = isset( $old['themes'][ $slug ] ) ? $old['themes'][ $slug ]['version'] : null;
        if ( is_null( $old_ver ) )
            wpup_insert_log( array( 'update_type' => 'theme', 'action_type' => 'install', 'name' => $data['name'], 'slug' => $slug, 'old_version' => '', 'new_version' => $data['version'], 'source' => 'snapshot' ) );
        elseif ( version_compare( $data['version'], $old_ver, '>' ) )
            wpup_insert_log( array( 'update_type' => 'theme', 'action_type' => 'update', 'name' => $data['name'], 'slug' => $slug, 'old_version' => $old_ver, 'new_version' => $data['version'], 'source' => 'snapshot' ) );
    }
    if ( version_compare( $new['core'], isset( $old['core'] ) ? $old['core'] : '0', '>' ) )
        wpup_insert_log( array( 'update_type' => 'core', 'action_type' => 'update', 'name' => 'WordPress', 'slug' => 'wordpress', 'old_version' => isset( $old['core'] ) ? $old['core'] : '', 'new_version' => $new['core'], 'source' => 'snapshot' ) );
    update_option( WPUP_SNAPSHOT, $new, false );
    // Save the last checked timestamp in UTC
    update_option( 'wpup_last_snapshot_check', gmdate('Y-m-d H:i:s'), false );
}

// ─────────────────────────────────────────────
// 5. HOOK-BASED LOGGING
// ─────────────────────────────────────────────
add_action( 'upgrader_process_complete', 'wpup_log_upgrade', 10, 2 );
function wpup_log_upgrade( $upgrader, $options ) {
    $type   = isset( $options['type'] )   ? $options['type']   : '';
    $action = isset( $options['action'] ) ? $options['action'] : '';
    if ( ! in_array( $type, array( 'plugin', 'theme', 'core' ), true ) ) return;

    if ( $type === 'core' ) {
        global $wp_version;
        $snap    = get_option( WPUP_SNAPSHOT, array() );
        $old_ver = isset( $snap['core'] ) ? $snap['core'] : '';
        wpup_insert_log( array( 'update_type' => 'core', 'action_type' => $action, 'name' => 'WordPress', 'slug' => 'wordpress', 'old_version' => $old_ver, 'new_version' => $wp_version, 'source' => 'hook' ) );
        $snap['core'] = $wp_version;
        update_option( WPUP_SNAPSHOT, $snap, false );
        return;
    }

    if ( $type === 'plugin' ) {
        $slugs = isset( $options['plugins'] ) ? $options['plugins'] : array();
        foreach ( $slugs as $slug ) {
            $snap    = get_option( WPUP_SNAPSHOT, array() );
            $old_ver = isset( $snap['plugins'][ $slug ]['version'] ) ? $snap['plugins'][ $slug ]['version'] : '';
            $pf      = wpup_find_plugin_file( $slug );
            $data    = $pf ? get_plugin_data( $pf, false, false ) : array();
            $nv      = isset( $data['Version'] ) ? $data['Version'] : '';
            $nm      = isset( $data['Name'] )    ? $data['Name']    : $slug;
            wpup_insert_log( array( 'update_type' => 'plugin', 'action_type' => $action, 'name' => $nm, 'slug' => $slug, 'old_version' => $old_ver, 'new_version' => $nv, 'source' => 'hook' ) );
            if ( isset( $snap['plugins'][ $slug ] ) ) {
                $snap['plugins'][ $slug ]['version'] = $nv;
                update_option( WPUP_SNAPSHOT, $snap, false );
            }
        }
        return;
    }

    if ( $type === 'theme' ) {
        $slugs = isset( $options['themes'] ) ? $options['themes'] : array();
        foreach ( $slugs as $slug ) {
            $snap    = get_option( WPUP_SNAPSHOT, array() );
            $old_ver = isset( $snap['themes'][ $slug ]['version'] ) ? $snap['themes'][ $slug ]['version'] : '';
            $theme   = wp_get_theme( $slug );
            $nv      = $theme->get('Version') ? $theme->get('Version') : '';
            $nm      = $theme->get('Name')    ? $theme->get('Name')    : $slug;
            wpup_insert_log( array( 'update_type' => 'theme', 'action_type' => $action, 'name' => $nm, 'slug' => $slug, 'old_version' => $old_ver, 'new_version' => $nv, 'source' => 'hook' ) );
            if ( isset( $snap['themes'][ $slug ] ) ) {
                $snap['themes'][ $slug ]['version'] = $nv;
                update_option( WPUP_SNAPSHOT, $snap, false );
            }
        }
    }
}

function wpup_find_plugin_file( $folder ) {
    $dir = WP_PLUGIN_DIR . '/' . $folder;
    if ( ! is_dir( $dir ) ) return false;
    $files = glob( $dir . '/*.php' );
    if ( ! $files ) return false;
    foreach ( $files as $file ) { $data = get_plugin_data( $file, false, false ); if ( ! empty( $data['Name'] ) ) return $file; }
    return false;
}

function wpup_insert_log( $args ) {
    global $wpdb;
    // Never log updates to this plugin itself
    if ( isset( $args['slug'] ) && $args['slug'] === 'wp-site-reports' ) return;
    $nv     = isset( $args['new_version'] ) ? $args['new_version'] : '';
    $exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM " . WPUP_TABLE . " WHERE slug = %s AND new_version = %s AND DATE(updated_at) = %s LIMIT 1",
        $args['slug'], $nv, current_time('Y-m-d')
    ) );
    if ( $exists ) return;
    $wpdb->insert( WPUP_TABLE, array(
        'update_type' => $args['update_type'],
        'action_type' => isset( $args['action_type'] ) ? $args['action_type'] : 'update',
        'name'        => $args['name'],
        'slug'        => $args['slug'],
        'old_version' => isset( $args['old_version'] ) ? $args['old_version'] : '',
        'new_version' => $nv,
        'updated_by'  => get_current_user_id(),
        'updated_at'  => current_time('mysql'),
        'source'      => isset( $args['source'] ) ? $args['source'] : 'hook',
    ) );
}

// ─────────────────────────────────────────────
// 6. AUTO-SEND
// ─────────────────────────────────────────────
function wpup_get_report_range() {
    $send_day = get_option( 'wpup_send_day', '1' );
    if ( $send_day === '15' ) {
        $from = date( 'Y-m-16', strtotime('last month') );
        $to   = date( 'Y-m-15' );
    } else {
        $from = date( 'Y-m-01', strtotime('first day of last month') );
        $to   = date( 'Y-m-t',  strtotime('last day of last month') );
    }
    return array( $from, $to );
}

function wpup_maybe_send_report() {
    if ( get_option( 'wpup_autosend_enabled', '0' ) !== '1' ) return;
    $send_day   = (int) get_option( 'wpup_send_day', '1' );
    $today      = (int) date('j');
    $this_month = date('Y-m');
    if ( $today < $send_day ) return;
    if ( get_option( 'wpup_last_sent', '' ) === $this_month ) return;
    wpup_send_report();
}

function wpup_send_report( $manual = false, $custom_from = '', $custom_to = '' ) {
    global $wpdb;
    $recipients   = get_option( 'wpup_recipients',     array() );
    $cc           = get_option( 'wpup_recipients_cc',  array() );
    $bcc          = get_option( 'wpup_recipients_bcc', array() );
    $sender_email = get_option( 'wpup_sender_email',   get_option('admin_email') );
    $sender_name  = get_option( 'wpup_sender_name',    get_bloginfo('name') );
    $replyto      = get_option( 'wpup_replyto_email',  $sender_email );
    $client_name  = get_option( 'wpup_client_name',    'there' );
    $signoff_name = get_option( 'wpup_signoff_name',   $sender_name );
    if ( empty( $recipients ) ) return new WP_Error( 'no_recipients', 'No recipient emails configured.' );
    if ( $custom_from && $custom_to ) { $from = $custom_from; $to = $custom_to; }
    else { $range = wpup_get_report_range(); $from = $range[0]; $to = $range[1]; }
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM " . WPUP_TABLE . " WHERE DATE(updated_at) BETWEEN %s AND %s ORDER BY update_type, name",
        $from, $to
    ) );
    $plugins = array(); $themes = array(); $core = array();
    foreach ( $rows as $r ) {
        if ( $r->update_type === 'plugin' ) $plugins[] = $r;
        if ( $r->update_type === 'theme' )  $themes[]  = $r;
        if ( $r->update_type === 'core' )   $core[]    = $r;
    }
    $plugins = wpup_filter_active( $plugins, $to );
    $themes  = wpup_filter_active( $themes,  $to );
    $plugins = wpup_dedupe( $plugins );
    $themes  = wpup_dedupe( $themes );
    $core    = wpup_dedupe( $core );
    $subject = wpup_build_subject();
    $body    = wpup_build_email( $client_name, $signoff_name, $plugins, $themes, $core );
    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $sender_name . ' <' . $sender_email . '>',
        'Reply-To: ' . $replyto,
    );
    if ( ! empty( $cc ) )
        $headers[] = 'Cc: ' . implode( ', ', $cc );
    if ( ! empty( $bcc ) )
        $headers[] = 'Bcc: ' . implode( ', ', $bcc );
    $result = wp_mail( $recipients, $subject, $body, $headers );
    if ( $result && ! $manual ) update_option( 'wpup_last_sent', date('Y-m') );
    $bcc_log = ! empty($bcc) ? ' (BCC: ' . implode( ', ', $bcc ) . ')' : '';
    $wpdb->insert( WPUP_EMAIL_LOG_TABLE, array(
        'sent_at'      => current_time('mysql'),
        'trigger_type' => $manual ? 'manual' : 'scheduled',
        'status'       => $result === true ? 'success' : 'failed',
        'sender_name'  => $sender_name,
        'sender_email' => $sender_email,
        'recipients'   => implode( ', ', $recipients )
                          . ( ! empty($cc)  ? ' (CC: '  . implode( ', ', $cc  ) . ')' : '' )
                          . $bcc_log,
        'report_from'  => $from,
        'report_to'    => $to,
    ) );
    return $result;
}

// ─────────────────────────────────────────────
// 7. ADMIN MENU + ENQUEUE
// ─────────────────────────────────────────────
add_action( 'admin_menu', 'wpup_admin_menu' );
function wpup_admin_menu() {
    add_menu_page( 'WP Site Reports', 'WP Site Reports', 'manage_options', 'wp-update-reporter', 'wpup_admin_page', 'dashicons-clipboard', 81 );
}

add_action( 'admin_enqueue_scripts', 'wpup_enqueue_scripts' );
function wpup_enqueue_scripts( $hook ) {
    if ( $hook === 'toplevel_page_wp-update-reporter' ) wp_enqueue_media();
}

// ─────────────────────────────────────────────
// 8. ADMIN PAGE
// ─────────────────────────────────────────────
function wpup_admin_page() {
    global $wpdb, $wp_version;

    if ( ! current_user_can( 'manage_options' ) )
        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );

    $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'log';
    $tab_url    = admin_url('admin.php?page=wp-update-reporter');

    // ── Save settings ─────────────────────────
    if ( isset( $_POST['wpup_save_settings'] ) && check_admin_referer('wpup_save_settings') ) {
        // Email Content
        update_option( 'wpup_client_name',  sanitize_text_field( isset( $_POST['client_name'] )  ? $_POST['client_name']  : '' ) );
        update_option( 'wpup_signoff_name', sanitize_text_field( isset( $_POST['signoff_name'] ) ? $_POST['signoff_name'] : '' ) );
        update_option( 'wpup_greeting',     wp_unslash( sanitize_text_field( isset( $_POST['wpup_greeting'] ) ? $_POST['wpup_greeting'] : '' ) ) );
        // Email Delivery
        update_option( 'wpup_sender_name',  sanitize_text_field( isset( $_POST['sender_name'] )  ? $_POST['sender_name']  : '' ) );
        update_option( 'wpup_sender_email', sanitize_email( isset( $_POST['sender_email'] )      ? $_POST['sender_email'] : '' ) );
        $replyto = sanitize_email( isset( $_POST['replyto_email'] ) ? $_POST['replyto_email'] : '' );
        update_option( 'wpup_replyto_email', $replyto ? $replyto : sanitize_email( isset( $_POST['sender_email'] ) ? $_POST['sender_email'] : '' ) );
        $raw_to  = sanitize_textarea_field( isset( $_POST['recipients_to']  ) ? $_POST['recipients_to']  : '' );
        $raw_cc  = sanitize_textarea_field( isset( $_POST['recipients_cc']  ) ? $_POST['recipients_cc']  : '' );
        $raw_bcc = sanitize_textarea_field( isset( $_POST['recipients_bcc'] ) ? $_POST['recipients_bcc'] : '' );
        update_option( 'wpup_recipients',     array_values( array_filter( array_map( 'sanitize_email', preg_split('/[\n,]+/', $raw_to)  ) ) ) );
        update_option( 'wpup_recipients_cc',  array_values( array_filter( array_map( 'sanitize_email', preg_split('/[\n,]+/', $raw_cc)  ) ) ) );
        update_option( 'wpup_recipients_bcc', array_values( array_filter( array_map( 'sanitize_email', preg_split('/[\n,]+/', $raw_bcc) ) ) ) );
        // Signature
        $raw_color = isset( $_POST['sig_accent_color'] ) ? trim( $_POST['sig_accent_color'] ) : '#000000';
        $color     = preg_match( '/^#([a-fA-F0-9]{3}|[a-fA-F0-9]{6})$/', $raw_color ) ? $raw_color : '#000000';
        update_option( 'wpup_sig_enabled',      isset( $_POST['sig_enabled'] ) ? '1' : '0' );
        update_option( 'wpup_sig_accent_color', $color );
        update_option( 'wpup_sig_name',         sanitize_text_field( isset( $_POST['sig_name'] )         ? $_POST['sig_name']         : '' ) );
        update_option( 'wpup_sig_title',        sanitize_text_field( isset( $_POST['sig_title'] )        ? $_POST['sig_title']        : '' ) );
        update_option( 'wpup_sig_phone_office', sanitize_text_field( isset( $_POST['sig_phone_office'] ) ? $_POST['sig_phone_office'] : '' ) );
        update_option( 'wpup_sig_phone_cell',   sanitize_text_field( isset( $_POST['sig_phone_cell'] )   ? $_POST['sig_phone_cell']   : '' ) );
        update_option( 'wpup_sig_email',        sanitize_email( isset( $_POST['sig_email'] )             ? $_POST['sig_email']        : '' ) );
        update_option( 'wpup_sig_logo_url',     esc_url_raw( isset( $_POST['sig_logo_url'] )             ? $_POST['sig_logo_url']     : '' ) );
        echo '<div class="notice notice-success is-dismissible"><p>Settings saved. <a href="' . esc_url($tab_url.'&tab=log') . '">View live preview →</a></p></div>';
        $active_tab = 'settings';
    }

    // ── Save scheduled report settings ────────
    if ( isset( $_POST['wpup_save_autosend'] ) && check_admin_referer('wpup_save_autosend') ) {
        update_option( 'wpup_autosend_enabled', isset( $_POST['autosend_enabled'] ) ? '1' : '0' );
        update_option( 'wpup_send_day',         sanitize_text_field( isset( $_POST['send_day'] ) ? $_POST['send_day'] : '1' ) );
        $send_day_label = get_option('wpup_send_day','1') === '15' ? '15th' : '1st';
        $auto_enabled   = get_option('wpup_autosend_enabled','0') === '1';
        $sched_msg      = $auto_enabled
            ? 'Saved. The report will automatically send on the ' . $send_day_label . ' of each month.'
            : 'Saved. Auto-send is currently disabled — enable it above to activate the schedule.';
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($sched_msg) . '</p></div>';
        $active_tab = 'scheduled';
    }

    // ── Send now (from preview tab) ────────────
    if ( isset( $_POST['wpup_send_now'] ) && check_admin_referer('wpup_send_now') ) {
        $send_from = sanitize_text_field( isset( $_POST['send_from'] ) ? $_POST['send_from'] : '' );
        $send_to   = sanitize_text_field( isset( $_POST['send_to'] )   ? $_POST['send_to']   : '' );
        $result    = wpup_send_report( true, $send_from, $send_to );
        if ( $result === true )
            echo '<div class="notice notice-success is-dismissible"><p>Report sent successfully!</p></div>';
        else {
            $msg = is_wp_error($result) ? $result->get_error_message() : 'Unknown error. Check your SMTP settings.';
            echo '<div class="notice notice-error is-dismissible"><p>Send failed: ' . esc_html($msg) . '</p></div>';
        }
        $active_tab = 'log';
    }

    // ── Clear email log ────────────────────────
    if ( isset( $_POST['wpup_clear_email_log'] ) && check_admin_referer('wpup_clear_email_log') ) {
        $wpdb->query( "TRUNCATE TABLE " . WPUP_EMAIL_LOG_TABLE );
        echo '<div class="notice notice-success is-dismissible"><p>Email log cleared.</p></div>';
        $active_tab = 'emaillog';
    }

    // ── Snapshot ───────────────────────────────
    $snapshot_notice = '';
    if ( isset( $_GET['wpup_run_snapshot'] ) && check_admin_referer('wpup_run_snapshot') ) {
        if ( current_user_can( 'manage_options' ) ) {
            wpup_snapshot_check();
            $snapshot_notice = 'success';
        } else {
            $snapshot_notice = 'error';
        }
        $active_tab = 'settings';
    }

    // ── Shared state ───────────────────────────
    $default_from  = current_time('Y-m-01');
    $default_to    = current_time('Y-m-d');
    $saved_client  = get_option( 'wpup_client_name',  'there' );
    $saved_signoff = get_option( 'wpup_signoff_name', '' );
    $nonce_valid   = isset( $_GET['wpup_report_nonce'] ) && wp_verify_nonce( $_GET['wpup_report_nonce'], 'wpup_report_form' );
    $from          = $nonce_valid && isset( $_GET['from'] ) ? sanitize_text_field( $_GET['from'] ) : $default_from;
    $to            = $nonce_valid && isset( $_GET['to'] )   ? sanitize_text_field( $_GET['to'] )   : $default_to;

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM " . WPUP_TABLE . " WHERE DATE(updated_at) BETWEEN %s AND %s ORDER BY update_type, name",
        $from, $to
    ) );
    $plugins = array(); $themes = array(); $core = array();
    foreach ( $rows as $r ) {
        if ( $r->update_type === 'plugin' ) $plugins[] = $r;
        if ( $r->update_type === 'theme' )  $themes[]  = $r;
        if ( $r->update_type === 'core' )   $core[]    = $r;
    }
    $plugins = wpup_filter_active( $plugins, $to );
    $themes  = wpup_filter_active( $themes,  $to );
    $plugins = wpup_dedupe( $plugins );
    $themes  = wpup_dedupe( $themes );
    $core    = wpup_dedupe( $core );
    $email   = wpup_build_email( $saved_client, $saved_signoff, $plugins, $themes, $core );
    $subject = wpup_build_subject();

    // Delivery settings for preview
    $sender_name  = get_option( 'wpup_sender_name',    get_bloginfo('name') );
    $sender_email = get_option( 'wpup_sender_email',   get_option('admin_email') );
    $replyto      = get_option( 'wpup_replyto_email',  $sender_email );
    $recipients   = get_option( 'wpup_recipients',     array() );
    $cc           = get_option( 'wpup_recipients_cc',  array() );
    $bcc          = get_option( 'wpup_recipients_bcc', array() );

    $next_cron     = wp_next_scheduled( WPUP_CRON );
    $next_cron_str = $next_cron ? get_date_from_gmt( date('Y-m-d H:i:s', $next_cron), 'M j, Y \a\t g:i a' ) : 'Not scheduled';
    $snapshot_url  = wp_nonce_url( add_query_arg( array( 'page' => 'wp-update-reporter', 'wpup_run_snapshot' => '1' ), admin_url('admin.php') ), 'wpup_run_snapshot' );

    $as_enabled  = get_option( 'wpup_autosend_enabled', '0' );
    $as_send_day = get_option( 'wpup_send_day',         '1' );

    $sig_enabled      = get_option( 'wpup_sig_enabled',      '0' );
    $sig_accent_color = get_option( 'wpup_sig_accent_color', '#000000' );
    $sig_name         = get_option( 'wpup_sig_name',         '' );
    $sig_title        = get_option( 'wpup_sig_title',        '' );
    $sig_phone_office = get_option( 'wpup_sig_phone_office', '' );
    $sig_phone_cell   = get_option( 'wpup_sig_phone_cell',   '' );
    $sig_email        = get_option( 'wpup_sig_email',        '' );
    $sig_logo_url     = get_option( 'wpup_sig_logo_url',     '' );
    ?>
    <div class="wrap">
        <h1>WP Site Reports</h1>
        <p style="margin:0 0 16px;">Logs all updates including those made by <strong>Smart Plugin Manager</strong>.</p>

        <nav class="nav-tab-wrapper" style="margin-bottom:0;">
            <a href="<?php echo esc_url($tab_url.'&tab=log'); ?>"       class="nav-tab <?php echo $active_tab==='log'       ?'nav-tab-active':''; ?>">📋 Updates &amp; Preview</a>
            <a href="<?php echo esc_url($tab_url.'&tab=settings'); ?>"  class="nav-tab <?php echo $active_tab==='settings'  ?'nav-tab-active':''; ?>">⚙️ Settings</a>
            <a href="<?php echo esc_url($tab_url.'&tab=scheduled'); ?>" class="nav-tab <?php echo $active_tab==='scheduled' ?'nav-tab-active':''; ?>">
                📧 Scheduled Reports<?php if ($as_enabled==='1') echo ' <span style="background:#00a32a;color:#fff;font-size:10px;padding:1px 6px;border-radius:8px;margin-left:4px;">ON</span>'; ?>
            </a>
            <a href="<?php echo esc_url($tab_url.'&tab=emaillog'); ?>"  class="nav-tab <?php echo $active_tab==='emaillog'  ?'nav-tab-active':''; ?>">📨 Email Log</a>
        </nav>

        <div style="background:#fff;border:1px solid #ccd0d4;border-top:none;padding:24px;margin-bottom:24px;">

        <?php if ( $active_tab === 'log' ) : ?>

            <!-- ── Date range filter ── -->
            <form method="get" action="" style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end;padding-bottom:20px;margin-bottom:24px;border-bottom:1px solid #eee;">
                <input type="hidden" name="page" value="wp-update-reporter">
                <input type="hidden" name="tab"  value="log">
                <?php wp_nonce_field('wpup_report_form','wpup_report_nonce'); ?>
                <div><label style="display:block;font-weight:600;margin-bottom:4px;">From Date</label><input type="date" name="from" value="<?php echo esc_attr($from); ?>"></div>
                <div><label style="display:block;font-weight:600;margin-bottom:4px;">To Date</label><input type="date" name="to" value="<?php echo esc_attr($to); ?>"></div>
                <div style="padding-bottom:1px;"><button type="submit" class="button button-primary">Generate Report</button></div>
            </form>

            <!-- ── Updates log ── -->
            <h2 style="margin-top:0;">Updates Log</h2>
            <?php if ( empty($rows) ) : ?>
                <div class="notice notice-warning inline"><p>No updates found for the selected date range.</p></div>
            <?php else : ?>
                <p style="color:#666;margin-top:-8px;">
                    <?php echo count($plugins); ?> plugin<?php echo count($plugins)!==1?'s':''; ?>,
                    <?php echo count($themes);  ?> theme<?php echo count($themes)!==1?'s':'';   ?>,
                    <?php echo count($core);    ?> core update<?php echo count($core)!==1?'s':''; ?>
                    &nbsp;·&nbsp;
                    <?php echo esc_html(date('M j, Y', strtotime($from))); ?> – <?php echo esc_html(date('M j, Y', strtotime($to))); ?>
                </p>
                <table class="wp-list-table widefat fixed striped" style="margin-bottom:40px;">
                    <thead><tr><th>Type</th><th>Name</th><th>Old Version</th><th>New Version</th><th>Logged At</th><th>Source</th></tr></thead>
                    <tbody>
                    <?php foreach ( array_merge($plugins,$themes,$core) as $r ) :
                        if ( $r->action_type === 'install' )  { $label = 'Install';      $bg = '#e8f0fe'; }
                        elseif ( $r->source === 'snapshot' )  { $label = 'Detected';     $bg = '#fff3cd'; }
                        else                                   { $label = 'WP Dashboard'; $bg = '#d4edda'; }
                    ?>
                        <tr>
                            <td style="text-transform:capitalize;"><?php echo esc_html($r->update_type); ?></td>
                            <td><?php echo esc_html($r->name); ?></td>
                            <td><?php echo esc_html($r->old_version ? $r->old_version : '—'); ?></td>
                            <td><?php echo esc_html($r->new_version); ?></td>
                            <td><?php echo esc_html($r->updated_at); ?></td>
                            <td><span style="background:<?php echo esc_attr($bg); ?>;padding:2px 8px;border-radius:10px;font-size:12px;"><?php echo esc_html($label); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <!-- ── Email preview ── -->
            <h2 style="margin-top:0;padding-top:24px;border-top:2px solid #eee;">Email Preview</h2>

            <!-- Delivery summary -->
            <?php $has_recipients = ! empty($recipients); ?>
            <?php if ( ! $has_recipients ) : ?>
                <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:10px 16px;margin-bottom:20px;font-size:13px;">
                    ⚠️ No recipient addresses configured. <a href="<?php echo esc_url($tab_url.'&tab=settings'); ?>">Add them in Settings</a> before sending.
                </div>
            <?php endif; ?>

            <div style="background:#f9f9f9;border:1px solid #ccd0d4;border-radius:4px;padding:16px 20px;margin-bottom:20px;font-size:13px;line-height:2;font-family:monospace;">
                <div><span style="display:inline-block;width:80px;color:#555;">To:</span> <?php echo $has_recipients ? esc_html(implode(', ', $recipients)) : '<span style="color:#999;">Not configured</span>'; ?></div>
                <?php if ( ! empty($cc) ) : ?>
                <div><span style="display:inline-block;width:80px;color:#555;">CC:</span> <?php echo esc_html(implode(', ', $cc)); ?></div>
                <?php endif; ?>
                <?php if ( ! empty($bcc) ) : ?>
                <div><span style="display:inline-block;width:80px;color:#555;">BCC:</span> <?php echo esc_html(implode(', ', $bcc)); ?></div>
                <?php endif; ?>
                <div><span style="display:inline-block;width:80px;color:#555;">From:</span> <?php echo esc_html($sender_name . ' <' . $sender_email . '>'); ?></div>
                <div><span style="display:inline-block;width:80px;color:#555;">Reply-To:</span> <?php echo esc_html($replyto ? $replyto : $sender_email); ?></div>
            </div>

            <!-- Subject -->
            <div style="margin-bottom:16px;">
                <label style="display:block;font-weight:600;margin-bottom:6px;">Subject</label>
                <div style="display:flex;gap:8px;align-items:center;">
                    <input id="wpup-subject-output" type="text" value="<?php echo esc_attr($subject); ?>" readonly style="width:100%;font-family:Arial,sans-serif;font-size:14px;padding:8px 12px;border:1px solid #ccd0d4;border-radius:4px;background:#f9f9f9;">
                    <button onclick="wpupCopySubject(event)" class="button button-secondary" style="white-space:nowrap;">Copy</button>
                </div>
            </div>

            <!-- Body -->
            <div style="margin-bottom:20px;">
                <label style="display:block;font-weight:600;margin-bottom:6px;">Body</label>
                <div style="position:relative;">
                    <div style="border:1px solid #ccd0d4;border-radius:4px;background:#f9f9f9;padding:24px;font-family:Arial,sans-serif;font-size:14px;line-height:1.8;">
                        <div id="wpup-email-preview"><?php echo $email; ?></div>
                    </div>
                    <button onclick="wpupCopyBody(event)" title="Copy email to clipboard" style="position:absolute;top:10px;right:10px;background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:4px 8px;cursor:pointer;font-size:13px;color:#555;line-height:1;" class="button">
                        Copy
                    </button>
                </div>
            </div>

            <!-- Actions -->
            <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                <?php if ( $has_recipients ) : ?>
                <form method="post" action="<?php echo esc_url($tab_url.'&tab=log'); ?>" style="margin:0;">
                    <?php wp_nonce_field('wpup_send_now'); ?>
                    <input type="hidden" name="wpup_send_now" value="1">
                    <input type="hidden" name="send_from" value="<?php echo esc_attr($from); ?>">
                    <input type="hidden" name="send_to"   value="<?php echo esc_attr($to); ?>">
                    <button type="submit" class="button button-primary"
                        onclick="return confirm('Send this report now to <?php echo esc_js(implode(', ', $recipients)); ?>?');">
                        Send Now
                    </button>
                </form>
                <?php else : ?>
                    <button class="button button-primary" disabled title="Configure recipients in Settings first">Send Now</button>
                <?php endif; ?>
                <span style="color:#999;font-size:12px;">Sends exactly what is shown above.</span>
            </div>

            <script>
            function wpupCopySubject(e) {
                var el = document.getElementById('wpup-subject-output');
                el.select(); document.execCommand('copy');
                var btn = e.target; btn.textContent = 'Copied!';
                setTimeout(function(){ btn.textContent = 'Copy'; }, 2000);
            }
            function wpupCopyBody(e) {
                var el   = document.getElementById('wpup-email-preview');
                var blob = new Blob([el.innerHTML], {type:'text/html'});
                navigator.clipboard.write([new ClipboardItem({'text/html':blob})]).then(function() {
                    var btn = e.target; btn.textContent = '✓ Copied';
                    setTimeout(function(){ btn.textContent = '📋 Copy'; }, 2000);
                });
            }
            </script>

        <?php elseif ( $active_tab === 'settings' ) : ?>

            <h2 style="margin-top:0;">Settings</h2>
            <form method="post" action="<?php echo esc_url($tab_url.'&tab=settings'); ?>">
                <?php wp_nonce_field('wpup_save_settings'); ?>
                <input type="hidden" name="wpup_save_settings" value="1">

                <!-- ── Email Content ── -->
                <h3 style="border-bottom:1px solid #eee;padding-bottom:8px;">Email Content</h3>
                <table class="form-table" style="margin-bottom:24px;">
                    <tr>
                        <th style="width:200px;"><label for="client_name">Recipient Name</label></th>
                        <td>
                            <input type="text" name="client_name" id="client_name" value="<?php echo esc_attr($saved_client !== 'there' ? $saved_client : ''); ?>" placeholder="e.g. John" style="width:280px;">
                            <p class="description">Used in the greeting — e.g. "Hi John,". Defaults to "Hi there," if left blank.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="signoff_name">Sign-off Name</label></th>
                        <td>
                            <input type="text" name="signoff_name" id="signoff_name" value="<?php echo esc_attr($saved_signoff); ?>" placeholder="e.g. Jane" style="width:280px;">
                            <p class="description">Appears at the bottom of the email if no HTML signature is enabled.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wpup_greeting">Greeting Line</label></th>
                        <td>
                            <input type="text" name="wpup_greeting" id="wpup_greeting" value="<?php echo esc_attr( get_option('wpup_greeting', "I hope you're doing well. Please see the website updates below:") ); ?>" style="width:420px;">
                            <p class="description">Appears after "Hi [Name]," at the top of the email.</p>
                        </td>
                    </tr>
                </table>

                <!-- ── Email Delivery ── -->
                <h3 style="border-bottom:1px solid #eee;padding-bottom:8px;">Email Delivery</h3>
                <table class="form-table" style="margin-bottom:24px;">
                    <tr>
                        <th style="width:200px;"><label for="sender_name">Sender Name</label></th>
                        <td><input type="text" name="sender_name" id="sender_name" value="<?php echo esc_attr(get_option('wpup_sender_name', get_bloginfo('name'))); ?>" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>" style="width:280px;"></td>
                    </tr>
                    <tr>
                        <th><label for="sender_email">Sender Email</label></th>
                        <td><input type="email" name="sender_email" id="sender_email" value="<?php echo esc_attr(get_option('wpup_sender_email', get_option('admin_email'))); ?>" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>" style="width:280px;"></td>
                    </tr>
                    <tr>
                        <th><label for="replyto_email">Reply-To</label></th>
                        <td>
                            <input type="email" name="replyto_email" id="replyto_email" value="<?php echo esc_attr(get_option('wpup_replyto_email', '')); ?>" style="width:280px;">
                            <p class="description">Leave blank to use the sender email.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="recipients_to">To:</label></th>
                        <td>
                            <textarea name="recipients_to" id="recipients_to" rows="3" style="width:380px;font-family:monospace;"><?php echo esc_textarea(implode("\n", get_option('wpup_recipients', array()))); ?></textarea>
                            <p class="description">One email per line, or comma-separated.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="recipients_cc">CC:</label></th>
                        <td>
                            <textarea name="recipients_cc" id="recipients_cc" rows="3" style="width:380px;font-family:monospace;"><?php echo esc_textarea(implode("\n", get_option('wpup_recipients_cc', array()))); ?></textarea>
                            <p class="description">One email per line, or comma-separated. Optional.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="recipients_bcc">BCC:</label></th>
                        <td>
                            <textarea name="recipients_bcc" id="recipients_bcc" rows="3" style="width:380px;font-family:monospace;"><?php echo esc_textarea(implode("\n", get_option('wpup_recipients_bcc', array()))); ?></textarea>
                            <p class="description">One email per line, or comma-separated. Optional.</p>
                        </td>
                    </tr>
                </table>

                <!-- ── Email Signature ── -->
                <h3 style="border-bottom:1px solid #eee;padding-bottom:8px;">Email Signature</h3>
                <p style="color:#666;margin-bottom:20px;">When enabled this replaces the plain sign-off name in all emails.</p>
                <table class="form-table">
                    <tr>
                        <th style="width:200px;">Enable Signature</th>
                        <td><label><input type="checkbox" name="sig_enabled" value="1" <?php checked($sig_enabled,'1'); ?> onchange="wpupPreviewSig()"> Use HTML signature in emails</label></td>
                    </tr>
                    <tr>
                        <th><label for="sig_accent_color">Accent Color</label></th>
                        <td style="display:flex;align-items:center;gap:10px;padding-top:10px;">
                            <input type="color" name="sig_accent_color" id="sig_accent_color" value="<?php echo esc_attr($sig_accent_color); ?>" oninput="wpupPreviewSig()">
                            <span style="color:#666;font-size:13px;">Used for the name and accent elements</span>
                        </td>
                    </tr>
                    <tr><th><label for="sig_name">Name</label></th><td><input type="text" name="sig_name" id="sig_name" value="<?php echo esc_attr($sig_name); ?>" style="width:280px;" oninput="wpupPreviewSig()"></td></tr>
                    <tr><th><label for="sig_title">Job Title / Tagline</label></th><td><input type="text" name="sig_title" id="sig_title" value="<?php echo esc_attr($sig_title); ?>" style="width:280px;" oninput="wpupPreviewSig()"></td></tr>
                    <tr><th><label for="sig_phone_office">Office Phone</label></th><td><input type="text" name="sig_phone_office" id="sig_phone_office" value="<?php echo esc_attr($sig_phone_office); ?>" style="width:200px;" placeholder="(000) 000-0000" oninput="wpupPreviewSig()"></td></tr>
                    <tr><th><label for="sig_phone_cell">Cell Phone</label></th><td><input type="text" name="sig_phone_cell" id="sig_phone_cell" value="<?php echo esc_attr($sig_phone_cell); ?>" style="width:200px;" placeholder="(000) 000-0000" oninput="wpupPreviewSig()"></td></tr>
                    <tr><th><label for="sig_email">Email Address</label></th><td><input type="email" name="sig_email" id="sig_email" value="<?php echo esc_attr($sig_email); ?>" style="width:280px;" oninput="wpupPreviewSig()"></td></tr>
                    <tr>
                        <th><label for="sig_logo_url">Logo</label></th>
                        <td>
                            <div style="display:flex;gap:8px;align-items:center;">
                                <input type="text" name="sig_logo_url" id="sig_logo_url" value="<?php echo esc_attr($sig_logo_url); ?>" style="width:320px;" placeholder="https://... or upload" oninput="wpupPreviewSig()">
                                <button type="button" class="button" onclick="wpupOpenMediaUploader()">Upload</button>
                            </div>
                            <p class="description">Recommended max width: 180px. Use PNG or JPG — WebP and SVG are not supported in most email clients.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Live Preview</th>
                        <td>
                            <div id="wpup-sig-preview" style="border:1px solid #ccd0d4;border-radius:4px;padding:16px;background:#f9f9f9;display:inline-block;min-width:240px;font-family:Arial,sans-serif;font-size:14px;line-height:1.8;">
                                <?php
                                $preview_html = wpup_build_signature();
                                echo $preview_html ? $preview_html : '<span style="color:#999;font-size:13px;">Fill in the fields above to see your signature preview.</span>';
                                ?>
                            </div>
                        </td>
                    </tr>
                </table>
                <!-- ── Snapshot ── -->
                <h3 id="wpup-snapshot" style="border-bottom:1px solid #eee;padding-bottom:8px;">Snapshot</h3>
                <?php
                $tz_string  = get_option('timezone_string');
                $tz_abbr    = '';
                if ( $tz_string ) {
                    $dt      = new DateTime('now', new DateTimeZone($tz_string));
                    $tz_abbr = $dt->format('T');
                }
                $next_cron_display = $next_cron
                    ? get_date_from_gmt( date('Y-m-d H:i:s', $next_cron), 'F j, Y \a\t g:i a' ) . ( $tz_abbr ? ' ' . $tz_abbr : '' )
                    : 'Not scheduled';
                $snapshot_url_settings = wp_nonce_url( add_query_arg( array( 'page' => 'wp-update-reporter', 'tab' => 'settings', 'wpup_run_snapshot' => '1' ), admin_url('admin.php') ), 'wpup_run_snapshot' ) . '#wpup-snapshot';
                ?>
                <table class="form-table" style="margin-bottom:24px;">
                    <tr>
                        <th style="width:200px;">Run Snapshot Check</th>
                        <td>
                            <a href="<?php echo esc_url($snapshot_url_settings); ?>" class="button button-secondary">Check Now</a>
                            <?php if ( $snapshot_notice === 'success' ) : ?>
                                <span style="margin-left:12px;color:#00a32a;font-size:13px;">✓ Success</span>
                            <?php elseif ( $snapshot_notice === 'error' ) : ?>
                                <span style="margin-left:12px;color:#b32d2e;font-size:13px;">You do not have permission to run a snapshot check.</span>
                            <?php endif; ?>
                            <p class="description" style="margin-top:8px;">Check for updates since the last automatic check.</p>
                            <?php
                            $last_check = get_option('wpup_last_snapshot_check', '');
                            if ( $last_check && $tz_string ) {
                                $dt_last = new DateTime( $last_check, new DateTimeZone('UTC') );
                                $dt_last->setTimezone( new DateTimeZone($tz_string) );
                                $last_check_display = $dt_last->format('F j, Y \a\t g:i a') . ( $tz_abbr ? ' ' . $tz_abbr : '' );
                            } else {
                                $last_check_display = 'Never';
                            }
                            ?>
                            <p class="description">Last checked: <?php echo esc_html($last_check_display); ?></p>
                            <p class="description">Next automatic check: <?php echo esc_html($next_cron_display); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit"><button type="submit" class="button button-primary">Save Settings</button></p>
            </form>

            <script>
            document.addEventListener('DOMContentLoaded', function() { wpupPreviewSig(); });
            function wpupPreviewSig() {
                var color  = document.getElementById('sig_accent_color') ? document.getElementById('sig_accent_color').value : '#000000';
                var name   = document.getElementById('sig_name')         ? document.getElementById('sig_name').value         : '';
                var title  = document.getElementById('sig_title')        ? document.getElementById('sig_title').value        : '';
                var office = document.getElementById('sig_phone_office') ? document.getElementById('sig_phone_office').value : '';
                var cell   = document.getElementById('sig_phone_cell')   ? document.getElementById('sig_phone_cell').value   : '';
                var email  = document.getElementById('sig_email')        ? document.getElementById('sig_email').value        : '';
                var logo   = document.getElementById('sig_logo_url')     ? document.getElementById('sig_logo_url').value     : '';
                var html   = '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.8;">';
                if (name)   html += '<div style="font-weight:bold;font-size:16px;color:'+color+';">'+name+'</div>';
                if (title)  html += '<div style="font-size:13px;color:#333;">'+title+'</div>';
                if (office) html += '<div style="font-size:13px;color:#333;"><span style="color:'+color+';">o:</span>&nbsp;&nbsp;'+office+'</div>';
                if (cell)   html += '<div style="font-size:13px;color:#333;"><span style="color:'+color+';">c:</span>&nbsp;&nbsp;'+cell+'</div>';
                if (email)  html += '<div style="font-size:13px;"><span style="color:'+color+';">e:</span>&nbsp;&nbsp;<a href="mailto:'+email+'" style="color:'+color+';">'+email+'</a></div>';
                if (logo)   html += '<div style="margin-top:10px;"><img src="'+logo+'" style="max-width:180px;height:auto;display:block;border:0;outline:0;"></div>';
                html += '</div>';
                if (!name && !title && !office && !cell && !email && !logo) html = '<span style="color:#999;font-size:13px;">Fill in the fields above to see your signature preview.</span>';
                var preview = document.getElementById('wpup-sig-preview');
                if (preview) preview.innerHTML = html;
            }
            function wpupOpenMediaUploader() {
                if (typeof wp === 'undefined' || !wp.media) { alert('Media uploader not available.'); return; }
                var frame = wp.media({ title: 'Select Logo', button: { text: 'Use this image' }, multiple: false });
                frame.on('select', function() {
                    var att = frame.state().get('selection').first().toJSON();
                    var el = document.getElementById('sig_logo_url');
                    if (el) { el.value = att.url; wpupPreviewSig(); }
                });
                frame.open();
            }
            </script>

        <?php elseif ( $active_tab === 'scheduled' ) : ?>

            <h2 style="margin-top:0;">Scheduled Reports <span style="font-size:13px;font-weight:400;color:#666;">— optional, off by default</span></h2>
            <p style="color:#666;margin-bottom:20px;">When enabled, the report will be automatically emailed on the selected day each month using the delivery settings configured in <a href="<?php echo esc_url($tab_url.'&tab=settings'); ?>">Settings</a>.</p>

            <form method="post" action="<?php echo esc_url($tab_url.'&tab=scheduled'); ?>">
                <?php wp_nonce_field('wpup_save_autosend'); ?>
                <input type="hidden" name="wpup_save_autosend" value="1">
                <table class="form-table">
                    <tr>
                        <th style="width:200px;">Enable Auto-Send</th>
                        <td><label><input type="checkbox" name="autosend_enabled" value="1" <?php checked($as_enabled,'1'); ?>> Automatically send the monthly report</label></td>
                    </tr>
                    <tr>
                        <th><label for="send_day">Send On</label></th>
                        <td>
                            <select name="send_day" id="send_day">
                                <option value="1"  <?php selected($as_send_day,'1');  ?>>1st of the month</option>
                                <option value="15" <?php selected($as_send_day,'15'); ?>>15th of the month</option>
                            </select>
                            <?php
                            $tz_string_sched = get_option('timezone_string');
                            $tz_abbr_sched   = '';
                            if ( $tz_string_sched ) {
                                $dt_sched      = new DateTime('now', new DateTimeZone($tz_string_sched));
                                $tz_abbr_sched = $dt_sched->format('T');
                            }
                            ?>
                            <p class="description">The report will cover the previous month's updates and send at 8:00 am<?php echo $tz_abbr_sched ? ' ' . esc_html($tz_abbr_sched) : ''; ?>.</p>
                        </td>
                    </tr>
                </table>
                <p class="submit"><button type="submit" class="button button-primary">Save</button></p>
            </form>

        <?php elseif ( $active_tab === 'emaillog' ) : ?>

            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                <h2 style="margin:0;">Email Log</h2>
                <?php
                $email_log_count = $wpdb->get_var( "SELECT COUNT(*) FROM " . WPUP_EMAIL_LOG_TABLE );
                if ( $email_log_count > 0 ) : ?>
                <form method="post" action="<?php echo esc_url($tab_url.'&tab=emaillog'); ?>">
                    <?php wp_nonce_field('wpup_clear_email_log'); ?>
                    <input type="hidden" name="wpup_clear_email_log" value="1">
                    <button type="submit" class="button button-secondary" style="color:#b32d2e;border-color:#b32d2e;" onclick="return confirm('Are you sure you want to clear the entire email log? This cannot be undone.');">Clear Email Log</button>
                </form>
                <?php endif; ?>
            </div>

            <?php $email_logs = $wpdb->get_results( "SELECT * FROM " . WPUP_EMAIL_LOG_TABLE . " ORDER BY sent_at DESC LIMIT 100" ); ?>
            <?php if ( empty($email_logs) ) : ?>
                <div class="notice notice-info inline"><p>No emails have been sent yet.</p></div>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:160px;">Date &amp; Time</th>
                            <th style="width:100px;">Status</th>
                            <th style="width:110px;">Trigger</th>
                            <th style="width:180px;">Sent By</th>
                            <th>Recipients</th>
                            <th style="width:180px;">Report Period</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $email_logs as $log ) :
                        $success       = $log->status === 'success';
                        $scheduled     = $log->trigger_type === 'scheduled';
                        $report_period = $log->report_from && $log->report_to
                            ? date('M j, Y', strtotime($log->report_from)) . ' – ' . date('M j, Y', strtotime($log->report_to))
                            : '—';
                    ?>
                        <tr>
                            <td><?php echo esc_html( date('M j, Y g:i a', strtotime($log->sent_at)) ); ?></td>
                            <td>
                                <span style="background:<?php echo $success ? '#d4edda' : '#f8d7da'; ?>;color:<?php echo $success ? '#155724' : '#721c24'; ?>;padding:2px 10px;border-radius:10px;font-size:12px;font-weight:600;">
                                    <?php echo $success ? 'Sent' : 'Failed'; ?>
                                </span>
                            </td>
                            <td>
                                <span style="background:<?php echo $scheduled ? '#fff3cd' : '#e8f0fe'; ?>;padding:2px 10px;border-radius:10px;font-size:12px;">
                                    <?php echo $scheduled ? 'Scheduled' : 'Manual'; ?>
                                </span>
                            </td>
                            <td><?php echo esc_html( $log->sender_name ? $log->sender_name . ' <' . $log->sender_email . '>' : $log->sender_email ); ?></td>
                            <td style="word-break:break-all;"><?php echo esc_html($log->recipients); ?></td>
                            <td><?php echo esc_html($report_period); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="color:#666;font-size:13px;margin-top:8px;">Showing the most recent 100 entries.</p>
            <?php endif; ?>

        <?php endif; ?>

        </div>
    </div>
    <?php
}

// ─────────────────────────────────────────────
// 9. HELPERS
// ─────────────────────────────────────────────

/**
 * Filters out plugins/themes that are no longer installed.
 * Only applied when the report range includes today — historical reports show everything as logged.
 * Used for email preview and sending — not applied to the Updates Log.
 */
function wpup_filter_active( $rows, $to = '' ) {
    if ( empty( $rows ) ) return $rows;
    $today   = current_time('Y-m-d');
    $to_date = $to ? $to : $today;
    if ( $to_date < $today ) return $rows;
    if ( ! function_exists( 'get_plugins' ) )
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $plugin_slugs = array();
    foreach ( array_keys( get_plugins() ) as $file ) {
        $parts          = explode( '/', $file );
        $plugin_slugs[] = $parts[0];
    }
    $theme_slugs = array_keys( wp_get_themes() );
    $filtered = array();
    foreach ( $rows as $r ) {
        if ( $r->update_type === 'plugin' && ! in_array( $r->slug, $plugin_slugs, true ) ) continue;
        if ( $r->update_type === 'theme'  && ! in_array( $r->slug, $theme_slugs,  true ) ) continue;
        $filtered[] = $r;
    }
    return $filtered;
}

/**
 * Deduplicates rows by slug, keeping the last-logged entry (most recent version).
 */
function wpup_dedupe( $rows ) {
    $seen = array();
    foreach ( $rows as $r ) { $seen[ $r->slug ] = $r; }
    return array_values( $seen );
}

/**
 * Builds the email subject line.
 * Single-month ranges show "Month YYYY"; cross-month ranges show "Mon YYYY – Mon YYYY".
 */
function wpup_build_subject() {
    // Subject always reflects when the email is sent, not the date range it covers
    return 'Website Updates | ' . date('F Y') . ' | ' . get_bloginfo('name');
}

function wpup_build_signature() {
    if ( get_option('wpup_sig_enabled','0') !== '1' ) return '';
    $color  = get_option('wpup_sig_accent_color',  '#000000');
    $name   = get_option('wpup_sig_name',          '');
    $title  = get_option('wpup_sig_title',         '');
    $office = get_option('wpup_sig_phone_office',  '');
    $cell   = get_option('wpup_sig_phone_cell',    '');
    $email  = get_option('wpup_sig_email',         '');
    $logo   = get_option('wpup_sig_logo_url',      '');
    $html   = '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.8;">';
    if ($name)   $html .= '<div style="font-weight:bold;font-size:16px;color:' . esc_attr($color) . ';">' . esc_html($name) . '</div>';
    if ($title)  $html .= '<div style="font-size:13px;color:#333;">' . esc_html($title) . '</div>';
    if ($office) $html .= '<div style="font-size:13px;color:#333;"><span style="color:' . esc_attr($color) . ';">o:</span>&nbsp;&nbsp;' . esc_html($office) . '</div>';
    if ($cell)   $html .= '<div style="font-size:13px;color:#333;"><span style="color:' . esc_attr($color) . ';">c:</span>&nbsp;&nbsp;' . esc_html($cell) . '</div>';
    if ($email)  $html .= '<div style="font-size:13px;"><span style="color:' . esc_attr($color) . ';">e:</span>&nbsp;&nbsp;<a href="mailto:' . esc_attr($email) . '" style="color:' . esc_attr($color) . ';">' . esc_html($email) . '</a></div>';
    if ($logo)   $html .= '<div style="margin-top:10px;"><img src="' . esc_url($logo) . '" style="max-width:180px;height:auto;display:block;border:0;outline:0;"></div>';
    $html .= '</div>';
    return $html;
}

function wpup_build_email( $client, $signoff, $plugins, $themes, $core ) {
    global $wp_version;
    $active_theme = wp_get_theme();
    $greeting     = get_option( 'wpup_greeting', "I hope you're doing well. Please see the website updates below:" );
    $l   = array();
    $l[] = 'Hi ' . esc_html($client) . ',';
    $l[] = '';
    $l[] = esc_html($greeting);
    $l[] = '';
    $l[] = '<strong><u>Plugin Updates:</u></strong>';
    if ( ! empty($plugins) ) {
        foreach ( $plugins as $p ) {
            $v   = $p->new_version ? ' to ' . esc_html($p->new_version) : '';
            $l[] = ' - ' . esc_html($p->name) . ' updated' . $v;
        }
    } else {
        $l[] = ' - All plugins up-to-date';
    }
    $l[] = '';
    $l[] = '<strong><u>Theme:</u></strong>';
    if ( ! empty($themes) ) {
        foreach ( $themes as $t ) {
            $v   = $t->new_version ? ' to ' . esc_html($t->new_version) : '';
            $l[] = ' - ' . esc_html($t->name) . ' updated' . $v;
        }
    } else {
        $l[] = ' - ' . esc_html($active_theme->get('Name')) . ' is up-to-date';
    }
    $l[] = '';
    $l[] = '<strong><u>WordPress:</u></strong>';
    if ( ! empty($core) ) {
        $latest = end($core);
        $l[]    = ' - WordPress updated to ' . esc_html($latest->new_version);
    } else {
        $l[] = ' - On latest version of WordPress (' . esc_html($wp_version) . ')';
    }
    $l[] = '';
    $l[] = 'Thank you!';
    $sig  = wpup_build_signature();
    if ( $sig ) {
        $body = '<p>' . implode('<br>', $l) . '</p>' . $sig;
    } else {
        $l[]  = esc_html($signoff);
        $body = '<p>' . implode('<br>', $l) . '</p>';
    }
    return $body;
}

// ─────────────────────────────────────────────
// 10. PLUGIN UPDATE CHECKER (GitHub)
// ─────────────────────────────────────────────

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {

    require __DIR__ . '/vendor/autoload.php';

    $updateChecker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/efreeman-dev/wp-site-reports/',
        __FILE__,
        'wp-site-reports'
    );

}