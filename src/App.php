<?php

namespace WordpressCourses;

use WpApp\WpApp;
use WpApp\BaseApp;
use WpApp\BaseStorage;

class App extends BaseApp {
    private const LEARN_API_BASE = 'https://learn.wordpress.org/wp-json/';
    private const PLAN_POST_TYPE = 'wp_course_plan';
    private const COURSE_ID_META_KEY = '_wordpress_courses_course_id';
    private const COMPLETED_LESSONS_META_KEY = '_wordpress_courses_completed_lesson_ids';
    private const START_DATE_META_KEY = '_wordpress_courses_start_date';
    private const END_DATE_META_KEY = '_wordpress_courses_end_date';

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
            'app_name_textdomain' => 'wordpress-courses',
            // 'my_apps'             => true,
            'my_apps_icon'        => 'dashicons-welcome-learn-more',
        ] );

        add_action( 'init', [ $this, 'register_post_types' ] );
        add_action( 'wp_ajax_wordpress_courses_save_progress', [ $this, 'ajax_save_progress' ] );
        // add_action( 'init', [ $this, 'register_taxonomies' ] );
        // add_action( 'wp_dashboard_setup', [ $this, 'register_dashboard_widgets' ] );
        // add_action( 'wp_abilities_api_categories_init', [ $this, 'register_ability_category' ] );
        // add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
        // add_filter( 'ai_assistant_ability_domains', [ $this, 'register_ai_assistant_ability_domains' ] );
        // add_filter( 'ai_assistant_ability_instructions', [ $this, 'get_ai_assistant_ability_instructions' ], 10, 4 );
    }

    protected function get_url_path(): string {
        return 'wordpress-courses';
    }

    protected function get_template_dir(): string {
        return dirname( __DIR__ ) . '/templates';
    }

    protected function get_plugin_name(): string {
        if ( ! function_exists( 'get_file_data' ) ) {
            return 'WordPress Courses';
        }

        $plugin_data = get_file_data( dirname( __DIR__ ) . '/wordpress-courses.php', [ 'name' => 'Plugin Name' ] );

        return $plugin_data['name'] ?: 'WordPress Courses';
    }

    protected function setup_storage(): void {
        /*
         * Prefer WordPress-native storage before custom tables:
         * - Custom post types and post meta for content-like records.
         * - Taxonomies, terms, and term meta for shared categories or labels.
         * - User meta for per-user settings, preferences, and profile data.
         *
         * Use BaseStorage only when native entities do not fit, such as
         * high-volume rows, relational data, or non-content records.
         *
         * If you do need custom tables:
         *
         * class WordpressCoursesStorage extends BaseStorage {
         *     protected function get_schema() {
         *         $charset_collate = $this->wpdb->get_charset_collate();
         *         return [
         *             "CREATE TABLE {$this->wpdb->prefix}wordpress_courses_items (
         *                 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
         *                 user_id bigint(20) unsigned NOT NULL,
         *                 title varchar(255) NOT NULL,
         *                 created_at datetime DEFAULT CURRENT_TIMESTAMP,
         *                 PRIMARY KEY (id),
         *                 KEY user_id (user_id)
         *             ) $charset_collate;",
         *         ];
         *     }
         * }
         *
         * Then in __construct(): $this->storage = new WordpressCoursesStorage();
         * And in activate():     $this->storage->create_tables();
         */
    }

    protected function setup_database(): void {
        $this->setup_storage();
    }

    protected function setup_routes(): void {
        /*
         * Add WpApp routes here. BaseApp calls this method during init().
         *
         * $this->app->route( '' );               // -> templates/index.php
         * $this->app->route( 'overview' );       // -> templates/overview.php
         * $this->app->route( 'item/{id}' );      // -> templates/item.php
         */
    }

    protected function setup_menu(): void {
        /*
         * Add WpApp masterbar/menu entries here. BaseApp calls this method
         * during init(), after routes have been registered.
         *
         * $this->app->add_menu_item( 'overview', 'Overview', home_url( '/wordpress-courses/overview' ) );
         */
    }

    public static function get_active_plans( int $user_id ): array {
        $plans = get_posts(
            [
                'post_type'      => self::PLAN_POST_TYPE,
                'post_status'    => 'publish',
                'author'         => $user_id,
                'posts_per_page' => -1,
                'orderby'        => 'modified',
                'order'          => 'DESC',
            ]
        );

        return array_values(
            array_map(
                function ( \WP_Post $plan ): array {
                    $dates = self::get_plan_dates_for_plan( $plan->ID );

                    return [
                        'id'        => $plan->ID,
                        'course_id' => absint( get_post_meta( $plan->ID, self::COURSE_ID_META_KEY, true ) ),
                        'title'     => get_the_title( $plan ),
                        'start_date' => $dates['start_date'],
                        'end_date'   => $dates['end_date'],
                    ];
                },
                $plans
            )
        );
    }

    public static function get_plan_course_id( int $user_id, int $plan_id ): int {
        if ( ! self::user_can_use_plan( $user_id, $plan_id ) ) {
            return 0;
        }

        return absint( get_post_meta( $plan_id, self::COURSE_ID_META_KEY, true ) );
    }

    public static function get_or_create_plan_id( int $user_id, int $course_id ): int {
        $plan_id = self::find_plan_id( $user_id, $course_id );

        if ( $plan_id > 0 ) {
            return $plan_id;
        }

        $course = self::get_course( $course_id );
        $title  = is_array( $course ) && ! empty( $course['title'] )
            ? $course['title']
            : sprintf( 'Learn course %d', $course_id );

        $plan_id = wp_insert_post(
            [
                'post_type'   => self::PLAN_POST_TYPE,
                'post_status' => 'publish',
                'post_author' => $user_id,
                'post_title'  => $title,
            ],
            true
        );

        if ( is_wp_error( $plan_id ) ) {
            return 0;
        }

        update_post_meta( $plan_id, self::COURSE_ID_META_KEY, $course_id );
        update_post_meta( $plan_id, self::COMPLETED_LESSONS_META_KEY, [] );
        self::set_default_plan_dates( (int) $plan_id );

        return (int) $plan_id;
    }

    public static function get_plan_dates( int $user_id, int $plan_id ): array {
        if ( ! self::user_can_use_plan( $user_id, $plan_id ) ) {
            return [
                'start_date' => '',
                'end_date'   => '',
            ];
        }

        $dates = self::get_plan_dates_for_plan( $plan_id );

        if ( '' === $dates['start_date'] || '' === $dates['end_date'] ) {
            self::set_default_plan_dates( $plan_id );
            $dates = self::get_plan_dates_for_plan( $plan_id );
        }

        return $dates;
    }

    public static function set_plan_dates( int $user_id, int $plan_id, string $start_date, string $end_date ) {
        if ( ! self::user_can_use_plan( $user_id, $plan_id ) ) {
            return new \WP_Error( 'invalid_plan', __( 'You cannot edit that course plan.', 'wordpress-courses' ) );
        }

        $start_date = self::normalize_date( $start_date );
        $end_date   = self::normalize_date( $end_date );

        if ( '' === $start_date || '' === $end_date ) {
            return new \WP_Error( 'invalid_dates', __( 'Enter a valid start and end date.', 'wordpress-courses' ) );
        }

        if ( strtotime( $end_date ) < strtotime( $start_date ) ) {
            return new \WP_Error( 'invalid_date_order', __( 'End date must be on or after the start date.', 'wordpress-courses' ) );
        }

        update_post_meta( $plan_id, self::START_DATE_META_KEY, $start_date );
        update_post_meta( $plan_id, self::END_DATE_META_KEY, $end_date );

        return true;
    }

    public static function trash_plan( int $user_id, int $plan_id ): void {
        if ( self::user_can_use_plan( $user_id, $plan_id ) ) {
            wp_trash_post( $plan_id );
        }
    }

    public static function restore_plan( int $user_id, int $plan_id ): bool {
        $plan = get_post( $plan_id );

        if ( ! $plan || self::PLAN_POST_TYPE !== $plan->post_type || (int) $plan->post_author !== $user_id || 'trash' !== $plan->post_status ) {
            return false;
        }

        wp_untrash_post( $plan_id );
        wp_update_post(
            [
                'ID'          => $plan_id,
                'post_status' => 'publish',
            ]
        );

        return true;
    }

    public static function get_completed_lesson_ids( int $user_id, int $plan_id ): array {
        if ( ! self::user_can_use_plan( $user_id, $plan_id ) ) {
            return [];
        }

        $lesson_ids = get_post_meta( $plan_id, self::COMPLETED_LESSONS_META_KEY, true );

        if ( ! is_array( $lesson_ids ) ) {
            return [];
        }

        return array_values( array_unique( array_filter( array_map( 'absint', $lesson_ids ) ) ) );
    }

    public static function set_completed_lesson_ids( int $user_id, int $plan_id, array $lesson_ids ): void {
        $lesson_ids = array_values( array_unique( array_filter( array_map( 'absint', $lesson_ids ) ) ) );

        if ( ! self::user_can_use_plan( $user_id, $plan_id ) ) {
            return;
        }

        update_post_meta( $plan_id, self::COMPLETED_LESSONS_META_KEY, $lesson_ids );
    }

    public function ajax_save_progress(): void {
        check_ajax_referer( 'wordpress_courses_progress', 'nonce' );

        $user_id    = get_current_user_id();
        $plan_id    = isset( $_POST['plan_id'] ) ? absint( wp_unslash( $_POST['plan_id'] ) ) : 0;
        $lesson_ids = isset( $_POST['completed_lessons'] ) && is_array( $_POST['completed_lessons'] )
            ? array_map( 'absint', wp_unslash( $_POST['completed_lessons'] ) )
            : [];

        if ( $user_id <= 0 || ! self::user_can_use_plan( $user_id, $plan_id ) ) {
            wp_send_json_error( [ 'message' => __( 'You cannot edit that course plan.', 'wordpress-courses' ) ], 403 );
        }

        self::set_completed_lesson_ids( $user_id, $plan_id, $lesson_ids );

        $course_id      = self::get_plan_course_id( $user_id, $plan_id );
        $modules        = self::get_course_modules( $course_id );
        $lesson_count   = is_wp_error( $modules ) || ! is_array( $modules ) ? 0 : self::count_lessons( $modules );
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

            $completed_count = count( array_intersect( self::get_completed_lesson_ids( $user_id, $plan_id ), $course_lesson_ids ) );
        }

        $lesson_progress = $lesson_count > 0 ? (int) round( ( $completed_count / $lesson_count ) * 100 ) : 0;

        wp_send_json_success(
            [
                'completed_count' => $completed_count,
                'lesson_count'    => $lesson_count,
                'lesson_progress' => $lesson_progress,
                'summary'         => sprintf(
                    /* translators: 1: completed lesson count, 2: total lesson count */
                    __( '%1$d of %2$d', 'wordpress-courses' ),
                    $completed_count,
                    $lesson_count
                ),
                'percent_label'   => sprintf( __( '%d%% complete', 'wordpress-courses' ), $lesson_progress ),
            ]
        );
    }

    public static function get_time_progress_percent( string $start_date, string $end_date ): int {
        $start = strtotime( $start_date . ' 00:00:00' );
        $end   = strtotime( $end_date . ' 23:59:59' );

        if ( ! $start || ! $end || $end < $start ) {
            return 0;
        }

        $now = current_time( 'timestamp' );

        if ( $now <= $start ) {
            return 0;
        }

        if ( $now >= $end ) {
            return 100;
        }

        return (int) round( ( ( $now - $start ) / ( $end - $start ) ) * 100 );
    }

    private static function find_plan_id( int $user_id, int $course_id ): int {
        if ( $course_id <= 0 ) {
            return 0;
        }

        $plans = get_posts(
            [
                'post_type'      => self::PLAN_POST_TYPE,
                'post_status'    => 'publish',
                'author'         => $user_id,
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_key'       => self::COURSE_ID_META_KEY,
                'meta_value'     => $course_id,
            ]
        );

        return ! empty( $plans ) ? absint( $plans[0] ) : 0;
    }

    private static function set_default_plan_dates( int $plan_id ): void {
        $start_date = get_the_date( 'Y-m-d', $plan_id );

        if ( '' === $start_date ) {
            $start_date = wp_date( 'Y-m-d' );
        }

        update_post_meta( $plan_id, self::START_DATE_META_KEY, $start_date );
        update_post_meta( $plan_id, self::END_DATE_META_KEY, wp_date( 'Y-m-d', strtotime( $start_date . ' +30 days' ) ) );
    }

    private static function get_plan_dates_for_plan( int $plan_id ): array {
        return [
            'start_date' => self::normalize_date( (string) get_post_meta( $plan_id, self::START_DATE_META_KEY, true ) ),
            'end_date'   => self::normalize_date( (string) get_post_meta( $plan_id, self::END_DATE_META_KEY, true ) ),
        ];
    }

    private static function normalize_date( string $date ): string {
        $date = trim( $date );

        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return '';
        }

        $parts = array_map( 'absint', explode( '-', $date ) );

        if ( 3 !== count( $parts ) || ! checkdate( $parts[1], $parts[2], $parts[0] ) ) {
            return '';
        }

        return sprintf( '%04d-%02d-%02d', $parts[0], $parts[1], $parts[2] );
    }

    private static function user_can_use_plan( int $user_id, int $plan_id ): bool {
        $plan = get_post( $plan_id );

        if ( ! $plan || self::PLAN_POST_TYPE !== $plan->post_type || 'trash' === $plan->post_status ) {
            return false;
        }

        return (int) $plan->post_author === $user_id;
    }

    public static function get_courses( string $search = '' ) {
        $args = [
            'per_page' => 20,
            '_fields'  => 'id,link,title,excerpt,meta',
        ];

        if ( '' !== $search ) {
            $args['search']  = $search;
            $args['orderby'] = 'relevance';
        }

        $courses = self::learn_api_get( 'wp/v2/courses', $args, 'courses_' . md5( wp_json_encode( $args ) ), 10 * MINUTE_IN_SECONDS );

        if ( is_wp_error( $courses ) ) {
            return $courses;
        }

        return array_map( [ self::class, 'normalize_course' ], is_array( $courses ) ? $courses : [] );
    }

    public static function get_course( int $course_id ) {
        if ( $course_id <= 0 ) {
            return null;
        }

        $course = self::learn_api_get(
            'wp/v2/courses/' . $course_id,
            [
                '_fields' => 'id,link,title,excerpt,meta',
            ],
            'course_' . $course_id,
            HOUR_IN_SECONDS
        );

        if ( is_wp_error( $course ) ) {
            return $course;
        }

        return self::normalize_course( is_array( $course ) ? $course : [] );
    }

    public static function get_course_modules( int $course_id ) {
        if ( $course_id <= 0 ) {
            return [];
        }

        $modules = self::learn_api_get(
            'sensei-internal/v1/course-structure/' . $course_id,
            [],
            'course_structure_' . $course_id,
            HOUR_IN_SECONDS
        );

        if ( is_wp_error( $modules ) ) {
            return $modules;
        }

        if ( ! is_array( $modules ) ) {
            return [];
        }

        return array_values(
            array_map(
                function ( array $module ): array {
                    $lessons = isset( $module['lessons'] ) && is_array( $module['lessons'] ) ? $module['lessons'] : [];

                    return [
                        'id'          => absint( $module['id'] ?? 0 ),
                        'title'       => self::clean_text( $module['title'] ?? '' ),
                        'description' => self::clean_text( $module['description'] ?? '' ),
                        'lessons'     => array_values( array_map( [ self::class, 'normalize_structure_lesson' ], $lessons ) ),
                    ];
                },
                $modules
            )
        );
    }

    public static function count_lessons( array $modules ): int {
        return array_reduce(
            $modules,
            function ( int $count, array $module ): int {
                return $count + count( $module['lessons'] ?? [] );
            },
            0
        );
    }

    private static function learn_api_get( string $path, array $args = [], string $cache_key = '', int $ttl = 300 ) {
        $path = ltrim( $path, '/' );
        $url  = add_query_arg( $args, self::LEARN_API_BASE . $path );

        if ( '' !== $cache_key ) {
            $transient_key = 'wp_courses_' . $cache_key;
            $cached        = get_transient( $transient_key );

            if ( false !== $cached ) {
                return $cached;
            }
        }

        $response = wp_remote_get(
            $url,
            [
                'timeout' => 15,
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );

        if ( $status_code < 200 || $status_code >= 300 ) {
            return new \WP_Error(
                'learn_api_error',
                sprintf( 'Learn WordPress returned HTTP %d.', $status_code )
            );
        }

        $data = json_decode( $body, true );

        if ( JSON_ERROR_NONE !== json_last_error() ) {
            return new \WP_Error( 'learn_api_json_error', 'Learn WordPress returned invalid JSON.' );
        }

        if ( '' !== $cache_key ) {
            set_transient( $transient_key, $data, $ttl );
        }

        return $data;
    }

    private static function normalize_course( array $course ): array {
        $meta = isset( $course['meta'] ) && is_array( $course['meta'] ) ? $course['meta'] : [];

        return [
            'id'       => absint( $course['id'] ?? 0 ),
            'title'    => self::clean_text( $course['title']['rendered'] ?? '' ),
            'excerpt'  => self::clean_text( $course['excerpt']['rendered'] ?? '' ),
            'link'     => esc_url_raw( $course['link'] ?? '' ),
            'duration' => isset( $meta['_duration'] ) ? (float) $meta['_duration'] : 0.0,
        ];
    }

    private static function normalize_structure_lesson( array $lesson ): array {
        $lesson_id = absint( $lesson['id'] ?? 0 );

        return [
            'id'      => $lesson_id,
            'title'   => self::clean_text( $lesson['title'] ?? '' ),
            'preview' => ! empty( $lesson['preview'] ),
            'link'    => $lesson_id > 0 ? 'https://learn.wordpress.org/?p=' . $lesson_id : '',
        ];
    }

    private static function clean_text( $value ): string {
        $value = is_scalar( $value ) ? (string) $value : '';
        $value = wp_strip_all_tags( $value );
        $value = html_entity_decode( $value, ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' );

        return trim( preg_replace( '/\s+/', ' ', $value ) );
    }

    public function register_post_types(): void {
        register_post_type(
            self::PLAN_POST_TYPE,
            [
                'labels'       => [
                    'name'          => __( 'Course Plans', 'wordpress-courses' ),
                    'singular_name' => __( 'Course Plan', 'wordpress-courses' ),
                ],
                'public'       => false,
                'show_ui'      => true,
                'show_in_menu' => true,
                'show_in_rest' => true,
                'supports'     => [ 'title', 'author' ],
                'capability_type' => 'post',
            ]
        );
    }

    public function register_taxonomies(): void {
        /*
         * Register taxonomies here. This method runs on WordPress init.
         *
         * register_taxonomy( 'wordpress_courses_category', 'wordpress_courses_item', [
         *     'label'        => 'Wordpress Courses Categories',
         *     'hierarchical' => true,
         *     'show_ui'      => true,
         *     'show_in_rest' => true,
         * ] );
         */
    }

    public function register_dashboard_widgets(): void {
        /*
         * Register dashboard widgets here. This method runs on
         * wp_dashboard_setup.
         *
         * wp_add_dashboard_widget(
         *     'wordpress_courses_dashboard',
         *     'Wordpress Courses',
         *     [ $this, 'render_dashboard_widget' ]
         * );
         */
    }

    public function render_dashboard_widget(): void {
        /*
         * echo esc_html__( 'Add your dashboard summary here.', 'wordpress-courses' );
         */
    }

    public function register_ability_category(): void {
        // Register an Abilities API category for this plugin.
        //
        // if ( ! function_exists( 'wp_register_ability_category' ) ) {
        //     return;
        // }
        //
        // wp_register_ability_category( 'wordpress-courses', [
        //     'label'       => __( 'Wordpress Courses', 'wordpress-courses' ),
        //     'description' => __( 'Abilities for Wordpress Courses.', 'wordpress-courses' ),
        // ] );
    }

    public function register_abilities(): void {
        // Register focused WordPress Abilities here. AI Assistant can discover
        // and execute these instead of guessing plugin internals.
        // See https://github.com/akirk/ai-assistant/blob/main/docs/plugin-integration.md
        // for AI Assistant-specific guidance.
        //
        // if ( ! function_exists( 'wp_register_ability' ) ) {
        //     return;
        // }
        //
        // wp_register_ability( 'wordpress-courses/list-items', [
        //     'label'               => __( 'List Wordpress Courses Items', 'wordpress-courses' ),
        //     'description'         => 'Returns Wordpress Courses items with IDs and titles for follow-up ability calls.',
        //     'category'            => 'wordpress-courses',
        //     'input_schema'        => [
        //         'type'                 => 'object',
        //         'properties'           => [
        //             'search' => [
        //                 'type'        => 'string',
        //                 'description' => 'Optional search term for item titles.',
        //             ],
        //         ],
        //         'additionalProperties' => false,
        //     ],
        //     'output_schema'       => [
        //         'type'       => 'object',
        //         'properties' => [
        //             'items' => [
        //                 'type'  => 'array',
        //                 'items' => [
        //                     'type'       => 'object',
        //                     'properties' => [
        //                         'id'    => [ 'type' => 'integer', 'description' => 'Use with wordpress-courses/get-item.' ],
        //                         'title' => [ 'type' => 'string' ],
        //                     ],
        //                 ],
        //             ],
        //         ],
        //     ],
        //     'execute_callback'    => [ $this, 'list_ability_items' ],
        //     'permission_callback' => function() {
        //         return current_user_can( 'read' );
        //     },
        //     'meta'                => [
        //         'annotations' => [
        //             'instructions' => 'Use returned item IDs for follow-up detail or edit abilities.',
        //             'readonly'     => true,
        //             'destructive'  => false,
        //             'idempotent'   => true,
        //         ],
        //     ],
        // ] );
    }

    public function list_ability_items( $input ): array {
        // Sanitize ability input and return structured data. Return WP_Error
        // for failures.
        //
        // $input = is_array( $input ) ? $input : [];
        // $search = isset( $input['search'] ) ? sanitize_text_field( $input['search'] ) : '';
        //
        // return [
        //     'items' => [
        //         [
        //             'id'    => 123,
        //             'title' => __( 'Example item', 'wordpress-courses' ),
        //         ],
        //     ],
        // ];
        return [
            'items' => [],
        ];
    }

    public function register_ai_assistant_ability_domains( array $domains ): array {
        // Tell AI Assistant which user terms belong to this plugin so it
        // considers your abilities for domain-specific requests.
        //
        // $domains['wordpress-courses'] = 'Wordpress Courses, items, records, dashboard';
        return $domains;
    }

    public function get_ai_assistant_ability_instructions( string $instructions, string $ability_id, $args, $result ): string {
        // Add presentation or follow-up guidance after a specific ability runs.
        //
        // if ( 'wordpress-courses/list-items' === $ability_id && ! empty( $result['items'] ) ) {
        //     $instructions = 'Present the items as a compact table. Mention that item IDs can be used for follow-up changes.';
        // }
        return $instructions;
    }

    public function activate(): void {
        /*
         * If using BaseStorage, create/update custom tables here:
         *
         * $this->storage->create_tables();
         */
        $this->register_post_types();
        flush_rewrite_rules();
    }

    public function deactivate(): void {
        flush_rewrite_rules();
    }
}
