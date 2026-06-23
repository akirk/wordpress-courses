<?php

namespace WordpressCourses;

class LearnApi {
    private const LEARN_API_BASE = 'https://learn.wordpress.org/wp-json/';

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
}
