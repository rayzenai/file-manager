<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Filament\Resources\MediaMetadataResource\Pages;

use Filament\Resources\Pages\ManageRecords;
use Kirantimsina\FileManager\Filament\Resources\MediaMetadataResource;

class ManageMediaMetadata extends ManageRecords
{
    protected static string $resource = MediaMetadataResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // No actions needed as this is view-only
        ];
    }
}