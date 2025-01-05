<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Traits;

use Kirantimsina\FileManager\Jobs\DeleteImages;
use Kirantimsina\FileManager\Jobs\ResizeImages;

trait HasImages
{
    // TODO: this does not yet work for images of type array / json

    protected static function bootHasImages()
    {
        self::creating(function (self $model) {
            $fieldsToWatch = $model->hasImagesTraitFields();
            foreach ($fieldsToWatch as $field) {
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
            $fieldsToWatch = $model->hasImagesTraitFields();

            foreach ($fieldsToWatch as $field) {
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

    public function getViewRoute($field)
    {
        return route('media.page', $field);
    }
}
