<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Facades;

use Illuminate\Support\Facades\Facade;
use Kirantimsina\FileManager\FileManagerService;

/**
 * @method static array getImageSizes() Get configured image sizes
 * @method static array uploadImages($model, $files, $tag = null, $fit = false, $resize = true) Upload multiple images
 * @method static string filename($file, $tag = null, $ext = null) Generate SEO-friendly filename
 * @method static array uploadBase64($model, $base64Image, $tag = null, $fit = false, $resize = true) Upload base64 encoded image
 * @method static string|null getUploadDirectory($model) Get upload directory for a model
 * @method static array upload($model, $file, $tag = null, $fit = false, $resize = true, $webp = false, $reencode = false) Upload a single file
 * @method static void resizeImage($file, $fit = false) Resize an image to configured sizes
 * @method static string|null moveTempImage($model, $tempFile) Move temp image and dispatch resize job
 * @method static string|null moveTempImageWithoutResize($model, $tempFile) Move temp image without resizing
 * @method static void deleteImagesArray($arr) Delete multiple images and their sizes
 * @method static void deleteImage($filename) Delete an image and all its sizes
 * @method static array uploadTempVideo($file) Upload video to temp directory
 * @method static bool moveTempVideo($filename, $to) Move video from temp to final location
 * @method static string mainMediaUrl() Get the main media URL with trailing slash
 * @method static string|null getMediaPath($filename = null, $size = null) Get the full media path for a file with optional size
 * @method static string getExtensionFromName(string $filename) Get file extension from filename
 *
 * @see FileManagerService
 */
class FileManager extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FileManagerService::class;
    }
}
