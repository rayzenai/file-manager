<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Kirantimsina\FileManager\Filament\Resources\MediaMetadataResource;

class FileManagerPlugin implements Plugin
{
    protected bool $hasMediaMetadataResource = true;

    public function getId(): string
    {
        return 'file-manager';
    }

    public function register(Panel $panel): void
    {
        if ($this->hasMediaMetadataResource) {
            $panel->resources([
                MediaMetadataResource::class,
            ]);
        }
    }

    public function boot(Panel $panel): void
    {
        //
    }

    public static function make(): static
    {
        return new static();
    }

    public function mediaMetadataResource(bool $condition = true): static
    {
        $this->hasMediaMetadataResource = $condition;

        return $this;
    }
}