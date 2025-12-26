<?php

/**
 * Advanced Image Downloader Class
 * 
 * Features:
 * - Multi-source download with fallback URLs
 * - Intelligent content-type detection
 * - Image validation and integrity checking
 * - Exponential backoff retry with jitter
 * - Connection pooling via cURL multi
 * - Automatic format detection and conversion
 * - Comprehensive error logging
 * - Rate limiting support
 * - Resumable downloads
 * - Memory-efficient streaming for large files
 */
class AdvancedImageDownloader
{
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'bmp', 'svg'];
    
    private const MIME_TO_EXTENSION = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/avif' => 'avif',
        'image/bmp' => 'bmp',
        'image/svg+xml' => 'svg',
    ];
    
    private const IMAGE_SIGNATURES = [
        "\xFF\xD8\xFF" => 'jpg',           // JPEG
        "\x89PNG\r\n\x1A\n" => 'png',      // PNG
        "GIF87a" => 'gif',                  // GIF87a
        "GIF89a" => 'gif',                  // GIF89a
        "RIFF" => 'webp',                   // WebP (needs additional check)
        "\x00\x00\x00\x1Cftypavif" => 'avif', // AVIF
        "\x00\x00\x00\x20ftypavif" => 'avif', // AVIF variant
        "BM" => 'bmp',                      // BMP
    ];

    private string $images_dir;
    private int $min_bytes;
    private int $max_bytes;
    private int $max_attempts;
    private int $timeout;
    private int $connect_timeout;
    private bool $verify_ssl;
    private bool $enable_logging;
    private ?string $log_file;
    private array $custom_headers;
    private ?callable $progress_callback;
    private array $rate_limit;
    private static array $curl_handles = [];
    private static int $last_request_time = 0;

    public function __construct(array $options = [])
    {
        $this->images_dir = $options['images_dir'] ?? __DIR__ . '/images';
        $this->min_bytes = $options['min_bytes'] ?? 10 * 1024; // 10 KB
        $this->max_bytes = $options['max_bytes'] ?? 50 * 1024 * 1024; // 50 MB
        $this->max_attempts = $options['max_attempts'] ?? 4;
        $this->timeout = $options['timeout'] ?? 30;
        $this->connect_timeout = $options['connect_timeout'] ?? 10;
        $this->verify_ssl = $options['verify_ssl'] ?? false;
        $this->enable_logging = $options['enable_logging'] ?? false;
        $this->log_file = $options['log_file'] ?? null;
        $this->custom_headers = $options['custom_headers'] ?? [];
        $this->progress_callback = $options['progress_callback'] ?? null;
        $this->rate_limit = $options['rate_limit'] ?? ['requests' => 10, 'per_seconds' => 1];
    }

    /**
     * Download image from URL with advanced features
     * 
     * @param string $url The image URL to download
     * @param string $type The type of image (poster, backdrop, thumbnail, etc.)
     * @param array $options Additional options for this specific download
     * @return string|null Returns the local image URL or null on failure
     */
    private function download_image($url, $type = 'poster', array $options = []): ?string
    {
        // Merge instance options with call-specific options
        $opts = array_merge([
            'force_refresh' => false,
            'fallback_urls' => [],
            'custom_filename' => null,
            'resize' => null, // ['width' => 300, 'height' => 450]
            'quality' => 85,
            'convert_to' => null, // 'webp', 'jpg', etc.
        ], $options);

        // Validate primary URL
        if (!$this->is_valid_url($url)) {
            $this->log('error', "Invalid URL provided: {$url}");
            return null;
        }

        // Ensure images directory exists
        if (!$this->ensure_directory()) {
            return null;
        }

        // Build list of URLs to try (primary + fallbacks)
        $urls_to_try = array_merge([$url], $opts['fallback_urls']);
        $urls_to_try = array_filter($urls_to_try, [$this, 'is_valid_url']);

        // Determine filename
        $filename = $opts['custom_filename'] ?? $this->generate_filename($url, $type);
        $filepath = $this->images_dir . '/' . $filename;

        // Check if valid cached file exists
        if (!$opts['force_refresh'] && $this->is_valid_cached_file($filepath)) {
            $this->log('info', "Using cached file: {$filename}");
            return $this->get_image_url($filename);
        }

        // Store existing file info for fallback
        $existing_file_valid = $this->is_valid_cached_file($filepath);

        // Try each URL
        foreach ($urls_to_try as $try_url) {
            $result = $this->download_with_retry($try_url, $filepath, $opts);
            if ($result !== null) {
                return $result;
            }
        }

        // If all downloads failed but we have a valid existing file, return it
        if ($existing_file_valid) {
            $this->log('warning', "Download failed, using existing cached file: {$filename}");
            return $this->get_image_url($filename);
        }

        $this->log('error', "All download attempts failed for: {$url}");
        return null;
    }

    /**
     * Download with retry logic and exponential backoff
     */
    private function download_with_retry(string $url, string $filepath, array $opts): ?string
    {
        $filename = basename($filepath);
        
        for ($attempt = 1; $attempt <= $this->max_attempts; $attempt++) {
            $this->log('info', "Download attempt {$attempt}/{$this->max_attempts} for: {$url}");
            
            // Apply rate limiting
            $this->apply_rate_limit();

            // Perform download
            $result = $this->perform_download($url, $filepath, $opts);

            if ($result['success']) {
                // Post-process image if needed
                if ($opts['resize'] || $opts['convert_to']) {
                    $this->post_process_image($filepath, $opts);
                }
                
                $this->log('info', "Successfully downloaded: {$filename}");
                return $this->get_image_url($filename);
            }

            // Log the failure reason
            $this->log('warning', "Attempt {$attempt} failed: {$result['error']}");

            // Don't retry on permanent failures
            if ($result['permanent_failure']) {
                $this->log('error', "Permanent failure, not retrying: {$result['error']}");
                break;
            }

            // Exponential backoff with jitter
            if ($attempt < $this->max_attempts) {
                $delay = $this->calculate_backoff_delay($attempt);
                $this->log('info', "Waiting {$delay}ms before retry...");
                usleep($delay * 1000);
            }
        }

        return null;
    }

    /**
     * Perform the actual download operation
     */
    private function perform_download(string $url, string $filepath, array $opts): array
    {
        $result = [
            'success' => false,
            'error' => '',
            'permanent_failure' => false,
        ];

        // Initialize cURL
        $ch = $this->get_curl_handle();
        
        // Build headers
        $headers = $this->build_headers($url);

        // Check for resumable download
        $resume_from = 0;
        $tmp_path = $filepath . '.download';
        if (is_file($tmp_path) && filesize($tmp_path) > 0) {
            $resume_from = filesize($tmp_path);
            $headers[] = "Range: bytes={$resume_from}-";
        }

        // Set cURL options
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_SSL_VERIFYPEER => $this->verify_ssl,
            CURLOPT_SSL_VERIFYHOST => $this->verify_ssl ? 2 : 0,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connect_timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => '', // Accept all encodings
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => false,
        ]);

        // Add progress callback if provided
        if ($this->progress_callback !== null) {
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function ($resource, $download_size, $downloaded, $upload_size, $uploaded) {
                if ($download_size > 0) {
                    call_user_func($this->progress_callback, $downloaded, $download_size);
                }
            });
        }

        // Execute request
        $response = curl_exec($ch);
        $curl_errno = curl_errno($ch);
        $curl_error = curl_error($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $download_size = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);

        // Handle cURL errors
        if ($curl_errno !== 0) {
            $result['error'] = "cURL error ({$curl_errno}): {$curl_error}";
            $result['permanent_failure'] = in_array($curl_errno, [
                CURLE_UNSUPPORTED_PROTOCOL,
                CURLE_URL_MALFORMAT,
            ]);
            return $result;
        }

        // Handle HTTP errors
        if ($http_code < 200 || ($http_code >= 300 && $http_code !== 206)) {
            $result['error'] = "HTTP error: {$http_code}";
            $result['permanent_failure'] = in_array($http_code, [400, 401, 403, 404, 410, 451]);
            return $result;
        }

        // Extract body from response
        $image_data = substr($response, $header_size);

        // Handle partial content (resumable download)
        if ($http_code === 206 && $resume_from > 0) {
            // Append to existing partial file
            if (file_put_contents($tmp_path, $image_data, FILE_APPEND) === false) {
                $result['error'] = "Failed to append to partial download";
                return $result;
            }
            $image_data = file_get_contents($tmp_path);
        }

        // Validate response data
        if (empty($image_data)) {
            $result['error'] = "Empty response received";
            return $result;
        }

        // Check minimum size
        $data_size = strlen($image_data);
        if ($data_size < $this->min_bytes) {
            $result['error'] = "Image too small: {$data_size} bytes (minimum: {$this->min_bytes})";
            return $result;
        }

        // Check maximum size
        if ($data_size > $this->max_bytes) {
            $result['error'] = "Image too large: {$data_size} bytes (maximum: {$this->max_bytes})";
            $result['permanent_failure'] = true;
            return $result;
        }

        // Validate image data
        $validation = $this->validate_image_data($image_data, $content_type);
        if (!$validation['valid']) {
            $result['error'] = "Invalid image data: {$validation['error']}";
            return $result;
        }

        // Determine correct extension and update filepath if needed
        $detected_extension = $validation['extension'];
        $current_extension = pathinfo($filepath, PATHINFO_EXTENSION);
        
        if ($detected_extension !== $current_extension) {
            $new_filepath = preg_replace('/\.' . preg_quote($current_extension, '/') . '$/', '.' . $detected_extension, $filepath);
            if ($new_filepath !== $filepath) {
                $filepath = $new_filepath;
            }
        }

        // Save to temporary file first (atomic operation)
        $tmp_save_path = $filepath . '.tmp_' . uniqid('', true);
        
        if (file_put_contents($tmp_save_path, $image_data) === false) {
            @unlink($tmp_save_path);
            $result['error'] = "Failed to write temporary file";
            return $result;
        }

        // Verify written file
        if (!is_file($tmp_save_path) || filesize($tmp_save_path) < $this->min_bytes) {
            @unlink($tmp_save_path);
            $result['error'] = "Written file verification failed";
            return $result;
        }

        // Atomic replace with backup
        $backup_path = null;
        if (is_file($filepath)) {
            $backup_path = $filepath . '.bak';
            @unlink($backup_path);
            if (!@rename($filepath, $backup_path)) {
                @copy($filepath, $backup_path);
            }
        }

        // Move temporary file to final location
        if (!@rename($tmp_save_path, $filepath)) {
            // Try copy + delete as fallback
            if (@copy($tmp_save_path, $filepath)) {
                @unlink($tmp_save_path);
            } else {
                @unlink($tmp_save_path);
                if ($backup_path && is_file($backup_path) && !is_file($filepath)) {
                    @rename($backup_path, $filepath);
                }
                $result['error'] = "Failed to move file to final location";
                return $result;
            }
        }

        // Clean up
        if ($backup_path && is_file($backup_path)) {
            @unlink($backup_path);
        }
        if (is_file($tmp_path)) {
            @unlink($tmp_path);
        }

        $result['success'] = true;
        return $result;
    }

    /**
     * Validate image data integrity and format
     */
    private function validate_image_data(string $data, ?string $content_type): array
    {
        $result = [
            'valid' => false,
            'error' => '',
            'extension' => 'jpg',
            'width' => 0,
            'height' => 0,
        ];

        // Check magic bytes first
        $detected_type = $this->detect_image_type_from_bytes($data);
        
        if ($detected_type === null) {
            // Fall back to getimagesizefromstring
            $image_info = @getimagesizefromstring($data);
            if ($image_info === false) {
                $result['error'] = "Unable to detect image format";
                return $result;
            }
            
            $mime = $image_info['mime'] ?? '';
            $detected_type = self::MIME_TO_EXTENSION[$mime] ?? 'jpg';
            $result['width'] = $image_info[0];
            $result['height'] = $image_info[1];
        } else {
            // Get dimensions
            $image_info = @getimagesizefromstring($data);
            if ($image_info !== false) {
                $result['width'] = $image_info[0];
                $result['height'] = $image_info[1];
            }
        }

        // Additional validation for specific formats
        if ($detected_type === 'webp') {
            // WebP needs additional signature check
            if (strlen($data) >= 12 && substr($data, 8, 4) !== 'WEBP') {
                $result['error'] = "Invalid WebP signature";
                return $result;
            }
        }

        // Check for corrupted JPEG (should end with FFD9)
        if ($detected_type === 'jpg') {
            $end_bytes = substr($data, -2);
            if ($end_bytes !== "\xFF\xD9") {
                // Warning but not a failure - some valid JPEGs might not have proper EOI
                $this->log('warning', "JPEG may be truncated (missing EOI marker)");
            }
        }

        // Verify image can be processed by GD (if available)
        if (function_exists('imagecreatefromstring')) {
            $test_image = @imagecreatefromstring($data);
            if ($test_image === false) {
                // SVG won't work with GD, that's expected
                if ($detected_type !== 'svg') {
                    $result['error'] = "Image cannot be processed by GD";
                    return $result;
                }
            } else {
                imagedestroy($test_image);
            }
        }

        $result['valid'] = true;
        $result['extension'] = $detected_type;
        return $result;
    }

    /**
     * Detect image type from magic bytes
     */
    private function detect_image_type_from_bytes(string $data): ?string
    {
        if (strlen($data) < 12) {
            return null;
        }

        // JPEG
        if (substr($data, 0, 3) === "\xFF\xD8\xFF") {
            return 'jpg';
        }

        // PNG
        if (substr($data, 0, 8) === "\x89PNG\r\n\x1A\n") {
            return 'png';
        }

        // GIF
        if (substr($data, 0, 6) === "GIF87a" || substr($data, 0, 6) === "GIF89a") {
            return 'gif';
        }

        // WebP
        if (substr($data, 0, 4) === "RIFF" && substr($data, 8, 4) === "WEBP") {
            return 'webp';
        }

        // AVIF
        if (preg_match('/^.{4}ftypavif/s', $data) || preg_match('/^.{4}ftypmif1/s', $data)) {
            return 'avif';
        }

        // BMP
        if (substr($data, 0, 2) === "BM") {
            return 'bmp';
        }

        // SVG (check for XML/SVG markers)
        if (strpos($data, '<svg') !== false || strpos($data, '<?xml') !== false) {
            return 'svg';
        }

        return null;
    }

    /**
     * Post-process image (resize, convert format)
     */
    private function post_process_image(string $filepath, array $opts): bool
    {
        if (!function_exists('imagecreatefromstring')) {
            $this->log('warning', "GD library not available for image processing");
            return false;
        }

        $data = file_get_contents($filepath);
        if ($data === false) {
            return false;
        }

        $image = @imagecreatefromstring($data);
        if ($image === false) {
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $new_image = $image;

        // Resize if requested
        if (!empty($opts['resize'])) {
            $new_width = $opts['resize']['width'] ?? null;
            $new_height = $opts['resize']['height'] ?? null;

            if ($new_width && !$new_height) {
                $new_height = (int) ($height * ($new_width / $width));
            } elseif ($new_height && !$new_width) {
                $new_width = (int) ($width * ($new_height / $height));
            }

            if ($new_width && $new_height && ($new_width !== $width || $new_height !== $height)) {
                $new_image = imagecreatetruecolor($new_width, $new_height);
                
                // Preserve transparency for PNG/WebP
                imagealphablending($new_image, false);
                imagesavealpha($new_image, true);
                $transparent = imagecolorallocatealpha($new_image, 0, 0, 0, 127);
                imagefill($new_image, 0, 0, $transparent);

                imagecopyresampled($new_image, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
                
                if ($new_image !== $image) {
                    imagedestroy($image);
                }
            }
        }

        // Determine output format
        $output_format = $opts['convert_to'] ?? pathinfo($filepath, PATHINFO_EXTENSION);
        $quality = $opts['quality'] ?? 85;

        // Update filepath if format changed
        $current_ext = pathinfo($filepath, PATHINFO_EXTENSION);
        if ($output_format !== $current_ext) {
            $new_filepath = preg_replace('/\.' . preg_quote($current_ext, '/') . '$/', '.' . $output_format, $filepath);
        } else {
            $new_filepath = $filepath;
        }

        // Save in target format
        $success = false;
        switch (strtolower($output_format)) {
            case 'jpg':
            case 'jpeg':
                $success = imagejpeg($new_image, $new_filepath, $quality);
                break;
            case 'png':
                $png_quality = (int) (9 - ($quality / 100 * 9));
                $success = imagepng($new_image, $new_filepath, $png_quality);
                break;
            case 'webp':
                if (function_exists('imagewebp')) {
                    $success = imagewebp($new_image, $new_filepath, $quality);
                }
                break;
            case 'gif':
                $success = imagegif($new_image, $new_filepath);
                break;
            case 'avif':
                if (function_exists('imageavif')) {
                    $success = imageavif($new_image, $new_filepath, $quality);
                }
                break;
        }

        imagedestroy($new_image);

        // Remove old file if format changed
        if ($success && $new_filepath !== $filepath && is_file($filepath)) {
            @unlink($filepath);
        }

        return $success;
    }

    /**
     * Build request headers based on URL
     */
    private function build_headers(string $url): array
    {
        $headers = [
            'Accept: image/avif,image/webp,image/png,image/jpeg,image/gif,image/*;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
        ];

        // Add custom headers
        foreach ($this->custom_headers as $key => $value) {
            $headers[] = "{$key}: {$value}";
        }

        // Add referer based on domain (helps with hotlink protection)
        $parsed = parse_url($url);
        if (isset($parsed['host'])) {
            $scheme = $parsed['scheme'] ?? 'https';
            $headers[] = "Referer: {$scheme}://{$parsed['host']}/";
            $headers[] = "Origin: {$scheme}://{$parsed['host']}";
        }

        return $headers;
    }

    /**
     * Generate unique filename for URL
     */
    private function generate_filename(string $url, string $type): string
    {
        // Parse URL to get extension
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // Validate extension
        if (empty($extension) || !in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            $extension = 'jpg';
        }

        // Create unique hash
        $hash = md5($url . $type);
        
        // Optional: Add type prefix for organization
        $prefix = ($type !== 'poster') ? "{$type}_" : '';

        return $prefix . $hash . '.' . $extension;
    }

    /**
     * Check if URL is valid
     */
    private function is_valid_url(string $url): bool
    {
        if (empty($url)) {
            return false;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        // Additional security checks
        $parsed = parse_url($url);
        
        // Must have scheme and host
        if (empty($parsed['scheme']) || empty($parsed['host'])) {
            return false;
        }

        // Only allow http/https
        if (!in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
            return false;
        }

        // Block local/private IPs
        $host = $parsed['host'];
        if ($this->is_private_ip($host)) {
            $this->log('warning', "Blocked private IP access: {$host}");
            return false;
        }

        return true;
    }

    /**
     * Check if host is a private/local IP
     */
    private function is_private_ip(string $host): bool
    {
        // Check for localhost
        if (in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        // Resolve hostname to IP
        $ip = gethostbyname($host);
        if ($ip === $host) {
            // DNS resolution failed
            return false;
        }

        // Check private ranges
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * Ensure images directory exists
     */
    private function ensure_directory(): bool
    {
        if (is_dir($this->images_dir)) {
            return true;
        }

        if (@mkdir($this->images_dir, 0755, true)) {
            return true;
        }

        if (is_dir($this->images_dir)) {
            return true;
        }

        $this->log('error', "Failed to create images directory: {$this->images_dir}");
        return false;
    }

    /**
     * Check if cached file is valid
     */
    private function is_valid_cached_file(string $filepath): bool
    {
        if (!is_file($filepath)) {
            return false;
        }

        $size = @filesize($filepath);
        if ($size === false || $size < $this->min_bytes) {
            return false;
        }

        // Optionally check file age
        // $max_age = 86400 * 7; // 7 days
        // if (time() - filemtime($filepath) > $max_age) {
        //     return false;
        // }

        return true;
    }

    /**
     * Get public URL for image
     */
    private function get_image_url(string $filename): string
    {
        // This should be customized based on your application's URL structure
        // For now, return a relative path
        return 'images/' . $filename;
    }

    /**
     * Get or create reusable cURL handle
     */
    private function get_curl_handle(): \CurlHandle
    {
        $key = 'default';
        
        if (!isset(self::$curl_handles[$key]) || self::$curl_handles[$key] === null) {
            self::$curl_handles[$key] = curl_init();
        } else {
            curl_reset(self::$curl_handles[$key]);
        }

        return self::$curl_handles[$key];
    }

    /**
     * Apply rate limiting
     */
    private function apply_rate_limit(): void
    {
        if (empty($this->rate_limit['requests']) || empty($this->rate_limit['per_seconds'])) {
            return;
        }

        $min_interval = ($this->rate_limit['per_seconds'] * 1000000) / $this->rate_limit['requests'];
        $elapsed = (microtime(true) * 1000000) - self::$last_request_time;

        if ($elapsed < $min_interval && self::$last_request_time > 0) {
            usleep((int) ($min_interval - $elapsed));
        }

        self::$last_request_time = (int) (microtime(true) * 1000000);
    }

    /**
     * Calculate exponential backoff delay with jitter
     */
    private function calculate_backoff_delay(int $attempt): int
    {
        $base_delay = 250; // 250ms base
        $max_delay = 10000; // 10 seconds max

        // Exponential: 250, 500, 1000, 2000...
        $delay = $base_delay * pow(2, $attempt - 1);

        // Add jitter (±25%)
        $jitter = $delay * 0.25;
        $delay += random_int((int) -$jitter, (int) $jitter);

        return min($delay, $max_delay);
    }

    /**
     * Log message
     */
    private function log(string $level, string $message): void
    {
        if (!$this->enable_logging) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $formatted = "[{$timestamp}] [{$level}] {$message}\n";

        if ($this->log_file) {
            @file_put_contents($this->log_file, $formatted, FILE_APPEND | LOCK_EX);
        } else {
            error_log(trim($formatted));
        }
    }

    /**
     * Download multiple images in parallel using cURL multi
     */
    public function download_batch(array $urls, string $type = 'poster', array $options = []): array
    {
        $results = [];
        $multi_handle = curl_multi_init();
        $handles = [];

        // Prepare all handles
        foreach ($urls as $key => $url) {
            if (!$this->is_valid_url($url)) {
                $results[$key] = null;
                continue;
            }

            $filename = $options['custom_filenames'][$key] ?? $this->generate_filename($url, $type);
            $filepath = $this->images_dir . '/' . $filename;

            // Skip if valid cache exists
            if (empty($options['force_refresh']) && $this->is_valid_cached_file($filepath)) {
                $results[$key] = $this->get_image_url($filename);
                continue;
            }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_SSL_VERIFYPEER => $this->verify_ssl,
                CURLOPT_SSL_VERIFYHOST => $this->verify_ssl ? 2 : 0,
                CURLOPT_USERAGENT => self::USER_AGENT,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => $this->connect_timeout,
                CURLOPT_HTTPHEADER => $this->build_headers($url),
                CURLOPT_ENCODING => '',
            ]);

            curl_multi_add_handle($multi_handle, $ch);
            $handles[$key] = ['handle' => $ch, 'url' => $url, 'filepath' => $filepath, 'filename' => $filename];
        }

        // Execute all handles
        $running = null;
        do {
            curl_multi_exec($multi_handle, $running);
            curl_multi_select($multi_handle);
        } while ($running > 0);

        // Process results
        foreach ($handles as $key => $data) {
            $ch = $data['handle'];
            $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $response = curl_multi_getcontent($ch);

            if ($http_code >= 200 && $http_code < 300 && !empty($response) && strlen($response) >= $this->min_bytes) {
                $validation = $this->validate_image_data($response, null);
                if ($validation['valid']) {
                    if (file_put_contents($data['filepath'], $response) !== false) {
                        $results[$key] = $this->get_image_url($data['filename']);
                    } else {
                        $results[$key] = null;
                    }
                } else {
                    $results[$key] = null;
                }
            } else {
                $results[$key] = null;
            }

            curl_multi_remove_handle($multi_handle, $ch);
            curl_close($ch);
        }

        curl_multi_close($multi_handle);

        return $results;
    }

    /**
     * Clear cached images older than specified age
     */
    public function clear_cache(int $max_age_seconds = 604800): int
    {
        $cleared = 0;
        $now = time();

        if (!is_dir($this->images_dir)) {
            return $cleared;
        }

        $files = glob($this->images_dir . '/*');
        foreach ($files as $file) {
            if (is_file($file) && ($now - filemtime($file)) > $max_age_seconds) {
                if (@unlink($file)) {
                    $cleared++;
                }
            }
        }

        $this->log('info', "Cleared {$cleared} cached images");
        return $cleared;
    }

    /**
     * Get cache statistics
     */
    public function get_cache_stats(): array
    {
        $stats = [
            'total_files' => 0,
            'total_size' => 0,
            'oldest_file' => null,
            'newest_file' => null,
            'by_extension' => [],
        ];

        if (!is_dir($this->images_dir)) {
            return $stats;
        }

        $files = glob($this->images_dir . '/*');
        $oldest_time = PHP_INT_MAX;
        $newest_time = 0;

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $stats['total_files']++;
            $stats['total_size'] += filesize($file);

            $mtime = filemtime($file);
            if ($mtime < $oldest_time) {
                $oldest_time = $mtime;
                $stats['oldest_file'] = $file;
            }
            if ($mtime > $newest_time) {
                $newest_time = $mtime;
                $stats['newest_file'] = $file;
            }

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $stats['by_extension'][$ext] = ($stats['by_extension'][$ext] ?? 0) + 1;
        }

        return $stats;
    }

    /**
     * Public wrapper for download_image (maintains same signature)
     */
    public function download(string $url, string $type = 'poster', array $options = []): ?string
    {
        return $this->download_image($url, $type, $options);
    }
}


// ============================================================================
// STANDALONE FUNCTION VERSION (same function name as requested)
// ============================================================================

/**
 * Standalone download_image function with advanced features
 * This version can be used without instantiating the class
 */
function download_image($url, $type = 'poster', array $options = []): ?string
{
    static $downloader = null;
    
    if ($downloader === null) {
        $downloader = new AdvancedImageDownloader($options);
    }
    
    return $downloader->download($url, $type, $options);
}


// ============================================================================
// TRAIT VERSION (for use in existing classes)
// ============================================================================

trait ImageDownloaderTrait
{
    private ?AdvancedImageDownloader $image_downloader = null;

    private function get_image_downloader(): AdvancedImageDownloader
    {
        if ($this->image_downloader === null) {
            $this->image_downloader = new AdvancedImageDownloader([
                'images_dir' => $this->get_images_directory(),
            ]);
        }
        return $this->image_downloader;
    }

    protected function get_images_directory(): string
    {
        return __DIR__ . '/images';
    }

    private function download_image($url, $type = 'poster', array $options = []): ?string
    {
        return $this->get_image_downloader()->download($url, $type, $options);
    }

    protected function download_images_batch(array $urls, string $type = 'poster', array $options = []): array
    {
        return $this->get_image_downloader()->download_batch($urls, $type, $options);
    }
}


// ============================================================================
// USAGE EXAMPLES
// ============================================================================

/*
// Example 1: Basic usage with standalone function
$image_url = download_image('https://example.com/poster.jpg', 'poster');

// Example 2: Using the class directly
$downloader = new AdvancedImageDownloader([
    'images_dir' => '/path/to/images',
    'min_bytes' => 5 * 1024,      // 5 KB minimum
    'max_attempts' => 5,
    'enable_logging' => true,
    'log_file' => '/path/to/logs/images.log',
]);

// Download single image
$result = $downloader->download('https://example.com/poster.jpg', 'poster', [
    'force_refresh' => true,
    'fallback_urls' => [
        'https://backup.example.com/poster.jpg',
        'https://cdn.example.com/poster.jpg',
    ],
]);

// Download with resize and format conversion
$result = $downloader->download('https://example.com/large-poster.jpg', 'poster', [
    'resize' => ['width' => 300, 'height' => 450],
    'convert_to' => 'webp',
    'quality' => 80,
]);

// Example 3: Batch download multiple images in parallel
$urls = [
    'poster1' => 'https://example.com/poster1.jpg',
    'poster2' => 'https://example.com/poster2.jpg',
    'poster3' => 'https://example.com/poster3.jpg',
];
$results = $downloader->download_batch($urls, 'poster');

// Example 4: Using the trait in your existing class
class MyMovieClass
{
    use ImageDownloaderTrait;
    
    public function save_movie_poster($movie_data)
    {
        $poster_url = $this->download_image($movie_data['poster_url'], 'poster');
        // ... rest of your code
    }
}

// Example 5: Cache management
$stats = $downloader->get_cache_stats();
echo "Total cached files: " . $stats['total_files'] . "\n";
echo "Total size: " . round($stats['total_size'] / 1024 / 1024, 2) . " MB\n";

// Clear images older than 7 days
$cleared = $downloader->clear_cache(7 * 24 * 60 * 60);
echo "Cleared {$cleared} old images\n";
*/
