<?php
/**
 * Snippet: drop this method into your existing class.
 *
 * Requirements implemented:
 * - Retry download if invalid / too small.
 * - Verify it's an actual image AND size > 10KB.
 * - Only replace the existing file after a verified successful download;
 *   otherwise keep the old one.
 */
private function download_image($url, $type = 'poster')
{
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }

    // إنشاء مجلد للصور
    $images_dir = __DIR__ . '/images';
    if (!is_dir($images_dir)) {
        if (!@mkdir($images_dir, 0755, true) && !is_dir($images_dir)) {
            return null;
        }
    }

    // الحصول على امتداد الملف
    $parsed_url = parse_url($url);
    $path = isset($parsed_url['path']) ? $parsed_url['path'] : '';
    $extension = pathinfo($path, PATHINFO_EXTENSION);

    // إذا لم يكن هناك امتداد، نستخدم jpg كافتراضي
    if (empty($extension)) {
        $extension = 'jpg';
    }

    // تنظيف الامتداد
    $extension = strtolower($extension);
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    if (!in_array($extension, $allowed_extensions, true)) {
        $extension = 'jpg';
    }

    // إنشاء اسم ملف فريد
    $filename = md5($url) . '.' . $extension;
    $filepath = $images_dir . '/' . $filename;

    $min_bytes = 10 * 1024; // 10 KB
    $max_attempts = 4;

    // إذا كانت الصورة موجودة بالفعل وبحجم كافي، نرجع URL
    if (is_file($filepath) && filesize($filepath) >= $min_bytes) {
        return $this->get_image_url($filename);
    }

    $last_good_existing = (is_file($filepath) && filesize($filepath) >= $min_bytes);

    for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
        // تحميل الصورة
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, self::user_agent);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FAILONERROR, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: image/avif,image/webp,image/png,image/jpeg,image/gif,image/*;q=0.9,*/*;q=0.8',
        ]);

        $image_data = curl_exec($ch);
        $curl_errno = curl_errno($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // تحقق أساسي: نجاح التحميل + بيانات غير فارغة + حجم أكبر من 10KB
        if ($curl_errno !== 0 || $http_code < 200 || $http_code >= 300 || empty($image_data)) {
            if ($attempt < $max_attempts) {
                usleep(250000 * $attempt); // backoff بسيط
                continue;
            }
            break;
        }

        if (strlen($image_data) < $min_bytes) {
            if ($attempt < $max_attempts) {
                usleep(250000 * $attempt);
                continue;
            }
            break;
        }

        // التحقق من أن البيانات هي صورة صحيحة
        $image_info = @getimagesizefromstring($image_data);
        if ($image_info === false) {
            if ($attempt < $max_attempts) {
                usleep(250000 * $attempt);
                continue;
            }
            break;
        }

        // حفظ إلى ملف مؤقت أولاً (حتى لا نخرب الملف القديم)
        $tmp_path = $filepath . '.tmp_' . str_replace('.', '', uniqid('', true));
        if (file_put_contents($tmp_path, $image_data) === false) {
            @unlink($tmp_path);
            if ($attempt < $max_attempts) {
                usleep(250000 * $attempt);
                continue;
            }
            break;
        }

        // تأكيد الحجم على القرص (أحيانًا تكون strlen مختلفة مع ترميزات/محتوى)
        if (!is_file($tmp_path) || filesize($tmp_path) < $min_bytes) {
            @unlink($tmp_path);
            if ($attempt < $max_attempts) {
                usleep(250000 * $attempt);
                continue;
            }
            break;
        }

        // استبدال آمن: نعمل backup للقديم ثم نستبدله بالجديد
        $backup_path = null;
        if (is_file($filepath)) {
            $backup_path = $filepath . '.bak';
            @unlink($backup_path);
            if (!@rename($filepath, $backup_path)) {
                // إذا فشل rename، نحاول copy كحل احتياطي
                if (!@copy($filepath, $backup_path)) {
                    $backup_path = null;
                }
            }
        }

        if (@rename($tmp_path, $filepath)) {
            if ($backup_path !== null && is_file($backup_path)) {
                @unlink($backup_path);
            }
            return $this->get_image_url($filename);
        }

        // فشل الاستبدال: نحذف المؤقت ونرجع القديم إن كان لدينا backup
        @unlink($tmp_path);
        if ($backup_path !== null && is_file($backup_path) && !is_file($filepath)) {
            @rename($backup_path, $filepath);
        }

        if ($attempt < $max_attempts) {
            usleep(250000 * $attempt);
        }
    }

    // إذا فشل التحميل لكن لدينا ملف قديم صالح، نرجعه بدل null
    if ($last_good_existing) {
        return $this->get_image_url($filename);
    }

    return null;
}

