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

    public static function get_plan_notes( int $user_id, int $plan_id ): string {
        if ( ! self::user_can_use_plan( $user_id, $plan_id ) ) {
            return '';
        }

        return wp_kses_post( get_post_field( 'post_content', $plan_id, 'raw' ) );
    }

    public static function get_plan_notes_editor_text( int $user_id, int $plan_id ): string {
        return self::content_to_editor_text( self::get_plan_notes( $user_id, $plan_id ) );
    }

    public static function set_plan_notes( int $user_id, int $plan_id, string $notes ) {
        if ( ! self::user_can_use_plan( $user_id, $plan_id ) ) {
            return new \WP_Error( 'invalid_plan', __( 'You cannot edit that course plan.', 'learn-app' ) );
        }

        $notes_html = self::markdown_to_html( $notes );

        $result = wp_update_post(
            [
                'ID'           => $plan_id,
                'post_content' => wp_slash( wp_kses_post( $notes_html ) ),
            ],
            true
        );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

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

    private static function markdown_to_html( string $markdown ): string {
        $markdown = str_replace( "\r\n", "\n", trim( $markdown ) );

        if ( '' === $markdown ) {
            return '';
        }

        $lines   = explode( "\n", $markdown );
        $blocks  = [];
        $para    = [];
        $list    = [];
        $ordered = false;
        $quote   = [];
        $code    = [];
        $in_code = false;

        $flush_para = static function () use ( &$blocks, &$para ): void {
            if ( [] === $para ) {
                return;
            }

            $blocks[] = '<p>' . self::markdown_inline_to_html( implode( '<br>', $para ) ) . '</p>';
            $para     = [];
        };
        $flush_list = static function () use ( &$blocks, &$list, &$ordered ): void {
            if ( [] === $list ) {
                return;
            }

            $tag      = $ordered ? 'ol' : 'ul';
            $items    = array_map(
                static function ( string $item ): string {
                    return '<li>' . self::markdown_inline_to_html( $item ) . '</li>';
                },
                $list
            );
            $blocks[] = '<' . $tag . '>' . implode( '', $items ) . '</' . $tag . '>';
            $list     = [];
            $ordered  = false;
        };
        $flush_quote = static function () use ( &$blocks, &$quote ): void {
            if ( [] === $quote ) {
                return;
            }

            $blocks[] = '<blockquote><p>' . self::markdown_inline_to_html( implode( '<br>', $quote ) ) . '</p></blockquote>';
            $quote    = [];
        };

        foreach ( $lines as $line ) {
            if ( preg_match( '/^```/', $line ) ) {
                if ( $in_code ) {
                    $blocks[] = '<pre><code>' . esc_html( rtrim( implode( "\n", $code ), "\n" ) ) . '</code></pre>';
                    $code     = [];
                    $in_code  = false;
                } else {
                    $flush_para();
                    $flush_list();
                    $flush_quote();
                    $in_code = true;
                }
                continue;
            }

            if ( $in_code ) {
                $code[] = $line;
                continue;
            }

            if ( '' === trim( $line ) ) {
                $flush_para();
                $flush_list();
                $flush_quote();
                continue;
            }

            if ( preg_match( '/^\s*(#{1,6})\s+(.+)$/', $line, $heading ) ) {
                $flush_para();
                $flush_list();
                $flush_quote();
                $level    = strlen( $heading[1] );
                $blocks[] = '<h' . $level . '>' . self::markdown_inline_to_html( trim( $heading[2] ) ) . '</h' . $level . '>';
                continue;
            }

            if ( preg_match( '/^\s*---+\s*$/', $line ) ) {
                $flush_para();
                $flush_list();
                $flush_quote();
                $blocks[] = '<hr>';
                continue;
            }

            if ( preg_match( '/^\s*[-*]\s+(.+)$/', $line, $item ) ) {
                $flush_para();
                $flush_quote();
                if ( $ordered ) {
                    $flush_list();
                }
                $list[]  = trim( $item[1] );
                $ordered = false;
                continue;
            }

            if ( preg_match( '/^\s*\d+\.\s+(.+)$/', $line, $item ) ) {
                $flush_para();
                $flush_quote();
                if ( [] !== $list && ! $ordered ) {
                    $flush_list();
                }
                $list[]  = trim( $item[1] );
                $ordered = true;
                continue;
            }

            if ( preg_match( '/^\s*>\s?(.*)$/', $line, $quoted ) ) {
                $flush_para();
                $flush_list();
                $quote[] = $quoted[1];
                continue;
            }

            $flush_list();
            $flush_quote();
            $para[] = trim( $line );
        }

        if ( $in_code ) {
            $blocks[] = '<pre><code>' . esc_html( rtrim( implode( "\n", $code ), "\n" ) ) . '</code></pre>';
        }
        $flush_para();
        $flush_list();
        $flush_quote();

        return implode( "\n\n", $blocks );
    }

    private static function markdown_inline_to_html( string $text ): string {
        $text = esc_html( $text );
        $text = preg_replace_callback(
            '/`([^`]+)`/',
            static function ( array $match ): string {
                return '<code>' . $match[1] . '</code>';
            },
            $text
        );
        $text = preg_replace_callback(
            '/\[(.*?)\]\((https?:\/\/[^)\s]+|mailto:[^)\s]+|ftp:\/\/[^)\s]+|\/[^)\s]*|#[^)\s]*|\?[^)\s]*|\.[^)\s]*)\)/',
            static function ( array $match ): string {
                $label = trim( wp_strip_all_tags( html_entity_decode( $match[1], ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' ) ) );
                $url   = esc_url( html_entity_decode( $match[2], ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' ) );

                if ( '' === $label || '' === $url ) {
                    return $match[0];
                }

                return '<a href="' . $url . '">' . esc_html( $label ) . '</a>';
            },
            $text
        );
        $text = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text );
        $text = preg_replace( '/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $text );
        $text = preg_replace( '/__([^_]+)__/', '<strong>$1</strong>', $text );
        $text = preg_replace( '/(?<!_)_([^_]+)_(?!_)/', '<em>$1</em>', $text );

        return $text;
    }

    private static function content_to_editor_text( string $content ): string {
        $markdown = self::html_to_editor_markdown( $content );

        if ( '' !== $markdown ) {
            return $markdown;
        }

        $content = preg_replace( '/<!--\s*\/?wp:[^>]*-->/', '', $content );
        $content = preg_replace( '/<br\s*\/?>/i', "\n", $content );
        $content = preg_replace( '/<\/(p|div|li|h[1-6])\s*>/i', "\n\n", $content );
        $content = preg_replace( '/<hr\b[^>]*>/i', "\n\n---\n\n", $content );
        $text    = wp_strip_all_tags( $content, true );
        $text    = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
        $text    = preg_replace( "/\n{3,}/", "\n\n", $text );

        return trim( $text );
    }

    private static function html_to_editor_markdown( string $content ): string {
        $content = preg_replace( '/<!--\s*\/?wp:[^>]*-->/', '', $content );
        $content = trim( (string) $content );

        if ( '' === $content ) {
            return '';
        }

        $blocks  = [];
        $offset  = 0;
        $pattern = '/<(h[1-6]|p|ul|ol|blockquote|pre)\b([^>]*)>(.*?)<\/\1>|<hr\b[^>]*>/is';

        if ( preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
            foreach ( $matches as $match ) {
                $raw_match = $match[0][0];
                $position  = $match[0][1];
                self::append_editor_html_fragment( $blocks, substr( $content, $offset, $position - $offset ) );
                $offset = $position + strlen( $raw_match );

                $tag = isset( $match[1][0] ) ? strtolower( $match[1][0] ) : '';
                if ( '' === $tag && 0 === stripos( $raw_match, '<hr' ) ) {
                    $blocks[] = '---';
                    continue;
                }

                $inner = $match[3][0] ?? '';
                if ( preg_match( '/^h([1-6])$/', $tag, $heading ) ) {
                    $text = self::html_inline_to_markdown( $inner );
                    if ( '' !== $text ) {
                        $blocks[] = str_repeat( '#', (int) $heading[1] ) . ' ' . $text;
                    }
                    continue;
                }

                if ( 'p' === $tag ) {
                    $text = self::html_inline_to_markdown( $inner );
                    if ( '' !== $text ) {
                        $blocks[] = $text;
                    }
                    continue;
                }

                if ( 'ul' === $tag || 'ol' === $tag ) {
                    $items = [];
                    if ( preg_match_all( '/<li\b[^>]*>(.*?)<\/li>/is', $inner, $li_matches ) ) {
                        foreach ( $li_matches[1] as $index => $li ) {
                            $text = self::html_inline_to_markdown( $li );
                            if ( '' !== $text ) {
                                $items[] = ( 'ol' === $tag ? ( $index + 1 ) . '. ' : '- ' ) . $text;
                            }
                        }
                    }
                    if ( [] !== $items ) {
                        $blocks[] = implode( "\n", $items );
                    }
                    continue;
                }

                if ( 'blockquote' === $tag ) {
                    $text = self::html_inline_to_markdown( $inner );
                    if ( '' !== $text ) {
                        $blocks[] = '> ' . str_replace( "\n", "\n> ", $text );
                    }
                    continue;
                }

                if ( 'pre' === $tag ) {
                    $code     = html_entity_decode( wp_strip_all_tags( $inner ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
                    $blocks[] = "```\n" . trim( $code, "\n" ) . "\n```";
                }
            }
        }

        self::append_editor_html_fragment( $blocks, substr( $content, $offset ) );
        $markdown = trim( preg_replace( "/\n{3,}/", "\n\n", implode( "\n\n", $blocks ) ) );

        return preg_replace( '/^([ \t]*)[\x{2013}\x{2014}][ \t]+/mu', '$1- ', $markdown );
    }

    private static function append_editor_html_fragment( array &$blocks, string $html ): void {
        $html = trim( $html );

        if ( '' !== $html ) {
            $blocks[] = self::html_inline_to_markdown( $html );
        }
    }

    private static function html_inline_to_markdown( string $html ): string {
        $html = preg_replace( '/<br\s*\/?>/i', "\n", $html );
        $html = preg_replace( '/<code\b[^>]*>(.*?)<\/code>/is', '`$1`', $html );
        $html = preg_replace( '/<(strong|b)\b[^>]*>(.*?)<\/\1>/is', '**$2**', $html );
        $html = preg_replace( '/<(em|i)\b[^>]*>(.*?)<\/\1>/is', '*$2*', $html );
        $html = preg_replace_callback(
            '/<a\b([^>]*)>(.*?)<\/a>/is',
            static function ( array $match ): string {
                if ( ! preg_match( '/\bhref\s*=\s*(["\'])(.*?)\1/i', $match[1], $href ) ) {
                    return wp_strip_all_tags( $match[2] );
                }

                return '[' . wp_strip_all_tags( $match[2] ) . '](' . $href[2] . ')';
            },
            $html
        );
        $text = wp_strip_all_tags( $html, true );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
        $text = preg_replace( "/[ \t]+\n/", "\n", $text );

        return trim( $text );
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
