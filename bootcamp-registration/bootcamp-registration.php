<?php
/**
 * Plugin Name: Resistance Bootcamp Registration
 * Description: Handles registration for Resistance Bootcamp 2026 with custom database storage and admin CSV export.
 * Version: 1.1
 * Author: Your Team
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Force PHP error logging to wp-content/debug.log so errors are visible on
// shared hosts (e.g. GoDaddy) where the default error_log destination is
// inaccessible or disabled.
@ini_set( 'log_errors', '1' );
@ini_set( 'error_log', WP_CONTENT_DIR . '/debug.log' );

// ─── Activation ──────────────────────────────────────────────────────────────

register_activation_hook( __FILE__, 'bootcamp_register_activate' );
function bootcamp_register_activate() {
    bootcamp_run_dbdelta();

    if ( ! get_page_by_path( 'register-thank-you' ) ) {
        wp_insert_post( [
            'post_title'   => 'Registration Complete',
            'post_name'    => 'register-thank-you',
            'post_content' => '<h2>Thank you for registering!</h2><p>We\'ve received your registration and will be in touch with more details soon.</p>',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ] );
    }
}

// Run dbDelta to create or upgrade the table schema.
// dbDelta adds new columns but does not drop or modify existing ones.
function bootcamp_run_dbdelta() {
    global $wpdb;
    $table_name      = $wpdb->prefix . 'bootcamp_registrations';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        timestamp datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        email VARCHAR(100) NOT NULL,
        first_name VARCHAR(50) NOT NULL,
        last_name VARCHAR(50) NOT NULL,
        pronouns VARCHAR(50),
        display_name VARCHAR(60),
        primary_affiliation VARCHAR(150),
        other_affiliation TEXT,
        other_organizing_affiliations TEXT,
        pod_or_buddy_name VARCHAR(100),
        volunteer_hours VARCHAR(30),
        session_a VARCHAR(200),
        session_b VARCHAR(200),
        session_c VARCHAR(200),
        session_d VARCHAR(200),
        session_e VARCHAR(200),
        accessibility_needs TEXT,
        donation_pledge VARCHAR(100),
        activist_background TEXT,
        nvda_experience TEXT,
        epx_trainings TEXT,
        other_trainings TEXT,
        motivation TEXT,
        refcode VARCHAR(36),
        donation_status VARCHAR(20) DEFAULT 'no_donation',
        donation_received VARCHAR(20),
        PRIMARY KEY (id),
        UNIQUE KEY uq_email (email)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

// Run dbDelta only when the schema version changes, not on every page load.
add_action( 'plugins_loaded', 'bootcamp_maybe_upgrade_db' );
function bootcamp_maybe_upgrade_db() {
    $current_version = 5;
    $installed       = (int) get_option( 'bootcamp_db_version', 0 );

    if ( $installed < $current_version ) {
        // v4: schema must be updated first so the new columns exist before data migration.
        if ( $installed < 4 ) {
            bootcamp_run_dbdelta();
            $success = bootcamp_migrate_v4_activist_background();
            if ( ! $success ) {
                error_log( 'Bootcamp: v4 migration failed — version not bumped, will retry on next load.' );
                return;
            }
        }

        // v5: duplicates must be removed BEFORE dbDelta adds the UNIQUE KEY on email.
        if ( $installed < 5 ) {
            $success = bootcamp_migrate_v5_dedup_emails();
            if ( ! $success ) {
                error_log( 'Bootcamp: v5 migration failed — version not bumped, will retry on next load.' );
                return;
            }
        }

        // Apply the current schema (adds UNIQUE KEY uq_email for v5, safe now that dupes are gone).
        bootcamp_run_dbdelta();
        update_option( 'bootcamp_db_version', $current_version );
    }
}

function bootcamp_migrate_v4_activist_background() {
    global $wpdb;
    $table = $wpdb->prefix . 'bootcamp_registrations';

    $rows = $wpdb->get_results( "SELECT id, activist_background FROM `$table` WHERE activist_background != '' AND activist_background IS NOT NULL" );
    if ( $rows === null ) {
        error_log( 'Bootcamp: v4 migration could not read rows: ' . $wpdb->last_error );
        return false;
    }

    $map = [
        'NVDA Experience'    => 'nvda_experience',
        'East PDX Trainings' => 'epx_trainings',
        'Other Trainings'    => 'other_trainings',
        'Motivation'         => 'motivation',
    ];

    $wpdb->query( 'START TRANSACTION' );

    foreach ( $rows as $row ) {
        $fields = [ 'nvda_experience' => '', 'epx_trainings' => '', 'other_trainings' => '', 'motivation' => '' ];
        foreach ( explode( ' | ', $row->activist_background ) as $part ) {
            foreach ( $map as $label => $col ) {
                $prefix = $label . ': ';
                if ( strncmp( $part, $prefix, strlen( $prefix ) ) === 0 ) {
                    $fields[ $col ] = substr( $part, strlen( $prefix ) );
                    break;
                }
            }
        }
        $result = $wpdb->update( $table, $fields, [ 'id' => $row->id ] );
        if ( $result === false ) {
            error_log( 'Bootcamp: v4 migration failed on row ' . $row->id . ': ' . $wpdb->last_error );
            $wpdb->query( 'ROLLBACK' );
            return false;
        }
    }

    $wpdb->query( 'COMMIT' );
    return true;
}

function bootcamp_migrate_v5_dedup_emails() {
    global $wpdb;
    $table = $wpdb->prefix . 'bootcamp_registrations';

    // Keep only the highest id (most recent insert) per email; delete all earlier duplicates.
    // The double-subquery wrapper is required by MySQL when deleting from the same table
    // you are selecting from.
    $wpdb->query( 'START TRANSACTION' );

    $result = $wpdb->query(
        "DELETE FROM `$table`
         WHERE id NOT IN (
             SELECT keeper FROM (
                 SELECT MAX(id) AS keeper FROM `$table` GROUP BY email
             ) AS t
         )"
    );

    if ( $result === false ) {
        error_log( 'Bootcamp: v5 dedup migration failed: ' . $wpdb->last_error );
        $wpdb->query( 'ROLLBACK' );
        return false;
    }

    $wpdb->query( 'COMMIT' );

    if ( $result > 0 ) {
        error_log( "Bootcamp: v5 migration removed $result duplicate registration(s)." );
    }

    return true;
}

// ─── Thank-you page ───────────────────────────────────────────────────────────

add_action( 'init', 'bootcamp_ensure_thankyou_page' );
function bootcamp_ensure_thankyou_page() {
    $page = get_page_by_path( 'register-thank-you' );
    if ( ! $page ) {
        wp_insert_post( [
            'post_title'   => 'Registration Complete',
            'post_name'    => 'register-thank-you',
            'post_content' => '[bootcamp_thankyou]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ] );
    } elseif ( strpos( $page->post_content, '[bootcamp_thankyou]' ) === false ) {
        // Upgrade existing static page to use the shortcode
        wp_update_post( [ 'ID' => $page->ID, 'post_content' => '[bootcamp_thankyou]' ] );
    }
}

// ─── CSV restore ─────────────────────────────────────────────────────────────

function bootcamp_import_csv( $file ) {
    if ( empty( $file['tmp_name'] ) || $file['error'] !== UPLOAD_ERR_OK ) {
        return new WP_Error( 'upload', 'File upload failed (error code ' . ( $file['error'] ?? 'unknown' ) . ').' );
    }
    if ( strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) !== 'csv' ) {
        return new WP_Error( 'filetype', 'Only .csv files are accepted.' );
    }

    $fh = fopen( $file['tmp_name'], 'r' );
    if ( ! $fh ) {
        return new WP_Error( 'open', 'Could not open the uploaded file.' );
    }

    $headers = fgetcsv( $fh );
    if ( ! $headers ) {
        fclose( $fh );
        return new WP_Error( 'empty', 'The CSV file appears to be empty.' );
    }

    // Maps the human-readable headers used by the Download CSV export to DB columns.
    // 'ID' is intentionally absent — auto-increment handles it on insert.
    $header_map = [
        'Timestamp'                     => 'timestamp',
        'First Name'                    => 'first_name',
        'Last Name'                     => 'last_name',
        'Pronouns'                      => 'pronouns',
        'Display Name'                  => 'display_name',
        'Email'                         => 'email',
        'Primary Affiliation'           => 'primary_affiliation',
        'Other Organizing Affiliations' => 'other_organizing_affiliations',
        'Pod or Buddy Name'             => 'pod_or_buddy_name',
        'Volunteer Hours'               => 'volunteer_hours',
        'Accessibility Needs'           => 'accessibility_needs',
        'Donation Pledge'               => 'donation_pledge',
        'Donation Status'               => 'donation_status',
        'Donation Received'             => 'donation_received',
        'NVDA Experience'               => 'nvda_experience',
        'East PDX Trainings'            => 'epx_trainings',
        'Other Trainings'               => 'other_trainings',
        'Motivation'                    => 'motivation',
        'Session A'                     => 'session_a',
        'Session B'                     => 'session_b',
        'Session C'                     => 'session_c',
        'Session D'                     => 'session_d',
        'Session E'                     => 'session_e',
        'Refcode'                       => 'refcode',
    ];

    // Build a positional index: CSV column index → DB column name (null = skip)
    $col_index = [];
    foreach ( $headers as $i => $h ) {
        $col_index[ $i ] = $header_map[ trim( $h ) ] ?? null;
    }

    if ( ! in_array( 'email', $col_index, true ) ) {
        fclose( $fh );
        return new WP_Error( 'format', 'No Email column found. Is this a bootcamp registrations CSV?' );
    }

    global $wpdb;
    $table    = $wpdb->prefix . 'bootcamp_registrations';
    $inserted = 0;
    $updated  = 0;
    $errors   = 0;
    $line     = 1;

    while ( ( $row = fgetcsv( $fh ) ) !== false ) {
        $line++;
        $data = [];
        foreach ( $col_index as $pos => $col ) {
            if ( $col === null ) continue;
            $val          = $row[ $pos ] ?? '';
            $data[ $col ] = ( $val === '—' ) ? '' : $val;
        }

        if ( empty( $data['email'] ) || ! is_email( $data['email'] ) ) {
            error_log( "Bootcamp restore: skipping line $line — invalid or missing email." );
            $errors++;
            continue;
        }

        $exists = (bool) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM `$table` WHERE email = %s", $data['email'] )
        );

        if ( $exists ) {
            $result = $wpdb->update( $table, $data, [ 'email' => $data['email'] ] );
        } else {
            $result = $wpdb->insert( $table, $data );
        }

        if ( $result === false ) {
            error_log( "Bootcamp restore: DB error on line $line — " . $wpdb->last_error );
            $errors++;
        } elseif ( $exists ) {
            $updated++;
        } else {
            $inserted++;
        }
    }

    fclose( $fh );
    return [ 'inserted' => $inserted, 'updated' => $updated, 'errors' => $errors ];
}

// ─── Pre-deletion SQL dump ────────────────────────────────────────────────────

function bootcamp_dump_registrations() {
    global $wpdb;
    $table    = $wpdb->prefix . 'bootcamp_registrations';
    $filename = WP_CONTENT_DIR . '/bootcamp-registrations-' . date( 'Y-m-d-His' ) . '.csv';

    $rows = $wpdb->get_results( "SELECT * FROM `$table` ORDER BY timestamp DESC" );
    if ( $rows === null ) {
        error_log( 'Bootcamp dump: could not read table — ' . $wpdb->last_error );
        return false;
    }

    $fh = fopen( $filename, 'w' );
    if ( ! $fh ) {
        error_log( 'Bootcamp dump: could not open ' . $filename . ' for writing.' );
        return false;
    }

    bootcamp_write_csv( $fh, $rows );
    fclose( $fh );
    return $filename;
}

// ─── Admin menu ───────────────────────────────────────────────────────────────

// Handle session capacity saves before any output is sent
add_action( 'admin_init', 'bootcamp_maybe_save_capacities' );
function bootcamp_maybe_save_capacities() {
    if ( ! isset( $_POST['bootcamp_save_capacities'] ) ) {
        return;
    }
    check_admin_referer( 'bootcamp_save_capacities' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Insufficient permissions.' );
    }

    $stored = [];
    foreach ( bootcamp_sessions() as $key => $session ) {
        $i = 0;
        foreach ( $session['options'] as $name => $default_cap ) {
            $stored[ $key ][ $i ] = max( 0, intval( $_POST['bootcamp_cap'][ $key ][ $i ] ?? $default_cap ) );
            $i++;
        }
    }
    update_option( 'bootcamp_session_capacities', $stored );
}

// Handle ActBlue settings saves
add_action( 'admin_init', 'bootcamp_maybe_save_actblue_settings' );
function bootcamp_maybe_save_actblue_settings() {
    if ( ! isset( $_POST['bootcamp_save_actblue'] ) ) {
        return;
    }
    check_admin_referer( 'bootcamp_save_actblue' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Insufficient permissions.' );
    }
    update_option( 'bootcamp_actblue_campaign', sanitize_text_field( $_POST['bootcamp_actblue_campaign'] ?? '' ) );
    update_option( 'bootcamp_webhook_user',     sanitize_text_field( $_POST['bootcamp_webhook_user'] ?? '' ) );
    // Only overwrite password if a new one was entered
    if ( ! empty( $_POST['bootcamp_webhook_pass'] ) ) {
        update_option( 'bootcamp_webhook_pass', sanitize_text_field( $_POST['bootcamp_webhook_pass'] ) );
    }
}

// Handle test email sending
add_action( 'admin_init', 'bootcamp_maybe_send_test_email' );
function bootcamp_maybe_send_test_email() {
    if ( ! isset( $_POST['bootcamp_send_test_email'] ) ) {
        return;
    }
    check_admin_referer( 'bootcamp_test_email' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Insufficient permissions.' );
    }

    $test_email = sanitize_email( $_POST['bootcamp_test_email'] ?? '' );
    if ( ! is_email( $test_email ) ) {
        wp_die( 'Invalid email address.' );
    }

    // Create sample registration data for the test email
    $sample_data = [
        'email'              => $test_email,
        'first_name'         => 'Test',
        'last_name'          => 'User',
        'session_a'          => 'How to Start a Mutual Aid Project Workshop / Community Track',
        'session_b'          => 'Self Care & Managing Burnout / Community Track',
        'session_c'          => 'Basic First Aid for NVDA / Community Track',
        'session_d'          => 'Basic First Aid for NVDA Part 2 / Community Track',
        'session_e'          => 'Decolonization Workshop / Community Track',
        'donation_pledge'    => '$100',
    ];

    bootcamp_send_test_email_message( $sample_data );
}

// Writes the standard CSV header row and all registration rows to an open file handle.
// Used by both the Download CSV export and the pre-deletion backup so they stay identical.
function bootcamp_write_csv( $fh, $rows ) {
    fputcsv( $fh, [
        'ID', 'Timestamp', 'First Name', 'Last Name', 'Pronouns', 'Display Name',
        'Email', 'Primary Affiliation', 'Other Organizing Affiliations', 'Pod or Buddy Name',
        'Volunteer Hours', 'Accessibility Needs', 'Donation Pledge', 'Donation Status',
        'Donation Received', 'NVDA Experience', 'East PDX Trainings', 'Other Trainings',
        'Motivation', 'Session A', 'Session B', 'Session C', 'Session D', 'Session E', 'Refcode',
    ] );
    foreach ( $rows as $row ) {
        fputcsv( $fh, [
            $row->id,
            $row->timestamp,
            $row->first_name,
            $row->last_name,
            $row->pronouns ?: '—',
            $row->display_name ?: '—',
            $row->email,
            $row->primary_affiliation ?: '—',
            $row->other_organizing_affiliations ?: '—',
            $row->pod_or_buddy_name ?: '—',
            $row->volunteer_hours ?: '—',
            $row->accessibility_needs ?: '—',
            $row->donation_pledge ?: '—',
            $row->donation_status ?: '—',
            $row->donation_received ?: '—',
            $row->nvda_experience ?: '—',
            $row->epx_trainings ?: '—',
            $row->other_trainings ?: '—',
            $row->motivation ?: '—',
            $row->session_a ?: '—',
            $row->session_b ?: '—',
            $row->session_c ?: '—',
            $row->session_d ?: '—',
            $row->session_e ?: '—',
            $row->refcode ?: '—',
        ] );
    }
}

// Handle CSV export before any output is sent
add_action( 'admin_init', 'bootcamp_maybe_export_csv' );
function bootcamp_maybe_export_csv() {
    if ( ! isset( $_GET['page'], $_GET['export'] ) ) {
        return;
    }
    if ( $_GET['page'] !== 'bootcamp-registrations' || $_GET['export'] != 1 ) {
        return;
    }
    // Verify nonce and capability — export contains sensitive PII
    if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'bootcamp_export_csv' ) ) {
        wp_die( 'Security check failed.' );
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Insufficient permissions.' );
    }

    error_log( sprintf( 'Bootcamp: CSV exported by user %d at %s', get_current_user_id(), current_time( 'mysql' ) ) );

    global $wpdb;
    $table_name = $wpdb->prefix . 'bootcamp_registrations';

    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="bootcamp-registrations-' . date( 'Y-m-d' ) . '.csv"' );
    header( 'Pragma: no-cache' );
    header( 'Expires: 0' );

    $output = fopen( 'php://output', 'w' );
    if ( ! $output ) {
        wp_die( 'Could not generate export.' );
    }
    bootcamp_write_csv( $output, $wpdb->get_results( "SELECT * FROM $table_name ORDER BY timestamp DESC" ) );
    fclose( $output );
    exit;
}

add_action( 'admin_menu', 'bootcamp_admin_menu' );
function bootcamp_admin_menu() {
    add_submenu_page(
        'tools.php',
        'Bootcamp Registrations',
        'Bootcamp Registrations',
        'manage_options',
        'bootcamp-registrations',
        'bootcamp_registrations_page'
    );
}

function bootcamp_registrations_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'bootcamp_registrations';

    // Handle CSV restore
    if ( isset( $_POST['bootcamp_restore_csv'] ) ) {
        check_admin_referer( 'bootcamp_restore_csv' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }
        $result = bootcamp_import_csv( $_FILES['bootcamp_csv_file'] ?? [] );
        if ( is_wp_error( $result ) ) {
            echo '<div class="notice notice-error"><p>Restore failed: ' . esc_html( $result->get_error_message() ) . '</p></div>';
        } else {
            echo '<div class="notice notice-success"><p>Restore complete: '
                . esc_html( $result['inserted'] ) . ' inserted, '
                . esc_html( $result['updated'] ) . ' updated, '
                . esc_html( $result['errors'] ) . ' skipped due to errors.</p></div>';
        }
    }

    // Handle clear-all action
    if ( isset( $_POST['bootcamp_clear_all'] ) ) {
        check_admin_referer( 'bootcamp_clear_all' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );

        $backup_path = bootcamp_dump_registrations();
        if ( $backup_path === false ) {
            echo '<div class="notice notice-error"><p><strong>Aborted:</strong> Could not write a pre-deletion backup. Registrations were <strong>not</strong> deleted. Check file permissions on the uploads directory.</p></div>';
        } else {
            $wpdb->query( "TRUNCATE TABLE $table_name" );
            error_log( sprintf( 'Bootcamp: User %d cleared %d registrations at %s. Backup: %s', get_current_user_id(), $count, current_time( 'mysql' ), $backup_path ) );
            echo '<div class="notice notice-success"><p>All registrations cleared. Backup saved to <code>' . esc_html( $backup_path ) . '</code></p></div>';
        }
    }

    echo '<div class="wrap"><h2>Bootcamp Registrations</h2>';
    printf( '<p><a href="%s" class="button button-primary">Download CSV</a></p>',
        esc_url( wp_nonce_url( add_query_arg( 'export', '1' ), 'bootcamp_export_csv' ) )
    );

    $results = $wpdb->get_results( "SELECT id, timestamp, first_name, last_name, email, donation_pledge FROM $table_name ORDER BY timestamp DESC LIMIT 20" );

    if ( $results ) {
        echo '<h3>Last 20 Registrations</h3>';
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>ID</th><th>Time</th><th>Name</th><th>Email</th><th>Pledge</th></tr></thead><tbody>';
        foreach ( $results as $row ) {
            printf(
                '<tr><td>%d</td><td>%s</td><td>%s %s</td><td>%s</td><td>%s</td></tr>',
                esc_html( $row->id ),
                esc_html( $row->timestamp ),
                esc_html( $row->first_name ),
                esc_html( $row->last_name ),
                esc_html( $row->email ),
                esc_html( $row->donation_pledge ?: '—' )
            );
        }
        echo '</tbody></table>';
    } else {
        echo '<p>No registrations yet.</p>';
    }

    // ── CSV restore ───────────────────────────────────────────────────────────
    ?>
    <h2 style="margin-top: 2em;">Restore from CSV</h2>
    <p>Upload a CSV exported by the <em>Download CSV</em> button to restore or backfill registrations.
       Rows whose email already exists in the database will be overwritten; new emails will be inserted.</p>
    <form method="POST" enctype="multipart/form-data" style="max-width: 500px;">
      <?php wp_nonce_field( 'bootcamp_restore_csv' ); ?>
      <input type="hidden" name="bootcamp_restore_csv" value="1">
      <table class="form-table">
        <tr>
          <th><label for="bc_csv_file">CSV File</label></th>
          <td><input type="file" id="bc_csv_file" name="bootcamp_csv_file" accept=".csv" required></td>
        </tr>
      </table>
      <p><input type="submit" class="button button-primary" value="Upload and Restore"></p>
    </form>
    <?php

    // ── Session capacity settings ──────────────────────────────────────────────
    if ( current_user_can( 'manage_options' ) ) :
        if ( isset( $_POST['bootcamp_save_capacities'] ) ) {
            echo '<div class="notice notice-success"><p>Session capacities saved.</p></div>';
        }
        ?>
        <h2 style="margin-top: 2em;">Session Capacities</h2>
        <p>Set the maximum number of first-choice registrations allowed per session option. The option is removed from the form once full.</p>
        <form method="POST">
          <?php wp_nonce_field( 'bootcamp_save_capacities' ); ?>
          <input type="hidden" name="bootcamp_save_capacities" value="1">
          <?php foreach ( bootcamp_sessions() as $key => $session ) : ?>
            <h4><?php echo esc_html( $session['label'] ); ?></h4>
            <table class="wp-list-table widefat fixed striped" style="max-width: 700px;">
              <thead><tr><th>Session Option</th><th style="width:120px">Capacity</th></tr></thead>
              <tbody>
              <?php $i = 0; foreach ( $session['options'] as $name => $cap ) : ?>
                <tr>
                  <td><?php echo esc_html( $name ); ?></td>
                  <td>
                    <input type="number" name="bootcamp_cap[<?php echo esc_attr( $key ); ?>][<?php echo $i; ?>]"
                           value="<?php echo esc_attr( $cap ); ?>" min="0" style="width: 80px;">
                  </td>
                </tr>
              <?php $i++; endforeach; ?>
              </tbody>
            </table>
          <?php endforeach; ?>
          <p><input type="submit" class="button button-primary" value="Save Capacities"></p>
        </form>

        <?php if ( isset( $_POST['bootcamp_save_actblue'] ) ) : ?>
          <div class="notice notice-success"><p>ActBlue settings saved.</p></div>
        <?php endif; ?>

        <h2 style="margin-top: 2em;">ActBlue Settings</h2>
        <p>
          Configure the ActBlue campaign and webhook credentials.<br>
          Webhook endpoint: <code><?php echo esc_html( rest_url( 'bootcamp/v1/actblue-webhook' ) ); ?></code>
        </p>
        <form method="POST" style="max-width: 500px;">
          <?php wp_nonce_field( 'bootcamp_save_actblue' ); ?>
          <input type="hidden" name="bootcamp_save_actblue" value="1">
          <table class="form-table">
            <tr>
              <th><label for="bc_campaign">ActBlue Campaign ID</label></th>
              <td><input type="text" id="bc_campaign" name="bootcamp_actblue_campaign"
                         value="<?php echo esc_attr( get_option( 'bootcamp_actblue_campaign', '' ) ); ?>"
                         class="regular-text" placeholder="YOUR_CAMPAIGN"></td>
            </tr>
            <tr>
              <th><label for="bc_wh_user">Webhook Username</label></th>
              <td><input type="text" id="bc_wh_user" name="bootcamp_webhook_user"
                         value="<?php echo esc_attr( get_option( 'bootcamp_webhook_user', '' ) ); ?>"
                         class="regular-text" autocomplete="off"></td>
            </tr>
            <tr>
              <th><label for="bc_wh_pass">Webhook Password</label></th>
              <td>
                <input type="password" id="bc_wh_pass" name="bootcamp_webhook_pass"
                       value="" class="regular-text" autocomplete="new-password"
                       placeholder="<?php echo get_option( 'bootcamp_webhook_pass' ) ? '(saved — enter new to change)' : ''; ?>">
              </td>
            </tr>
          </table>
          <p><input type="submit" class="button button-primary" value="Save ActBlue Settings"></p>
        </form>

        <h2 style="margin-top: 2em;">Test Email</h2>
        <p>Send a test confirmation email to verify email functionality is working correctly.</p>
        <?php
        if ( isset( $_POST['bootcamp_send_test_email'] ) ) {
            if ( bootcamp_send_test_email_message( [
                'email'           => sanitize_email( $_POST['bootcamp_test_email'] ),
                'first_name'      => 'Test',
                'last_name'       => 'User',
                'session_a'       => 'How to Start a Mutual Aid Project Workshop / Community Track',
                'session_b'       => 'Self Care & Managing Burnout / Community Track',
                'session_c'       => 'Basic First Aid for NVDA / Community Track',
                'session_d'       => 'Basic First Aid for NVDA Part 2 / Community Track',
                'session_e'       => 'Decolonization Workshop / Community Track',
                'donation_pledge' => '$100',
            ] ) ) {
                echo '<div class="notice notice-success"><p>✓ Test email sent successfully to ' . esc_html( sanitize_email( $_POST['bootcamp_test_email'] ) ) . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>✗ Failed to send test email. Check your server\'s mail configuration.</p></div>';
            }
        }
        ?>
        <form method="POST" style="max-width: 500px;">
          <?php wp_nonce_field( 'bootcamp_test_email' ); ?>
          <input type="hidden" name="bootcamp_send_test_email" value="1">
          <table class="form-table">
            <tr>
              <th><label for="bc_test_email">Email Address</label></th>
              <td>
                <input type="email" id="bc_test_email" name="bootcamp_test_email"
                       value="" class="regular-text" required placeholder="your@email.com">
              </td>
            </tr>
          </table>
          <p><input type="submit" class="button button-primary" value="Send Test Email"></p>
        </form>
    <?php endif;

    ?>
    <hr style="margin-top: 3em; border-color: #b32d2e;">
    <h2 style="color: #b32d2e;">Danger Zone</h2>
    <p>Permanently deletes every registration record. This cannot be undone.</p>
    <button type="button" class="button button-secondary"
            style="color:#b32d2e; border-color:#b32d2e;"
            onclick="document.getElementById('bootcamp-clear-modal').style.display='flex'">
        Clear All Registrations
    </button>

    <div id="bootcamp-clear-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:99999; align-items:center; justify-content:center;">
      <div style="background:#fff; border-top:4px solid #b32d2e; border-radius:3px; padding:28px 32px; max-width:420px; width:90%; box-shadow:0 4px 24px rgba(0,0,0,.3);">
        <h3 style="margin-top:0; color:#b32d2e;">Delete all registrations?</h3>
        <p>This will permanently erase every registration in the database. There is no undo.</p>
        <p>Type <strong>DELETE</strong> below to confirm:</p>
        <input type="text" id="bootcamp-clear-confirm-input" placeholder="DELETE"
               style="width:100%; padding:6px 8px; box-sizing:border-box; border:1px solid #b32d2e; margin-bottom:16px;"
               oninput="document.getElementById('bootcamp-clear-submit').disabled = this.value !== 'DELETE'">
        <form method="POST">
          <?php echo wp_nonce_field( 'bootcamp_clear_all', '_wpnonce', true, false ); ?>
          <input type="hidden" name="bootcamp_clear_all" value="1">
          <button type="submit" id="bootcamp-clear-submit"
                  class="button" disabled
                  style="color:#fff; background:#b32d2e; border-color:#b32d2e; margin-right:8px;">
              Yes, delete everything
          </button>
          <button type="button" class="button button-secondary"
                  onclick="document.getElementById('bootcamp-clear-modal').style.display='none';
                           document.getElementById('bootcamp-clear-confirm-input').value='';
                           document.getElementById('bootcamp-clear-submit').disabled=true;">
              Cancel
          </button>
        </form>
      </div>
    </div>
    <?php

    echo '</div>';
}

// ─── Session definitions ──────────────────────────────────────────────────────

// Default capacities are defined inline. Stored option values override them.
function bootcamp_sessions() {
    $stored = get_option( 'bootcamp_session_capacities', [] );

    $sessions = [
        'a' => [
            'label'   => 'Breakout Session A: Saturday 1 PM – 2:30 PM',
            'col'     => 'session_a',
            'options' => [
                'How to Start a Mutual Aid Project Workshop / Community Track'              => 100,
                'How to Start an Affinity Group Workshop / Organizing Track'                => 100,
                'How to Start a Safety Team Workshop / Safety Track'                        => 100,
                'Intro to Digital Security / Bonus Track'                                   => 100,
            ],
        ],
        'b' => [
            'label'   => 'Breakout Session B: Saturday 3 PM – 4:30 PM',
            'col'     => 'session_b',
            'options' => [
                'Self Care & Managing Burnout / Community Track'                                        => 100,
                'Leadership and Group Development / Organizing Track'                                   => 100,
                'Train the Trainers: NVDA Marshal Manual / Safety Track'                                => 100,
                'Moving from Protest to Strategic Non-Cooperation / Bonus Track'                        => 100,
            ],
        ],
        'c' => [
            'label'   => 'Breakout Session C: Sunday 9 AM – 10:30 AM',
            'col'     => 'session_c',
            'options' => [
                'Basic First Aid for NVDA / Community Track'                                => 100,
                'Coalition Building / Organizing Track'                                     => 100,
                'Advanced Deescalation & the Grey Rock Method / Safety Track'               => 100,
                'Organizing through Conflict and Discomfort (Part 1) / Bonus Track'         => 40,
            ],
        ],
        'd' => [
            'label'   => 'Breakout Session D: Sunday 10:45 AM – 12:15 PM',
            'col'     => 'session_d',
            'options' => [
                'Basic First Aid for NVDA Part 2 / Community Track'                        => 100,
                'Strategic Action Planning / Organizing Track'                             => 100,
                'Preparing for NVCD / High Risk NVDA Part 1 / Safety Track'                => 100,
                'Organizing through Conflict and Discomfort (Part 2) / Bonus Track'        => 40,
            ],
        ],
        'e' => [
            'label'   => 'Breakout Session E: Sunday 1:15 PM – 2:45 PM',
            'col'     => 'session_e',
            'options' => [
                'Decolonization Workshop / Community Track'                                 => 100,
                'Action Troubleshooting Clinic / Organizing Track'                          => 100,
                'Preparing for NVCD / High Risk NVDA Part 2 / Safety Track'                 => 100,
                'Election Protection / Bonus Track'                                         => 100,
            ],
        ],
    ];

    // Overlay stored capacities
    foreach ( $sessions as $key => &$session ) {
        $i = 0;
        foreach ( $session['options'] as $name => &$cap ) {
            if ( isset( $stored[ $key ][ $i ] ) ) {
                $cap = $stored[ $key ][ $i ];
            }
            $i++;
        }
    }
    unset( $session, $cap );

    return $sessions;
}

// Returns available (not-full) options for a given session slot.
function bootcamp_available_options( $session_key ) {
    global $wpdb;
    $table    = $wpdb->prefix . 'bootcamp_registrations';
    $sessions = bootcamp_sessions();

    if ( ! isset( $sessions[ $session_key ] ) ) {
        return [];
    }
    $session = $sessions[ $session_key ];
    $col     = $session['col'];

    // Whitelist column name — never interpolate unvalidated identifiers into SQL
    $allowed_cols = [ 'session_a', 'session_b', 'session_c', 'session_d', 'session_e' ];
    if ( ! in_array( $col, $allowed_cols, true ) ) {
        return [];
    }

    $rows = $wpdb->get_results(
        "SELECT `{$col}` AS session_option, COUNT(*) AS cnt FROM `{$table}` GROUP BY `{$col}`"
    );

    $counts = [];
    foreach ( $rows as $row ) {
        $counts[ $row->session_option ] = intval( $row->cnt );
    }

    $available = [];
    foreach ( $session['options'] as $name => $capacity ) {
        if ( ( $counts[ $name ] ?? 0 ) < $capacity ) {
            $available[] = $name;
        }
    }
    return $available;
}

// Renders <option> tags for a session select.
// Pass $preselect_first = true to auto-select the first available option (useful for testing).
function bootcamp_session_options( $session_key, $preselect_first = false ) {
    $options = bootcamp_available_options( $session_key );
    if ( empty( $options ) ) {
        echo '<option value="">— All sessions full —</option>';
        return;
    }
    if ( ! $preselect_first ) {
        echo '<option value="">Select a session</option>';
    }
    foreach ( $options as $i => $name ) {
        $selected = ( $preselect_first && $i === 0 ) ? ' selected' : '';
        printf( '<option value="%s"%s>%s</option>', esc_attr( $name ), $selected, esc_html( $name ) );
    }
}

// ─── ActBlue webhook REST endpoint ────────────────────────────────────────────

add_action( 'rest_api_init', 'bootcamp_register_rest_routes' );
function bootcamp_register_rest_routes() {
    register_rest_route( 'bootcamp/v1', '/actblue-webhook', [
        'methods'             => [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS' ],
        'callback'            => 'bootcamp_actblue_webhook_handler',
        'permission_callback' => '__return_true', // auth handled inside
    ] );
    register_rest_route( 'bootcamp/v1', '/export', [
        'methods'             => 'GET',
        'callback'            => 'bootcamp_rest_export_handler',
        'permission_callback' => fn() => current_user_can( 'manage_options' ),
    ] );
}

function bootcamp_rest_export_handler() {
    global $wpdb;
    $table = $wpdb->prefix . 'bootcamp_registrations';

    error_log( sprintf( 'Bootcamp: REST export by user %d at %s', get_current_user_id(), current_time( 'mysql' ) ) );

    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="bootcamp-registrations-' . date( 'Y-m-d' ) . '.csv"' );
    header( 'Pragma: no-cache' );
    header( 'Expires: 0' );

    $fh = fopen( 'php://output', 'w' );
    bootcamp_write_csv( $fh, $wpdb->get_results( "SELECT * FROM `$table` ORDER BY timestamp DESC" ) );
    fclose( $fh );
    exit;
}

function bootcamp_actblue_webhook_handler( WP_REST_Request $request ) {
    // ── Basic Auth check ──────────────────────────────────────────────────────
    $stored_user = get_option( 'bootcamp_webhook_user', '' );
    $stored_pass = get_option( 'bootcamp_webhook_pass', '' );

    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ( empty( $auth ) ) {
        // Some server configs put it here instead
        $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    }

    if ( ! preg_match( '/^Basic\s+(.+)$/i', $auth, $m ) ) {
        return new WP_REST_Response( [ 'error' => 'Unauthorized' ], 401 );
    }
    [ $user, $pass ] = array_pad( explode( ':', base64_decode( $m[1] ), 2 ), 2, '' );
    // Use hash_equals() for timing-safe comparison to prevent timing attacks
    if ( ! hash_equals( $stored_user, $user ) || ! hash_equals( $stored_pass, $pass ) ) {
        return new WP_REST_Response( [ 'error' => 'Unauthorized' ], 401 );
    }

    // ── Parse payload ─────────────────────────────────────────────────────────
    // Log the full HTTP request for debugging
    $log_output = "=== FULL ACTBLUE WEBHOOK REQUEST ===\n";
    $log_output .= "METHOD: " . $request->get_method() . "\n";
    $log_output .= "PATH: " . $request->get_route() . "\n";
    $log_output .= "HEADERS:\n";
    foreach ( $request->get_headers() as $key => $value ) {
        // Mask the Authorization header value for security
        if ( 'authorization' === strtolower( $key ) ) {
            $log_output .= "  $key: [REDACTED]\n";
        } else {
            $log_output .= "  $key: " . ( is_array( $value ) ? implode( ', ', $value ) : $value ) . "\n";
        }
    }
    $log_output .= "BODY:\n" . $request->get_body() . "\n";
    $log_output .= "=== END REQUEST ===\n";

    // Write to a file in wp-content for easy access via file manager
    $log_file = WP_CONTENT_DIR . '/actblue-webhook.log';
    $write_result = @file_put_contents( $log_file, $log_output, FILE_APPEND );

    // If wp-content is not writable, try the uploads directory as fallback
    if ( $write_result === false ) {
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/actblue-webhook.log';
        @file_put_contents( $log_file, $log_output, FILE_APPEND );
    }

    $body = $request->get_json_params() ?? [];

    // ActBlue nests data under contribution.refcodes.refcode and contribution.amount.
    // These are the most likely paths; adjust if the real payload differs.
    $refcode = $body['contribution']['refcodes']['refcode']
        ?? $body['refcode']
        ?? '';
    $amount  = $body['contribution']['amount']
        ?? $body['amount']
        ?? '';

    $refcode = sanitize_text_field( $refcode );
    $amount  = sanitize_text_field( $amount );

    if ( empty( $refcode ) ) {
        return new WP_REST_Response( [ 'error' => 'Missing refcode' ], 400 );
    }

    global $wpdb;
    $table = $wpdb->prefix . 'bootcamp_registrations';

    $updated = $wpdb->update(
        $table,
        [ 'donation_status' => 'donated', 'donation_received' => $amount ],
        [ 'refcode' => $refcode ],
        [ '%s', '%s' ],
        [ '%s' ]
    );

    if ( $updated === false ) {
        error_log( 'ActBlue webhook DB update failed: ' . $wpdb->last_error );
        return new WP_REST_Response( [ 'error' => 'Database error' ], 500 );
    }

    return new WP_REST_Response( [ 'success' => true ], 200 );
}

// ─── Email confirmation ───────────────────────────────────────────────────────

// Set the From address for confirmation emails
add_filter( 'wp_mail_from', 'bootcamp_mail_from' );
function bootcamp_mail_from( $from ) {
    return 'noreply@' . wp_parse_url( home_url(), PHP_URL_HOST );
}

// Set the From name for confirmation emails
add_filter( 'wp_mail_from_name', 'bootcamp_mail_from_name' );
function bootcamp_mail_from_name( $from_name ) {
    return 'Resistance Bootcamp 2026';
}

// Build email message body (shared by confirmation and test emails)
function bootcamp_build_email_message( $data ) {
    $message = "Hello {$data['first_name']},\n\n";
    $message .= "Thank you for registering for Resistance Bootcamp 2026!\n\n";
    $message .= "We've received your registration and will be in touch with location details soon.\n\n";

    $message .= "--- Your Registration Summary ---\n\n";
    $message .= "Name: {$data['first_name']} {$data['last_name']}\n";
    $message .= "Email: {$data['email']}\n";

    // Include session selections
    $sessions = bootcamp_sessions();
    $session_keys = ['a', 'b', 'c', 'd', 'e'];
    foreach ( $session_keys as $key ) {
        $col = 'session_' . $key;
        if ( ! empty( $data[ $col ] ) ) {
            $session_label = $sessions[ $key ]['label'] ?? '';
            $message .= "\n{$session_label}:\n{$data[$col]}\n";
        }
    }

    // Add donation information if applicable
    if ( ! empty( $data['donation_pledge'] ) ) {
        $message .= "\nDonation Pledge: {$data['donation_pledge']}\n";
    }

    $message .= "\n--- End Summary ---\n\n";
    $message .= "If you have any questions, please visit our website or contact us through our main channels.\n\n";

    // Footer
    $message .= "---\n";
    $message .= "Note: This is an automated message sent from a noreply address. Please do not reply to this email, as replies are not monitored. If you need to reach us, please contact us through our website.\n";

    return $message;
}

// Send registration confirmation email
function bootcamp_send_confirmation_email( $data, $reg_id ) {
    $to      = $data['email'];
    $subject = 'Resistance Bootcamp 2026 Registration Confirmation';
    $message = bootcamp_build_email_message( $data );

    // Send email
    $headers = [ 'Content-Type: text/plain; charset=UTF-8' ];
    $sent = wp_mail( $to, $subject, $message, $headers );

    if ( ! $sent ) {
        error_log( sprintf( 'Bootcamp: Failed to send confirmation email to %s for registration %d', $to, $reg_id ) );
    } else {
        error_log( sprintf( 'Bootcamp: Confirmation email sent to %s for registration %d', $to, $reg_id ) );
    }
}

// Send test email
function bootcamp_send_test_email_message( $data ) {
    $to      = $data['email'];
    $subject = '[TEST] Resistance Bootcamp 2026 Registration Confirmation';
    $message = bootcamp_build_email_message( $data );

    // Send email
    $headers = [ 'Content-Type: text/plain; charset=UTF-8' ];
    $sent = wp_mail( $to, $subject, $message, $headers );

    return $sent;
}

// ─── Thank-you page shortcode ─────────────────────────────────────────────────

add_shortcode( 'bootcamp_thankyou', 'bootcamp_thankyou_shortcode' );
function bootcamp_thankyou_shortcode() {
    // If ActBlue redirected back for a merch order (different cookie), hand off to the merch thank-you page.
    if ( ! empty( $_COOKIE['merch_refcode'] ) && empty( $_COOKIE['bootcamp_refcode'] ) ) {
        $merch_page = get_page_by_path( 'merch-thank-you' );
        $merch_url  = $merch_page ? get_permalink( $merch_page->ID ) : home_url( '/merch-thank-you/' );
        wp_redirect( $merch_url );
        exit;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'bootcamp_registrations';

    $refcode = sanitize_text_field( $_GET['refcode'] ?? '' );
    $reg_id  = intval( $_GET['reg_id'] ?? 0 );

    // Ignore the literal ActBlue template placeholder in case it wasn't substituted
    if ( $refcode === '[REFCODE]' ) {
        $refcode = '';
    }

    // Fall back to cookie set before ActBlue redirect if URL params are absent
    if ( ! $refcode && ! $reg_id ) {
        $refcode = sanitize_text_field( $_COOKIE['bootcamp_refcode'] ?? '' );
    }

    // Clear the cookie now that we've used it
    if ( isset( $_COOKIE['bootcamp_refcode'] ) ) {
        setcookie( 'bootcamp_refcode', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ] );
    }

    $reg = null;
    if ( $refcode ) {
        // Try exact match first, then hyphen-stripped match (ActBlue strips hyphens from refcodes)
        $reg = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `$table` WHERE refcode = %s", $refcode ) )
            ?? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `$table` WHERE REPLACE(refcode, '-', '') = %s", $refcode ) );
    } elseif ( $reg_id ) {
        $reg = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `$table` WHERE id = %d", $reg_id ) );
    }

    // Session labels for display
    $session_labels = [];
    foreach ( bootcamp_sessions() as $key => $session ) {
        $session_labels[ $key ] = $session['label'];
    }

    ob_start();
    ?>
    <style>
      .bootcamp-thankyou {
        font-family: 'Quattrocento', Georgia, serif;
        max-width: 640px;
      }
      .bootcamp-thankyou h2 {
        font-family: 'Oswald', sans-serif;
        font-weight: 400;
        color: #000080;
        text-transform: uppercase;
        letter-spacing: 0.04em;
      }
      .bootcamp-thankyou .reg-summary {
        background: #f5f5f5;
        border-left: 4px solid #000080;
        padding: 16px 20px;
        margin-top: 16px;
      }
      .bootcamp-thankyou .reg-summary h3 {
        font-family: 'Oswald', sans-serif;
        font-weight: 400;
        color: #000080;
        text-transform: uppercase;
        margin: 0 0 12px;
      }
      .bootcamp-thankyou .reg-summary table { border-collapse: collapse; width: 100%; }
      .bootcamp-thankyou .reg-summary td { padding: 5px 0; vertical-align: top; }
      .bootcamp-thankyou .reg-summary td:first-child { font-weight: bold; width: 200px; color: #000080; }
      .bootcamp-thankyou .donation-confirmed {
        background: #eaf4ea;
        border-left: 4px solid #2a6e2a;
        padding: 12px 20px;
        margin-top: 16px;
        font-size: 1.05em;
      }
    </style>

    <?php if ( ! $reg ) : ?>
      <h2>Thank You for Registering!</h2>
      <p>We've received your registration and will be in touch with details soon.</p>

    <?php else : ?>
      <h2>Thank you, <?php echo esc_html( $reg->first_name ); ?>!</h2>
      <p>We've received your registration for Resistance Bootcamp 2026 and will be in touch with location details soon.</p>

      <div class="reg-summary">
        <h3>Your Registration</h3>
        <table>
          <tr><td>Name</td><td><?php echo esc_html( $reg->first_name . ' ' . $reg->last_name ); ?></td></tr>
          <tr><td>Email</td><td><?php echo esc_html( $reg->email ); ?></td></tr>
          <?php foreach ( $session_labels as $key => $label ) :
            $col  = 'session_' . $key;
            $val  = $reg->$col ?? '';
            if ( $val ) : ?>
          <tr><td><?php echo esc_html( $label ); ?></td><td><?php echo esc_html( $val ); ?></td></tr>
          <?php endif; endforeach; ?>
        </table>
      </div>

      <?php if ( $reg->donation_status === 'donated' && ! empty( $reg->donation_received ) ) : ?>
        <div class="donation-confirmed">
          ✓ Donation of <strong><?php echo esc_html( '$' . $reg->donation_received ); ?></strong> received — thank you for your support!
        </div>
      <?php elseif ( $reg->donation_status === 'pending' ) : ?>
        <p><em>Your donation is being processed. This page will reflect the confirmed amount once it clears.</em></p>
      <?php endif; ?>

    <?php endif; ?>
    <?php
    return ob_get_clean();
}

// ─── Form submission handler ──────────────────────────────────────────────────

add_action( 'admin_post_nopriv_bootcamp_register', 'handle_bootcamp_registration' );
add_action( 'admin_post_bootcamp_register',        'handle_bootcamp_registration' );
function handle_bootcamp_registration() {
    // Validate required fields early, before sanitization
    if ( empty( $_POST['email'] ) || ! is_email( $_POST['email'] ) ) {
        wp_redirect( home_url( '/register?error=invalid-email' ) );
        exit;
    }
    if ( empty( $_POST['first_name'] ) || empty( $_POST['last_name'] ) ) {
        wp_redirect( home_url( '/register?error=missing-name' ) );
        exit;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'bootcamp_registrations';

    // Pronouns: combine radio + other text
    $pronouns = sanitize_text_field( $_POST['pronouns'] ?? '' );
    if ( $pronouns === 'other' && ! empty( $_POST['pronouns_other'] ) ) {
        $pronouns = sanitize_text_field( $_POST['pronouns_other'] );
    }

    // Primary affiliation: combine select + other text
    $primary_affiliation = sanitize_text_field( $_POST['primary_affiliation'] ?? '' );
    if ( $primary_affiliation === 'Other' && ! empty( $_POST['primary_affiliation_other'] ) ) {
        $primary_affiliation = sanitize_text_field( $_POST['primary_affiliation_other'] );
    }

    // Other organizing affiliations
    $affiliations = [];
    if ( ! empty( $_POST['affiliations'] ) && is_array( $_POST['affiliations'] ) ) {
        $affiliations = array_filter(
            array_map( 'sanitize_text_field', $_POST['affiliations'] ),
            fn( $v ) => $v !== 'other'
        );
    }
    if ( ! empty( $_POST['affiliations_other'] ) ) {
        $affiliations[] = sanitize_text_field( $_POST['affiliations_other'] );
    }

    // Activist background: NVDA experience + trainings + motivation
    $nvda_experience = '';
    if ( ! empty( $_POST['nvda_experience'] ) && is_array( $_POST['nvda_experience'] ) ) {
        $nvda_experience = implode( ', ', array_map( 'sanitize_text_field', $_POST['nvda_experience'] ) );
    }

    $epx_trainings = '';
    if ( ! empty( $_POST['epx_trainings'] ) && is_array( $_POST['epx_trainings'] ) ) {
        $epx_trainings = implode( ', ', array_map( 'sanitize_text_field', $_POST['epx_trainings'] ) );
    }

    $other_trainings_arr = [];
    if ( ! empty( $_POST['other_trainings'] ) && is_array( $_POST['other_trainings'] ) ) {
        $other_trainings_arr = array_filter(
            array_map( 'sanitize_text_field', $_POST['other_trainings'] ),
            fn( $v ) => $v !== 'other'
        );
    }
    if ( ! empty( $_POST['other_trainings_other'] ) ) {
        $other_trainings_arr[] = sanitize_text_field( $_POST['other_trainings_other'] );
    }
    $other_trainings = implode( ', ', $other_trainings_arr );

    $motivation = sanitize_textarea_field( $_POST['motivation'] ?? '' );

    // ── Donation amount & refcode ──────────────────────────────────────────────
    $pledge_raw = $_POST['donation_pledge'] ?? '';
    if ( $pledge_raw === 'other' ) {
        $pledge_text = sanitize_text_field( $_POST['donation_pledge_other'] ?? '' );
    } else {
        $pledge_text = sanitize_text_field( $pledge_raw );
    }

    // Extract a numeric dollar amount from the pledge string (e.g. "$40" → 40)
    preg_match( '/\d+/', $pledge_text, $amount_match );
    $donation_amount = isset( $amount_match[0] ) ? intval( $amount_match[0] ) : 0;
    // Clamp to a sane range to prevent manipulated amounts reaching ActBlue
    $donation_amount = max( 0, min( 25000, $donation_amount ) );

    $needs_actblue   = $donation_amount > 0;
    $donation_status = $needs_actblue ? 'pending' : 'no_donation';
    $refcode         = wp_generate_uuid4();

    $data = [
        'email'                         => sanitize_email( $_POST['email'] ?? '' ),
        'first_name'                    => sanitize_text_field( $_POST['first_name'] ?? '' ),
        'last_name'                     => sanitize_text_field( $_POST['last_name'] ?? '' ),
        'pronouns'                      => $pronouns,
        'display_name'                  => sanitize_text_field( $_POST['name_tag'] ?? '' ),
        'primary_affiliation'           => $primary_affiliation,
        'other_organizing_affiliations' => implode( ', ', $affiliations ),
        'pod_or_buddy_name'             => sanitize_text_field( $_POST['group_name'] ?? '' ),
        'volunteer_hours'               => sanitize_text_field( $_POST['volunteer_hours'] ?? '' ),
        'session_a'                     => sanitize_text_field( $_POST['session_a'] ?? '' ),
        'session_b'                     => sanitize_text_field( $_POST['session_b'] ?? '' ),
        'session_c'                     => sanitize_text_field( $_POST['session_c'] ?? '' ),
        'session_d'                     => sanitize_text_field( $_POST['session_d'] ?? '' ),
        'session_e'                     => sanitize_text_field( $_POST['session_e'] ?? '' ),
        'accessibility_needs'           => sanitize_textarea_field( $_POST['accessibility'] ?? '' ),
        'donation_pledge'               => $pledge_text,
        'nvda_experience'               => $nvda_experience,
        'epx_trainings'                 => $epx_trainings,
        'other_trainings'               => $other_trainings,
        'motivation'                    => $motivation,
        'refcode'                       => $refcode,
        'donation_status'               => $donation_status,
    ];

    // Check if email already registered
    $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `$table_name` WHERE email = %s", $data['email'] ) );
    if ( $existing ) {
        wp_redirect( home_url( '/register?error=duplicate-email' ) );
        exit;
    }

    $result = $wpdb->insert( $table_name, $data );

    if ( $result === false ) {
        error_log( 'Bootcamp DB insert failed: ' . $wpdb->last_error );
        wp_die( 'Database error — please contact support.' );
    }

    $reg_id = $wpdb->insert_id;

    // Send confirmation email
    bootcamp_send_confirmation_email( $data, $reg_id );

    if ( $needs_actblue ) {
        // Store refcode in a cookie so we can identify the registrant when
        // ActBlue redirects back — ActBlue does not pass our dynamic refcode
        // back in the redirect URL.
        setcookie( 'bootcamp_refcode', $refcode, [
            'expires'  => time() + 2 * HOUR_IN_SECONDS,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ] );

        $campaign = get_option( 'bootcamp_actblue_campaign' ) ?: 'indivisiblepm1426469368';
        // Strip hyphens — ActBlue refcodes are expected to be short alphanumeric
        // strings; hyphens in a UUID may cause ActBlue to drop the refcode entirely,
        // preventing [REFCODE] substitution in the redirect URL.
        $actblue_refcode = str_replace( '-', '', $refcode );
        $actblue_url = add_query_arg( [
            'amount'  => $donation_amount,
            'refcode' => $actblue_refcode,
        ], 'https://secure.actblue.com/donate/' . rawurlencode( $campaign ) );
        wp_redirect( $actblue_url );
    } else {
        $thankyou = get_page_by_path( 'register-thank-you' );
        $base     = $thankyou ? get_permalink( $thankyou->ID ) : home_url( '/register-thank-you/' );
        wp_redirect( add_query_arg( 'reg_id', $reg_id, $base ) );
    }
    exit;
}

// ─── Registration form shortcode ──────────────────────────────────────────────

add_shortcode( 'bootcamp_registration_form', 'bootcamp_registration_form_shortcode' );
function bootcamp_registration_form_shortcode() {
    // Diagnostic: confirm which log destination is active on this host.
    error_log( 'Bootcamp: registration form loaded — error_log destination: ' . ini_get( 'error_log' ) );
    $diag_file = WP_CONTENT_DIR . '/debug.log';
    @file_put_contents( $diag_file, '[' . date( 'Y-m-d H:i:s' ) . '] Bootcamp: registration form loaded' . PHP_EOL, FILE_APPEND );

    nocache_headers();
    ob_start();
    ?>
    <style>
      /* ── Resistance Bootcamp form — matches site palette & typography ── */
      .bootcamp-form {
        max-width: 640px;
        font-family: 'Quattrocento', Georgia, serif;
        font-size: clamp(14px, 0.875rem + ((1vw - 3.2px) * 0.588), 20px);
        color: #000000;
      }
      .bootcamp-form .form-field {
        display: flex;
        flex-direction: column;
        margin-bottom: 20px;
      }
      .bootcamp-form .form-field > label.field-label {
        font-family: 'Oswald', sans-serif;
        font-weight: 400;
        font-size: 1rem;
        color: #000080;
        margin-bottom: 6px;
        text-transform: uppercase;
        letter-spacing: 0.03em;
      }
      .bootcamp-form input[type="text"],
      .bootcamp-form input[type="email"],
      .bootcamp-form select,
      .bootcamp-form textarea {
        width: 100%;
        padding: 8px 10px;
        box-sizing: border-box;
        border: 1px solid #000080;
        border-radius: 0.33rem;
        font-family: 'Quattrocento', Georgia, serif;
        font-size: inherit;
        color: #000000;
        background: #ffffff;
      }
      .bootcamp-form input[type="text"]:focus,
      .bootcamp-form input[type="email"]:focus,
      .bootcamp-form select:focus,
      .bootcamp-form textarea:focus {
        outline: 2px solid #000080;
        outline-offset: 1px;
      }
      .bootcamp-form .radio-group,
      .bootcamp-form .checkbox-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
        margin-top: 4px;
      }
      .bootcamp-form .radio-group label,
      .bootcamp-form .checkbox-group label {
        font-weight: normal;
        font-family: 'Quattrocento', Georgia, serif;
        display: flex;
        align-items: baseline;
        gap: 8px;
      }
      .bootcamp-form h3 {
        font-family: 'Oswald', sans-serif;
        font-weight: 400;
        color: #000080;
        font-size: clamp(22px, 1.378rem + ((1vw - 3.2px) * 1.369), 36px);
        margin: 36px 0 16px;
        padding-top: 20px;
        border-top: 2px solid #000080;
        text-transform: uppercase;
        letter-spacing: 0.04em;
      }
      .bootcamp-form h4 {
        font-family: 'Oswald', sans-serif;
        font-weight: 400;
        color: #000080;
        margin: 24px 0 8px;
        text-transform: uppercase;
        letter-spacing: 0.03em;
      }
      .bootcamp-form button[type="submit"] {
        margin-top: 28px;
        padding: 10px 28px;
        font-family: 'Oswald', sans-serif;
        font-size: 1.1rem;
        font-weight: 400;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        background-color: #000080;
        color: #ffffff;
        border: none;
        border-radius: 0.33rem;
        cursor: pointer;
      }
      .bootcamp-form button[type="submit"]:hover {
        background-color: #0000b3;
      }
      .bootcamp-form button[type="submit"]:focus {
        background-color: #ffffff;
        color: #000080;
        outline: 2px solid #000080;
      }
      .bootcamp-form button[type="submit"]:disabled {
        background-color: #666666;
        border-color: #666666;
        cursor: not-allowed;
        opacity: 0.6;
      }
      .bootcamp-form .other-input { margin-top: 6px; display: none; }
      .bootcamp-form .other-input.visible { display: block; }
    </style>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        // Show a text input when a radio with a given value is selected
        function watchRadio(name, triggerValue, targetId) {
            document.querySelectorAll('input[name="' + name + '"]').forEach(function (r) {
                r.addEventListener('change', function () {
                    var el = document.getElementById(targetId);
                    if (el) el.classList.toggle('visible', r.value === triggerValue && r.checked);
                });
            });
        }
        // Show a text input when a specific checkbox is checked
        function watchCheckbox(cbId, targetId) {
            var cb = document.getElementById(cbId);
            if (!cb) return;
            cb.addEventListener('change', function () {
                var el = document.getElementById(targetId);
                if (el) el.classList.toggle('visible', this.checked);
            });
        }
        watchRadio('pronouns', 'other', 'pronouns-other-input');
        // primary_affiliation is a <select>, not radios — watch it directly
        var affiliationSelect = document.querySelector('select[name="primary_affiliation"]');
        var affiliationOther  = document.getElementById('affiliation-other-input');
        if (affiliationSelect && affiliationOther) {
            affiliationSelect.addEventListener('change', function () {
                affiliationOther.classList.toggle('visible', this.value === 'Other');
            });
        }
        watchCheckbox('affiliations-other-cb', 'affiliations-other-input');
        watchCheckbox('other-trainings-other-cb', 'other-trainings-other-input');

        // Show text input when "Other" is selected in donation dropdown
        // Also update submit button label based on donation intent
        var donationSelect = document.getElementById('donation-pledge-select');
        var donationOther  = document.getElementById('donation-pledge-other-input');
        var submitBtn      = document.querySelector('.bootcamp-form button[type="submit"]');
        function updateSubmitLabel() {
            if (!submitBtn) return;
            var noDonation = donationSelect.value === 'Unable to donate' || donationSelect.value === '' || donationSelect.value === 'Already Donated';
            submitBtn.textContent = noDonation ? 'Register' : 'Register & Donate';
        }
        if (donationSelect && donationOther) {
            donationSelect.addEventListener('change', function () {
                donationOther.classList.toggle('visible', this.value === 'other');
                updateSubmitLabel();
            });
        }

        // Disable submit button after first submission to prevent double-clicks
        var form = document.querySelector('.bootcamp-form');
        if (form && submitBtn) {
            form.addEventListener('submit', function () {
                submitBtn.disabled = true;
            });
        }
    });
    </script>

    <?php
    $error = isset( $_GET['error'] ) ? sanitize_text_field( $_GET['error'] ) : '';
    $error_messages = [
        'invalid-email'   => 'Please enter a valid email address.',
        'missing-name'    => 'Please enter your first and last name.',
        'duplicate-email' => 'This email address is already registered. Please use a different email or contact support if you need to update your registration.',
    ];
    if ( $error && isset( $error_messages[ $error ] ) ) {
        echo '<div style="background:#fee; border:1px solid #c33; border-radius:3px; padding:12px 16px; margin-bottom:20px; color:#900;"><strong>Error:</strong> ' . esc_html( $error_messages[ $error ] ) . '</div>';
    }
    ?>

    <form class="bootcamp-form" method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
      <input type="hidden" name="action" value="bootcamp_register">
      <h3>Section 1: Registration</h3>

      <div class="form-field">
        <label class="field-label">Email *</label>
        <input type="email" name="email" value="" required>
      </div>

      <div class="form-field">
        <label class="field-label">Last Name *</label>
        <input type="text" name="last_name" value="" required>
      </div>

      <div class="form-field">
        <label class="field-label">First Name *</label>
        <input type="text" name="first_name" value="" required>
      </div>

      <div class="form-field">
        <label class="field-label">Preferred Pronouns *</label>
        <div class="radio-group">
          <label><input type="radio" name="pronouns" value="they/them" required> They/them</label>
          <label><input type="radio" name="pronouns" value="she/her" required> She/her</label>
          <label><input type="radio" name="pronouns" value="she/them" required> She/them</label>
          <label><input type="radio" name="pronouns" value="he/him" required> He/him</label>
          <label><input type="radio" name="pronouns" value="he/them" required> He/them</label>
          <label><input type="radio" name="pronouns" value="I prefer not to answer" required> I prefer not to answer</label>
          <label><input type="radio" name="pronouns" value="other" required> Other:</label>
          <input type="text" name="pronouns_other" id="pronouns-other-input" class="other-input" placeholder="Please specify">
        </div>
      </div>

      <div class="form-field">
        <label class="field-label">Name to Display on Name Tag</label>
        <input type="text" name="name_tag" value="">
      </div>

      <div class="form-field">
        <label class="field-label">Primary Indivisible Affiliation *</label>
        <select name="primary_affiliation" required>
          <option value="">Select</option>
          <?php
          $affiliations_list = [
            'Indivisible Beaverton', 'Blackberry Alliance', 'Citizens Indivisible', 'COIN',
            'District 2 Neighbors Indivisible', 'East Portland Indivisible (District 1)',
            'Indivisible Clackamas', 'Indivisible Clark County', 'Indivisible Forest Grove',
            'Indivisible Hilsboro', 'Indivisible Justice For All', 'Indivisible Medford',
            'Multnomah East Indivisible', 'Indivisible Oregon', 'Indivisible PDX D3',
            'Indivisible Washington County', 'Indivisiblepdx23', 'Miller Street Indivisible',
            'Salem Region Indivisible', 'SW Indivisible Resistance', 'No Indivisible affiliation',
            'Eastlandia Indivisible',
          ];
          foreach ( $affiliations_list as $aff ) {
              $sel = '';
              printf( '<option value="%s"%s>%s</option>', esc_attr( $aff ), $sel, esc_html( $aff ) );
          }
          ?>
          <option value="Other">Other:</option>
        </select>
        <input type="text" name="primary_affiliation_other" id="affiliation-other-input" class="other-input" placeholder="Please specify">
      </div>

      <div class="form-field">
        <label class="field-label">Other Organizing Affiliations (check all that apply)</label>
        <div class="checkbox-group">
          <?php
          $orgs = [
            '350.org', 'Amalgamated Transit Union (ATU)', 'American Civil Liberties Union (ACLU)',
            'Anapurna Village', 'Associated University Registered Nurses/Oregon Nurses Association (AURN/ONA)',
            'Bright Green', 'Common Defense', 'Democratic Party', 'Democratic Socialists of America',
            'East PDX Safety Team', 'EcoFaith Recovery', 'Freedom Trainers', 'Frog Brigade',
            'General Strike US', 'Good Neighbors Project', 'Hands Off',
            'Interfaith Movement for Immigrant Justice (IMIRJ)', 'Interfaith Silent Solidarity March',
            'International Alliance of Theater and Stage Employees (IATSE)',
            'International Union of Painters and Allied Trades (IUPAT)',
            'Lift Every Voice Oregon (LEVO)', 'Migra Watch', 'MoveOn', 'Multnomah Friends Meeting',
            'National Lawyers Guild (NLG)', 'National Writers Union (NWU)', 'No Kings (formerly 50501)',
            'Oregon Federation of Nurses and Health Professionals', 'Oregon For All', 'Overpass Brigade',
            'Portland Buddhists for Justice', 'Portland Community Defense',
            'Portland Contra las Deportaciones (PDXCD)', 'Portland Immigrants Rights Coalition (PIRC)',
            'Portland Raging Grannies', 'Protect Oregon', 'Rural Organizing Project',
            'Service Employees International Union (SEIU)', 'Sherwood Liberals',
            'Showing Up for Racial Justice (SURJ)', 'Signs of Fascism', 'Signs of Solidarity',
            'Sunrise Movement', 'Swing Left', 'Tesla Takedown', 'Third Act',
            'Working Families Party', 'Worth Fighting For St. Johns', 'Together Lab',
            'Jobs with Justice', 'Common Cause', 'Moms Demand Justice'
          ];
          foreach ( $orgs as $org ) {
              printf(
                  '<label><input type="checkbox" name="affiliations[]" value="%s"> %s</label>',
                  esc_attr( $org ), esc_html( $org )
              );
          }
          ?>
          <label><input type="checkbox" name="affiliations[]" value="other" id="affiliations-other-cb"> Other:</label>
          <input type="text" name="affiliations_other" id="affiliations-other-input" class="other-input" placeholder="Please specify">
        </div>
      </div>

      <div class="form-field">
        <label class="field-label">Are You Currently a Member of an Affinity Group or a Pod?</label>
        <input type="text" name="group_name" value="">
      </div>

      <div class="form-field">
        <label class="field-label">Let us know about your mobility issues or other accessibility needs and we will do our best to accommodate you.</label>
        <textarea name="accessibility" rows="2"></textarea>
      </div>

      <div class="form-field">
        <label class="field-label">Donation Pledge *</label>
        <select name="donation_pledge" id="donation-pledge-select">
          <option value="">Select a donation amount</option>
          <option value="Already Donated">I already donated more than $40</option>
          <option value="$40">I pledge to donate $40 today to partially cover the costs of my training.</option>
          <option value="$100">I pledge to donate $100 today to pay the full costs of my training.</option>
          <option value="$200">I pledge to donate $200 today to pay for the full costs of training another participant as well as myself.</option>
          <option value="$200+">I pledge to donate more than $200 today to help support Resistance Bootcamp.</option>
          <option value="Unable to donate">I am not able to donate at this time.</option>
          <option value="other">Other: I will donate a different amount.</option>
        </select>
        <input type="text" name="donation_pledge_other" id="donation-pledge-other-input" class="other-input" placeholder="Enter your pledge amount (e.g. $150)">
      </div>

      <h3>Section 2: Your Activist Background</h3>

      <div class="form-field">
        <label class="field-label">"Average number of hours per week volunteering in Resistance Activities"</label>
        <select name="volunteer_hours">
          <option value="">Select</option>
          <option value="None">None</option>
          <option value="1 hour or less">1 hour or less</option>
          <option value="2 to 5 hours">2 to 5 hours</option>
          <option value="6 to 10 hours">6 to 10 hours</option>
          <option value="10-20 hours">10-20 hours</option>
          <option value="20-30 hours">20-30 hours</option>
          <option value="30-40 hours">30-40 hours</option>
          <option value="More than 40 hours">More than 40 hours</option>
          <option value="I don't know">I don't know</option>
          <option value="I prefer not to answer">I prefer not to answer</option>
        </select>
      </div>

      <div class="form-field">
        <label class="field-label">Personal Motivations or Goals for Participating in Resistance Bootcamp 2.0</label>
        <textarea name="motivation" rows="3"></textarea>
      </div>

      <div class="form-field">
        <label class="field-label">Have you participated in non-violent direct action (NVDA)? Check all that apply.</label> <small><i>NVDA is a form of social protest and political struggle that uses nonviolent, active, and often disruptive tactics, such as marches, boycotts, sit-ins, and strikes, to confront injustice and force negotiation without using physical force.</i></small>
        <div class="checkbox-group">
          <?php
          $nvda_options = [
            'Attended a non-violent direct action',
            'Volunteered as a marshal at a non-violent direct action',
            'Provided first aid at a non-violent direct action',
            'Provided emotional support and trauma response at a non-violent direct action',
            'Served on a deescalation team at a non-violent direct action',
            'Served as police liaison at a non-violent direct action',
            'Served as a corner captain, wheel guard, or corker at a non-violent direct action',
            'Acted as a legal observer at a non-violent direct action',
            'Acted as a documentarian at a non-violent direct action',
            'Coordinated media and public relations for a non-violent direct action',
            'Donated in-kind professional services or products to a non-violent direct action',
            'Organized or helped organize a non-violent direct action',
            'Participated in a non-arrest role during nonviolent civil disobedience action',
            'Arrested during a nonviolent civil disobedience action',
          ];
          foreach ( $nvda_options as $opt ) {
              printf(
                  '<label><input type="checkbox" name="nvda_experience[]" value="%s"> %s</label>',
                  esc_attr( $opt ), esc_html( $opt )
              );
          }
          ?>
        </div>
      </div>

      <div class="form-field">
        <label class="field-label">East PDX Safety Team / Resistance Bootcamp trainings completed. Check all that apply. *</label>
        <div class="checkbox-group">
          <?php
          $epx_trainings = [
            'Resistance 101',
            'Six Steps to a Safety Team',
            'Safety Principles and Practices for NVDA Organizers',
            'Non-violent Direct Action Marshal Training',
            'Advanced Deescalation',
            'Preparing for Non-Violent Civil Disobedience',
            'Six Steps to an Affinity Group',
            'Radio Communication Protocols',
          ];
          foreach ( $epx_trainings as $t ) {
              printf(
                  '<label><input type="checkbox" name="epx_trainings[]" value="%s"> %s</label>',
                  esc_attr( $t ), esc_html( $t )
              );
          }
          ?>
        </div>
      </div>

      <div class="form-field">
        <label class="field-label">Other trainings completed. Check all that apply.</label>
        <div class="checkbox-group">
          <?php
          $other_trainings_list = [
            'Gray Rock Deescalation (Humble Boys)',
            'Community Strike Readiness (Freedom Trainers)',
            'First Aid and CPR/AED (American Red Cross or equivalent)',
            'Know Your Rights (ACLU)',
            'Legal Observer Training (NLG)',
            'Legal Observer Training (PIRC)',
            'Management of Aggressive Behavior - MOAB (Employer)',
            'Migra Watch (PIRC)',
            'Neighborhood Emergency Team Training (City of Portland)',
            'Noncooperation 101 Training (Freedom Trainers)',
            'One Million Rising (Indivisible)',
            "People's Promise Campaign (Common Cause)",
            'Resistance Lab: Organizing for Justice (Pramila Jayapal)',
            'Satori Alternatives to Managing Aggression - SAMA (Employer)',
            'Signs of Solidarity (Indivisible)',
            'Stop the Bleed (American College of Surgeons)',
            'Strategic Non-cooperation (Protect Oregon)',
            'Protest Medic Training (Indivisible)',
            'Deescalation (Portland Peace Team)'
          ];
          foreach ( $other_trainings_list as $t ) {
              printf(
                  '<label><input type="checkbox" name="other_trainings[]" value="%s"> %s</label>',
                  esc_attr( $t ), esc_html( $t )
              );
          }
          ?>
          <label><input type="checkbox" name="other_trainings[]" value="other" id="other-trainings-other-cb"> Other:</label>
          <input type="text" name="other_trainings_other" id="other-trainings-other-input" class="other-input" placeholder="Please specify">
        </div>
      </div>

      <h3>Section 3: Breakout Session Selection</h3>

      <?php foreach ( bootcamp_sessions() as $key => $session ) : ?>
      <div class="form-field">
        <label class="field-label"><?php echo esc_html( $session['label'] ); ?> *</label>
        <select name="session_<?php echo esc_attr( $key ); ?>" required>
          <?php bootcamp_session_options( $key ); ?>
        </select>
      </div>
      <?php endforeach; ?>

      <button type="submit">Register & Donate</button>
    </form>
    <?php
    return ob_get_clean();
}
