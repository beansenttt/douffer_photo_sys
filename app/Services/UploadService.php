<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Validates and stores uploaded images (GCash screenshots, chat pictures).
 *
 * Checks: upload error codes, size, real MIME type (finfo, not the file name),
 * a readable image header and sane dimensions. Files get random names and live
 * in storage/ (outside the web root). With the GD extension the image is
 * re-encoded as JPEG (strips EXIF/GPS metadata) and a small thumbnail is made.
 */
final class UploadService
{
    public const MAX_BYTES = 4 * 1024 * 1024;
    public const CHAT_MAX_BYTES = 2 * 1024 * 1024;
    private const MAX_PIXELS = 40_000_000;
    private const MAX_SIDE = 1600;
    private const THUMB_SIDE = 320;
    private const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * @param array  $file     one entry of $_FILES
     * @param string $subdir   folder inside storage/ (e.g. 'receipts', 'chat')
     * @param bool   $trusted  tests only: skip is_uploaded_file()
     * @param int    $maxBytes maximum allowed file size in bytes
     * @return array{0:string,1:?string} [relative path, relative thumbnail path or null]
     */
    public static function saveImage(array $file, string $subdir, bool $trusted = false, int $maxBytes = self::MAX_BYTES): array
    {
        $tmp = self::validate($file, $trusted, $maxBytes);
        $info = getimagesize($tmp);
        $mime = (string) $info['mime'];

        $dir = storage_path($subdir);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new UploadException('The server could not save your file. Please try again.');
        }
        $name = bin2hex(random_bytes(16));

        if (self::gdAvailable($mime)) {
            $img = self::load($tmp, $mime);
            if ($img !== null) {
                $path = "$subdir/$name.jpg";
                $thumb = "$subdir/$name.t.jpg";
                self::writeJpeg($img, self::MAX_SIDE, storage_path($path), 82);
                self::writeJpeg($img, self::THUMB_SIDE, storage_path($thumb), 70);
                imagedestroy($img);
                return [$path, $thumb];
            }
        }

        // No GD: keep the validated original under a random name.
        $path = "$subdir/$name." . self::TYPES[$mime];
        $ok = $trusted ? copy($tmp, storage_path($path)) : move_uploaded_file($tmp, storage_path($path));
        if (!$ok) {
            throw new UploadException('The server could not save your file. Please try again.');
        }
        return [$path, null];
    }

    /** Save an optional chat image attachment (JPG/PNG/WebP, max 2 MB per §8.1). */
    public static function saveChatImage(array $file, bool $trusted = false): array
    {
        return self::saveImage($file, 'chat', $trusted, self::CHAT_MAX_BYTES);
    }

    /** Remove stored files (used if a later step fails). */
    public static function delete(?string ...$relPaths): void
    {
        foreach ($relPaths as $p) {
            if ($p && preg_match('#^[a-z]+/[A-Za-z0-9._-]+$#', $p) && is_file(storage_path($p))) {
                @unlink(storage_path($p));
            }
        }
    }

    public static function mimeForPath(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png'   => 'image/png',
            'webp'  => 'image/webp',
            default => 'image/jpeg',
        };
    }

    /** Returns the temp file path or throws UploadException with a friendly message. */
    private static function validate(array $file, bool $trusted, int $maxBytes = self::MAX_BYTES): string
    {
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE) {
            throw new UploadException('Please choose a screenshot to upload.');
        }
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw new UploadException('That picture is too large. Please choose a smaller one.');
        }
        if ($err !== UPLOAD_ERR_OK) {
            throw new UploadException('The upload did not finish. Please try again.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || (!$trusted && !is_uploaded_file($tmp))) {
            throw new UploadException('The upload was not valid. Please try again.');
        }
        if ((int) ($file['size'] ?? 0) > $maxBytes || filesize($tmp) > $maxBytes) {
            $limitMb = max(1, (int) round($maxBytes / (1024 * 1024)));
            throw new UploadException("That picture is too large (limit {$limitMb} MB). Please choose a smaller one.");
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset(self::TYPES[$mime])) {
            throw new UploadException('Please upload a JPG, PNG or WebP picture.');
        }
        $info = @getimagesize($tmp);
        if ($info === false || ($info['mime'] ?? '') !== $mime) {
            throw new UploadException('That file is not a valid picture.');
        }
        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            throw new UploadException('That picture is too large. Please choose a smaller one.');
        }
        return $tmp;
    }

    private static function gdAvailable(string $mime): bool
    {
        if (!extension_loaded('gd')) {
            return false;
        }
        return match ($mime) {
            'image/jpeg' => function_exists('imagecreatefromjpeg'),
            'image/png'  => function_exists('imagecreatefrompng'),
            'image/webp' => function_exists('imagecreatefromwebp'),
            default      => false,
        };
    }

    private static function load(string $file, string $mime): ?\GdImage
    {
        $img = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($file),
            'image/png'  => @imagecreatefrompng($file),
            'image/webp' => @imagecreatefromwebp($file),
            default      => false,
        };
        return $img instanceof \GdImage ? $img : null;
    }

    /** Scale down to fit $maxSide, flatten transparency onto white, save as JPEG. */
    private static function writeJpeg(\GdImage $src, int $maxSide, string $dest, int $quality): void
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1.0, $maxSide / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $out = imagecreatetruecolor($nw, $nh);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagejpeg($out, $dest, $quality);
        imagedestroy($out);
    }
}
