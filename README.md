# WordPress Courses

A WordPress app powered by [WpApp](https://github.com/akirk/wp-app), intended to become a personalized helper for students using [Learn WordPress](https://learn.wordpress.org/).

## Product direction

Students in the WordPress Credits program need more guidance and structure than the Learn WordPress site provides on its own. The first version should behave like a study guide layered over Learn WordPress content:

- Pick a course or learning path.
- Set a start date and target end date.
- Generate a dated plan from the course lessons.
- Maintain an integrated to-do list for each student.
- Track local completion and optionally sync with Learn WordPress when authenticated access is confirmed.

## Learn WordPress API notes

Learn WordPress exposes a public WordPress REST API at:

- `https://learn.wordpress.org/wp-json/`

Useful public content endpoints discovered on 2026-06-17:

- `wp/v2/courses`
- `wp/v2/lessons`
- `wp/v2/lesson-plan`
- `wp/v2/wporg_workshop`
- `wp/v2/course-category`
- `wp/v2/audience`
- `wp/v2/level`
- `wp/v2/topic`
- `wp/v2/learning-pathway`

Course and lesson payloads expose enough metadata for a local planning MVP:

- Courses include title, excerpt, permalink, taxonomy terms, `_duration`, start/expiration settings, and Sensei course metadata.
- Lessons include title, permalink, `_lesson_course`, `_lesson_length`, `_duration`, `_lesson_complexity`, quiz flags, preview flags, and video metadata.

The REST index advertises WordPress Application Passwords:

- Authorization URL: `https://learn.wordpress.org/wp-admin/authorize-application.php`
- WordPress REST API auth reference: `https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/`

Application Passwords use HTTPS Basic Auth for remote REST requests. That likely works for authenticated requests as a WordPress.org user, but the exact permissions available to normal students still need to be tested with a real account.

Potential authenticated integration points discovered in the REST route index:

- `wp/v2/users/me`
- `wp/v2/users/me/application-passwords`
- `wporg/v1/favorite`
- `sensei-internal/v1/course-structure/{course_id}`
- `sensei-internal/v1/course-progress/batch`
- `sensei-internal/v1/lesson-quiz/{lesson_id}`
- `sensei-pro-student-groups/v1/groups/{group_id}/courses`

Treat the `sensei-internal/*` and `sensei-pro-student-groups/*` routes as experimental until permission checks are verified. They may require elevated Learn/Sensei capabilities and should not block the student helper MVP.

## Recommended MVP

Build the first version as a local planning layer that reads public Learn content and stores personalization in this WordPress site.

Data model:

- Store synced Learn courses/lessons as cached records or transients.
- Store per-user plans in user meta or a private custom post type.
- Store per-user task completion locally first.
- Store optional Learn credentials encrypted or avoid storing them by requiring reconnect-on-demand.

Core workflow:

1. Student searches/selects a Learn course.
2. Student enters start date, end date, and weekly availability.
3. The plugin fetches course lessons and estimates workload from lesson length/duration metadata.
4. The plugin creates a dated checklist grouped by week.
5. Student marks items complete locally.
6. Later: add authenticated sync if Learn exposes student progress/favorites with normal account permissions.

## Auth decision

Do not make Learn authentication a prerequisite for the first useful version.

Use public REST reads for catalog, course, and lesson structure. Add an optional "Connect Learn WordPress" step only after validating these questions with a real WordPress.org account:

- Can a student create an Application Password on `learn.wordpress.org`?
- Does `wp/v2/users/me` work with that credential?
- Does `sensei-internal/v1/course-structure/{course_id}` return useful structure for enrolled courses?
- Does `sensei-internal/v1/course-progress/batch` expose student progress to the current user?
- Are write routes available to normal students, or only to Learn administrators/group managers?
