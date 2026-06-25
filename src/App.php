<?php

namespace WordpressCourses;

use WpApp\BaseApp;
use WpApp\WpApp;

class App extends BaseApp {
    public function __construct() {
        // See https://github.com/akirk/wp-app for documentation.
        $this->app = new WpApp( $this->get_template_dir(), $this->get_url_path(), [
            // Access control
            'require_login'      => true,
            // 'require_capability' => 'read',

            // Masterbar
            // 'show_masterbar_for_anonymous' => false,
            // 'show_wp_logo'                 => true,
            // 'show_site_name'               => true,
            // 'show_dark_mode_toggle'        => false,
            // 'clear_admin_bar'              => false,
            // 'add_app_node'                 => false,

            // App identity
            'app_name'            => $this->get_plugin_name(),
            'app_name_textdomain' => 'learn-app',
            // 'my_apps'             => true,
            'my_apps_icon'        => 'dashicons-welcome-learn-more',
        ] );

        add_action( 'init', [ $this, 'register_post_types' ] );
        add_action( 'wp_ajax_wordpress_courses_save_progress', [ $this, 'ajax_save_progress' ] );
    }

    protected function get_url_path(): string {
        return 'learn-app';
    }

    protected function get_template_dir(): string {
        return dirname( __DIR__ ) . '/templates';
    }

    protected function get_plugin_name(): string {
        if ( ! function_exists( 'get_file_data' ) ) {
            return 'Learn WordPress';
        }

        $plugin_data = get_file_data( dirname( __DIR__ ) . '/learn-app.php', [ 'name' => 'Plugin Name' ] );

        return $plugin_data['name'] ?: 'Learn WordPress';
    }

    protected function setup_database(): void {}

    protected function setup_routes(): void {}

    protected function setup_menu(): void {}

    public function ajax_save_progress(): void {
        check_ajax_referer( 'wordpress_courses_progress', 'nonce' );

        $user_id    = get_current_user_id();
        $plan_id    = isset( $_POST['plan_id'] ) ? absint( wp_unslash( $_POST['plan_id'] ) ) : 0;
        $lesson_ids = isset( $_POST['completed_lessons'] ) && is_array( $_POST['completed_lessons'] )
            ? array_map( 'absint', wp_unslash( $_POST['completed_lessons'] ) )
            : [];

        if ( $user_id <= 0 || ! CoursePlans::user_can_use_plan( $user_id, $plan_id ) ) {
            wp_send_json_error( [ 'message' => __( 'You cannot edit that course plan.', 'learn-app' ) ], 403 );
        }

        CoursePlans::set_completed_lesson_ids( $user_id, $plan_id, $lesson_ids );

        $course_id       = CoursePlans::get_plan_course_id( $user_id, $plan_id );
        $modules         = LearnApi::get_course_modules( $course_id );
        $lesson_count    = is_wp_error( $modules ) || ! is_array( $modules ) ? 0 : LearnApi::count_lessons( $modules );
        $completed_count = 0;

        if ( ! is_wp_error( $modules ) && is_array( $modules ) ) {
            $course_lesson_ids = [];

            foreach ( $modules as $module ) {
                foreach ( $module['lessons'] ?? [] as $lesson ) {
                    if ( ! empty( $lesson['id'] ) ) {
                        $course_lesson_ids[] = absint( $lesson['id'] );
                    }
                }
            }

            $completed_count = count( array_intersect( CoursePlans::get_completed_lesson_ids( $user_id, $plan_id ), $course_lesson_ids ) );
        }

        $lesson_progress = $lesson_count > 0 ? (int) round( ( $completed_count / $lesson_count ) * 100 ) : 0;

        wp_send_json_success(
            [
                'completed_count' => $completed_count,
                'lesson_count'    => $lesson_count,
                'lesson_progress' => $lesson_progress,
                'summary'         => sprintf(
                    /* translators: 1: completed lesson count, 2: total lesson count */
                    __( '%1$d of %2$d', 'learn-app' ),
                    $completed_count,
                    $lesson_count
                ),
                'percent_label'   => sprintf( __( '%d%% complete', 'learn-app' ), $lesson_progress ),
            ]
        );
    }

    public function register_post_types(): void {
        CoursePlans::register_post_type();
    }

    public function activate(): void {
        $this->register_post_types();
        flush_rewrite_rules();
    }

    public function deactivate(): void {
        flush_rewrite_rules();
    }
}
