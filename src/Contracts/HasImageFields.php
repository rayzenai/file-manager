<?php

namespace Kirantimsina\FileManager\Contracts;

interface HasImageFields
{
    /**
     * Check if the model has image trait fields.
     */
    public function hasImagesTraitFields(): array;
}
