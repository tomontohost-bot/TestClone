<?php

/**
 * Advanced Image Downloader Method
 * Drop-in replacement for your existing download_image function
 * 
 * Features:
 * - Magic bytes validation (detects real image format)
 * - Intelligent content-type detection
 * - Exponential backoff with jitter
 * - Atomic file operations with backup
 * - Comprehensive error handling
 * - GD validation for image integrity
 */

// Add these constants to your class (or adjust existing ones)
// private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';

private function download_image($url, $type = 'poster')
{
    // ═══════════════════════════════════════════════════════════════════
    // CONFIGURATION
    // ═══════════════════════════════════════════════════════════════════
    $config = [
        'images_dir'      => __DIR__ . '/images',
        'min_bytes'       => 10 * 1024,        // 10 KB minimum
        'max_bytes'       => 50 * 1024 * 1024, // 50 MB maximum
        'max_attempts'    => 4,
        'timeout'         => 30,
        'connect_timeout' => 10,
        'allowed_ext'     => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'bmp'],
    ];

    // MIME to extension mapping
    $mime_map = [
        'image/jpeg'    => 'jpg',
        'image/jpg'     => 'jpg',
        'image/png'     => 'png',
        'image/webp'    => 'webp',
        'image/gif'     => 'gif',
        'image/avif'    => 'avif',
        'image/bmp'     => 'bmp',
    ];

    // ═══════════════════════════════════════════════════════════════════
    // URL VALIDATION
    // ═══════════════════════════════════════════════════════════════════
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }

    $parsed_url = parse_url($url);
    if (empty($parsed_url['scheme']) || empty($parsed_url['host'])) {
        return null;
    }

    if (!in_array(strtolower($parsed_url['scheme']), ['http', 'https'], true)) {
        return null;
    }

    // Block private/local IPs for security
    $host = $parsed_url['host'];
    if (in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
        return null;
    }

    // ═══════════════════════════════════════════════════════════════════
    // DIRECTORY SETUP
    // ═══════════════════════════════════════════════════════════════════
    if (!is_dir($config['images_dir'])) {
        if (!@mkdir($config['images_dir'], 0755, true) && !is_dir($config['images_dir'])) {
            return null;
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // FILENAME GENERATION
    // ═══════════════════════════════════════════════════════════════════
    $path = $parsed_url['path'] ?? '';
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    if (empty($extension) || !in_array($extension, $config['allowed_ext'], true)) {
        $extension = 'jpg';
    }

    $filename = md5($url) . '.' . $extension;
    $filepath = $config['images_dir'] . '/' . $filename;

    // ═══════════════════════════════════════════════════════════════════
    // CACHE CHECK
    // ═══════════════════════════════════════════════════════════════════
    if (is_file($filepath) && filesize($filepath) >= $config['min_bytes']) {
        // Validate cached file is a real image
        $cached_data = @file_get_contents($filepath);
        if ($cached_data !== false && $this->_validate_image_bytes($cached_data) !== null) {
            return $this->get_image_url($filename);
        }
        // Invalid cache, will re-download
    }

    $existing_valid = is_file($filepath) && filesize($filepath) >= $config['min_bytes'];

    // ═══════════════════════════════════════════════════════════════════
    // BUILD REQUEST HEADERS
    // ═══════════════════════════════════════════════════════════════════
    $headers = [
        'Accept: image/avif,image/webp,image/png,image/jpeg,image/gif,image/*;q=0.9,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
        'Accept-Encoding: gzip, deflate',
        'Cache-Control: no-cache',
        'Pragma: no-cache',
        'Connection: keep-alive',
    ];

    // Add referer to bypass hotlink protection
    $scheme = $parsed_url['scheme'] ?? 'https';
    $headers[] = "Referer: {$scheme}://{$host}/";
    $headers[] = "Origin: {$scheme}://{$host}";

    // ═══════════════════════════════════════════════════════════════════
    // DOWNLOAD WITH RETRY
    // ═══════════════════════════════════════════════════════════════════
    for ($attempt = 1; $attempt <= $config['max_attempts']; $attempt++) {
        
        // Initialize cURL
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => defined('self::USER_AGENT') ? self::USER_AGENT : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_TIMEOUT        => $config['timeout'],
            CURLOPT_CONNECTTIMEOUT => $config['connect_timeout'],
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_ENCODING       => '',  // Accept all encodings, auto-decompress
            CURLOPT_FAILONERROR    => false, // Don't fail silently, we check HTTP code
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        ]);

        $image_data = curl_exec($ch);
        $curl_errno = curl_errno($ch);
        $curl_error = curl_error($ch);
        $http_code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        // ─────────────────────────────────────────────────────────────────
        // Check for cURL errors
        // ─────────────────────────────────────────────────────────────────
        if ($curl_errno !== 0) {
            // Permanent errors - don't retry
            if (in_array($curl_errno, [CURLE_UNSUPPORTED_PROTOCOL, CURLE_URL_MALFORMAT], true)) {
                break;
            }
            if ($attempt < $config['max_attempts']) {
                usleep($this->_calculate_backoff($attempt));
                continue;
            }
            break;
        }

        // ─────────────────────────────────────────────────────────────────
        // Check HTTP status
        // ─────────────────────────────────────────────────────────────────
        if ($http_code < 200 || $http_code >= 300) {
            // Permanent HTTP errors - don't retry
            if (in_array($http_code, [400, 401, 403, 404, 410, 451], true)) {
                break;
            }
            if ($attempt < $config['max_attempts']) {
                usleep($this->_calculate_backoff($attempt));
                continue;
            }
            break;
        }

        // ─────────────────────────────────────────────────────────────────
        // Validate response data exists
        // ─────────────────────────────────────────────────────────────────
        if (empty($image_data)) {
            if ($attempt < $config['max_attempts']) {
                usleep($this->_calculate_backoff($attempt));
                continue;
            }
            break;
        }

        $data_size = strlen($image_data);

        // ─────────────────────────────────────────────────────────────────
        // Check minimum size
        // ─────────────────────────────────────────────────────────────────
        if ($data_size < $config['min_bytes']) {
            if ($attempt < $config['max_attempts']) {
                usleep($this->_calculate_backoff($attempt));
                continue;
            }
            break;
        }

        // ─────────────────────────────────────────────────────────────────
        // Check maximum size
        // ─────────────────────────────────────────────────────────────────
        if ($data_size > $config['max_bytes']) {
            break; // Too large, permanent failure
        }

        // ─────────────────────────────────────────────────────────────────
        // Validate image using magic bytes
        // ─────────────────────────────────────────────────────────────────
        $detected_ext = $this->_validate_image_bytes($image_data);
        if ($detected_ext === null) {
            // Fallback: try getimagesizefromstring
            $image_info = @getimagesizefromstring($image_data);
            if ($image_info === false) {
                if ($attempt < $config['max_attempts']) {
                    usleep($this->_calculate_backoff($attempt));
                    continue;
                }
                break;
            }
            // Get extension from MIME
            $mime = $image_info['mime'] ?? '';
            $detected_ext = $mime_map[$mime] ?? 'jpg';
        }

        // ─────────────────────────────────────────────────────────────────
        // Validate with GD if available (ensures image is processable)
        // ─────────────────────────────────────────────────────────────────
        if (function_exists('imagecreatefromstring') && $detected_ext !== 'svg') {
            $test_img = @imagecreatefromstring($image_data);
            if ($test_img === false) {
                if ($attempt < $config['max_attempts']) {
                    usleep($this->_calculate_backoff($attempt));
                    continue;
                }
                break;
            }
            imagedestroy($test_img);
        }

        // ─────────────────────────────────────────────────────────────────
        // Update filename if detected extension differs
        // ─────────────────────────────────────────────────────────────────
        if ($detected_ext !== $extension) {
            $filename = md5($url) . '.' . $detected_ext;
            $filepath = $config['images_dir'] . '/' . $filename;
        }

        // ─────────────────────────────────────────────────────────────────
        // Atomic save: write to temp file first
        // ─────────────────────────────────────────────────────────────────
        $tmp_path = $filepath . '.tmp_' . uniqid('', true);

        if (@file_put_contents($tmp_path, $image_data, LOCK_EX) === false) {
            @unlink($tmp_path);
            if ($attempt < $config['max_attempts']) {
                usleep($this->_calculate_backoff($attempt));
                continue;
            }
            break;
        }

        // Verify written file
        clearstatcache(true, $tmp_path);
        if (!is_file($tmp_path) || filesize($tmp_path) < $config['min_bytes']) {
            @unlink($tmp_path);
            if ($attempt < $config['max_attempts']) {
                usleep($this->_calculate_backoff($attempt));
                continue;
            }
            break;
        }

        // ─────────────────────────────────────────────────────────────────
        // Backup existing file and replace atomically
        // ─────────────────────────────────────────────────────────────────
        $backup_path = null;
        if (is_file($filepath)) {
            $backup_path = $filepath . '.bak';
            @unlink($backup_path);
            if (!@rename($filepath, $backup_path)) {
                if (!@copy($filepath, $backup_path)) {
                    $backup_path = null;
                }
            }
        }

        // Atomic rename
        if (@rename($tmp_path, $filepath)) {
            if ($backup_path !== null && is_file($backup_path)) {
                @unlink($backup_path);
            }
            clearstatcache(true, $filepath);
            return $this->get_image_url($filename);
        }

        // Rename failed, try copy
        if (@copy($tmp_path, $filepath)) {
            @unlink($tmp_path);
            if ($backup_path !== null && is_file($backup_path)) {
                @unlink($backup_path);
            }
            clearstatcache(true, $filepath);
            return $this->get_image_url($filename);
        }

        // Complete failure, restore backup
        @unlink($tmp_path);
        if ($backup_path !== null && is_file($backup_path) && !is_file($filepath)) {
            @rename($backup_path, $filepath);
        }

        if ($attempt < $config['max_attempts']) {
            usleep($this->_calculate_backoff($attempt));
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // FALLBACK TO EXISTING VALID FILE
    // ═══════════════════════════════════════════════════════════════════
    if ($existing_valid) {
        return $this->get_image_url(md5($url) . '.' . $extension);
    }

    return null;
}

/**
 * Validate image data using magic bytes (file signatures)
 * Returns detected extension or null if invalid
 */
private function _validate_image_bytes(string $data): ?string
{
    if (strlen($data) < 12) {
        return null;
    }

    // JPEG: FF D8 FF
    if (substr($data, 0, 3) === "\xFF\xD8\xFF") {
        return 'jpg';
    }

    // PNG: 89 50 4E 47 0D 0A 1A 0A
    if (substr($data, 0, 8) === "\x89PNG\r\n\x1A\n") {
        return 'png';
    }

    // GIF: GIF87a or GIF89a
    if (substr($data, 0, 6) === "GIF87a" || substr($data, 0, 6) === "GIF89a") {
        return 'gif';
    }

    // WebP: RIFF....WEBP
    if (substr($data, 0, 4) === "RIFF" && substr($data, 8, 4) === "WEBP") {
        return 'webp';
    }

    // AVIF: ....ftypavif or ....ftypmif1
    if (strlen($data) >= 12) {
        $ftyp = substr($data, 4, 8);
        if (strpos($ftyp, 'ftypavif') === 0 || strpos($ftyp, 'ftypmif1') === 0) {
            return 'avif';
        }
    }

    // BMP: BM
    if (substr($data, 0, 2) === "BM") {
        return 'bmp';
    }

    // ICO: 00 00 01 00
    if (substr($data, 0, 4) === "\x00\x00\x01\x00") {
        return 'ico';
    }

    return null;
}

/**
 * Calculate exponential backoff delay with jitter
 * Returns microseconds to sleep
 */
private function _calculate_backoff(int $attempt): int
{
    $base_ms = 250;
    $max_ms = 8000;
    
    // Exponential: 250, 500, 1000, 2000, 4000, 8000
    $delay_ms = min($base_ms * pow(2, $attempt - 1), $max_ms);
    
    // Add jitter: ±25%
    $jitter = (int) ($delay_ms * 0.25);
    $delay_ms += random_int(-$jitter, $jitter);
    
    // Convert to microseconds
    return $delay_ms * 1000;
}
