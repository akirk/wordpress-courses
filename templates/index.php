<?php
use WordpressCourses\App;

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
    $modules   = $course_id > 0 ? App::get_course_modules( $course_id ) : [];

    if ( is_wp_error( $modules ) || ! is_array( $modules ) ) {
        $modules = [];
    }

    $lesson_count          = App::count_lessons( $modules );
    $completed_lesson_ids  = App::get_completed_lesson_ids( $user_id, $plan_id );
    $completed_count       = count( array_intersect( $completed_lesson_ids, $collect_lesson_ids( $modules ) ) );
    $lesson_progress       = $lesson_count > 0 ? (int) round( ( $completed_count / $lesson_count ) * 100 ) : 0;
    $time_progress         = App::get_time_progress_percent( $plan['start_date'] ?? '', $plan['end_date'] ?? '' );
    $days_left             = ! empty( $plan['end_date'] ) ? (int) ceil( ( strtotime( $plan['end_date'] . ' 23:59:59' ) - current_time( 'timestamp' ) ) / DAY_IN_SECONDS ) : 0;

    if ( $days_left > 1 ) {
        $time_status = sprintf( __( '%d days left', 'wordpress-courses' ), $days_left );
    } elseif ( 1 === $days_left ) {
        $time_status = __( '1 day left', 'wordpress-courses' );
    } elseif ( 0 === $days_left ) {
        $time_status = __( 'Due today', 'wordpress-courses' );
    } elseif ( -1 === $days_left ) {
        $time_status = __( '1 day overdue', 'wordpress-courses' );
    } else {
        $time_status = sprintf( __( '%d days overdue', 'wordpress-courses' ), abs( $days_left ) );
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
        $error = __( 'That request could not be verified. Please try again.', 'wordpress-courses' );
    } elseif ( 'select_course' === $action ) {
        $course_id = isset( $_POST['course_id'] ) ? absint( $_POST['course_id'] ) : 0;
        $plan_id   = App::get_or_create_plan_id( $user_id, $course_id );

        if ( $plan_id > 0 ) {
            wp_safe_redirect( add_query_arg( 'plan_id', $plan_id, home_url( '/wordpress-courses/' ) ) );
            exit;
        }

        $error = __( 'The course plan could not be created.', 'wordpress-courses' );
    } elseif ( 'clear_selection' === $action ) {
        $trashed_plan_id = $selected_plan_id;
        App::trash_plan( $user_id, $selected_plan_id );
        wp_safe_redirect( add_query_arg( 'trashed_plan_id', $trashed_plan_id, home_url( '/wordpress-courses/' ) ) );
        exit;
    } elseif ( 'undo_clear_selection' === $action ) {
        $restore_plan_id = isset( $_POST['plan_id'] ) ? absint( $_POST['plan_id'] ) : 0;

        if ( App::restore_plan( $user_id, $restore_plan_id ) ) {
            wp_safe_redirect( add_query_arg( 'plan_id', $restore_plan_id, home_url( '/wordpress-courses/' ) ) );
            exit;
        }

        $error = __( 'The course could not be restored.', 'wordpress-courses' );
    } elseif ( 'save_dates' === $action ) {
        $dates_plan_id = isset( $_POST['plan_id'] ) ? absint( $_POST['plan_id'] ) : $selected_plan_id;
        $start_date    = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
        $end_date      = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';
        $result        = App::set_plan_dates( $user_id, $dates_plan_id, $start_date, $end_date );

        if ( is_wp_error( $result ) ) {
            $error = $result->get_error_message();
        } else {
            $selected_plan_id   = $dates_plan_id;
            $selected_course_id = App::get_plan_course_id( $user_id, $selected_plan_id );
            $notice             = __( 'Course dates saved.', 'wordpress-courses' );
        }
    } elseif ( 'save_progress' === $action ) {
        $progress_plan_id     = isset( $_POST['plan_id'] ) ? absint( $_POST['plan_id'] ) : $selected_plan_id;
        $completed_lesson_ids = isset( $_POST['completed_lessons'] ) && is_array( $_POST['completed_lessons'] )
            ? array_map( 'absint', wp_unslash( $_POST['completed_lessons'] ) )
            : [];
        App::set_completed_lesson_ids( $user_id, $progress_plan_id, $completed_lesson_ids );
        $selected_plan_id = $progress_plan_id;
        $selected_course_id = App::get_plan_course_id( $user_id, $selected_plan_id );
        $notice = __( 'Progress saved.', 'wordpress-courses' );
    }
}

$active_plans = App::get_active_plans( $user_id );

if ( $selected_plan_id <= 0 && '' === $search && 1 === count( $active_plans ) ) {
    $selected_plan_id = absint( $active_plans[0]['id'] );
}

$selected_course_id = App::get_plan_course_id( $user_id, $selected_plan_id );
$selected_course       = $selected_course_id > 0 ? App::get_course( $selected_course_id ) : null;
$selected_modules      = $selected_course_id > 0 ? App::get_course_modules( $selected_course_id ) : [];
$completed_lesson_ids  = App::get_completed_lesson_ids( $user_id, $selected_plan_id );
$selected_lesson_count = is_array( $selected_modules ) ? App::count_lessons( $selected_modules ) : 0;
$completed_count       = count( array_intersect( $completed_lesson_ids, $collect_lesson_ids( is_array( $selected_modules ) ? $selected_modules : [] ) ) );
$lesson_progress       = $selected_lesson_count > 0 ? (int) round( ( $completed_count / $selected_lesson_count ) * 100 ) : 0;
$plan_dates            = $selected_plan_id > 0 ? App::get_plan_dates( $user_id, $selected_plan_id ) : [ 'start_date' => '', 'end_date' => '' ];
$time_progress         = App::get_time_progress_percent( $plan_dates['start_date'], $plan_dates['end_date'] );
$days_left             = '' !== $plan_dates['end_date'] ? (int) ceil( ( strtotime( $plan_dates['end_date'] . ' 23:59:59' ) - current_time( 'timestamp' ) ) / DAY_IN_SECONDS ) : 0;
if ( $days_left > 1 ) {
    $time_status = sprintf( __( '%d days left', 'wordpress-courses' ), $days_left );
} elseif ( 1 === $days_left ) {
    $time_status = __( '1 day left', 'wordpress-courses' );
} elseif ( 0 === $days_left ) {
    $time_status = __( 'Due today', 'wordpress-courses' );
} elseif ( -1 === $days_left ) {
    $time_status = __( '1 day overdue', 'wordpress-courses' );
} else {
    $time_status = sprintf( __( '%d days overdue', 'wordpress-courses' ), abs( $days_left ) );
}
$courses               = ( 0 === $selected_course_id || '' !== $search ) ? App::get_courses( $search ) : [];
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
    <title><?php wp_app_title( 'WordPress Courses' ); ?></title>
    <?php wp_app_head(); ?>
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
            grid-template-columns: 24px minmax(0, 1fr);
            gap: 8px;
            align-items: start;
        }
        .lesson input { margin-top: 4px; }
        .lesson a {
            color: var(--wp-app-color-link);
            text-decoration-thickness: 1px;
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
        }
    </style>
</head>
<body>
    <?php wp_app_body_open(); ?>

    <main>
        <header class="page-header">
            <div>
                <h1><?php echo esc_html__( 'WordPress Courses', 'wordpress-courses' ); ?></h1>
                <p class="muted"><?php echo esc_html__( 'Choose the Learn WordPress course you joined and track your own study progress here.', 'wordpress-courses' ); ?></p>
            </div>
        </header>

        <?php if ( '' !== $notice ) : ?>
            <div class="notice"><?php echo esc_html( $notice ); ?></div>
        <?php endif; ?>

        <?php if ( $trashed_plan_id > 0 ) : ?>
            <div class="notice">
                <?php echo esc_html__( 'Course removed.', 'wordpress-courses' ); ?>
                <form class="notice-form" method="post" action="<?php echo esc_url( home_url( '/wordpress-courses/' ) ); ?>">
                    <?php wp_nonce_field( 'wordpress_courses_action', 'wordpress_courses_nonce' ); ?>
                    <input type="hidden" name="wordpress_courses_action" value="undo_clear_selection">
                    <input type="hidden" name="plan_id" value="<?php echo esc_attr( $trashed_plan_id ); ?>">
                    <button type="submit"><?php echo esc_html__( 'Undo', 'wordpress-courses' ); ?></button>
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
                        <h2><?php echo esc_html__( 'Course Checklist', 'wordpress-courses' ); ?></h2>

                        <?php if ( is_wp_error( $selected_modules ) ) : ?>
                            <div class="error"><?php echo esc_html( $selected_modules->get_error_message() ); ?></div>
                        <?php elseif ( empty( $selected_modules ) ) : ?>
                            <p class="muted"><?php echo esc_html__( 'No lessons were found for this course yet.', 'wordpress-courses' ); ?></p>
                        <?php else : ?>
                            <form id="course-progress-form" method="post" action="<?php echo esc_url( add_query_arg( 'plan_id', $selected_plan_id, home_url( '/wordpress-courses/' ) ) ); ?>" data-plan-id="<?php echo esc_attr( $selected_plan_id ); ?>">
                                <?php wp_nonce_field( 'wordpress_courses_action', 'wordpress_courses_nonce' ); ?>
                                <input type="hidden" name="wordpress_courses_action" value="save_progress">
                                <input type="hidden" name="plan_id" value="<?php echo esc_attr( $selected_plan_id ); ?>">

                                <?php foreach ( $selected_modules as $module ) : ?>
                                    <section class="module">
                                        <h3><?php echo esc_html( $module['title'] ); ?></h3>
                                        <?php if ( '' !== $module['description'] ) : ?>
                                            <p class="muted"><?php echo esc_html( $module['description'] ); ?></p>
                                        <?php endif; ?>
                                        <div class="lesson-list">
                                            <?php foreach ( $module['lessons'] as $lesson ) : ?>
                                                <label class="lesson">
                                                    <input type="checkbox" name="completed_lessons[]" value="<?php echo esc_attr( $lesson['id'] ); ?>" <?php checked( in_array( $lesson['id'], $completed_lesson_ids, true ) ); ?>>
                                                    <span>
                                                        <?php if ( '' !== $lesson['link'] ) : ?>
                                                            <a href="<?php echo esc_url( $lesson['link'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $lesson['title'] ); ?></a>
                                                        <?php else : ?>
                                                            <?php echo esc_html( $lesson['title'] ); ?>
                                                        <?php endif; ?>
                                                    </span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </section>
                                <?php endforeach; ?>

                                <div class="actions">
                                    <span class="save-status" data-save-status></span>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ( 0 === $selected_course_id && ! empty( $active_plans ) && '' === $search ) : ?>
                    <div class="panel">
                        <h2><?php echo esc_html__( 'Your Courses', 'wordpress-courses' ); ?></h2>
                        <div class="course-list">
                            <?php foreach ( $active_plans as $plan ) : ?>
                                <?php $plan_progress = $get_plan_progress( $plan ); ?>
                                <article class="course-item">
                                    <div>
                                        <h3><a href="<?php echo esc_url( add_query_arg( 'plan_id', $plan['id'], home_url( '/wordpress-courses/' ) ) ); ?>"><?php echo esc_html( $plan['title'] ); ?></a></h3>
                                        <div class="course-meta">
                                            <span>
                                                <?php
                                                echo esc_html(
                                                    sprintf(
                                                        __( '%1$d%% complete (%2$d lessons left) . %3$s until %4$s', 'wordpress-courses' ),
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
                        <h2><?php echo esc_html__( 'Find Your Course', 'wordpress-courses' ); ?></h2>
                        <form class="search-form" method="get" action="<?php echo esc_url( home_url( '/wordpress-courses/' ) ); ?>">
                            <input type="search" name="course_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Search Learn WordPress courses', 'wordpress-courses' ); ?>">
                            <button type="submit"><?php echo esc_html__( 'Search', 'wordpress-courses' ); ?></button>
                        </form>

                        <?php if ( is_wp_error( $courses ) ) : ?>
                            <div class="error"><?php echo esc_html( $courses->get_error_message() ); ?></div>
                        <?php elseif ( empty( $courses ) ) : ?>
                            <p class="muted"><?php echo esc_html__( 'No courses found.', 'wordpress-courses' ); ?></p>
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
                                                <span><?php echo esc_html( sprintf( __( 'Learn ID: %d', 'wordpress-courses' ), $course['id'] ) ); ?></span>
                                                <?php if ( $course['duration'] > 0 ) : ?>
                                                    <span><?php echo esc_html( sprintf( __( 'Estimated %.1f hours', 'wordpress-courses' ), $course['duration'] ) ); ?></span>
                                                <?php endif; ?>
                                                <?php if ( '' !== $course['link'] ) : ?>
                                                    <a href="<?php echo esc_url( $course['link'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'View on Learn', 'wordpress-courses' ); ?></a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <form method="post" action="<?php echo esc_url( home_url( '/wordpress-courses/' ) ); ?>">
                                            <?php wp_nonce_field( 'wordpress_courses_action', 'wordpress_courses_nonce' ); ?>
                                            <input type="hidden" name="wordpress_courses_action" value="select_course">
                                            <input type="hidden" name="course_id" value="<?php echo esc_attr( $course['id'] ); ?>">
                                            <button type="submit"><?php echo esc_html__( 'Select', 'wordpress-courses' ); ?></button>
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
                        <h2><?php echo esc_html__( 'Your Course', 'wordpress-courses' ); ?></h2>

                        <?php if ( is_wp_error( $selected_course ) ) : ?>
                            <div class="error"><?php echo esc_html( $selected_course->get_error_message() ); ?></div>
                        <?php elseif ( empty( $selected_course_id ) || empty( $selected_course ) ) : ?>
                            <p class="muted"><?php echo esc_html__( 'Select the course you joined on Learn WordPress.', 'wordpress-courses' ); ?></p>
                        <?php else : ?>
                            <div class="selected-summary">
                        <div class="selected-header">
                            <h3><?php echo esc_html( $selected_course['title'] ); ?></h3>
                            <form method="post" action="<?php echo esc_url( add_query_arg( 'plan_id', $selected_plan_id, home_url( '/wordpress-courses/' ) ) ); ?>">
                                <?php wp_nonce_field( 'wordpress_courses_action', 'wordpress_courses_nonce' ); ?>
                                <input type="hidden" name="wordpress_courses_action" value="clear_selection">
                                <button type="submit" class="icon-button" aria-label="<?php echo esc_attr__( 'Remove course', 'wordpress-courses' ); ?>">&times;</button>
                            </form>
                        </div>
                        <div class="progress-row">
                            <div class="progress-label">
                                <span><?php echo esc_html__( 'Lessons', 'wordpress-courses' ); ?></span>
                                <span data-progress-summary><?php echo esc_html( sprintf( __( '%1$d of %2$d', 'wordpress-courses' ), $completed_count, $selected_lesson_count ) ); ?></span>
                            </div>
                            <div class="progress-meter" aria-hidden="true" data-progress-meter>
                                <span style="width: <?php echo esc_attr( $lesson_progress ); ?>%"></span>
                            </div>
                        </div>
                        <div class="progress-row">
                            <div class="progress-label">
                                <span><?php echo esc_html( $time_status ); ?></span>
                                <span><?php echo esc_html( sprintf( __( '%d%%', 'wordpress-courses' ), $time_progress ) ); ?></span>
                            </div>
                            <div class="progress-meter time" aria-hidden="true">
                                <span style="width: <?php echo esc_attr( $time_progress ); ?>%"></span>
                            </div>
                        </div>
                        <details class="date-details">
                            <summary><?php echo esc_html__( 'Edit Dates', 'wordpress-courses' ); ?></summary>
                            <form class="date-form" method="post" action="<?php echo esc_url( add_query_arg( 'plan_id', $selected_plan_id, home_url( '/wordpress-courses/' ) ) ); ?>">
                                <?php wp_nonce_field( 'wordpress_courses_action', 'wordpress_courses_nonce' ); ?>
                                <input type="hidden" name="wordpress_courses_action" value="save_dates">
                                <input type="hidden" name="plan_id" value="<?php echo esc_attr( $selected_plan_id ); ?>">
                                <div class="date-fields">
                                    <label class="date-field">
                                        <span><?php echo esc_html__( 'Start', 'wordpress-courses' ); ?></span>
                                        <input type="date" name="start_date" value="<?php echo esc_attr( $plan_dates['start_date'] ); ?>">
                                    </label>
                                    <label class="date-field">
                                        <span><?php echo esc_html__( 'End', 'wordpress-courses' ); ?></span>
                                        <input type="date" name="end_date" value="<?php echo esc_attr( $plan_dates['end_date'] ); ?>">
                                    </label>
                                </div>
                                <button type="submit" class="secondary"><?php echo esc_html__( 'Save Dates', 'wordpress-courses' ); ?></button>
                            </form>
                        </details>
                        <?php if ( '' !== $selected_course['link'] ) : ?>
                            <a class="button secondary" href="<?php echo esc_url( $selected_course['link'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Open on Learn', 'wordpress-courses' ); ?></a>
                        <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ( ! empty( $other_plans ) ) : ?>
                            <div class="active-course-list">
                                <?php foreach ( $other_plans as $plan ) : ?>
                                    <div class="active-course">
                                        <strong><a href="<?php echo esc_url( add_query_arg( 'plan_id', $plan['id'], home_url( '/wordpress-courses/' ) ) ); ?>"><?php echo esc_html( $plan['title'] ); ?></a></strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $active_plans ) ) : ?>
                    <div class="panel">
                        <h3><?php echo esc_html__( 'Add Course', 'wordpress-courses' ); ?></h3>
                        <form class="search-form" method="get" action="<?php echo esc_url( home_url( '/wordpress-courses/' ) ); ?>">
                            <input type="search" name="course_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Search Learn WordPress courses', 'wordpress-courses' ); ?>">
                            <button type="submit"><?php echo esc_html__( 'Search', 'wordpress-courses' ); ?></button>
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
                    setStatus('<?php echo esc_js( __( 'Saving...', 'wordpress-courses' ) ); ?>');

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

                        setStatus('<?php echo esc_js( __( 'Saved', 'wordpress-courses' ) ); ?>');
                    }).catch(function(error) {
                        if ('AbortError' === error.name) {
                            return;
                        }

                        setStatus('<?php echo esc_js( __( 'Could not save', 'wordpress-courses' ) ); ?>');
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
