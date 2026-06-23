<?php

namespace WordpressCourses;

class CoursePlans {
    private const PLAN_POST_TYPE = 'wp_course_plan';
    private const COURSE_ID_META_KEY = '_wordpress_courses_course_id';
    private const COMPLETED_LESSONS_META_KEY = '_wordpress_courses_completed_lesson_ids';
    private const START_DATE_META_KEY = '_wordpress_courses_start_date';
    private const END_DATE_META_KEY = '_wordpress_courses_end_date';

    public static function register_post_type(): void {
        register_post_type(
            self::PLAN_POST_TYPE,
            [
                'labels'          => [
                    'name'          => __( 'Course Plans', 'learn-app' ),
                    'singular_name' => __( 'Course Plan', 'learn-app' ),
                ],
                'public'          => false,
                'show_ui'         => true,
                'show_in_menu'    => true,
                'show_in_rest'    => true,
                'supports'        => [ 'title', 'author' ],
                'capability_type' => 'post',
            ]
        );
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
                        'id'         => $plan->ID,
                        'course_id'  => absint( get_post_meta( $plan->ID, self::COURSE_ID_META_KEY, true ) ),
                        'title'      => get_the_title( $plan ),
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

        $course = LearnApi::get_course( $course_id );
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
            return new \WP_Error( 'invalid_plan', __( 'You cannot edit that course plan.', 'learn-app' ) );
        }

        $start_date = self::normalize_date( $start_date );
        $end_date   = self::normalize_date( $end_date );

        if ( '' === $start_date || '' === $end_date ) {
            return new \WP_Error( 'invalid_dates', __( 'Enter a valid start and end date.', 'learn-app' ) );
        }

        if ( strtotime( $end_date ) < strtotime( $start_date ) ) {
            return new \WP_Error( 'invalid_date_order', __( 'End date must be on or after the start date.', 'learn-app' ) );
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

    public static function user_can_use_plan( int $user_id, int $plan_id ): bool {
        $plan = get_post( $plan_id );

        if ( ! $plan || self::PLAN_POST_TYPE !== $plan->post_type || 'trash' === $plan->post_status ) {
            return false;
        }

        return (int) $plan->post_author === $user_id;
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
}
