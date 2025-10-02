<?php
/**
 * save_cover_image()
 * -------------------
 * Downloads a cover image from a URL (e.g. Steam),
 * converts it to WebP format, and saves it under /assets/img/.
 * 
 * Returns the relative image path (e.g. "assets/img/elden-ring.webp")
 * or false if something fails.
 */

function save_cover_image(string $url, string $basename): string|false {
    // Folder where images will be stored (relative to project root)
    $dir = __DIR__ . '/../assets/img/';
    
    // Create the folder if it doesn’t exist
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    // Create a clean, lowercase filename "elden-ring.webp"
    $slug = strtolower(trim($basename));
    $slug = preg_replace('/[^a-z0-9\-]+/i', '-', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = trim($slug, '-');
    $filename = $slug . '.webp';

    // Download image data from the URL
    $data = @file_get_contents($url);
    if ($data === false) {
        // Failed to reach the URL
        return false;
    }

    // Create a GD image resource from the downloaded data
    $src = @imagecreatefromstring($data);
    if (!$src) {
        // Invalid image or unsupported format
        return false;
    }

    // Save as WebP with quality 90
    $path = $dir . $filename;
    imagewebp($src, $path, 90);

    // Free up memory
    imagedestroy($src);

    // Return relative path for DB use or HTML display
    return 'assets/img/' . $filename;
}
