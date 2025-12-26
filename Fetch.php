<?php
// Fetch.php - Final v5.3+ - poster enhancement: set poster for existing posts too

declare(strict_types=1);
define('WP_USE_THEMES', false);

$URI = explode('wp-content', $_SERVER['SCRIPT_FILENAME'] ?? '');
require_once(($URI[0] ?? '') . 'wp-load.php');
require_once(ABSPATH . 'wp-admin/includes/post.php');
require_once(ABSPATH . 'wp-admin/includes/image.php');
require_once(ABSPATH . 'wp-admin/includes/media.php');
require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/taxonomy.php');

// ============================================================================
// TITLE CHECK SYSTEM - Fast file-based duplicate detection
// ============================================================================

/**
 * Get the path to today's title check file
 * Creates: Checks/YYYY-MM-DD/titles.txt
 */
function get_title_check_file_path() {
    $plugin_dir = dirname(__FILE__);
    $checks_dir = $plugin_dir . '/Checks';
    $date_folder = date('Y-m-d');
    $date_dir = $checks_dir . '/' . $date_folder;
    $file_path = $date_dir . '/titles.txt';

    // Create directories if needed
    if (!file_exists($checks_dir)) {
        wp_mkdir_p($checks_dir);
        @file_put_contents($checks_dir . '/index.php', '<?php // Silence is golden');
    }
    if (!file_exists($date_dir)) {
        wp_mkdir_p($date_dir);
        @file_put_contents($date_dir . '/index.php', '<?php // Silence is golden');
    }

    // Create file if needed
    if (!file_exists($file_path)) {
        @file_put_contents($file_path, '');
        @chmod($file_path, 0644);
    }

    return $file_path;
}

/**
 * Check if title exists in today's check file
 */
function title_exists_in_file($title) {
    if (empty(trim((string)$title))) {
        return false;
    }

    $file_path = get_title_check_file_path();
    if (!file_exists($file_path) || !is_readable($file_path)) {
        return false;
    }

    $content = @file_get_contents($file_path);
    if ($content === false || empty(trim($content))) {
        return false;
    }

    $title_normalized = trim((string)$title);
    $lines = explode("\n", $content);

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        // Exact match
        if ($line === $title_normalized) {
            return true;
        }

        // Case-insensitive match
        if (mb_strtolower($line, 'UTF-8') === mb_strtolower($title_normalized, 'UTF-8')) {
            return true;
        }
    }

    return false;
}

/**
 * Add title to today's check file
 */
function add_title_to_file($title) {
    if (empty(trim((string)$title))) {
        return false;
    }

    // Check if already exists
    if (title_exists_in_file($title)) {
        return true;
    }

    $file_path = get_title_check_file_path();
    $entry = trim((string)$title) . "\n";
    $result = @file_put_contents($file_path, $entry, FILE_APPEND | LOCK_EX);

    return $result !== false;
}

// ============================================================================
// POSTER CHECK SYSTEM - Avoid duplicate poster downloads
// ============================================================================

/**
 * Get the path to posters check file
 * Creates: Checks/posters.txt
 */
function get_posters_file_path() {
    $plugin_dir = dirname(__FILE__);
    $checks_dir = $plugin_dir . '/Checks';
    $file_path = $checks_dir . '/posters.txt';

    // Create Checks directory if needed
    if (!file_exists($checks_dir)) {
        wp_mkdir_p($checks_dir);
        @file_put_contents($checks_dir . '/index.php', '<?php // Silence is golden');
    }

    // Create file if needed
    if (!file_exists($file_path)) {
        @file_put_contents($file_path, '');
        @chmod($file_path, 0644);
    }

    return $file_path;
}

/**
 * Get poster ID for a series/season from file
 * Format: series_name|season_name|poster_id
 */
function get_series_poster_id($series_name, $season_name) {
    if (empty($series_name) || empty($season_name)) {
        return false;
    }

    $file_path = get_posters_file_path();
    if (!file_exists($file_path) || !is_readable($file_path)) {
        return false;
    }

    $content = @file_get_contents($file_path);
    if ($content === false || empty(trim($content))) {
        return false;
    }

    $series_normalized = trim((string)$series_name);
    $season_normalized = trim((string)$season_name);
    $lines = explode("\n", $content);

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        $parts = explode('|', $line);
        if (count($parts) >= 3) {
            $file_series = trim($parts[0]);
            $file_season = trim($parts[1]);
            $poster_id = (int)trim($parts[2]);

            if ($file_series === $series_normalized && $file_season === $season_normalized) {
                // Verify poster still exists
                $attachment = get_post($poster_id);
                if ($attachment && $attachment->post_type === 'attachment' &&
                    strpos((string)$attachment->post_mime_type, 'image/') === 0) {
                    return $poster_id;
                }
            }
        }
    }

    return false;
}

/**
 * Save poster ID for a series/season
 */
function save_series_poster_id($series_name, $season_name, $poster_id) {
    if (empty($series_name) || empty($season_name) || empty($poster_id)) {
        return false;
    }

    // Check if already saved
    if (get_series_poster_id($series_name, $season_name)) {
        return true;
    }

    $file_path = get_posters_file_path();
    $entry = trim((string)$series_name) . '|' . trim((string)$season_name) . '|' . (int)$poster_id . "\n";
    $result = @file_put_contents($file_path, $entry, FILE_APPEND | LOCK_EX);

    return $result !== false;
}

/**
 * Normalize poster URL for comparisons.
 */
function normalize_poster_url($poster_url) {
    $poster_url = trim((string)$poster_url);
    if ($poster_url === '' || !filter_var($poster_url, FILTER_VALIDATE_URL)) {
        return '';
    }
    $normalized = preg_replace('/[?#].*$/', '', $poster_url);
    $normalized = rtrim((string)$normalized, '/');
    return (string)$normalized;
}

/**
 * Find existing poster by URL in WordPress database
 */
function find_poster_by_url($poster_url) {
    $normalized_url = normalize_poster_url($poster_url);
    if ($normalized_url === '') {
        return false;
    }

    // Fast path: WordPress native resolver
    $id = attachment_url_to_postid($normalized_url);
    if ($id) {
        $attachment = get_post($id);
        if ($attachment && $attachment->post_type === 'attachment' &&
            strpos((string)$attachment->post_mime_type, 'image/') === 0) {
            return (int)$id;
        }
    }

    global $wpdb;

    // Search by source URL meta (if we saved it before)
    $attachment_id = $wpdb->get_var($wpdb->prepare(
        "SELECT pm.post_id FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        WHERE pm.meta_key = '_poster_source_url'
        AND pm.meta_value = %s
        AND p.post_type = 'attachment'
        AND p.post_mime_type LIKE 'image/%%'
        ORDER BY p.ID DESC
        LIMIT 1",
        $normalized_url
    ));

    if ($attachment_id) {
        return (int)$attachment_id;
    }

    // Fallback: Search by filename in _wp_attached_file
    $url_path = parse_url($normalized_url, PHP_URL_PATH);
    $filename = basename((string)$url_path);

    if ($filename !== '') {
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = '_wp_attached_file'
            AND meta_value LIKE %s
            LIMIT 1",
            '%' . $wpdb->esc_like($filename) . '%'
        ));

        if ($attachment_id) {
            $attachment = get_post($attachment_id);
            if ($attachment && $attachment->post_type === 'attachment' &&
                strpos((string)$attachment->post_mime_type, 'image/') === 0) {
                return (int)$attachment_id;
            }
        }
    }

    return false;
}

/**
 * Find poster by filename (strong fallback when URL varies).
 */
function find_poster_by_filename($poster_url) {
    $normalized_url = normalize_poster_url($poster_url);
    if ($normalized_url === '') {
        return false;
    }

    $path = parse_url($normalized_url, PHP_URL_PATH);
    $filename = basename((string)$path);
    if ($filename === '') {
        return false;
    }

    global $wpdb;
    $attachment_id = $wpdb->get_var($wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
         WHERE p.post_type = 'attachment'
           AND p.post_mime_type LIKE 'image/%%'
           AND pm.meta_key = '_wp_attached_file'
           AND pm.meta_value LIKE %s
         ORDER BY p.ID DESC
         LIMIT 1",
        '%' . $wpdb->esc_like($filename) . '%'
    ));

    return $attachment_id ? (int)$attachment_id : false;
}

/**
 * Ensure a post has a featured image, using existing posters if available.
 * Returns attachment ID on success, false otherwise.
 */
function ensure_post_has_poster($post_id, $poster_url, $title, $series_name = '', $season_name = '') {
    $post_id = (int)$post_id;
    if ($post_id <= 0) return false;

    // If already has thumbnail, keep it
    if (has_post_thumbnail($post_id)) {
        $thumb_id = get_post_thumbnail_id($post_id);
        return $thumb_id ? (int)$thumb_id : true;
    }

    $poster_url = trim((string)$poster_url);
    if ($poster_url === '' || !filter_var($poster_url, FILTER_VALIDATE_URL)) {
        return false;
    }
    $poster_url = str_replace(' ', '%20', $poster_url);
    $normalized_url = normalize_poster_url($poster_url);

    $poster_id = false;

    // 1) Series/season mapping (fastest for episodes)
    if ($series_name !== '' && $season_name !== '') {
        $poster_id = get_series_poster_id($series_name, $season_name);
    }

    // 2) URL-based lookup (meta, attachment_url_to_postid, filename)
    if (!$poster_id) {
        $poster_id = find_poster_by_url($poster_url);
    }
    if (!$poster_id) {
        $poster_id = find_poster_by_filename($poster_url);
    }

    // 3) If found, set it
    if ($poster_id) {
        set_post_thumbnail($post_id, (int)$poster_id);
        if ($series_name !== '' && $season_name !== '') {
            save_series_poster_id($series_name, $season_name, (int)$poster_id);
        }
        return (int)$poster_id;
    }

    // 4) As last resort, sideload
    $attach_id = media_sideload_image($poster_url, $post_id, (string)$title, 'id');
    if (!is_wp_error($attach_id) && $attach_id) {
        set_post_thumbnail($post_id, (int)$attach_id);
        if ($normalized_url !== '') {
            update_post_meta((int)$attach_id, '_poster_source_url', $normalized_url);
        }
        if ($series_name !== '' && $season_name !== '') {
            save_series_poster_id($series_name, $season_name, (int)$attach_id);
        }
        return (int)$attach_id;
    }

    return false;
}

// ============================================================================
// DOWNLOAD LINK ENCODING SYSTEM - Encode links for download.php page
// ============================================================================

/**
 * Encode download link for download.php page
 * Process: base64_encode -> replace digits with X{digit}{digit}X -> urlencode
 * This matches the decoding process in download.php:
 * - urldecode -> replace X{digit}{digit}X with digit -> base64_decode
 */
function encode_download_link($download_url) {
    if (empty($download_url) || !filter_var($download_url, FILTER_VALIDATE_URL)) {
        return $download_url; // Return original if invalid
    }

    // Step 1: Base64 encode the URL
    $base64_encoded = base64_encode($download_url);

    // Step 2: Replace each digit (0-9) with X{digit}{digit}X
    $encoded_with_digits = $base64_encoded;
    for ($d = 0; $d <= 9; $d++) {
        $encoded_with_digits = str_replace((string)$d, "X{$d}{$d}X", $encoded_with_digits);
    }

    // Step 3: URL encode the final string
    $final_encoded = urlencode($encoded_with_digits);

    // Return the encoded link that points to download.php
    return 'https://downl.divhard.com/download.php?download=' . $final_encoded;
}

// ============================================================================

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    wp_send_json_error(['message' => 'Invalid request']);
    exit;
}

$value = trim((string)($_POST['valueText'] ?? ''));
if ($value === '' || !filter_var($value, FILTER_VALIDATE_URL)) {
    wp_send_json_error(['message' => 'Invalid URL']);
    exit;
}

// ---------- API Fetch
$api_url = 'https://divtheme.com/ftc/1/3/api.php?slug=' . urlencode($value);
$response = wp_remote_get($api_url, ['timeout' => 60, 'headers' => ['User-Agent' => 'DivHard Scraper v1.4.3']]);
if (is_wp_error($response)) {
    wp_send_json_error(['message' => 'API Error: ' . $response->get_error_message()]);
    exit;
}
$body = wp_remote_retrieve_body($response);
$data = json_decode($body, false);
if (!$data || empty($data->title)) {
    wp_send_json_error(['message' => 'Invalid API Response', 'body' => substr((string)$body, 0, 200)]);
    exit;
}

// ---------- Check if content is a series (not a movie)
function is_series_content($title, $selary = null, $current_season = null) {
    $title_lower = mb_strtolower((string)$title, 'UTF-8');

    // If title contains "فيلم", it's definitely a movie (check first)
    if (mb_strpos($title_lower, 'فيلم') !== false) {
        return false;
    }

    // If explicitly has series/season data, it's a series
    if (!empty($selary) || !empty($current_season)) {
        return true;
    }

    // Check title for series keywords
    $series_keywords = ['حلقة', 'حلقه', 'موسم', 'الموسم', 'انمي', 'برنامج'];

    foreach ($series_keywords as $keyword) {
        if (mb_strpos($title_lower, mb_strtolower($keyword, 'UTF-8')) !== false) {
            return true;
        }
    }

    // Default: not a series (it's a movie)
    return false;
}

// ---------- Title cleanup
$raw_title = (string)($data->title ?? '');
$title = trim((string)preg_replace('/\b(مشاهدة|تحميل|مترجمة|مترجم|اون لاين|كامل|الحلقة\s*\d+.*)\b/i', '', $raw_title));
$title = preg_replace('/\s+/', ' ', (string)$title);
$title = trim((string)$title);
if ($title === '') {
    // fallback to raw title if cleaning removed everything
    $title = sanitize_text_field($raw_title);
}

// ---------- Check if content is a series (use raw_title to check for keywords)
// Handle both string and array formats for selary and current_season
$selary_value = is_array($data->selary ?? null) ? ($data->selary[0] ?? null) : ($data->selary ?? null);
$season_value = is_array($data->current_season ?? null) ? ($data->current_season[0] ?? null) : ($data->current_season ?? null);
$is_series = is_series_content($raw_title, $selary_value, $season_value);

// ---------- Determine series and season info (for poster check) - Only for series
$series_name = '';
$season_name = '';
if ($is_series && (!empty($selary_value) || !empty($season_value))) {
    if (!empty($selary_value)) {
        $series_name = trim((string)$selary_value);
    }
    $series_name = $series_name ?: $title;
    $series_name = preg_replace('/\s*[-–]?\s*الحلقة.*$/i', '', (string)$series_name);
    $series_name = trim((string)strip_tags((string)$series_name));

    $season_name = 'الموسم الأول';
    if (!empty($season_value)) {
        $season_name = preg_replace('/^(انمي\s+|كامل\s*-\s*)/i', '', (string)$season_value);
        $season_name = trim((string)$season_name) ?: 'الموسم الأول';
    }
}

// Poster URL (for existing post update too)
$poster_url = '';
if (!empty($data->poster)) {
    $poster_url = is_array($data->poster) ? (string)($data->poster[0] ?? '') : (string)$data->poster;
    $poster_url = trim((string)$poster_url);
}

// ---------- Title check (fast file check first, then WordPress database)
// Enhancement: if post exists but has no poster, set it before returning "exists".
$existing_post_id = 0;

if (title_exists_in_file($title)) {
    $existing_post_id = (int)(post_exists($title) ?: 0);
    if ($existing_post_id <= 0) {
        $p = get_page_by_title($title, OBJECT, 'post');
        if ($p && isset($p->ID)) {
            $existing_post_id = (int)$p->ID;
        }
    }

    if ($existing_post_id > 0 && $poster_url !== '') {
        $poster_set_id = ensure_post_has_poster($existing_post_id, $poster_url, $title, $series_name, $season_name);
        wp_send_json_success([
            'status' => 'exists',
            'message' => 'الموضوع موجود مسبقًا',
            'post_id' => $existing_post_id,
            'poster_updated' => (bool)$poster_set_id,
            'poster_id' => $poster_set_id ? (int)$poster_set_id : 0,
        ]);
        exit;
    }

    wp_send_json_success(['status' => 'exists', 'message' => 'الموضوع موجود مسبقًا']);
    exit;
}

$existing_post_id = (int)(post_exists($title) ?: 0);
if ($existing_post_id > 0) {
    add_title_to_file($title); // Save to file for future checks

    $poster_set_id = false;
    if ($poster_url !== '') {
        $poster_set_id = ensure_post_has_poster($existing_post_id, $poster_url, $title, $series_name, $season_name);
    }

    wp_send_json_success([
        'status' => 'exists',
        'message' => 'الموضوع موجود مسبقًا',
        'post_id' => $existing_post_id,
        'poster_updated' => (bool)$poster_set_id,
        'poster_id' => $poster_set_id ? (int)$poster_set_id : 0,
    ]);
    exit;
}

// ---------- Story (raw)
$story = wp_kses_post($data->story ?? '');

// ---------- Intro / Outro templates (6 each)
$intro_templates = [
    "استمتع الآن بمشاهدة «{{title}}» ({{year}}) — هنا هتلاقي أفضل طرق المشاهدة والتحميل ليه، كل التفاصيل موجودة عشان تجربتك تكون كاملة.",
    "هل أنت مستعد لمشاهدة {{title}} إنتاج {{year}}؟ الصفحة دي بتجمع روابط المشاهدة والتحميل كاملة وبجودة مناسبة لكل الأجهزة.",
    "لو بتدور على تحميل أو مشاهدة «{{title}}» كامل {{year}}، هتلاقي هنا خيارات متعددة وسيرفرات سريعة تضمن لك تجربة ثابتة.",
    "فيلم «{{title}}» ({{year}}) يقدم تجربة مميزة — تقدر تشاهده مباشرة أو تحمله كامل من الروابط المتاحة هنا.",
    "في هذه الصفحة جمعنالك كل ما يخص {{title}} نسخة {{year}}: معلومات، روابط مشاهدة، وروابط تحميل كاملة بدون تعقيد.",
    "مشاهدة وتحميل «{{title}}» {{year}} دلوقتي أسهل — اختار السيرفر المفضل واستمتع بالفيلم كامل وبجودة ممتازة."
];

$outro_templates = [
    "دلوقتي وبعد ما قريت شوية عن {{title}} ({{year}})، اختار طريقة المشاهدة أو التحميل اللي تريحك واستمتع بالمشاهدة كاملة.",
    "صفحتنا بتقدملك روابط مشاهدة وتحميل «{{title}}» كامل {{year}}، اختار الجودة والسيرفر وابدأ فورًا.",
    "كل روابط {{title}} {{year}} متوفرة هنا سواء للمشاهدة أو التحميل — تجربة مشاهدة كاملة وسهلة بضغطة واحدة.",
    "لو حبيت تحتفظ بالفيلم، روابط التحميل كاملة ومتاحة بجودات متعددة. مشاهدة ممتعة لـ «{{title}}» ({{year}}).",
    "اختصرتلك كل الروابط المهمة لـ {{title}} نسخة {{year}} — تحميل أو مشاهدة كما تحب، بدون تعقيد.",
    "جرب مشاهدة {{title}} الآن أو حمله كامل بجودة مناسبة، كل الخيارات موجودة لتجربة مشاهدة بدون تقطيع."
];

// Choose random intro/outro
$intro_template = $intro_templates[array_rand($intro_templates)];
$outro_template = $outro_templates[array_rand($outro_templates)];

// Replace placeholders
// Handle release_year as array or string
$release_year_value = $data->release_year ?? '';
if (is_array($release_year_value) && !empty($release_year_value)) {
    $year = (string)$release_year_value[0];
} else {
    $year = (string)$release_year_value;
}
$year = preg_replace('/[^\d\-]/', '', (string)$year); // keep numbers and dash
$intro = str_replace(['{{title}}','{{year}}'], [esc_html($title), esc_html($year)], $intro_template);
$outro = str_replace(['{{title}}','{{year}}'], [esc_html($title), esc_html($year)], $outro_template);

// Ensure keywords presence (مشاهدة, تحميل, كامل) appear naturally
$keywords_sentence = "للبحث عن مشاهدة أو تحميل {{title}} كامل، استخدم روابط الصفحة.";
$keywords_sentence = str_replace('{{title}}', esc_html($title), $keywords_sentence);

// Build final post content (intro + story + outro)
if (empty(trim(strip_tags((string)$story)))) {
    $story = "وصف الفيلم غير متوفر حالياً، لكن تقدر تشاهد أو تحمل «{$title}» باستخدام الروابط الموجودة أسفل.";
}
$post_content = wp_kses_post($intro . "\n\n" . $story . "\n\n" . $outro . "\n\n" . $keywords_sentence);

// ---------- Insert post
$post_args = [
    'post_title'   => wp_strip_all_tags($title),
    'post_content' => $post_content,
    'post_status'  => 'publish',
    'post_type'    => 'post',
    'post_author'  => 1,
];

$post_id = wp_insert_post($post_args, true);
if (is_wp_error($post_id) || empty($post_id) || $post_id === 0) {
    // Try a safer insert (minimal) to avoid fatal stop
    $post_args_min = [
        'post_title'   => wp_strip_all_tags(substr($title, 0, 200)),
        'post_content' => wp_strip_all_tags(substr(strip_tags((string)$story), 0, 1000)),
        'post_status'  => 'publish',
        'post_type'    => 'post',
        'post_author'  => 1,
    ];
    $post_id = wp_insert_post($post_args_min, true);
    if (is_wp_error($post_id) || empty($post_id) || $post_id === 0) {
        $error_msg = is_wp_error($post_id) ? $post_id->get_error_message() : 'Post creation failed';
        wp_send_json_error(['message' => 'Post creation failed: ' . $error_msg]);
        exit;
    }
}
$post_id = (int)$post_id;

// ---------- Featured image (poster) - Enhanced
if ($poster_url !== '') {
    ensure_post_has_poster($post_id, $poster_url, $title, $series_name, $season_name);
}

// ---------- Metaboxes
// Handle watch servers (array)
$watch_servers = is_array($data->watch ?? null) ? $data->watch : [];
if (!is_array($watch_servers) && !empty($data->watch)) {
    $watch_servers = [$data->watch];
}
update_post_meta($post_id, 'watch_servers_list', implode("\n", array_unique(array_filter($watch_servers))));

// Encode all download links before saving
$download_links = is_array($data->download ?? null) ? $data->download : [];
if (!is_array($download_links) && !empty($data->download)) {
    $download_links = [$data->download];
}
$encoded_download_links = [];
foreach ($download_links as $link) {
    $link = trim((string)$link);
    if ($link !== '') {
        $encoded_download_links[] = encode_download_link($link);
    }
}
update_post_meta($post_id, 'download_links_list', implode("\n", array_unique($encoded_download_links)));
update_post_meta($post_id, 'story', $data->story ?? '');
update_post_meta($post_id, 'trailer', $data->trailer ?? '');

// Use number_en2 instead of number_en (matching ads-api.php output)
update_post_meta($post_id, 'number', $data->number_en2 ?? ($data->number_en ?? ''));
update_post_meta($post_id, 'imdbRating', $data->rate ?? '');
update_post_meta($post_id, 'released', $data->add_date ?? '');
update_post_meta($post_id, 'runtime', $data->time_now ?? '');

// ---------- Ribbon (quality)
$ribbon_text = 'جديد';
if (!empty($data->quality)) {
    if (is_array($data->quality) && !empty($data->quality)) {
        $quality_text = implode(' • ', array_map('trim', array_filter($data->quality)));
        $ribbon_text = $quality_text ?: $ribbon_text;
    } else {
        $ribbon_text = trim((string)$data->quality) ?: $ribbon_text;
    }
}
update_post_meta($post_id, 'ribbon', $ribbon_text);

// ---------- Main categories (prevent duplicates)
$main_category_names = $data->category ?? [];
$category_ids = [];
if (!empty($main_category_names)) {
    // Ensure it's an array
    if (!is_array($main_category_names)) {
        $main_category_names = [$main_category_names];
    }
    foreach ($main_category_names as $cat_name) {
        $cat_name = trim((string)$cat_name);
        if ($cat_name === '') continue;
        $term = get_term_by('name', $cat_name, 'category');
        if (!$term) {
            $inserted = wp_insert_term($cat_name, 'category');
            if (!is_wp_error($inserted) && isset($inserted['term_id'])) {
                $term_id = (int)$inserted['term_id'];
            } else {
                continue;
            }
        } else {
            $term_id = (int)$term->term_id;
        }
        $category_ids[] = $term_id;
    }
}
if (empty($category_ids)) {
    $uncategorized = get_term_by('slug', 'uncategorized', 'category');
    $category_ids[] = $uncategorized ? (int)$uncategorized->term_id : 1;
}
wp_set_post_categories($post_id, array_unique($category_ids));

// ---------- Hierarchical term helper (robust & non-blocking)
function add_hierarchical_term($post_id, $taxonomy, $parent_name, $child_name) {

    $parent_name = trim(strip_tags((string)$parent_name));
    $child_name  = trim(strip_tags((string)$child_name));

    if ($child_name === '' || $parent_name === '') {
        return;
    }

    // Parent
    $parent = get_term_by('name', $parent_name, $taxonomy);
    if (!$parent || !isset($parent->term_id)) {
        $parent_insert = wp_insert_term($parent_name, $taxonomy);
        if (is_wp_error($parent_insert) || !isset($parent_insert['term_id'])) {
            return;
        }
        $parent_id = (int)$parent_insert['term_id'];
    } else {
        $parent_id = (int)$parent->term_id;
    }

    // Child
    $child = get_term_by('name', $child_name, $taxonomy);
    if ($child && isset($child->term_id)) {
        $child_id = (int)$child->term_id;
        // ensure correct parent
        if ((int)$child->parent !== $parent_id) {
            wp_update_term($child_id, $taxonomy, ['parent' => $parent_id]);
        }
    } else {
        $child_insert = wp_insert_term($child_name, $taxonomy, ['parent' => $parent_id]);
        if (is_wp_error($child_insert) || !isset($child_insert['term_id'])) {
            return;
        }
        $child_id = (int)$child_insert['term_id'];
    }

    // Assign (append true)
    wp_set_object_terms((int)$post_id, [$child_id], $taxonomy, true);
}

// ---------- Hierarchical taxonomies
$quality_values = $data->quality ?? [];
if (!is_array($quality_values) && !empty($data->quality)) {
    $quality_values = [$data->quality];
}
$genre_values = $data->genre ?? [];
if (!is_array($genre_values) && !empty($data->genre)) {
    $genre_values = [$data->genre];
}
$release_year_values = $data->release_year ?? [];
if (!is_array($release_year_values) && !empty($data->release_year)) {
    $release_year_values = [$data->release_year];
}
$country_values = $data->country ?? [];
if (!is_array($country_values) && !empty($data->country)) {
    $country_values = [$data->country];
}
$language_values = $data->language ?? [];
if (!is_array($language_values) && !empty($data->language)) {
    $language_values = [$data->language];
}
$actor_values = $data->actor ?? [];
if (!is_array($actor_values) && !empty($data->actor)) {
    $actor_values = [$data->actor];
}

// ---------- Keywords / Tags
$keyword = [];

// Get quality text
$quality_text = is_array($quality_values) ? trim((string)($quality_values[0] ?? '')) : trim((string)($data->quality ?? ''));
// Get main category
$main_category = is_array($main_category_names) ? trim((string)($main_category_names[0] ?? '')) : trim((string)($data->category ?? ''));
// Clean title for keywords
$clean_title = trim((string)preg_replace(['/الحلقة\s*\d+/i', '/\b(مترجم|مترجمة|مدبلج|كامل|اون لاين|مشاهدة|تحميل)\b/i'], '', $title));
// Get year
$year_kw = is_array($release_year_values) ? trim((string)($release_year_values[0] ?? '')) : trim((string)($data->release_year ?? ''));
$year_kw = preg_replace('/[^\d]/', '', (string)$year_kw);

if ($is_series) {
    if ($series_name) {
        $keyword[] = $series_name;
        $keyword[] = 'مشاهدة ' . $series_name;
        $keyword[] = 'تحميل ' . $series_name;
        $keyword[] = $series_name . ' مترجم';
    }
    if ($season_name) {
        $keyword[] = $season_name;
        $keyword[] = 'مشاهدة ' . $season_name;
        $keyword[] = 'تحميل ' . $season_name;
        if ($quality_text) {
            $keyword[] = $season_name . ' ' . $quality_text;
            $keyword[] = 'مشاهدة ' . $season_name . ' ' . $quality_text;
        }
    }
    if ($series_name && $season_name && stripos($season_name, $series_name) === false) {
        $keyword[] = $series_name . ' ' . $season_name;
    }
    if ($clean_title && $clean_title !== $series_name) {
        $keyword[] = $clean_title;
    }
} else {
    if ($clean_title) {
        $keyword[] = $clean_title;
        $keyword[] = 'فيلم ' . $clean_title;
        $keyword[] = 'مشاهدة ' . $clean_title;
        $keyword[] = 'تحميل ' . $clean_title;
        $keyword[] = $clean_title . ' مترجم';
        $keyword[] = 'مشاهدة فيلم ' . $clean_title;
        $keyword[] = 'تحميل فيلم ' . $clean_title;
        if ($quality_text) {
            $keyword[] = $clean_title . ' ' . $quality_text;
            $keyword[] = 'مشاهدة ' . $clean_title . ' ' . $quality_text;
            $keyword[] = 'تحميل ' . $clean_title . ' ' . $quality_text;
        }
        if ($year_kw) {
            $keyword[] = $clean_title . ' ' . $year_kw;
            $keyword[] = 'فيلم ' . $clean_title . ' ' . $year_kw;
        }
    }
}

if ($main_category) {
    $keyword[] = $main_category;
    $keyword[] = 'مشاهدة ' . $main_category;
    $keyword[] = 'تحميل ' . $main_category;
    if ($quality_text) {
        $keyword[] = $main_category . ' ' . $quality_text;
        $keyword[] = 'مشاهدة ' . $main_category . ' ' . $quality_text;
    }
}
if ($quality_text) {
    $keyword[] = $quality_text;
    $keyword[] = 'مشاهدة ' . $quality_text;
}

// Add genres as keywords
if (!empty($genre_values) && is_array($genre_values)) {
    foreach ($genre_values as $genre) {
        $genre = trim((string)$genre);
        if ($genre !== '') {
            $keyword[] = $genre;
            $keyword[] = 'مشاهدة ' . $genre;
        }
    }
}

// Add actors as keywords (limit to first 5)
if (!empty($actor_values) && is_array($actor_values)) {
    $actors_kw = array_slice($actor_values, 0, 5);
    foreach ($actors_kw as $actor) {
        $actor = trim((string)$actor);
        if ($actor !== '' && mb_strlen($actor, 'UTF-8') < 50) {
            $keyword[] = $actor;
        }
    }
}

// Add country
if (!empty($country_values) && is_array($country_values)) {
    foreach ($country_values as $country) {
        $country = trim((string)$country);
        if ($country !== '') {
            $keyword[] = $country;
        }
    }
}

// Add year
if ($year_kw) {
    $keyword[] = $year_kw;
}

// Clean keywords
$keyword = array_unique(array_filter(array_map('trim', $keyword)));
$keyword = array_filter($keyword, function($k) {
    return mb_strlen($k, 'UTF-8') <= 100 && mb_strlen($k, 'UTF-8') >= 2;
});
$keyword = array_filter($keyword, function($k) use ($year_kw) {
    if (preg_match('/^\d+$/', $k)) {
        return $k === $year_kw;
    }
    return true;
});
$keyword = array_slice($keyword, 0, 30);

if (!empty($keyword)) {
    wp_set_post_terms($post_id, array_values($keyword), "post_tag");
}

// Fix: use $post_id (not $id)
if (!empty($language_values)) {
    wp_set_post_terms($post_id, array_values((array)$language_values), "language");
}

// Fix: use $post_id (not $id)
if (!empty($actor_values) && is_array($actor_values)) {
    $actors = array_map(function ($name) {
        $name = str_replace('ممثل', '', (string)$name);
        return trim((string)$name);
    }, $actor_values);
    $actors = array_values(array_filter($actors));
    if (!empty($actors)) {
        wp_set_post_terms($post_id, $actors, 'actor');
    }
}

// ---------- Hierarchical taxonomies assignment
$hierarchical_taxonomies = [
    'quality'      => ['parent' => 'الجودات',        'values' => $quality_values],
    'genre'        => ['parent' => 'الانواع',         'values' => $genre_values],
    'release-year' => ['parent' => 'سنة الاصدار',     'values' => $release_year_values],
    'country'      => ['parent' => 'الدولة',          'values' => $country_values],
];

foreach ($hierarchical_taxonomies as $tax => $config) {
    if (!taxonomy_exists($tax)) continue;
    $values = (array)($config['values'] ?? []);
    $values = array_filter(array_map('trim', $values));
    $values = array_unique($values);
    foreach ($values as $value) {
        if ($value === '') continue;
        add_hierarchical_term($post_id, $tax, $config['parent'], $value);
    }
}

// ---------- Series / season (selary) - Only add for series, not movies
$available_taxonomy = taxonomy_exists('selary') ? 'selary' : (taxonomy_exists('series') ? 'series' : null);
if ($available_taxonomy && !empty($data->selary)) {
    $series_term_name = is_array($data->selary) ? (string)($data->selary[0] ?? '') : (string)$data->selary;
    $series_term_name = trim(strip_tags($series_term_name));
    if ($series_term_name !== '') {
        wp_set_object_terms($post_id, $series_term_name, $available_taxonomy, true);
    }

    if (!empty($data->current_season)) {
        $season_term_name = is_array($data->current_season) ? (string)($data->current_season[0] ?? '') : (string)$data->current_season;
        $season_term_name = trim(strip_tags($season_term_name));

        if ($series_term_name !== '' && $season_term_name !== '') {
            $parent_category = term_exists($series_term_name, $available_taxonomy);
            if ($parent_category !== 0 && $parent_category !== null && isset($parent_category['term_id'])) {
                $parent_category_id = (int)$parent_category['term_id'];
            } else {
                $insert = wp_insert_term($series_term_name, $available_taxonomy);
                $parent_category_id = (!is_wp_error($insert) && isset($insert['term_id'])) ? (int)$insert['term_id'] : 0;
            }

            if ($parent_category_id > 0) {
                $season_term = get_term_by('name', $season_term_name, $available_taxonomy);
                if (!$season_term) {
                    $ins = wp_insert_term($season_term_name, $available_taxonomy, ['parent' => $parent_category_id]);
                    $season_term_id = (!is_wp_error($ins) && isset($ins['term_id'])) ? (int)$ins['term_id'] : 0;
                } else {
                    $season_term_id = (int)$season_term->term_id;
                }

                if ($season_term_id > 0) {
                    wp_set_object_terms($post_id, $season_term_id, $available_taxonomy, true);
                }
            }
        }
    }
}

// === Final JSON response ===
// Note: duplicate checks happen earlier; don't re-check here (it would mask "created").
if ($post_id && !is_wp_error($post_id)) {
    // Save title to check file after successful creation
    add_title_to_file($title);

    wp_send_json_success([
        'status'   => 'created',
        'message'  => 'تم إضافة المقال بنجاح',
        'title'    => $title,
        'post_id'  => $post_id
    ]);
} else {
    wp_send_json_error([
        'message' => 'فشل في إنشاء المقال'
    ]);
}
exit;

