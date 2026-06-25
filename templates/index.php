<?php
use WordpressCourses\CoursePlans;
use WordpressCourses\LearnApi;

$user_id            = get_current_user_id();
$notice             = '';
$error              = '';
$search             = isset( $_GET['course_search'] ) ? sanitize_text_field( wp_unslash( $_GET['course_search'] ) ) : '';
$selected_plan_id   = isset( $_GET['plan_id'] ) ? absint( $_GET['plan_id'] ) : 0;
$trashed_plan_id    = isset( $_GET['trashed_plan_id'] ) ? absint( $_GET['trashed_plan_id'] ) : 0;
$selected_course_id = 0;
$collect_lesson_ids = function ( array $modules ): array {
    $lesson_ids = [];

    foreach ( $modules as $module ) {
        foreach ( $module['lessons'] ?? [] as $lesson ) {
            if ( ! empty( $lesson['id'] ) ) {
                $lesson_ids[] = absint( $lesson['id'] );
            }
        }
    }

    return $lesson_ids;
};
$get_plan_progress = function ( array $plan ) use ( $user_id, $collect_lesson_ids ): array {
    $course_id = absint( $plan['course_id'] ?? 0 );
    $plan_id   = absint( $plan['id'] ?? 0 );
    $modules   = $course_id > 0 ? LearnApi::get_course_modules( $course_id ) : [];

    if ( is_wp_error( $modules ) || ! is_array( $modules ) ) {
        $modules = [];
    }

    $lesson_count          = LearnApi::count_lessons( $modules );
    $completed_lesson_ids  = CoursePlans::get_completed_lesson_ids( $user_id, $plan_id );
    $completed_count       = count( array_intersect( $completed_lesson_ids, $collect_lesson_ids( $modules ) ) );
    $lesson_progress       = $lesson_count > 0 ? (int) round( ( $completed_count / $lesson_count ) * 100 ) : 0;
    $time_progress         = CoursePlans::get_time_progress_percent( $plan['start_date'] ?? '', $plan['end_date'] ?? '' );
    $days_left             = ! empty( $plan['end_date'] ) ? (int) ceil( ( strtotime( $plan['end_date'] . ' 23:59:59' ) - current_time( 'timestamp' ) ) / DAY_IN_SECONDS ) : 0;

    if ( $days_left > 1 ) {
        $time_status = sprintf( __( '%d days left', 'learn-app' ), $days_left );
    } elseif ( 1 === $days_left ) {
        $time_status = __( '1 day left', 'learn-app' );
    } elseif ( 0 === $days_left ) {
        $time_status = __( 'Due today', 'learn-app' );
    } elseif ( -1 === $days_left ) {
        $time_status = __( '1 day overdue', 'learn-app' );
    } else {
        $time_status = sprintf( __( '%d days overdue', 'learn-app' ), abs( $days_left ) );
    }

    return [
        'completed_count' => $completed_count,
        'lesson_count'    => $lesson_count,
        'lessons_left'    => max( 0, $lesson_count - $completed_count ),
        'lesson_progress' => $lesson_progress,
        'time_progress'   => $time_progress,
        'time_status'     => $time_status,
        'end_date_label'  => ! empty( $plan['end_date'] ) ? wp_date( 'F j, Y', strtotime( $plan['end_date'] ) ) : '',
    ];
};

if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
    $action = isset( $_POST['wordpress_courses_action'] ) ? sanitize_key( wp_unslash( $_POST['wordpress_courses_action'] ) ) : '';

    if ( ! isset( $_POST['wordpress_courses_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wordpress_courses_nonce'] ) ), 'wordpress_courses_action' ) ) {
        $error = __( 'That request could not be verified. Please try again.', 'learn-app' );
    } elseif ( 'select_course' === $action ) {
        $course_id = isset( $_POST['course_id'] ) ? absint( $_POST['course_id'] ) : 0;
        $plan_id   = CoursePlans::get_or_create_plan_id( $user_id, $course_id );

        if ( $plan_id > 0 ) {
            wp_safe_redirect( add_query_arg( 'plan_id', $plan_id, home_url( '/learn-app/' ) ) );
            exit;
        }

        $error = __( 'The course plan could not be created.', 'learn-app' );
    } elseif ( 'clear_selection' === $action ) {
        $trashed_plan_id = $selected_plan_id;
        CoursePlans::trash_plan( $user_id, $selected_plan_id );
        wp_safe_redirect( add_query_arg( 'trashed_plan_id', $trashed_plan_id, home_url( '/learn-app/' ) ) );
        exit;
    } elseif ( 'undo_clear_selection' === $action ) {
        $restore_plan_id = isset( $_POST['plan_id'] ) ? absint( $_POST['plan_id'] ) : 0;

        if ( CoursePlans::restore_plan( $user_id, $restore_plan_id ) ) {
            wp_safe_redirect( add_query_arg( 'plan_id', $restore_plan_id, home_url( '/learn-app/' ) ) );
            exit;
        }

        $error = __( 'The course could not be restored.', 'learn-app' );
    } elseif ( 'save_dates' === $action ) {
        $dates_plan_id = isset( $_POST['plan_id'] ) ? absint( $_POST['plan_id'] ) : $selected_plan_id;
        $start_date    = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
        $end_date      = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
        $result        = CoursePlans::set_plan_dates( $user_id, $dates_plan_id, $start_date, $end_date );

        if ( is_wp_error( $result ) ) {
            $error = $result->get_error_message();
        } else {
            $selected_plan_id   = $dates_plan_id;
            $selected_course_id = CoursePlans::get_plan_course_id( $user_id, $selected_plan_id );
            $notice             = __( 'Course dates saved.', 'learn-app' );
        }
    } elseif ( 'save_progress' === $action ) {
        $progress_plan_id     = isset( $_POST['plan_id'] ) ? absint( $_POST['plan_id'] ) : $selected_plan_id;
        $completed_lesson_ids = isset( $_POST['completed_lessons'] ) && is_array( $_POST['completed_lessons'] )
            ? array_map( 'absint', wp_unslash( $_POST['completed_lessons'] ) )
            : [];
        CoursePlans::set_completed_lesson_ids( $user_id, $progress_plan_id, $completed_lesson_ids );
        $selected_plan_id = $progress_plan_id;
        $selected_course_id = CoursePlans::get_plan_course_id( $user_id, $selected_plan_id );
        $notice = __( 'Progress saved.', 'learn-app' );
    } elseif ( 'save_notes' === $action ) {
        $notes_plan_id = isset( $_POST['plan_id'] ) ? absint( $_POST['plan_id'] ) : $selected_plan_id;
        $notes         = isset( $_POST['notes'] ) ? wp_unslash( $_POST['notes'] ) : '';
        $result        = CoursePlans::set_plan_notes( $user_id, $notes_plan_id, $notes );

        if ( is_wp_error( $result ) ) {
            $error = $result->get_error_message();
        } else {
            $selected_plan_id   = $notes_plan_id;
            $selected_course_id = CoursePlans::get_plan_course_id( $user_id, $selected_plan_id );
            $notice             = __( 'Notes saved.', 'learn-app' );
        }
    } elseif ( 'save_lesson_note' === $action ) {
        $notes_plan_id       = isset( $_POST['plan_id'] ) ? absint( $_POST['plan_id'] ) : $selected_plan_id;
        $lesson_id           = isset( $_POST['lesson_id'] ) ? absint( $_POST['lesson_id'] ) : 0;
        $notes               = isset( $_POST['lesson_note'] ) ? wp_unslash( $_POST['lesson_note'] ) : '';
        $result              = CoursePlans::set_lesson_note( $user_id, $notes_plan_id, $lesson_id, $notes );

        if ( is_wp_error( $result ) ) {
            $error = $result->get_error_message();
        } else {
            $selected_plan_id   = $notes_plan_id;
            $selected_course_id = CoursePlans::get_plan_course_id( $user_id, $selected_plan_id );
            $notice             = __( 'Lesson note saved.', 'learn-app' );
        }
    }
}

$active_plans = CoursePlans::get_active_plans( $user_id );

if ( $selected_plan_id <= 0 && '' === $search && 1 === count( $active_plans ) ) {
    $selected_plan_id = absint( $active_plans[0]['id'] );
}

$selected_course_id = CoursePlans::get_plan_course_id( $user_id, $selected_plan_id );
$selected_course       = $selected_course_id > 0 ? LearnApi::get_course( $selected_course_id ) : null;
$selected_modules      = $selected_course_id > 0 ? LearnApi::get_course_modules( $selected_course_id ) : [];
$completed_lesson_ids  = CoursePlans::get_completed_lesson_ids( $user_id, $selected_plan_id );
$selected_lesson_count = is_array( $selected_modules ) ? LearnApi::count_lessons( $selected_modules ) : 0;
$completed_count       = count( array_intersect( $completed_lesson_ids, $collect_lesson_ids( is_array( $selected_modules ) ? $selected_modules : [] ) ) );
$lesson_progress       = $selected_lesson_count > 0 ? (int) round( ( $completed_count / $selected_lesson_count ) * 100 ) : 0;
$plan_dates            = $selected_plan_id > 0 ? CoursePlans::get_plan_dates( $user_id, $selected_plan_id ) : [ 'start_date' => '', 'end_date' => '' ];
$plan_notes_html       = $selected_plan_id > 0 ? CoursePlans::get_plan_notes( $user_id, $selected_plan_id ) : '';
$plan_notes_editor     = $selected_plan_id > 0 ? CoursePlans::get_plan_notes_editor_text( $user_id, $selected_plan_id ) : '';
$plan_notes_preview    = '' !== $plan_notes_html ? wp_trim_words( wp_strip_all_tags( $plan_notes_html ), 24, '...' ) : '';
$lesson_notes          = $selected_plan_id > 0 ? CoursePlans::get_lesson_notes( $user_id, $selected_plan_id ) : [];
$time_progress         = CoursePlans::get_time_progress_percent( $plan_dates['start_date'], $plan_dates['end_date'] );
$days_left             = '' !== $plan_dates['end_date'] ? (int) ceil( ( strtotime( $plan_dates['end_date'] . ' 23:59:59' ) - current_time( 'timestamp' ) ) / DAY_IN_SECONDS ) : 0;
if ( $days_left > 1 ) {
    $time_status = sprintf( __( '%d days left', 'learn-app' ), $days_left );
} elseif ( 1 === $days_left ) {
    $time_status = __( '1 day left', 'learn-app' );
} elseif ( 0 === $days_left ) {
    $time_status = __( 'Due today', 'learn-app' );
} elseif ( -1 === $days_left ) {
    $time_status = __( '1 day overdue', 'learn-app' );
} else {
    $time_status = sprintf( __( '%d days overdue', 'learn-app' ), abs( $days_left ) );
}
$courses               = ( 0 === $selected_course_id || '' !== $search ) ? LearnApi::get_courses( $search ) : [];
$other_plans           = array_values(
    array_filter(
        $active_plans,
        function ( array $plan ) use ( $selected_plan_id ): bool {
            return absint( $plan['id'] ) !== $selected_plan_id;
        }
    )
);
$show_sidebar          = '' === $search && ( $selected_course_id > 0 || ! empty( $active_plans ) );
?>
<!DOCTYPE html>
<html <?php wp_app_language_attributes(); ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( wp_app_title( 'Learn WordPress' ) ); ?></title>
    <?php wp_app_head(); ?>
    <?php if ( $selected_plan_id > 0 && $selected_course_id > 0 ) : ?>
        <script src="<?php echo esc_url( plugins_url( 'assets/vendor/overtype/overtype.min.js', dirname( __DIR__ ) . '/learn-app.php' ) ); ?>"></script>
        <script src="<?php echo esc_url( plugins_url( 'assets/wordpress-courses-notes.js', dirname( __DIR__ ) . '/learn-app.php' ) ); ?>"></script>
    <?php endif; ?>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            background: var(--wp-app-color-background);
            color: var(--wp-app-color-text);
            line-height: 1.5;
        }
        main {
            width: min(1120px, calc(100% - 32px));
            margin: 32px auto 56px;
        }
        header.page-header {
            display: flex;
            gap: 16px;
            align-items: flex-end;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        h1, h2, h3, p { margin-top: 0; }
        h1 { margin-bottom: 4px; font-size: 2rem; line-height: 1.15; }
        h1 a { color: inherit; text-decoration: none; }
        h2 { margin-bottom: 12px; font-size: 1.2rem; }
        h3 { margin-bottom: 8px; font-size: 1rem; }
        .muted { color: var(--wp-app-color-muted); }
        .layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 360px;
            gap: 24px;
            align-items: start;
        }
        .layout.full {
            grid-template-columns: 1fr;
        }
        .sidebar-stack {
            display: grid;
            gap: 16px;
            align-items: start;
        }
        .panel {
            background: var(--wp-app-color-surface);
            border: 1px solid var(--wp-app-color-border);
            border-radius: 6px;
            padding: 20px;
        }
        .stack { display: grid; gap: 16px; }
        .notice, .error {
            border-radius: 6px;
            padding: 12px 14px;
            margin-bottom: 16px;
        }
        .notice { background: #e8f5ee; color: #12472f; border: 1px solid #b9dfca; }
        .error { background: #fae8e8; color: #5f1717; border: 1px solid #e0b4b4; }
        .search-form {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 8px;
            margin-bottom: 16px;
        }
        input[type="search"] {
            width: 100%;
            min-height: 40px;
            padding: 8px 10px;
            border: 1px solid var(--wp-app-color-border);
            border-radius: 4px;
            background: var(--wp-app-color-surface-alt);
            color: var(--wp-app-color-text);
            font: inherit;
        }
        button, .button {
            min-height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 8px 12px;
            border: 1px solid var(--wp-app-color-border);
            border-radius: 4px;
            background: var(--wp-app-color-primary, #2271b1);
            color: #fff;
            font: inherit;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            white-space: nowrap;
        }
        button.secondary, .button.secondary {
            background: transparent;
            color: var(--wp-app-color-text);
        }
        .course-list {
            display: grid;
            gap: 10px;
        }
        .course-item {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 12px;
            align-items: center;
            border: 1px solid var(--wp-app-color-border);
            border-radius: 6px;
            padding: 14px;
            background: var(--wp-app-color-surface-alt);
        }
        .course-item p { margin-bottom: 0; }
        .course-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
            color: var(--wp-app-color-muted);
            font-size: 0.9rem;
        }
        .selected-summary {
            position: relative;
            display: grid;
            gap: 10px;
        }
        .selected-header {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 10px;
            align-items: start;
        }
        .selected-header h3 {
            margin-bottom: 0;
        }
        .icon-button {
            width: 32px;
            min-width: 32px;
            min-height: 32px;
            padding: 0;
            background: transparent;
            color: var(--wp-app-color-muted);
            font-size: 1.25rem;
            line-height: 1;
        }
        .icon-button:hover,
        .icon-button:focus {
            color: var(--wp-app-color-text);
        }
        .notice-form {
            display: inline-flex;
            margin-left: 8px;
        }
        .notice-form button {
            min-height: 0;
            padding: 0;
            border: 0;
            background: transparent;
            color: inherit;
            text-decoration: underline;
            font-weight: 700;
        }
        .active-course-list {
            display: grid;
            gap: 8px;
            margin-top: 18px;
            padding-top: 18px;
            border-top: 1px solid var(--wp-app-color-border);
        }
        .active-course {
            display: block;
        }
        .active-course strong {
            overflow-wrap: anywhere;
        }
        .progress-meter {
            width: 100%;
            height: 10px;
            overflow: hidden;
            border-radius: 999px;
            background: var(--wp-app-color-surface-alt);
        }
        .progress-meter span {
            display: block;
            height: 100%;
            background: #2f8f5b;
        }
        .progress-meter.time span {
            background: #b85c00;
        }
        .progress-row {
            display: grid;
            gap: 6px;
        }
        .progress-label {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            font-size: 0.9rem;
        }
        .date-form {
            display: grid;
            gap: 10px;
            padding: 10px 0 0;
        }
        .date-details {
            padding: 12px 0;
            border-top: 1px solid var(--wp-app-color-border);
            border-bottom: 1px solid var(--wp-app-color-border);
        }
        .date-details summary {
            cursor: pointer;
            font-weight: 600;
        }
        .date-details summary::marker {
            color: var(--wp-app-color-muted);
        }
        .date-fields {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }
        .date-field {
            display: grid;
            gap: 4px;
            font-size: 0.9rem;
        }
        input[type="date"] {
            min-height: 38px;
            padding: 7px 8px;
            border: 1px solid var(--wp-app-color-border);
            border-radius: 4px;
            background: var(--wp-app-color-surface-alt);
            color: var(--wp-app-color-text);
            font: inherit;
            width: 100%;
        }
        .notes-form {
            display: grid;
            gap: 10px;
            padding-top: 12px;
            border-top: 1px solid var(--wp-app-color-border);
        }
        .notes-form[hidden] {
            display: none;
        }
        .course-notes {
            display: grid;
            gap: 8px;
            padding-top: 12px;
            border-top: 1px solid var(--wp-app-color-border);
        }
        .course-notes-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .course-notes-header h3 {
            margin-bottom: 0;
        }
        .course-notes-preview {
            margin: 0;
            color: var(--wp-app-color-muted);
            font-size: 0.9rem;
        }
        .course-notes-toggle {
            min-height: 28px;
            padding: 2px 0;
            border: 0;
            background: transparent;
            color: var(--wp-app-color-link);
            font-size: 0.85rem;
            text-decoration: underline;
        }
        .notes-editor {
            display: none;
            width: 100%;
            min-height: 170px;
            height: clamp(170px, 28vh, 360px);
        }
        .notes-editor.is-ready {
            display: block;
        }
        .notes-source {
            min-height: 170px;
            padding: 10px;
            border: 1px solid var(--wp-app-color-border);
            border-radius: 4px;
            background: var(--wp-app-color-surface-alt);
            color: var(--wp-app-color-text);
            font: 14px/1.55 ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
            resize: vertical;
            width: 100%;
        }
        .notes-source-hidden {
            display: none !important;
        }
        .notes-editor .overtype-container {
            border: 1px solid var(--wp-app-color-border);
            border-radius: 4px;
            background: var(--wp-app-color-surface-alt);
            overflow: hidden;
            height: 100%;
        }
        .notes-editor .overtype-toolbar {
            border-bottom: 1px solid var(--wp-app-color-border);
        }
        .notes-editor .overtype-toolbar-button {
            border-radius: 3px;
        }
        .notes-editor .overtype-input,
        .notes-editor .overtype-preview {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
        }
        .notes-editor .overtype-stats {
            border-top: 1px solid var(--wp-app-color-border);
            color: var(--wp-app-color-muted);
            font-size: 0.78rem;
        }
        .module {
            padding: 16px 0;
            border-top: 1px solid var(--wp-app-color-border);
        }
        .module:first-child { border-top: 0; padding-top: 0; }
        .lesson-list {
            display: grid;
            gap: 8px;
            margin: 12px 0 0;
        }
        .lesson {
            display: grid;
            gap: 6px;
            padding: 4px 0;
        }
        .lesson-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 8px;
            align-items: center;
        }
        .lesson-check {
            display: grid;
            grid-template-columns: 24px minmax(0, 1fr);
            gap: 8px;
            align-items: start;
        }
        .lesson-check input { margin-top: 4px; }
        .lesson a {
            color: var(--wp-app-color-link);
            text-decoration-thickness: 1px;
        }
        .lesson-note-toggle {
            min-height: 28px;
            padding: 2px 0;
            border: 0;
            background: transparent;
            color: var(--wp-app-color-link);
            font-size: 0.85rem;
            opacity: 0;
            pointer-events: none;
            text-decoration: underline;
        }
        .lesson:hover .lesson-note-toggle,
        .lesson:focus-within .lesson-note-toggle,
        .lesson.has-note .lesson-note-toggle {
            opacity: 1;
            pointer-events: auto;
        }
        .lesson-note-preview {
            margin: 0 0 0 32px;
            color: var(--wp-app-color-muted);
            font-size: 0.9rem;
        }
        .lesson-note-form {
            display: grid;
            gap: 8px;
            margin: 4px 0 8px 32px;
        }
        .lesson-note-form[hidden] {
            display: none;
        }
        .lesson-note-editor {
            min-height: 130px;
            height: clamp(130px, 22vh, 260px);
        }
        .lesson-note-source {
            min-height: 130px;
        }
        .lesson-note-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .actions {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
            margin-top: 16px;
        }
        .save-status {
            color: var(--wp-app-color-muted);
            font-size: 0.9rem;
        }
        @media (max-width: 860px) {
            header.page-header { display: block; }
            .layout { grid-template-columns: 1fr; }
            .course-item { grid-template-columns: 1fr; }
            .search-form { grid-template-columns: 1fr; }
            button, .button { width: 100%; }
            .lesson-row {
                grid-template-columns: 1fr;
            }
            .lesson-note-toggle {
                width: auto;
                justify-self: start;
                opacity: 1;
                pointer-events: auto;
            }
            .lesson-note-preview,
            .lesson-note-form {
                margin-left: 32px;
            }
            .notes-editor .overtype-toolbar button {
                width: auto;
            }
        }
    </style>
</head>
<body>
    <?php wp_app_body_open(); ?>

    <main>
        <header class="page-header">
            <div>
                <h1><a href="<?php echo esc_url( home_url( '/learn-app/' ) ); ?>"><?php echo esc_html__( 'Learn WordPress', 'learn-app' ); ?></a></h1>
                <p class="muted"><?php echo esc_html__( 'Choose the Learn WordPress course you joined and track your own study progress here.', 'learn-app' ); ?></p>
            </div>
        </header>

        <?php if ( '' !== $notice ) : ?>
            <div class="notice"><?php echo esc_html( $notice ); ?></div>
        <?php endif; ?>

        <?php if ( $trashed_plan_id > 0 ) : ?>
            <div class="notice">
                <?php echo esc_html__( 'Course removed.', 'learn-app' ); ?>
                <form class="notice-form" method="post" action="<?php echo esc_url( home_url( '/learn-app/' ) ); ?>">
                    <?php wp_nonce_field( 'wordpress_courses_action', 'wordpress_courses_nonce' ); ?>
                    <input type="hidden" name="wordpress_courses_action" value="undo_clear_selection">
                    <input type="hidden" name="plan_id" value="<?php echo esc_attr( $trashed_plan_id ); ?>">
                    <button type="submit"><?php echo esc_html__( 'Undo', 'learn-app' ); ?></button>
                </form>
            </div>
        <?php endif; ?>

        <?php if ( '' !== $error ) : ?>
            <div class="error"><?php echo esc_html( $error ); ?></div>
        <?php endif; ?>

        <div class="layout <?php echo $show_sidebar ? '' : 'full'; ?>">
            <section class="stack">
                <?php if ( $selected_course_id > 0 ) : ?>
                    <div class="panel">
                        <h2><?php echo esc_html__( 'Course Checklist', 'learn-app' ); ?></h2>

                        <?php if ( is_wp_error( $selected_modules ) ) : ?>
                            <div class="error"><?php echo esc_html( $selected_modules->get_error_message() ); ?></div>
                        <?php elseif ( empty( $selected_modules ) ) : ?>
                            <p class="muted"><?php echo esc_html__( 'No lessons were found for this course yet.', 'learn-app' ); ?></p>
                        <?php else : ?>
                            <div id="course-progress-form" data-plan-id="<?php echo esc_attr( $selected_plan_id ); ?>">
                                <?php foreach ( $selected_modules as $module ) : ?>
                                    <section class="module">
                                        <h3><?php echo esc_html( $module['title'] ); ?></h3>
                                        <?php if ( '' !== $module['description'] ) : ?>
                                            <p class="muted"><?php echo esc_html( $module['description'] ); ?></p>
                                        <?php endif; ?>
                                        <div class="lesson-list">
                                            <?php foreach ( $module['lessons'] as $lesson ) : ?>
                                                <?php
                                                $lesson_id          = absint( $lesson['id'] );
                                                $lesson_note_html   = $lesson_notes[ $lesson_id ] ?? '';
                                                $lesson_note_editor = CoursePlans::get_lesson_note_editor_text( $user_id, $selected_plan_id, $lesson_id );
                                                $lesson_note_target = 'lesson-note-' . $lesson_id;
                                                $lesson_note_preview = '' !== $lesson_note_html
                                                    ? wp_trim_words( wp_strip_all_tags( $lesson_note_html ), 18, '...' )
                                                    : '';
                                                ?>
                                                <article class="lesson <?php echo '' !== $lesson_note_html ? 'has-note' : ''; ?>" data-lesson-note-item>
                                                    <div class="lesson-row">
                                                        <label class="lesson-check">
                                                            <input type="checkbox" name="completed_lessons[]" value="<?php echo esc_attr( $lesson_id ); ?>" <?php checked( in_array( $lesson_id, $completed_lesson_ids, true ) ); ?>>
                                                            <span>
                                                                <?php if ( '' !== $lesson['link'] ) : ?>
                                                                    <a href="<?php echo esc_url( $lesson['link'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $lesson['title'] ); ?></a>
                                                                <?php else : ?>
                                                                    <?php echo esc_html( $lesson['title'] ); ?>
                                                                <?php endif; ?>
                                                            </span>
                                                        </label>
                                                        <button type="button" class="lesson-note-toggle" data-lesson-note-toggle aria-expanded="false" aria-controls="<?php echo esc_attr( $lesson_note_target ); ?>">
                                                            <?php echo esc_html( '' !== $lesson_note_html ? __( 'Edit note', 'learn-app' ) : __( 'Add note', 'learn-app' ) ); ?>
                                                        </button>
                                                    </div>
                                                    <?php if ( '' !== $lesson_note_preview ) : ?>
                                                        <p class="lesson-note-preview"><?php echo esc_html( $lesson_note_preview ); ?></p>
                                                    <?php endif; ?>
                                                    <form id="<?php echo esc_attr( $lesson_note_target ); ?>" class="lesson-note-form" method="post" action="<?php echo esc_url( add_query_arg( 'plan_id', $selected_plan_id, home_url( '/learn-app/' ) ) ); ?>" data-lesson-note-form hidden>
                                                        <?php wp_nonce_field( 'wordpress_courses_action', 'wordpress_courses_nonce' ); ?>
                                                        <input type="hidden" name="wordpress_courses_action" value="save_lesson_note">
                                                        <input type="hidden" name="plan_id" value="<?php echo esc_attr( $selected_plan_id ); ?>">
                                                        <input type="hidden" name="lesson_id" value="<?php echo esc_attr( $lesson_id ); ?>">
                                                        <div class="notes-editor lesson-note-editor" data-notes-editor></div>
                                                        <textarea class="notes-source lesson-note-source" name="lesson_note" rows="5" data-notes-source aria-label="<?php echo esc_attr__( 'Lesson note markdown', 'learn-app' ); ?>" placeholder="<?php echo esc_attr__( 'Add a note for this lesson.', 'learn-app' ); ?>"><?php echo esc_textarea( $lesson_note_editor ); ?></textarea>
                                                        <div class="lesson-note-actions">
                                                            <button type="submit" class="secondary"><?php echo esc_html__( 'Save Note', 'learn-app' ); ?></button>
                                                            <button type="button" class="secondary" data-lesson-note-cancel><?php echo esc_html__( 'Cancel', 'learn-app' ); ?></button>
                                                        </div>
                                                    </form>
                                                </article>
                                            <?php endforeach; ?>
                                        </div>
                                    </section>
                                <?php endforeach; ?>

                                <div class="actions">
                                    <span class="save-status" data-save-status></span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ( 0 === $selected_course_id && ! empty( $active_plans ) && '' === $search ) : ?>
                    <div class="panel">
                        <h2><?php echo esc_html__( 'Your Courses', 'learn-app' ); ?></h2>
                        <div class="course-list">
                            <?php foreach ( $active_plans as $plan ) : ?>
                                <?php $plan_progress = $get_plan_progress( $plan ); ?>
                                <article class="course-item">
                                    <div>
                                        <h3><a href="<?php echo esc_url( add_query_arg( 'plan_id', $plan['id'], home_url( '/learn-app/' ) ) ); ?>"><?php echo esc_html( $plan['title'] ); ?></a></h3>
                                        <div class="course-meta">
                                            <span>
                                                <?php
                                                echo esc_html(
                                                    sprintf(
                                                        __( '%1$d%% complete (%2$d lessons left) . %3$s until %4$s', 'learn-app' ),
                                                        $plan_progress['lesson_progress'],
                                                        $plan_progress['lessons_left'],
                                                        $plan_progress['time_status'],
                                                        $plan_progress['end_date_label']
                                                    )
                                                );
                                                ?>
                                            </span>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ( 0 === $selected_course_id && ( empty( $active_plans ) || '' !== $search ) ) : ?>
                    <div class="panel">
                        <h2><?php echo esc_html__( 'Find Your Course', 'learn-app' ); ?></h2>
                        <form class="search-form" method="get" action="<?php echo esc_url( home_url( '/learn-app/' ) ); ?>">
                            <input type="search" name="course_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Search Learn WordPress courses', 'learn-app' ); ?>">
                            <button type="submit"><?php echo esc_html__( 'Search', 'learn-app' ); ?></button>
                        </form>

                        <?php if ( is_wp_error( $courses ) ) : ?>
                            <div class="error"><?php echo esc_html( $courses->get_error_message() ); ?></div>
                        <?php elseif ( empty( $courses ) ) : ?>
                            <p class="muted"><?php echo esc_html__( 'No courses found.', 'learn-app' ); ?></p>
                        <?php else : ?>
                            <div class="course-list">
                                <?php foreach ( $courses as $course ) : ?>
                                    <article class="course-item">
                                        <div>
                                            <h3><?php echo esc_html( $course['title'] ); ?></h3>
                                            <?php if ( '' !== $course['excerpt'] ) : ?>
                                                <p class="muted"><?php echo esc_html( $course['excerpt'] ); ?></p>
                                            <?php endif; ?>
                                            <div class="course-meta">
                                                <span><?php echo esc_html( sprintf( __( 'Learn ID: %d', 'learn-app' ), $course['id'] ) ); ?></span>
                                                <?php if ( $course['duration'] > 0 ) : ?>
                                                    <span><?php echo esc_html( sprintf( __( 'Estimated %.1f hours', 'learn-app' ), $course['duration'] ) ); ?></span>
                                                <?php endif; ?>
                                                <?php if ( '' !== $course['link'] ) : ?>
                                                    <a href="<?php echo esc_url( $course['link'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'View on Learn', 'learn-app' ); ?></a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <form method="post" action="<?php echo esc_url( home_url( '/learn-app/' ) ); ?>">
                                            <?php wp_nonce_field( 'wordpress_courses_action', 'wordpress_courses_nonce' ); ?>
                                            <input type="hidden" name="wordpress_courses_action" value="select_course">
                                            <input type="hidden" name="course_id" value="<?php echo esc_attr( $course['id'] ); ?>">
                                            <button type="submit"><?php echo esc_html__( 'Select', 'learn-app' ); ?></button>
                                        </form>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ( $show_sidebar ) : ?>
            <aside class="sidebar-stack">
                <?php if ( $selected_course_id > 0 ) : ?>
                    <div class="panel">
                        <h2><?php echo esc_html__( 'Your Course', 'learn-app' ); ?></h2>

                        <?php if ( is_wp_error( $selected_course ) ) : ?>
                            <div class="error"><?php echo esc_html( $selected_course->get_error_message() ); ?></div>
                        <?php elseif ( empty( $selected_course_id ) || empty( $selected_course ) ) : ?>
                            <p class="muted"><?php echo esc_html__( 'Select the course you joined on Learn WordPress.', 'learn-app' ); ?></p>
                        <?php else : ?>
                            <div class="selected-summary">
                        <div class="selected-header">
                            <h3><?php echo esc_html( $selected_course['title'] ); ?></h3>
                            <form method="post" action="<?php echo esc_url( add_query_arg( 'plan_id', $selected_plan_id, home_url( '/learn-app/' ) ) ); ?>">
                                <?php wp_nonce_field( 'wordpress_courses_action', 'wordpress_courses_nonce' ); ?>
                                <input type="hidden" name="wordpress_courses_action" value="clear_selection">
                                <button type="submit" class="icon-button" aria-label="<?php echo esc_attr__( 'Remove course', 'learn-app' ); ?>">&times;</button>
                            </form>
                        </div>
                        <div class="progress-row">
                            <div class="progress-label">
                                <span><?php echo esc_html__( 'Lessons', 'learn-app' ); ?></span>
                                <span data-progress-summary><?php echo esc_html( sprintf( __( '%1$d of %2$d', 'learn-app' ), $completed_count, $selected_lesson_count ) ); ?></span>
                            </div>
                            <div class="progress-meter" aria-hidden="true" data-progress-meter>
                                <span style="width: <?php echo esc_attr( $lesson_progress ); ?>%"></span>
                            </div>
                        </div>
                        <div class="progress-row">
                            <div class="progress-label">
                                <span><?php echo esc_html( $time_status ); ?></span>
                                <span><?php echo esc_html( sprintf( __( '%d%%', 'learn-app' ), $time_progress ) ); ?></span>
                            </div>
                            <div class="progress-meter time" aria-hidden="true">
                                <span style="width: <?php echo esc_attr( $time_progress ); ?>%"></span>
                            </div>
                        </div>
                        <details class="date-details">
                            <summary><?php echo esc_html__( 'Edit Dates', 'learn-app' ); ?></summary>
                            <form class="date-form" method="post" action="<?php echo esc_url( add_query_arg( 'plan_id', $selected_plan_id, home_url( '/learn-app/' ) ) ); ?>">
                                <?php wp_nonce_field( 'wordpress_courses_action', 'wordpress_courses_nonce' ); ?>
                                <input type="hidden" name="wordpress_courses_action" value="save_dates">
                                <input type="hidden" name="plan_id" value="<?php echo esc_attr( $selected_plan_id ); ?>">
                                <div class="date-fields">
                                    <label class="date-field">
                                        <span><?php echo esc_html__( 'Start', 'learn-app' ); ?></span>
                                        <input type="date" name="start_date" value="<?php echo esc_attr( $plan_dates['start_date'] ); ?>">
                                    </label>
                                    <label class="date-field">
                                        <span><?php echo esc_html__( 'End', 'learn-app' ); ?></span>
                                        <input type="date" name="end_date" value="<?php echo esc_attr( $plan_dates['end_date'] ); ?>">
                                    </label>
                                </div>
                                <button type="submit" class="secondary"><?php echo esc_html__( 'Save Dates', 'learn-app' ); ?></button>
                            </form>
                        </details>
                        <div class="course-notes">
                            <div class="course-notes-header">
                                <h3><?php echo esc_html__( 'Notes', 'learn-app' ); ?></h3>
                                <button type="button" class="course-notes-toggle" data-course-notes-toggle aria-expanded="false" aria-controls="course-notes-form">
                                    <?php echo esc_html( '' !== $plan_notes_html ? __( 'Edit notes', 'learn-app' ) : __( 'Add notes', 'learn-app' ) ); ?>
                                </button>
                            </div>
                            <?php if ( '' !== $plan_notes_preview ) : ?>
                                <p class="course-notes-preview"><?php echo esc_html( $plan_notes_preview ); ?></p>
                            <?php endif; ?>
                        </div>
                        <form id="course-notes-form" class="notes-form" method="post" action="<?php echo esc_url( add_query_arg( 'plan_id', $selected_plan_id, home_url( '/learn-app/' ) ) ); ?>" data-notes-form hidden>
                            <?php wp_nonce_field( 'wordpress_courses_action', 'wordpress_courses_nonce' ); ?>
                            <input type="hidden" name="wordpress_courses_action" value="save_notes">
                            <input type="hidden" name="plan_id" value="<?php echo esc_attr( $selected_plan_id ); ?>">
                            <div class="notes-editor" data-notes-editor></div>
                            <textarea class="notes-source" name="notes" rows="8" data-notes-source aria-label="<?php echo esc_attr__( 'Course notes markdown', 'learn-app' ); ?>" placeholder="<?php echo esc_attr__( 'Add notes for this course.', 'learn-app' ); ?>"><?php echo esc_textarea( $plan_notes_editor ); ?></textarea>
                            <div class="lesson-note-actions">
                                <button type="submit" class="secondary"><?php echo esc_html__( 'Save Notes', 'learn-app' ); ?></button>
                                <button type="button" class="secondary" data-course-notes-cancel><?php echo esc_html__( 'Cancel', 'learn-app' ); ?></button>
                            </div>
                        </form>
                        <?php if ( '' !== $selected_course['link'] ) : ?>
                            <a class="button secondary" href="<?php echo esc_url( $selected_course['link'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Open on Learn', 'learn-app' ); ?></a>
                        <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ( ! empty( $other_plans ) ) : ?>
                            <div class="active-course-list">
                                <?php foreach ( $other_plans as $plan ) : ?>
                                    <div class="active-course">
                                        <strong><a href="<?php echo esc_url( add_query_arg( 'plan_id', $plan['id'], home_url( '/learn-app/' ) ) ); ?>"><?php echo esc_html( $plan['title'] ); ?></a></strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $active_plans ) ) : ?>
                    <div class="panel">
                        <h3><?php echo esc_html__( 'Add Course', 'learn-app' ); ?></h3>
                        <form class="search-form" method="get" action="<?php echo esc_url( home_url( '/learn-app/' ) ); ?>">
                            <input type="search" name="course_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Search Learn WordPress courses', 'learn-app' ); ?>">
                            <button type="submit"><?php echo esc_html__( 'Search', 'learn-app' ); ?></button>
                        </form>
                    </div>
                <?php endif; ?>
            </aside>
            <?php endif; ?>
        </div>
    </main>

    <?php if ( $selected_plan_id > 0 && $selected_course_id > 0 ) : ?>
        <script>
            (function() {
                const form = document.getElementById('course-progress-form');
                if (!form) {
                    return;
                }

                const status = document.querySelector('[data-save-status]');
                const summary = document.querySelector('[data-progress-summary]');
                const meter = document.querySelector('[data-progress-meter] span');
                let pendingRequest = null;

                function setStatus(text) {
                    if (status) {
                        status.textContent = text;
                    }
                }

                function saveProgress() {
                    const data = new FormData();
                    data.append('action', 'wordpress_courses_save_progress');
                    data.append('nonce', '<?php echo esc_js( wp_create_nonce( 'wordpress_courses_progress' ) ); ?>');
                    data.append('plan_id', form.dataset.planId || '');

                    form.querySelectorAll('input[name="completed_lessons[]"]:checked').forEach(function(input) {
                        data.append('completed_lessons[]', input.value);
                    });

                    if (pendingRequest) {
                        pendingRequest.abort();
                    }

                    pendingRequest = new AbortController();
                    setStatus('<?php echo esc_js( __( 'Saving...', 'learn-app' ) ); ?>');

                    fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: data,
                        signal: pendingRequest.signal
                    }).then(function(response) {
                        return response.json();
                    }).then(function(response) {
                        if (!response || !response.success) {
                            throw new Error(response && response.data && response.data.message ? response.data.message : 'Save failed');
                        }

                        if (summary) {
                            summary.textContent = response.data.summary;
                        }

                        if (meter) {
                            meter.style.width = response.data.lesson_progress + '%';
                        }

                        setStatus('<?php echo esc_js( __( 'Saved', 'learn-app' ) ); ?>');
                    }).catch(function(error) {
                        if ('AbortError' === error.name) {
                            return;
                        }

                        setStatus('<?php echo esc_js( __( 'Could not save', 'learn-app' ) ); ?>');
                    });
                }

                form.querySelectorAll('input[name="completed_lessons[]"]').forEach(function(input) {
                    input.addEventListener('change', saveProgress);
                });
            })();

        </script>
    <?php endif; ?>

    <?php wp_app_body_close(); ?>
</body>
</html>
