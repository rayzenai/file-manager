<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Filament\Resources\MediaMetadataResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRecords;
use Kirantimsina\FileManager\Filament\Resources\MediaMetadataResource;

class ManageMediaMetadata extends ManageRecords
{
    protected static string $resource = MediaMetadataResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('image_processor')
                ->label('Image Processor')
                ->icon('heroicon-o-photo')
                ->color('info')
                ->url(fn () => MediaMetadataResource::getUrl('image-processor'))
                ->openUrlInNewTab(false),
        ];
    }
}
