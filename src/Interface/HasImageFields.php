<?php

namespace Kirantimsina\FileManager\Interfaces;

interface HasImageFields
{
    /**
     * Get the fields that should be watched for image resizing
     */
    public function hasImagesTraitFields(): array;
}
