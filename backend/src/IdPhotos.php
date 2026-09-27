<?php
// Student ID photos. Checked by content (not by file name), limited to 8 MB,
// re-drawn as a fresh JPEG on the server (which strips anything hidden in the
// file), and stored IN the database (table student_id_photos), so they are in
// every backup. There is no web address for them: the admin shows them only
// after sign-in (admin/photo.php).

declare(strict_types=1);

namespace Ismile;

final class IdPhotos
{
    public const MAX_BYTES = 8 * 1024 * 1024;
    private const TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    private const MAX_SIDE = 2000;

    /**
     * Takes one entry of $_FILES, stores the checked photo in the database and
     * returns its id. Throws UserError with a translation key the form understands.
     */
    public static function store(?array $upload): int
    {
        if (!$upload || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new UserError('err_student_id');
        }
        if (in_array($upload['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new UserError('err_student_id_size');
        }
        if ($upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $upload['tmp_name'])) {
            throw new UserError('err_student_id');
        }
        $path = (string) $upload['tmp_name'];
        if (filesize($path) > self::MAX_BYTES) {
            throw new UserError('err_student_id_size');
        }

        $type = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $size = @getimagesize($path);
        if (!in_array($type, self::TYPES, true) || $size === false || !in_array($size['mime'], self::TYPES, true)) {
            throw new UserError('err_student_id_type');
        }
        if ($size[0] < 50 || $size[1] < 50 || $size[0] * $size[1] > 16_000_000) {
            throw new UserError('err_student_id_type');
        }

        $image = match ($size['mime']) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png'  => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
        };
        if (!$image) {
            throw new UserError('err_student_id_type');
        }
        if ($size['mime'] === 'image/jpeg') {
            $image = self::applyOrientation($image, $path);
        }

        // Shrink very large photos; an ID card stays readable at 2000 px.
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, self::MAX_SIDE / max($width, $height));
        $canvas = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));   // transparent PNGs get a white ground
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, imagesx($canvas), imagesy($canvas), $width, $height);
        imagedestroy($image);

        ob_start();
        $written = imagejpeg($canvas, null, 85);
        $jpeg = (string) ob_get_clean();
        $finalWidth = imagesx($canvas);
        $finalHeight = imagesy($canvas);
        imagedestroy($canvas);
        if (!$written || $jpeg === '') {
            throw new \RuntimeException('The ID photo could not be saved.');
        }
        return Db::insert('student_id_photos', [
            'image'         => $jpeg,
            'mime'          => 'image/jpeg',
            'width'         => $finalWidth,
            'height'        => $finalHeight,
            'bytes'         => strlen($jpeg),
            'sha256'        => hash('sha256', $jpeg),
            'original_name' => mb_substr(basename((string) ($upload['name'] ?? '')), 0, 190) ?: null,
            'uploaded_at'   => App::now(),
            'uploaded_ip'   => App::clientIp(),
        ]);
    }

    /** One photo with its image, or null. */
    public static function find(int $id): ?array
    {
        return $id > 0 ? Db::one('SELECT * FROM student_id_photos WHERE id = ?', [$id]) : null;
    }

    /** Facts about a photo without loading the image itself. */
    public static function info(int $id): ?array
    {
        return $id > 0 ? Db::one('SELECT id, width, height, bytes, sha256, original_name, uploaded_at FROM student_id_photos WHERE id = ?', [$id]) : null;
    }

    public static function delete(?int $id): void
    {
        if ($id) {
            Db::run('DELETE FROM student_id_photos WHERE id = ?', [$id]);
        }
    }

    /** Phones store "which way is up" separately; draw the photo the right way round. */
    private static function applyOrientation(\GdImage $image, string $path): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($path);
        $turn = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($turn === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $turn, 0);
        if ($rotated === false) {
            return $image;
        }
        imagedestroy($image);
        return $rotated;
    }
}
