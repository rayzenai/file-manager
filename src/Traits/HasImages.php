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
                    ResizeImages::dispatch([$newFilename]);
                }
            }
        });

        self::updating(function (self $model) {
            $fieldsToWatch = $model->hasImagesTraitFields();
            foreach ($fieldsToWatch as $field) {
                if ($model->isDirty($field)) {
                    $oldFilename = $model->getOriginal($field);
                    $newFilename = $model->{$field};
                    ResizeImages::dispatch([$newFilename]);
                    if ($oldFilename) {
                        // Fire Delete images job only if oldFilename exists
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
