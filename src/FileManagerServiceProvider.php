<?php

namespace Kirantimsina\FileManager;

use Kirantimsina\FileManager\Commands\FileManagerCommand;
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
            ->hasViews()
            ->hasMigration('create_file_manager_table')
            ->hasCommand(FileManagerCommand::class);
    }
}
