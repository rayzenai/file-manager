<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager;

use Kirantimsina\FileManager\Commands\CompressVideosCommand;
use Kirantimsina\FileManager\Commands\ManageMediaSizesCommand;
use Kirantimsina\FileManager\Commands\PopulateMediaMetadataCommand;
use Kirantimsina\FileManager\Commands\PopulateSeoTitlesCommand;
use Kirantimsina\FileManager\Commands\RefreshAllMediaCommand;
use Kirantimsina\FileManager\Commands\RemoveDuplicateMediaMetadataCommand;
use Kirantimsina\FileManager\Commands\UpdateImageCacheHeadersCommand;
use Kirantimsina\FileManager\Commands\UpdateSeoTitlesCommand;
use Kirantimsina\FileManager\Filament\Resources\MediaMetadataResource\Pages\ImageProcessor;
use Livewire\Livewire;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FileManagerServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('file-manager')
            ->hasConfigFile()
            ->hasRoute('web')
            ->hasViews()
            ->hasMigrations([
                '2025_01_09_000001_create_media_metadata_table',
                '2025_01_09_000002_add_seo_title_to_media_metadata',
            ])
            ->hasCommands([
                CompressVideosCommand::class,
                ManageMediaSizesCommand::class,
                PopulateMediaMetadataCommand::class,
                PopulateSeoTitlesCommand::class,
                RefreshAllMediaCommand::class,
                RemoveDuplicateMediaMetadataCommand::class,
                UpdateImageCacheHeadersCommand::class,
                UpdateSeoTitlesCommand::class,
            ]);
    }

    public function packageBooted(): void
    {
        // Register Livewire components
        Livewire::component('kirantimsina.file-manager.filament.resources.media-metadata-resource.pages.image-processor', ImageProcessor::class);
    }
}
