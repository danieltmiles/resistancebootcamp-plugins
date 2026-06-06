<?php
/**
 * Plugin Name: Conflict Survey
 * Description: Collect survey responses about experiences with conflict
 * Version: 1.0
 * Author: Your Team
 */

if ( ! defined( 'ABSPATH' ) ) exit;

@ini_set( 'log_errors', '1' );
@ini_set( 'error_log', WP_CONTENT_DIR . '/debug.log' );

// ─── Activation & Upgrade ────────────────────────────────────────────────────

define( 'CONFLICT_SURVEY_DB_VERSION', '1.1' );

register_activation_hook( __FILE__, 'conflict_survey_activate' );
function conflict_survey_activate() {
    conflict_survey_create_tables();
    update_option( 'conflict_survey_db_version', CONFLICT_SURVEY_DB_VERSION );
}

add_action( 'plugins_loaded', 'conflict_survey_maybe_upgrade' );
function conflict_survey_maybe_upgrade() {
    if ( get_option( 'conflict_survey_db_version' ) !== CONFLICT_SURVEY_DB_VERSION ) {
        conflict_survey_create_tables();
        update_option( 'conflict_survey_db_version', CONFLICT_SURVEY_DB_VERSION );
    }
}

function conflict_survey_create_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();

    // Surveys table
    $surveys_table = $wpdb->prefix . 'conflict_surveys';
    $sql = "CREATE TABLE $surveys_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        name VARCHAR(255) NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";
    dbDelta( $sql );

    // Survey questions table
    $questions_table = $wpdb->prefix . 'conflict_survey_questions';
    $sql = "CREATE TABLE $questions_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        survey_id mediumint(9) NOT NULL,
        question_text TEXT NOT NULL,
        question_type VARCHAR(50) NOT NULL,
        question_order smallint(6) DEFAULT 0,
        PRIMARY KEY (id),
        KEY survey_id (survey_id),
        FOREIGN KEY (survey_id) REFERENCES $surveys_table (id) ON DELETE CASCADE
    ) $charset_collate;";
    dbDelta( $sql );

    // Survey question options table
    $options_table = $wpdb->prefix . 'conflict_survey_question_options';
    $sql = "CREATE TABLE $options_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        survey_question_id mediumint(9) NOT NULL,
        option_text VARCHAR(255) NOT NULL,
        option_order smallint(6) DEFAULT 0,
        PRIMARY KEY (id),
        KEY survey_question_id (survey_question_id),
        FOREIGN KEY (survey_question_id) REFERENCES $questions_table (id) ON DELETE CASCADE
    ) $charset_collate;";
    dbDelta( $sql );

    // Survey responses table
    $responses_table = $wpdb->prefix . 'conflict_survey_responses';
    $sql = "CREATE TABLE $responses_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        survey_id mediumint(9) NOT NULL,
        respondent_name VARCHAR(255) NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY (id),
        KEY survey_id (survey_id),
        FOREIGN KEY (survey_id) REFERENCES $surveys_table (id) ON DELETE CASCADE
    ) $charset_collate;";
    dbDelta( $sql );

    // Survey answers table
    $answers_table = $wpdb->prefix . 'conflict_survey_answers';
    $sql = "CREATE TABLE $answers_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        survey_response_id mediumint(9) NOT NULL,
        survey_question_id mediumint(9) NOT NULL,
        answer_text LONGTEXT NOT NULL,
        PRIMARY KEY (id),
        KEY survey_response_id (survey_response_id),
        KEY survey_question_id (survey_question_id),
        FOREIGN KEY (survey_response_id) REFERENCES $responses_table (id) ON DELETE CASCADE,
        FOREIGN KEY (survey_question_id) REFERENCES $questions_table (id) ON DELETE CASCADE
    ) $charset_collate;";
    dbDelta( $sql );

    // Survey invitations table (email is nullable for anonymous links)
    $invitations_table = $wpdb->prefix . 'conflict_survey_invitations';
    $sql = "CREATE TABLE $invitations_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        survey_id mediumint(9) NOT NULL,
        email VARCHAR(255) DEFAULT NULL,
        access_key VARCHAR(255) NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY (id),
        KEY survey_id (survey_id),
        UNIQUE KEY survey_email (survey_id, email),
        FOREIGN KEY (survey_id) REFERENCES $surveys_table (id) ON DELETE CASCADE
    ) $charset_collate;";
    dbDelta( $sql );
    // Ensure email is nullable on existing installs (dbDelta won't alter column types).
    $wpdb->query( "ALTER TABLE $invitations_table MODIFY COLUMN email VARCHAR(255) DEFAULT NULL" );
}

// ─── Admin Page ──────────────────────────────────────────────────────────────

add_action( 'admin_post_conflict_download_results', 'conflict_survey_handle_download_results' );
function conflict_survey_handle_download_results() {
    check_admin_referer( 'conflict_download_results' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

    $survey_id = intval( $_POST['survey_id'] ?? 0 );
    if ( ! $survey_id ) wp_die( 'Survey ID required.' );

    conflict_survey_download_results( $survey_id );
    exit;
}

add_action( 'admin_post_conflict_generate_anonymous_links', 'conflict_survey_handle_generate_anonymous_links' );
function conflict_survey_handle_generate_anonymous_links() {
    check_admin_referer( 'conflict_generate_anonymous_links' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

    $survey_id = intval( $_POST['survey_id'] ?? 0 );
    $count     = intval( $_POST['link_count'] ?? 0 );
    $page_url  = esc_url_raw( $_POST['survey_page_url'] ?? '' );

    if ( ! $survey_id || $count < 1 || $count > 1000 || ! $page_url ) {
        wp_die( 'Survey ID, a count between 1 and 1000, and a page URL are all required.' );
    }

    conflict_survey_generate_and_download_anonymous_links( $survey_id, $count, $page_url );
    exit;
}

add_action( 'admin_post_conflict_clear_survey_results', 'conflict_survey_handle_clear_survey_results' );
function conflict_survey_handle_clear_survey_results() {
    check_admin_referer( 'conflict_clear_survey_results' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

    $survey_id = intval( $_POST['survey_id'] ?? 0 );
    if ( ! $survey_id ) wp_die( 'Survey ID required.' );

    global $wpdb;
    $responses_table = $wpdb->prefix . 'conflict_survey_responses';
    $wpdb->delete( $responses_table, [ 'survey_id' => $survey_id ] );

    wp_redirect( add_query_arg( [ 'page' => 'conflict_survey', 'results_cleared' => 1 ], admin_url( 'tools.php' ) ) );
    exit;
}

add_action( 'admin_post_conflict_delete_survey_keys', 'conflict_survey_handle_delete_survey_keys' );
function conflict_survey_handle_delete_survey_keys() {
    check_admin_referer( 'conflict_delete_survey_keys' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );

    $survey_id = intval( $_POST['survey_id'] ?? 0 );
    if ( ! $survey_id ) wp_die( 'Survey ID required.' );

    global $wpdb;
    $invitations_table = $wpdb->prefix . 'conflict_survey_invitations';
    $wpdb->delete( $invitations_table, [ 'survey_id' => $survey_id ] );

    wp_redirect( add_query_arg( [ 'page' => 'conflict_survey', 'keys_deleted' => 1 ], admin_url( 'tools.php' ) ) );
    exit;
}

add_action( 'admin_menu', 'conflict_survey_add_admin_menu' );
function conflict_survey_add_admin_menu() {
    add_submenu_page(
        'tools.php',
        'Conflict Surveys',
        'Conflict Surveys',
        'manage_options',
        'conflict_survey',
        'conflict_survey_admin_page'
    );
}

function conflict_survey_admin_page() {
    global $wpdb;
    $surveys_table = $wpdb->prefix . 'conflict_surveys';
    $questions_table = $wpdb->prefix . 'conflict_survey_questions';

    // Create new survey
    if ( isset( $_POST['conflict_create_survey'] ) ) {
        check_admin_referer( 'conflict_create_survey' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        $name = sanitize_text_field( $_POST['survey_name'] ?? '' );
        if ( ! $name ) {
            echo '<div class="notice notice-error"><p>Survey name is required.</p></div>';
        } else {
            $wpdb->insert( $surveys_table, [ 'name' => $name ] );
            echo '<div class="notice notice-success"><p>Survey created: ' . esc_html( $name ) . '</p></div>';
        }
    }

    // Add question to survey
    if ( isset( $_POST['conflict_add_question'] ) ) {
        check_admin_referer( 'conflict_add_question' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        $survey_id = intval( $_POST['survey_id'] ?? 0 );
        $text = sanitize_text_field( $_POST['question_text'] ?? '' );
        $type = sanitize_text_field( $_POST['question_type'] ?? '' );

        if ( ! $survey_id || ! $text || ! in_array( $type, [ 'multiple_choice', 'short_text', 'long_text' ], true ) ) {
            echo '<div class="notice notice-error"><p>Invalid question data.</p></div>';
        } else {
            $max_order = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT MAX(question_order) FROM $questions_table WHERE survey_id = %d",
                $survey_id
            ) );
            $wpdb->insert( $questions_table, [
                'survey_id' => $survey_id,
                'question_text' => $text,
                'question_type' => $type,
                'question_order' => $max_order + 1,
            ] );
            $question_id = $wpdb->insert_id;

            // Insert options for multiple choice
            if ( $type === 'multiple_choice' ) {
                $raw_options = sanitize_textarea_field( $_POST['question_options'] ?? '' );
                $option_list = array_filter( array_map( 'trim', explode( "\n", $raw_options ) ) );
                $options_table = $wpdb->prefix . 'conflict_survey_question_options';
                foreach ( $option_list as $idx => $option_text ) {
                    $wpdb->insert( $options_table, [
                        'survey_question_id' => $question_id,
                        'option_text' => $option_text,
                        'option_order' => $idx,
                    ] );
                }
            }

            echo '<div class="notice notice-success"><p>Question added.</p></div>';
        }
    }

    // Edit question
    if ( isset( $_POST['conflict_edit_question'] ) ) {
        check_admin_referer( 'conflict_edit_question' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        $question_id = intval( $_POST['question_id'] ?? 0 );
        $text        = sanitize_text_field( $_POST['question_text'] ?? '' );

        if ( ! $question_id || ! $text ) {
            echo '<div class="notice notice-error"><p>Question text is required.</p></div>';
        } else {
            $existing = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM $questions_table WHERE id = %d",
                $question_id
            ) );

            if ( $existing ) {
                $wpdb->update( $questions_table, [ 'question_text' => $text ], [ 'id' => $question_id ] );

                if ( $existing->question_type === 'multiple_choice' ) {
                    $options_table = $wpdb->prefix . 'conflict_survey_question_options';
                    $raw_options   = sanitize_textarea_field( $_POST['question_options'] ?? '' );
                    $option_list   = array_values( array_filter( array_map( 'trim', explode( "\n", $raw_options ) ) ) );

                    $wpdb->delete( $options_table, [ 'survey_question_id' => $question_id ] );
                    foreach ( $option_list as $idx => $option_text ) {
                        $wpdb->insert( $options_table, [
                            'survey_question_id' => $question_id,
                            'option_text'        => $option_text,
                            'option_order'       => $idx,
                        ] );
                    }
                }

                echo '<div class="notice notice-success"><p>Question updated.</p></div>';
            }
        }
    }

    // Delete question
    if ( isset( $_POST['conflict_delete_question'] ) ) {
        check_admin_referer( 'conflict_delete_question' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        $question_id = intval( $_POST['question_id'] ?? 0 );
        if ( $question_id ) {
            $wpdb->delete( $questions_table, [ 'id' => $question_id ] );
            echo '<div class="notice notice-success"><p>Question deleted.</p></div>';
        }
    }

    // Delete survey
    if ( isset( $_POST['conflict_delete_survey'] ) ) {
        check_admin_referer( 'conflict_delete_survey' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.' );
        }

        $survey_id = intval( $_POST['survey_id'] ?? 0 );
        if ( $survey_id ) {
            $wpdb->delete( $surveys_table, [ 'id' => $survey_id ] );
            echo '<div class="notice notice-success"><p>Survey deleted.</p></div>';
        }
    }

    if ( ! empty( $_GET['keys_deleted'] ) ) {
        echo '<div class="notice notice-success"><p>All survey keys deleted.</p></div>';
    }

    if ( ! empty( $_GET['results_cleared'] ) ) {
        echo '<div class="notice notice-success"><p>All survey results cleared.</p></div>';
    }

    $surveys = $wpdb->get_results( "SELECT * FROM $surveys_table ORDER BY created_at DESC" );

    ?>
    <div class="wrap">
        <h1>Conflict Surveys</h1>

        <h2 style="margin-top: 2em;">Create Survey</h2>
        <form method="POST" style="max-width: 500px;">
            <?php wp_nonce_field( 'conflict_create_survey' ); ?>
            <input type="hidden" name="conflict_create_survey" value="1">
            <table class="form-table">
                <tr>
                    <th><label for="survey_name">Survey Name</label></th>
                    <td><input type="text" id="survey_name" name="survey_name" class="regular-text" required></td>
                </tr>
            </table>
            <p><input type="submit" class="button button-primary" value="Create Survey"></p>
        </form>

        <h2 style="margin-top: 2em;">Manage Surveys</h2>
        <?php if ( $surveys ) : ?>
            <?php foreach ( $surveys as $survey ) : ?>
                <?php
                $questions = $wpdb->get_results( $wpdb->prepare(
                    "SELECT * FROM $questions_table WHERE survey_id = %d ORDER BY question_order",
                    $survey->id
                ) );
                ?>
                <div style="border: 1px solid #ccc; padding: 15px; margin-bottom: 20px; background: #f5f5f5;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <h3 style="margin: 0;"><?php echo esc_html( $survey->name ); ?> (ID: <?php echo esc_html( $survey->id ); ?>)</h3>
                        <form method="POST" style="display: inline;">
                            <?php wp_nonce_field( 'conflict_delete_survey' ); ?>
                            <input type="hidden" name="conflict_delete_survey" value="1">
                            <input type="hidden" name="survey_id" value="<?php echo esc_attr( $survey->id ); ?>">
                            <input type="submit" class="button button-small" value="Delete Survey" onclick="return confirm('Delete this survey and all its questions, responses, and invitations?');">
                        </form>
                    </div>
                    <p><code>[conflict_survey survey_id="<?php echo esc_attr( $survey->id ); ?>"]</code></p>

                    <?php if ( $questions ) : ?>
                        <h4>Questions:</h4>
                        <ol>
                            <?php foreach ( $questions as $q ) : ?>
                                <li>
                                    <?php echo esc_html( $q->question_text ); ?>
                                    <br><small>(<?php echo esc_html( ucfirst( str_replace( '_', ' ', $q->question_type ) ) ); ?>)</small>
                                    <?php if ( $q->question_type === 'multiple_choice' ) : ?>
                                        <?php
                                        $options_table = $wpdb->prefix . 'conflict_survey_question_options';
                                        $options = $wpdb->get_results( $wpdb->prepare(
                                            "SELECT option_text FROM $options_table WHERE survey_question_id = %d ORDER BY option_order",
                                            $q->id
                                        ) );
                                        ?>
                                        <?php if ( $options ) : ?>
                                            <br><small>Options: <?php echo esc_html( implode( ', ', array_map( function( $o ) { return $o->option_text; }, $options ) ) ); ?></small>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <form method="POST" style="display: inline; margin-left: 10px;">
                                        <?php wp_nonce_field( 'conflict_delete_question' ); ?>
                                        <input type="hidden" name="conflict_delete_question" value="1">
                                        <input type="hidden" name="question_id" value="<?php echo esc_attr( $q->id ); ?>">
                                        <input type="submit" class="button button-small" value="Delete" onclick="return confirm('Delete this question?');">
                                    </form>
                                    <button type="button" class="button button-small" style="margin-left: 5px;"
                                        onclick="conflictToggleEditForm(<?php echo esc_attr( $q->id ); ?>)">Edit</button>

                                    <?php
                                    $edit_options_text = '';
                                    if ( $q->question_type === 'multiple_choice' ) {
                                        $options_table_edit = $wpdb->prefix . 'conflict_survey_question_options';
                                        $edit_opts = $wpdb->get_results( $wpdb->prepare(
                                            "SELECT option_text FROM $options_table_edit WHERE survey_question_id = %d ORDER BY option_order",
                                            $q->id
                                        ) );
                                        $edit_options_text = implode( "\n", array_map( function( $o ) { return $o->option_text; }, $edit_opts ) );
                                    }
                                    ?>
                                    <div id="conflict_edit_form_<?php echo esc_attr( $q->id ); ?>" style="display:none; margin-top: 10px; padding: 10px; background: #fff; border: 1px solid #bbb;">
                                        <form method="POST">
                                            <?php wp_nonce_field( 'conflict_edit_question' ); ?>
                                            <input type="hidden" name="conflict_edit_question" value="1">
                                            <input type="hidden" name="question_id" value="<?php echo esc_attr( $q->id ); ?>">
                                            <table class="form-table" style="margin:0;">
                                                <tr>
                                                    <th style="width:120px;"><label>Question</label></th>
                                                    <td><input type="text" name="question_text" class="regular-text"
                                                        value="<?php echo esc_attr( $q->question_text ); ?>" required></td>
                                                </tr>
                                                <?php if ( $q->question_type === 'multiple_choice' ) : ?>
                                                <tr>
                                                    <th><label>Options<br><small>(one per line)</small></label></th>
                                                    <td><textarea name="question_options" rows="4" style="width:100%;"><?php echo esc_textarea( $edit_options_text ); ?></textarea></td>
                                                </tr>
                                                <?php endif; ?>
                                            </table>
                                            <p style="margin:8px 0 0;">
                                                <input type="submit" class="button button-primary button-small" value="Save">
                                                <button type="button" class="button button-small" style="margin-left:5px;"
                                                    onclick="conflictToggleEditForm(<?php echo esc_attr( $q->id ); ?>)">Cancel</button>
                                            </p>
                                        </form>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php else : ?>
                        <p><em>No questions yet.</em></p>
                    <?php endif; ?>

                    <h4 style="margin-top: 1em;">Add Question</h4>
                    <form method="POST" style="max-width: 700px;">
                        <?php wp_nonce_field( 'conflict_add_question' ); ?>
                        <input type="hidden" name="conflict_add_question" value="1">
                        <input type="hidden" name="survey_id" value="<?php echo esc_attr( $survey->id ); ?>">
                        <table class="form-table">
                            <tr>
                                <th><label for="question_text_<?php echo esc_attr( $survey->id ); ?>">Question</label></th>
                                <td><input type="text" id="question_text_<?php echo esc_attr( $survey->id ); ?>" name="question_text" class="regular-text" required></td>
                            </tr>
                            <tr>
                                <th><label for="question_type_<?php echo esc_attr( $survey->id ); ?>">Type</label></th>
                                <td>
                                    <select id="question_type_<?php echo esc_attr( $survey->id ); ?>" name="question_type" required onchange="toggleOptions(this)">
                                        <option value="">— Select —</option>
                                        <option value="short_text">Short Text</option>
                                        <option value="long_text">Long Text</option>
                                        <option value="multiple_choice">Multiple Choice</option>
                                    </select>
                                </td>
                            </tr>
                            <tr id="options_row_<?php echo esc_attr( $survey->id ); ?>" style="display: none;">
                                <th><label for="question_options_<?php echo esc_attr( $survey->id ); ?>">Options (one per line)</label></th>
                                <td><textarea id="question_options_<?php echo esc_attr( $survey->id ); ?>" name="question_options" rows="4" style="width: 100%;"></textarea></td>
                            </tr>
                        </table>
                        <p><input type="submit" class="button button-primary" value="Add Question"></p>
                    </form>

                    <h4 style="margin-top: 1em;">Download Results</h4>
                    <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width: 700px;">
                        <?php wp_nonce_field( 'conflict_download_results' ); ?>
                        <input type="hidden" name="action" value="conflict_download_results">
                        <input type="hidden" name="survey_id" value="<?php echo esc_attr( $survey->id ); ?>">
                        <p><input type="submit" class="button button-primary" value="Download Results as CSV"></p>
                    </form>

                    <h4 style="margin-top: 1em;">Generate Anonymous Links</h4>
                    <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width: 700px;">
                        <?php wp_nonce_field( 'conflict_generate_anonymous_links' ); ?>
                        <input type="hidden" name="action" value="conflict_generate_anonymous_links">
                        <input type="hidden" name="survey_id" value="<?php echo esc_attr( $survey->id ); ?>">
                        <table class="form-table">
                            <tr>
                                <th><label for="link_count_<?php echo esc_attr( $survey->id ); ?>">Number of Links</label></th>
                                <td><input type="number" id="link_count_<?php echo esc_attr( $survey->id ); ?>" name="link_count" min="1" max="1000" value="30" class="small-text" required></td>
                            </tr>
                            <tr>
                                <th><label for="anon_page_url_<?php echo esc_attr( $survey->id ); ?>">Survey Page URL</label></th>
                                <td><input type="url" id="anon_page_url_<?php echo esc_attr( $survey->id ); ?>" name="survey_page_url" class="regular-text" placeholder="https://example.com/survey/" required></td>
                            </tr>
                        </table>
                        <p><input type="submit" class="button button-primary" value="Generate & Download CSV"></p>
                    </form>

                    <?php
                    $danger_words = [ 'apple', 'brave', 'cloud', 'dance', 'eagle', 'flame', 'grace', 'honey', 'ivory', 'jewel', 'lemon', 'maple', 'noble', 'ocean', 'pearl', 'river', 'stone', 'tiger', 'vivid', 'zebra' ];
                    $confirm_word_keys    = $danger_words[ array_rand( $danger_words ) ];
                    $confirm_word_results = $danger_words[ array_rand( $danger_words ) ];
                    $delete_keys_id = 'delete_keys_' . $survey->id;
                    ?>
                    <hr style="margin-top: 2em; border-color: #c00;">
                    <h4 style="color: #c00;">&#9888; Danger Zone</h4>
                    <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="<?php echo esc_attr( $delete_keys_id ); ?>">
                        <?php wp_nonce_field( 'conflict_delete_survey_keys' ); ?>
                        <input type="hidden" name="action" value="conflict_delete_survey_keys">
                        <input type="hidden" name="survey_id" value="<?php echo esc_attr( $survey->id ); ?>">
                        <input type="submit" class="button" style="background:#c00;border-color:#900;color:#fff;" value="Delete All Survey Keys"
                            onclick="return conflictConfirmDanger(this.form, '<?php echo esc_js( $confirm_word_keys ); ?>', 'delete all survey keys', 'Keys were not deleted.');">
                    </form>
                    <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 8px;">
                        <?php wp_nonce_field( 'conflict_clear_survey_results' ); ?>
                        <input type="hidden" name="action" value="conflict_clear_survey_results">
                        <input type="hidden" name="survey_id" value="<?php echo esc_attr( $survey->id ); ?>">
                        <input type="submit" class="button" style="background:#c00;border-color:#900;color:#fff;" value="Clear All Survey Results"
                            onclick="return conflictConfirmDanger(this.form, '<?php echo esc_js( $confirm_word_results ); ?>', 'clear all survey results', 'Results were not cleared.');">
                    </form>
                    <script>
                    function conflictConfirmDanger(form, word, action, cancelMsg) {
                        var typed = window.prompt('To ' + action + ', type this word: ' + word);
                        if (typed === null) return false;
                        if (typed.trim().toLowerCase() !== word) {
                            alert('Incorrect word. ' + cancelMsg);
                            return false;
                        }
                        return true;
                    }
                    </script>
                </div>
            <?php endforeach; ?>
        <?php else : ?>
            <p>No surveys yet. Create one above.</p>
        <?php endif; ?>
    </div>

    <script>
    function conflictToggleEditForm(questionId) {
        var el = document.getElementById('conflict_edit_form_' + questionId);
        el.style.display = el.style.display === 'none' ? 'block' : 'none';
    }
    function toggleOptions(select) {
        const survey_id = select.id.split('_')[2];
        const optionsRow = document.getElementById('options_row_' + survey_id);
        if (select.value === 'multiple_choice') {
            optionsRow.style.display = 'table-row';
        } else {
            optionsRow.style.display = 'none';
        }
    }
    </script>
    <?php
}

// ─── Shortcode ───────────────────────────────────────────────────────────────

add_shortcode( 'conflict_survey', 'conflict_survey_shortcode' );
function conflict_survey_shortcode( $atts ) {
    global $wpdb;
    ob_start();

    $atts = shortcode_atts( [ 'survey_id' => 0 ], $atts, 'conflict_survey' );
    $survey_id = intval( $atts['survey_id'] );
    $survey_key = isset( $_GET['survey_key'] ) ? sanitize_text_field( $_GET['survey_key'] ) : '';

    if ( ! $survey_id ) {
        ob_end_clean();
        return '<p><em>Survey ID required: [conflict_survey survey_id="1"]</em></p>';
    }

    $surveys_table = $wpdb->prefix . 'conflict_surveys';
    $questions_table = $wpdb->prefix . 'conflict_survey_questions';
    $invitations_table = $wpdb->prefix . 'conflict_survey_invitations';

    $survey = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $surveys_table WHERE id = %d", $survey_id ) );
    if ( ! $survey ) {
        ob_end_clean();
        return '<p><em>Survey not found.</em></p>';
    }

    $questions = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM $questions_table WHERE survey_id = %d ORDER BY question_order",
        $survey_id
    ) );

    $form_submitted = false;

    // Validate invitation key if provided
    if ( $survey_key ) {
        $invitation = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM $invitations_table WHERE survey_id = %d AND access_key = %s",
            $survey_id,
            $survey_key
        ) );
        if ( ! $invitation ) {
            ob_end_clean();
            return '<div class="notice notice-error"><p>Invalid or expired invitation link.</p></div>';
        }
    }

    // Handle form submission
    if ( isset( $_POST['conflict_survey_submit'] ) ) {
        check_admin_referer( 'conflict_survey_nonce_' . $survey_id );

        $responses_table = $wpdb->prefix . 'conflict_survey_responses';
        $answers_table = $wpdb->prefix . 'conflict_survey_answers';

        $respondent_name = sanitize_text_field( $_POST['respondent_name'] ?? 'Anonymous' );

        // Validate invitation key
        if ( $survey_key ) {
            $invitation = $wpdb->get_row( $wpdb->prepare(
                "SELECT id FROM $invitations_table WHERE survey_id = %d AND access_key = %s",
                $survey_id,
                $survey_key
            ) );
            if ( ! $invitation ) {
                ob_end_clean();
                return '<div class="notice notice-error"><p>Invalid or expired invitation link.</p></div>';
            }
        }

        {
            // Insert response record
            $wpdb->insert( $responses_table, [
                'survey_id' => $survey_id,
                'respondent_name' => $respondent_name,
            ] );
            $response_id = $wpdb->insert_id;

            // Insert answers
            foreach ( $questions as $question ) {
                $answer_key = 'question_' . $question->id;
                $answer_text = sanitize_textarea_field( $_POST[$answer_key] ?? '' );
                if ( $answer_text ) {
                    $wpdb->insert( $answers_table, [
                        'survey_response_id' => $response_id,
                        'survey_question_id' => $question->id,
                        'answer_text' => $answer_text,
                    ] );
                }
            }

            echo '<div class="notice notice-success"><p>Thank you! Your response has been recorded.</p></div>';
            $form_submitted = true;
        }
    }

    // Render form
    if ( ! $form_submitted ) :
    ?>
    <form method="POST" class="conflict-survey-form">
        <?php wp_nonce_field( 'conflict_survey_nonce_' . $survey_id ); ?>
        <input type="hidden" name="conflict_survey_submit" value="1">
        <?php if ( $survey_key ) : ?>
            <input type="hidden" name="survey_key" value="<?php echo esc_attr( $survey_key ); ?>">
        <?php endif; ?>

        <h3><?php echo esc_html( $survey->name ); ?></h3>

        <?php foreach ( $questions as $question ) : ?>
            <div style="margin-bottom: 20px;">
                <label><strong><?php echo esc_html( $question->question_text ); ?></strong></label>

                <?php if ( $question->question_type === 'short_text' ) : ?>
                    <input type="text" name="question_<?php echo esc_attr( $question->id ); ?>" style="width: 100%; max-width: 500px; padding: 5px;">

                <?php elseif ( $question->question_type === 'long_text' ) : ?>
                    <textarea name="question_<?php echo esc_attr( $question->id ); ?>" rows="6" style="width: 100%; max-width: 600px; padding: 5px;"></textarea>

                <?php elseif ( $question->question_type === 'multiple_choice' ) : ?>
                    <?php
                    $options_table = $wpdb->prefix . 'conflict_survey_question_options';
                    $options = $wpdb->get_results( $wpdb->prepare(
                        "SELECT option_text FROM $options_table WHERE survey_question_id = %d ORDER BY option_order",
                        $question->id
                    ) );
                    ?>
                    <?php foreach ( $options as $option ) : ?>
                        <div>
                            <label>
                                <input type="radio" name="question_<?php echo esc_attr( $question->id ); ?>" value="<?php echo esc_attr( $option->option_text ); ?>">
                                <?php echo esc_html( $option->option_text ); ?>
                            </label>
                        </div>
                    <?php endforeach; ?>

                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <p><input type="submit" class="conflict-survey-submit" value="Submit Survey"></p>
    </form>

    <style>
        .conflict-survey-form {
            max-width: 700px;
        }
        .conflict-survey-form input[type="text"],
        .conflict-survey-form textarea {
            display: block !important;
            font-family: inherit !important;
            font-size: 1rem !important;
            border: 1px solid #8c8f94 !important;
            border-radius: 3px !important;
            background-color: #fff !important;
            color: #2c3338 !important;
            padding: 6px 8px !important;
            box-sizing: border-box !important;
        }
        .conflict-survey-form input[type="text"]:focus,
        .conflict-survey-form textarea:focus {
            border-color: #2271b1 !important;
            outline: 2px solid #2271b1 !important;
            outline-offset: 0 !important;
        }
        .conflict-survey-submit {
            display: inline-block !important;
            padding: 8px 18px !important;
            font-size: 0.95rem !important;
            font-family: inherit !important;
            font-weight: 600 !important;
            color: #fff !important;
            background-color: #2271b1 !important;
            border: 1px solid #135e96 !important;
            border-radius: 3px !important;
            cursor: pointer !important;
            text-decoration: none !important;
        }
        .conflict-survey-submit:hover {
            background-color: #135e96 !important;
            border-color: #0a4b78 !important;
        }
    </style>
    <?php
    endif;
    return ob_get_clean();
}

// ─── Invitation Helpers ──────────────────────────────────────────────────────

function conflict_survey_generate_and_download_anonymous_links( $survey_id, $count, $page_url ) {
    global $wpdb;
    $invitations_table = $wpdb->prefix . 'conflict_survey_invitations';

    $separator = strpos( $page_url, '?' ) !== false ? '&' : '?';
    $csv_data  = [ [ 'link_number', 'invitation_link' ] ];

    for ( $i = 1; $i <= $count; $i++ ) {
        $key = wp_generate_password( 24, false );
        $wpdb->insert( $invitations_table, [
            'survey_id'  => $survey_id,
            'email'      => null,
            'access_key' => $key,
        ] );
        $csv_data[] = [ $i, $page_url . $separator . 'survey_id=' . $survey_id . '&survey_key=' . $key ];
    }

    while ( ob_get_level() ) {
        ob_end_clean();
    }
    nocache_headers();
    header( 'Content-Type: application/octet-stream' );
    header( 'Content-Disposition: attachment; filename=survey_anonymous_links_' . $survey_id . '_' . gmdate( 'Y-m-d' ) . '.csv' );
    header( 'Content-Transfer-Encoding: binary' );

    $output = fopen( 'php://output', 'w' );
    foreach ( $csv_data as $row ) {
        fputcsv( $output, $row );
    }
    fclose( $output );
}

function conflict_survey_download_results( $survey_id ) {
    global $wpdb;

    $questions_table = $wpdb->prefix . 'conflict_survey_questions';
    $responses_table = $wpdb->prefix . 'conflict_survey_responses';
    $answers_table = $wpdb->prefix . 'conflict_survey_answers';

    $questions = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, question_text FROM $questions_table WHERE survey_id = %d ORDER BY question_order",
        $survey_id
    ) );

    $responses = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, respondent_name, created_at FROM $responses_table WHERE survey_id = %d ORDER BY created_at DESC",
        $survey_id
    ) );

    // Build CSV header
    $csv_data = [];
    $headers = [ 'respondent_name', 'created_at' ];
    foreach ( $questions as $q ) {
        $headers[] = $q->question_text;
    }
    $csv_data[] = $headers;

    // Build CSV rows
    foreach ( $responses as $response ) {
        $row = [ $response->respondent_name, $response->created_at ];

        foreach ( $questions as $q ) {
            $answer = $wpdb->get_var( $wpdb->prepare(
                "SELECT answer_text FROM $answers_table WHERE survey_response_id = %d AND survey_question_id = %d",
                $response->id,
                $q->id
            ) );
            $row[] = $answer ?? '';
        }

        $csv_data[] = $row;
    }

    // Output CSV
    while ( ob_get_level() ) {
        ob_end_clean();
    }
    nocache_headers();
    header( 'Content-Type: application/octet-stream' );
    header( 'Content-Disposition: attachment; filename=survey_results_' . $survey_id . '_' . gmdate( 'Y-m-d' ) . '.csv' );
    header( 'Content-Transfer-Encoding: binary' );

    $output = fopen( 'php://output', 'w' );
    foreach ( $csv_data as $row ) {
        fputcsv( $output, $row );
    }
    fclose( $output );
}
