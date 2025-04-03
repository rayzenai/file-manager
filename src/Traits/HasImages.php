<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Traits;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Kirantimsina\FileManager\Facades\FileManager;
use Kirantimsina\FileManager\FileManagerService;
use Kirantimsina\FileManager\Jobs\DeleteImages;
use Kirantimsina\FileManager\Jobs\ResizeImages;

trait HasImages
{
    // TODO: this does not yet work for images of type array / json

    protected static function bootHasImages()
    {

        self::creating(function (self $model) {
            if (! method_exists($model, 'hasImagesTraitFields')) {
                throw new Exception('You must define a `hasImagesTraitFields` method in your model.');
            }

            $fieldsToWatch = $model->hasImagesTraitFields();
            foreach ($fieldsToWatch as $field) {

                if (static::shouldExcludeConversion($model, $field)) {
                    continue;
                }

                if ($model->{$field}) {
                    $newFilename = $model->{$field};
                    if (is_array($newFilename)) {
                        foreach ($newFilename as $filename) {
                            ResizeImages::dispatch([$filename]);
                        }
                    } else {
                        ResizeImages::dispatch([$newFilename]);
                    }
                }
            }
        });

        self::updating(function (self $model) {
            if (! method_exists($model, 'hasImagesTraitFields')) {
                throw new Exception('You must define a `hasImagesTraitFields` method in your model.');
            }

            $fieldsToWatch = $model->hasImagesTraitFields();

            foreach ($fieldsToWatch as $field) {
                // TODO: Auto Deleting non-images is no longer working since we are skipping the whole process here
                if (static::shouldExcludeConversion($model, $field)) {
                    continue;
                }

                if ($model->isDirty($field)) {
                    $oldFilename = $model->getOriginal($field);
                    $newFilename = $model->{$field};

                    // Handle resizing new images
                    if (is_array($newFilename)) {
                        foreach ($newFilename as $filename) {
                            ResizeImages::dispatch([$filename]);
                        }
                    } elseif ($newFilename) {
                        ResizeImages::dispatch([$newFilename]);
                    }

                    // Handle deleting old images
                    if (is_array($oldFilename)) {
                        $toDelete = array_diff($oldFilename, (array) $newFilename);
                        foreach ($toDelete as $filename) {
                            DeleteImages::dispatch([$filename]);
                        }
                    } elseif ($oldFilename && $oldFilename !== $newFilename) {
                        // Only delete if the old file is different from the new one
                        DeleteImages::dispatch([$oldFilename]);
                    }
                }
            }
        });

    }

    public function viewPageUrl(string $field = 'file', string $counter = ''): ?string
    {
        $modelKey = class_basename($this);

        $modelAlias = config("file-manager.model.{$modelKey}", Str::plural(Str::lower($modelKey)));

        if ($this->{$field}) {
            // Generate a URL matching your route: /media-page/{model}/{slug}
            if ($counter) {
                return route('media.page', [
                    'directory' => $modelAlias,
                    'slug' => $this->slug,
                    'counter' => $counter,
                ]);
            }

            return route('media.page', [
                'directory' => $modelAlias,
                'slug' => $this->slug,
            ]);

        }

        return null;
    }

    public function uploadFromUrl(string $url, Model $record, string $field): string
    {
        $content = Http::get($url)->body();

        $tempFileName = uniqid() . '_' . basename($url);

        $tempFilePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $tempFileName;

        File::put($tempFilePath, $content);

        $mimeType = mime_content_type($tempFilePath) ?: 'application/octet-stream';

        // Create the UploadedFile instance
        $uploadedFile = new UploadedFile(
            $tempFilePath,
            basename($tempFilePath),
            $mimeType,
            null,
            true
        );

        $model = class_basename($record);

        $upload = FileManagerService::upload(
            model: $model,
            file: $uploadedFile,
            tag: isset($record->name) ? $record->name : null,
        );

        if ($upload['status']) {
            $record->update([
                $field => $upload['file'],
            ]);
        }

        return $upload['file'];
    }

    private static function shouldExcludeConversion(Model $model, string $field)
    {
        $filenames = $model->{$field};

        if (empty($filesname)) {
            return false;
        }
        
        if (is_string($model->{$field})) {
            $filenames = [$model->{$field}];
        }

        foreach ($filenames as $filename) {
            $ext = FileManager::getExtensionFromName($filename);

            return in_array($ext, [
                'mp4',
                'mpeg',
                'mov',
                'avi',
                'webm',
            ]);
        }

    }
}
