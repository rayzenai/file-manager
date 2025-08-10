<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager;

use Kirantimsina\FileManager\Commands\PopulateMediaMetadataCommand;
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
            ->hasCommand(PopulateMediaMetadataCommand::class);
    }
}
