<?php

class ImageDownloader
{
    const user_agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    
    // Minimum file size in bytes (10 KB)
    const MIN_FILE_SIZE = 10240;
    
    // Maximum retry attempts
    const MAX_RETRIES = 3;
    
    // Delay between retries in seconds
    const RETRY_DELAY = 2;

    /**
     * Download image with verification and retry mechanism
     * 
     * @param string $url The image URL to download
     * @param string $type The type of image (poster, backdrop, etc.)
     * @return string|null Returns the local image URL on success, null on failure
     */
    private function download_image($url, $type = 'poster')
    {
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        
        // إنشاء مجلد للصور
        $images_dir = __DIR__ . '/images';
        if (!is_dir($images_dir)) {
            mkdir($images_dir, 0755, true);
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
        if (!in_array($extension, $allowed_extensions)) {
            $extension = 'jpg';
        }
        
        // إنشاء اسم ملف فريد
        $filename = md5($url) . '.' . $extension;
        $filepath = $images_dir . '/' . $filename;
        
        // التحقق من وجود الصورة وحجمها
        if (file_exists($filepath)) {
            $existing_size = filesize($filepath);
            // إذا كانت الصورة موجودة وحجمها أكبر من 10 كيلوبايت، نرجع URL
            if ($existing_size >= self::MIN_FILE_SIZE) {
                return $this->get_image_url($filename);
            }
            // إذا كان الحجم أقل من 10 كيلوبايت، نحذف الملف ونعيد التحميل
            unlink($filepath);
        }
        
        // محاولة تحميل الصورة مع إعادة المحاولة
        $downloaded_successfully = false;
        $attempt = 0;
        
        while (!$downloaded_successfully && $attempt < self::MAX_RETRIES) {
            $attempt++;
            
            // تأخير بين المحاولات (ليس في المحاولة الأولى)
            if ($attempt > 1) {
                sleep(self::RETRY_DELAY);
            }
            
            // تحميل الصورة
            $image_data = $this->fetch_image_data($url);
            
            if ($image_data === null) {
                continue;
            }
            
            // التحقق من حجم البيانات (أكبر من 10 كيلوبايت)
            if (strlen($image_data) < self::MIN_FILE_SIZE) {
                continue;
            }
            
            // التحقق من أن البيانات هي صورة صحيحة
            $image_info = @getimagesizefromstring($image_data);
            if ($image_info === false) {
                continue;
            }
            
            // حفظ الصورة في ملف مؤقت أولاً
            $temp_filepath = $filepath . '.tmp';
            if (!file_put_contents($temp_filepath, $image_data)) {
                continue;
            }
            
            // التحقق النهائي من حجم الملف المحفوظ
            $saved_size = filesize($temp_filepath);
            if ($saved_size < self::MIN_FILE_SIZE) {
                unlink($temp_filepath);
                continue;
            }
            
            // التحقق من أن الملف المحفوظ هو صورة صالحة
            $saved_image_info = @getimagesize($temp_filepath);
            if ($saved_image_info === false) {
                unlink($temp_filepath);
                continue;
            }
            
            // نقل الملف المؤقت إلى الملف النهائي
            if (rename($temp_filepath, $filepath)) {
                $downloaded_successfully = true;
            } else {
                // محاولة النسخ بدلاً من النقل
                if (copy($temp_filepath, $filepath)) {
                    unlink($temp_filepath);
                    $downloaded_successfully = true;
                } else {
                    unlink($temp_filepath);
                }
            }
        }
        
        // التحقق النهائي من نجاح التحميل
        if ($downloaded_successfully && file_exists($filepath)) {
            $final_size = filesize($filepath);
            if ($final_size >= self::MIN_FILE_SIZE) {
                return $this->get_image_url($filename);
            } else {
                // حذف الملف إذا كان الحجم غير صالح
                unlink($filepath);
            }
        }
        
        return null;
    }
    
    /**
     * Fetch image data from URL using cURL
     * 
     * @param string $url The image URL
     * @return string|null Returns image data on success, null on failure
     */
    private function fetch_image_data($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, self::user_agent);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60); // زيادة المهلة إلى 60 ثانية
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: image/webp,image/apng,image/*,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9,ar;q=0.8',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
        ]);
        
        // تمكين الضغط
        curl_setopt($ch, CURLOPT_ENCODING, '');
        
        $image_data = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        // التحقق من نجاح التحميل
        if ($http_code === 200 && !empty($image_data) && empty($curl_error)) {
            return $image_data;
        }
        
        return null;
    }
    
    /**
     * Get the public URL for an image
     * 
     * @param string $filename The image filename
     * @return string The public URL
     */
    private function get_image_url($filename)
    {
        // يمكنك تعديل هذا حسب إعدادات موقعك
        $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http');
        $base_url .= '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $script_dir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        
        return rtrim($base_url . $script_dir, '/') . '/images/' . $filename;
    }
}
