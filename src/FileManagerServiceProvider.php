<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager;

use Kirantimsina\FileManager\Commands\PopulateMediaMetadataCommand;
use Kirantimsina\FileManager\Commands\TestCompressionApiCommand;
use Kirantimsina\FileManager\Commands\UpdateImageCacheHeadersCommand;
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
            ->hasMigration('2025_01_09_000001_create_media_metadata_table')
            ->hasCommands([
                PopulateMediaMetadataCommand::class,
                TestCompressionApiCommand::class,
                UpdateImageCacheHeadersCommand::class,
            ]);
    }
    
    public function packageBooted(): void
    {
        // Register Livewire components
        Livewire::component('kirantimsina.file-manager.filament.resources.media-metadata-resource.pages.image-processor', ImageProcessor::class);
    }
}
