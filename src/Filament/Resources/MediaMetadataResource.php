<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select as FormSelect;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle as FormToggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Kirantimsina\FileManager\Filament\Resources\MediaMetadataResource\Pages;
use Kirantimsina\FileManager\Jobs\ResizeImages;
use Kirantimsina\FileManager\Models\MediaMetadata;
use Kirantimsina\FileManager\Services\ImageCompressionService;

class MediaMetadataResource extends Resource
{
    protected static ?string $model = MediaMetadata::class;

    protected static ?string $pluralLabel = 'Media Metadata';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationLabel = 'Media Metadata';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 100;

    public static function getNavigationBadge(): ?string
    {
        $count = static::getLargeFilesCount();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $count = static::getLargeFilesCount();

        return match (true) {
            $count > 100 => 'danger',
            $count > 50 => 'warning',
            $count > 0 => 'info',
            default => null,
        };
    }

    /**
     * Get the count of large files (>500KB) with caching
     */
    protected static function getLargeFilesCount(): int
    {
        return (int) Cache::remember(
            'media_metadata_large_files_count',
            5 * 60, // Cache for 5 minutes
            fn () => MediaMetadata::where('file_size', '>', 500 * 1024)->count()
        );
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('mediable_type')
                    ->required()
                    ->disabled(),
                TextInput::make('mediable_id')
                    ->required()
                    ->numeric()
                    ->disabled(),
                TextInput::make('mediable_field')
                    ->required()
                    ->disabled(),
                TextInput::make('file_name')
                    ->required()
                    ->disabled(),
                TextInput::make('file_size')
                    ->required()
                    ->numeric()
                    ->disabled(),
                TextInput::make('mime_type')
                    ->disabled(),
                TextInput::make('seo_title')
                    ->label('SEO Title')
                    ->maxLength(160)
                    ->helperText('SEO-friendly title for search engines (recommended 50-160 chars)'),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('mediable_type')
                    ->label('Model Type')
                    ->formatStateUsing(fn (string $state): string => class_basename($state)),
                TextEntry::make('mediable_id')
                    ->label('Model ID')
                    ->numeric(),
                TextEntry::make('mediable_field')
                    ->label('Field'),
                TextEntry::make('file_name')
                    ->label('File Name')
                    ->copyable(),
                TextEntry::make('file_size')
                    ->label('File Size')
                    ->formatStateUsing(function ($state) {
                        if (! $state) {
                            return '-';
                        }

                        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
                        $bytes = max($state, 0);
                        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
                        $pow = min($pow, count($units) - 1);
                        $bytes /= pow(1024, $pow);

                        return round($bytes, 2) . ' ' . $units[$pow];
                    }),
                TextEntry::make('mime_type')
                    ->label('MIME Type')
                    ->badge()
                    ->color(fn (string $state): string => match (true) {
                        str_starts_with($state, 'image/') => 'success',
                        str_starts_with($state, 'video/') => 'warning',
                        str_starts_with($state, 'application/pdf') => 'danger',
                        default => 'gray',
                    }),
                TextEntry::make('seo_title')
                    ->label('SEO Title')
                    ->badge()
                    ->color('primary')
                    ->copyable(),
                TextEntry::make('metadata')
                    ->label('Additional Metadata')
                    ->formatStateUsing(fn ($state) => $state ? json_encode($state, JSON_PRETTY_PRINT) : '-')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->dateTime(),
                TextEntry::make('updated_at')
                    ->dateTime(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('mediable_type')
                    ->label('Model Type')
                    ->searchable()
                    ->formatStateUsing(fn (string $state): string => class_basename($state))
                    ->sortable(),
                TextColumn::make('mediable_id')
                    ->label('Model ID')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('mediable_field')
                    ->label('Field')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('file_name')
                    ->label('File Name')
                    ->searchable()
                    ->limit(50)
                    ->tooltip(fn ($state) => $state),
                TextColumn::make('seo_title')
                    ->label('SEO Title')
                    ->searchable()
                    ->sortable()
                    ->limit(60)
                    ->tooltip(fn ($state) => $state)
                    ->toggleable(),
                TextColumn::make('file_size')
                    ->label('File Size')
                    ->sortable()
                    ->formatStateUsing(function ($state) {
                        if (! $state) {
                            return '-';
                        }

                        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
                        $bytes = max($state, 0);
                        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
                        $pow = min($pow, count($units) - 1);
                        $bytes /= pow(1024, $pow);

                        return round($bytes, 2) . ' ' . $units[$pow];
                    })
                    ->alignEnd(),
                TextColumn::make('width')
                    ->label('Width')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => $state ? $state . 'px' : '-')
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('height')
                    ->label('Height')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => $state ? $state . 'px' : '-')
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('mime_type')
                    ->label('Type')
                    ->searchable()
                    ->badge()
                    ->color(fn (string $state): string => match (true) {
                        str_starts_with($state, 'image/') => 'success',
                        str_starts_with($state, 'video/') => 'warning',
                        str_starts_with($state, 'application/pdf') => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('file_size', 'desc')
            ->toolbarActions([
                BulkAction::make('bulk_compress')
                    ->label('Compress Images')
                    ->deselectRecordsAfterCompletion()
                    ->icon('heroicon-o-arrows-pointing-in')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Bulk Compress Images')
                    ->modalDescription(fn (Collection $records): string => "Compress {$records->count()} selected images")
                    ->schema([
                        FormSelect::make('format')
                            ->label('Output Format')
                            ->options([
                                'preserve' => 'Preserve Original Format',
                                'webp' => 'WebP (Best compression)',
                                'avif' => 'AVIF (Smaller files, newer format)',
                                'jpg' => 'JPEG (Universal compatibility)',
                                'png' => 'PNG (Lossless)',
                            ])
                            ->default('webp')
                            ->helperText('WebP provides excellent compression with good quality')
                            ->required(),
                        FormSelect::make('quality')
                            ->label('Compression Quality')
                            ->options([
                                '100' => 'Maximum (100%)',
                                '95' => 'Very High (95%)',
                                '85' => 'High (85%) - Recommended',
                                '75' => 'Medium (75%)',
                                '65' => 'Low (65%)',
                                '50' => 'Very Low (50%)',
                            ])
                            ->default('85')
                            ->required(),
                        FormSelect::make('compression_method')
                            ->label('Processing Method')
                            ->options(function () {
                                $options = [];
                                
                                $hasApi = !empty(config('file-manager.compression.api.url'));
                                
                                if ($hasApi) {
                                    $options['auto'] = 'Auto (Use API, fallback to GD)';
                                    $options['api'] = 'API Only (Fast compression)';
                                }
                                
                                $options['gd'] = 'GD Library Only (Local processing)';
                                
                                return $options;
                            })
                            ->default(function () {
                                $configMethod = config('file-manager.compression.method', 'api');
                                // Map config values to form values
                                return match($configMethod) {
                                    'gd' => 'gd',
                                    'api' => 'auto',  // 'api' in config means auto (with fallback)
                                    default => 'auto'
                                };
                            })
                            ->visible(fn () => !empty(config('file-manager.compression.api.url')))
                            ->helperText('Choose which compression method to use'),
                        FormToggle::make('replace_original')
                            ->label('Replace original files')
                            ->helperText('This will permanently replace the original files with compressed versions')
                            ->default(true),
                        FormToggle::make('resize_after')
                            ->label('Resize all versions after compression')
                            ->default(true),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        // Override compression method if specified
                        $originalMethod = config('file-manager.compression.method');
                        if (isset($data['compression_method']) && $data['compression_method'] !== 'auto') {
                            $method = $data['compression_method'] === 'api' ? 'api' : 'gd';
                            config(['file-manager.compression.method' => $method]);
                        }
                        
                        // Create compression service after config override
                        $compressionService = new ImageCompressionService;
                        $successCount = 0;
                        $failedCount = 0;
                        $totalSaved = 0;
                        $compressionDetails = [];
                        $failedFiles = [];

                        foreach ($records as $record) {
                            // Only process image files
                            if (! str_starts_with($record->mime_type ?? '', 'image/')) {
                                continue;
                            }

                            try {
                                $result = static::compressMediaRecord($record, $data, $compressionService);

                                if ($result['success']) {
                                    // Store compression details for the notification
                                    $originalKb = round($result['original_size'] / 1024);
                                    $compressedKb = round($result['compressed_size'] / 1024);
                                    $modelName = class_basename($record->mediable_type);
                                    $compressionDetails[] = "{$modelName} {$record->mediable_id}: {$originalKb}KB → {$compressedKb}KB";

                                    $totalSaved += $result['saved'];
                                    $successCount++;
                                } else {
                                    throw new \Exception($result['message'] ?? 'Compression failed');
                                }
                            } catch (\Exception $e) {
                                $failedCount++;
                                $modelName = class_basename($record->mediable_type);
                                $failedFiles[] = "{$modelName} {$record->mediable_id}";
                            }
                        }

                        $savedMb = round($totalSaved / (1024 * 1024), 2);

                        // Build the detailed notification body
                        $notificationBody = "Successfully compressed {$successCount} images. Saved {$savedMb} MB total.";

                        // Add compression details (limit to first 5 to avoid huge notifications)
                        if (count($compressionDetails) > 0) {
                            $notificationBody .= "\n\n**Compression Results:**\n";
                            $detailsToShow = array_slice($compressionDetails, 0, 5);
                            foreach ($detailsToShow as $detail) {
                                $notificationBody .= "• {$detail}\n";
                            }
                            if (count($compressionDetails) > 5) {
                                $remaining = count($compressionDetails) - 5;
                                $notificationBody .= "• ...and {$remaining} more\n";
                            }
                        }

                        // Add failed files info
                        if ($failedCount > 0) {
                            $notificationBody .= "\n**Failed ({$failedCount}):**\n";
                            $failedToShow = array_slice($failedFiles, 0, 3);
                            foreach ($failedToShow as $failed) {
                                $notificationBody .= "• {$failed}\n";
                            }
                            if (count($failedFiles) > 3) {
                                $remaining = count($failedFiles) - 3;
                                $notificationBody .= "• ...and {$remaining} more\n";
                            }
                        }

                        // Restore original compression method
                        config(['file-manager.compression.method' => $originalMethod]);
                        
                        Notification::make()
                            ->title('Bulk compression completed')
                            ->body($notificationBody)
                            ->success()
                            ->duration(10000) // Show for 10 seconds to allow reading details
                            ->send();
                    }),

                BulkAction::make('bulk_resize')
                    ->label('Resize Images')
                    ->deselectRecordsAfterCompletion()
                    ->icon('heroicon-o-arrows-pointing-out')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Bulk Resize Images')
                    ->modalDescription(fn (Collection $records): string => "Resize {$records->count()} selected images to all configured sizes")
                    ->action(function (Collection $records): void {
                        $imageFiles = [];
                        $count = 0;

                        foreach ($records as $record) {
                            // Only process image files
                            if (str_starts_with($record->mime_type ?? '', 'image/')) {
                                $imageFiles[] = $record->file_name;
                                $count++;
                            }
                        }

                        if ($count > 0) {
                            // Dispatch resize job for all images
                            ResizeImages::dispatch($imageFiles);

                            Notification::make()
                                ->title('Bulk resize queued')
                                ->body("{$count} images will be resized to all configured sizes.")
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('No images to resize')
                                ->body('None of the selected items are images.')
                                ->warning()
                                ->send();
                        }
                    }),

                BulkAction::make('bulk_delete_resized')
                    ->label('Delete Resized Versions')
                    ->deselectRecordsAfterCompletion()
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Delete Resized Versions')
                    ->modalDescription(fn (Collection $records): string => "Delete all resized versions for {$records->count()} selected images")
                    ->modalContent(fn () => view('file-manager::actions.delete-resized-warning'))
                    ->action(function (Collection $records): void {
                        $totalDeleted = 0;
                        $processedCount = 0;

                        foreach ($records as $record) {
                            // Only process image files
                            if (! str_starts_with($record->mime_type ?? '', 'image/')) {
                                continue;
                            }

                            try {
                                $fileName = $record->file_name;
                                $pathParts = explode('/', $fileName);
                                $name = array_pop($pathParts);
                                $directory = implode('/', $pathParts);

                                // Get configured image sizes
                                $sizes = config('file-manager.image_sizes', []);

                                foreach (array_keys($sizes) as $size) {
                                    $resizedPath = "{$directory}/{$size}/{$name}";
                                    if (Storage::disk('s3')->exists($resizedPath)) {
                                        Storage::disk('s3')->delete($resizedPath);
                                        $totalDeleted++;
                                    }
                                }
                                $processedCount++;
                            } catch (\Exception $e) {
                                // Continue with next record
                            }
                        }

                        Notification::make()
                            ->title('Resized versions deleted')
                            ->body("Deleted {$totalDeleted} resized versions from {$processedCount} images")
                            ->success()
                            ->send();
                    }),
            ])
            ->filters([
                Filter::make('large_files')
                    ->label('Large Files (>500KB)')
                    ->query(fn (Builder $query): Builder => $query->where('file_size', '>', 500 * 1024))
                    ->toggle(),
                Filter::make('very_large_files')
                    ->label('Very Large Files (>2MB)')
                    ->query(fn (Builder $query): Builder => $query->where('file_size', '>', 2 * 1024 * 1024))
                    ->toggle(),

                SelectFilter::make('mediable_type')
                    ->label('Model Type')
                    ->options(function () {
                        return MediaMetadata::query()
                            ->distinct()
                            ->pluck('mediable_type')
                            ->mapWithKeys(fn ($type) => [$type => class_basename($type)])
                            ->toArray();
                    }),
                SelectFilter::make('mime_type')
                    ->label('File Type')
                    ->options([
                        'image/webp' => 'WebP Image',
                        'image/jpeg' => 'JPEG Image',
                        'image/png' => 'PNG Image',
                        'image/gif' => 'GIF Image',
                        'application/pdf' => 'PDF Document',
                        'video/mp4' => 'MP4 Video',
                    ])
                    ->multiple(),
            ], layout: FiltersLayout::AboveContent)
            ->recordActions([
                ViewAction::make(),
                Action::make('open_in_panel')
                    ->label('Open in Panel')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->visible(function (MediaMetadata $record): bool {
                        $resources = static::findResourcesForModel($record->mediable_type);

                        return count($resources) > 0;
                    })
                    ->action(function (MediaMetadata $record) use ($table): void {
                        $resources = static::findResourcesForModel($record->mediable_type);

                        if (count($resources) === 1) {
                            // Single resource - open directly in new tab
                            $resource = $resources[0];
                            $url = null;

                            try {
                                $url = $resource::getUrl('edit', ['record' => $record->mediable_id]);
                            } catch (\Exception $e) {
                                try {
                                    $url = $resource::getUrl('view', ['record' => $record->mediable_id]);
                                } catch (\Exception $e) {
                                    $url = $resource::getUrl('index');
                                }
                            }

                            if ($url) {
                                // Use JavaScript to open in new tab
                                $table->getLivewire()->js("window.open('{$url}', '_blank')");
                            }
                        }
                    })
                    ->modalHeading('Select Resource')
                    ->modalDescription(fn (MediaMetadata $record) => "Multiple resources found for {$record->mediable_type}")
                    ->modalSubmitActionLabel('Open in New Tab')
                    ->schema(function (MediaMetadata $record): array {
                        $resources = static::findResourcesForModel($record->mediable_type);

                        if (count($resources) <= 1) {
                            return [];
                        }

                        $options = [];
                        foreach ($resources as $resource) {
                            $resourceName = class_basename($resource);
                            $panelId = null;

                            // Try to identify which panel this resource belongs to
                            try {
                                $url = $resource::getUrl('index');
                                if (str_contains($url, '/admin/')) {
                                    $panelId = 'Admin';
                                } elseif (str_contains($url, '/baker/')) {
                                    $panelId = 'Baker';
                                } elseif (str_contains($url, '/partner/')) {
                                    $panelId = 'Partner';
                                }
                            } catch (\Exception $e) {
                                // Ignore
                            }

                            $label = $resourceName;
                            if ($panelId) {
                                $label .= " ({$panelId} Panel)";
                            }

                            // Try to get the URL for this specific record
                            try {
                                $recordUrl = $resource::getUrl('edit', ['record' => $record->mediable_id]);
                            } catch (\Exception $e) {
                                try {
                                    $recordUrl = $resource::getUrl('view', ['record' => $record->mediable_id]);
                                } catch (\Exception $e) {
                                    $recordUrl = $resource::getUrl('index');
                                }
                            }

                            $options[$recordUrl] = $label;
                        }

                        return [
                            Radio::make('resource_url')
                                ->label('Select which resource to open')
                                ->options($options)
                                ->required(),
                        ];
                    })
                    ->action(function (MediaMetadata $record, array $data) use ($table): void {
                        if (! empty($data['resource_url'])) {
                            // Use JavaScript to open in new tab
                            $table->getLivewire()->js("window.open('{$data['resource_url']}', '_blank')");
                        }
                    }),
                Action::make('resize')
                    ->label('Resize')
                    ->icon('heroicon-o-arrows-pointing-out')
                    ->color('info')
                    ->visible(fn (MediaMetadata $record): bool => str_starts_with($record->mime_type ?? '', 'image/'))
                    ->modalHeading('Resize Image')
                    ->modalDescription(fn (MediaMetadata $record): string => "Resize all versions of: {$record->file_name}")
                    ->action(function (MediaMetadata $record): void {
                        try {
                            // Dispatch resize job for this image
                            ResizeImages::dispatch([$record->file_name]);

                            Notification::make()
                                ->title('Image resize queued')
                                ->body('The image will be resized to all configured sizes.')
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Resize failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->requiresConfirmation(),

                Action::make('compress')
                    ->label('Compress')
                    ->icon('heroicon-o-arrows-pointing-in')
                    ->color('warning')
                    ->visible(fn (MediaMetadata $record): bool => str_starts_with($record->mime_type ?? '', 'image/'))
                    ->modalHeading('Compress Image')
                    ->modalDescription(fn (MediaMetadata $record): string => "Compress and replace: {$record->file_name}")
                    ->schema([
                        FormSelect::make('format')
                            ->label('Output Format')
                            ->options([
                                'preserve' => 'Preserve Original Format',
                                'webp' => 'WebP (Best compression)',
                                'avif' => 'AVIF (Smaller files, newer format)',
                                'jpg' => 'JPEG (Universal compatibility)',
                                'png' => 'PNG (Lossless)',
                            ])
                            ->default('webp')
                            ->helperText('WebP provides excellent compression with good quality. AVIF provides even better compression but has limited browser support.')
                            ->required(),
                        FormSelect::make('quality')
                            ->label('Compression Quality')
                            ->options([
                                '100' => 'Maximum (100%)',
                                '95' => 'Very High (95%)',
                                '85' => 'High (85%) - Recommended',
                                '75' => 'Medium (75%)',
                                '65' => 'Low (65%)',
                                '50' => 'Very Low (50%)',
                            ])
                            ->default('85')
                            ->required(),
                        FormSelect::make('compression_method')
                            ->label('Processing Method')
                            ->options(function () {
                                $options = [];
                                
                                $hasApi = !empty(config('file-manager.compression.api.url'));
                                
                                if ($hasApi) {
                                    $options['auto'] = 'Auto (Use API, fallback to GD)';
                                    $options['api'] = 'API Only (Fast compression)';
                                }
                                
                                $options['gd'] = 'GD Library Only (Local processing)';
                                
                                return $options;
                            })
                            ->default(function () {
                                $configMethod = config('file-manager.compression.method', 'api');
                                // Map config values to form values
                                return match($configMethod) {
                                    'gd' => 'gd',
                                    'api' => 'auto',  // 'api' in config means auto (with fallback)
                                    default => 'auto'
                                };
                            })
                            ->visible(fn () => !empty(config('file-manager.compression.api.url')))
                            ->helperText('Choose which compression method to use'),
                        FormToggle::make('replace_original')
                            ->label('Replace original file')
                            ->helperText('This will permanently replace the original file with the compressed version')
                            ->default(true),
                        FormToggle::make('resize_after')
                            ->label('Resize all versions after compression')
                            ->default(true),
                    ])
                    ->action(function (MediaMetadata $record, array $data): void {
                        try {
                            // Override compression method if specified
                            $originalMethod = config('file-manager.compression.method');
                            if (isset($data['compression_method']) && $data['compression_method'] !== 'auto') {
                                $method = $data['compression_method'] === 'api' ? 'api' : 'gd';
                                config(['file-manager.compression.method' => $method]);
                            }
                            
                            // Create compression service after config override
                            $compressionService = new ImageCompressionService;
                            $result = static::compressMediaRecord($record, $data, $compressionService);
                            
                            // Restore original compression method
                            config(['file-manager.compression.method' => $originalMethod]);

                            if ($result['success']) {
                                $savedKb = round($result['saved'] / 1024, 2);
                                $compressionRatio = round(($result['saved'] / $result['original_size']) * 100, 1);

                                // Check compression method and show appropriate notification
                                $compressionMethod = $result['compression_method'] ?? 'unknown';

                                if ($compressionMethod === 'gd_fallback') {
                                    // API failed, used GD as fallback
                                    $reason = $result['api_fallback_reason'] ?? 'Unknown reason';
                                    Notification::make()
                                        ->warning()
                                        ->title('API Compression Failed - Used GD Fallback')
                                        ->body("API Error: {$reason}<br>
                                               Compressed with GD: Saved {$savedKb} KB ({$compressionRatio}% reduction)")
                                        ->duration(8000)
                                        ->send();
                                } elseif ($compressionMethod === 'gd') {
                                    // Direct GD compression
                                    Notification::make()
                                        ->success()
                                        ->title('Image Compressed with GD')
                                        ->body("Saved {$savedKb} KB ({$compressionRatio}% reduction)")
                                        ->send();
                                } elseif ($compressionMethod === 'api') {
                                    // Successful API compression
                                    Notification::make()
                                        ->success()
                                        ->title('Image Compressed via API')
                                        ->body("Saved {$savedKb} KB ({$compressionRatio}% reduction)")
                                        ->send();
                                } else {
                                    // Fallback for unknown method
                                    Notification::make()
                                        ->success()
                                        ->title('Image compressed successfully')
                                        ->body("Saved {$savedKb} KB ({$compressionRatio}% reduction)")
                                        ->send();
                                }
                            } else {
                                throw new \Exception($result['message'] ?? 'Compression failed');
                            }
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Compression failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('refetch')
                    ->label('Refetch')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->modalHeading('Refetch Metadata')
                    ->modalDescription(fn (MediaMetadata $record): string => "Refetch metadata from parent model for: {$record->file_name}")
                    ->requiresConfirmation()
                    ->action(function (MediaMetadata $record): void {
                        try {
                            // Get the parent model
                            $model = $record->mediable_type::find($record->mediable_id);
                            
                            if (!$model) {
                                throw new \Exception('Parent model not found');
                            }
                            
                            $updates = [];
                            
                            // Refetch SEO title if the model has seoTitleField method
                            if (method_exists($model, 'seoTitleField')) {
                                $seoField = $model->seoTitleField();
                                $seoTitle = $model->$seoField ?? null;
                                
                                if ($seoTitle) {
                                    // Clean control characters
                                    $seoTitle = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $seoTitle);
                                    // Limit to 160 characters
                                    $seoTitle = substr($seoTitle, 0, 160);
                                    $updates['seo_title'] = $seoTitle;
                                }
                            }
                            
                            // Check if file exists and update file info
                            if (Storage::disk('s3')->exists($record->file_name)) {
                                $fileSize = Storage::disk('s3')->size($record->file_name);
                                $updates['file_size'] = $fileSize;
                                
                                // Get MIME type if possible
                                $mimeType = Storage::disk('s3')->mimeType($record->file_name);
                                if ($mimeType) {
                                    $updates['mime_type'] = $mimeType;
                                }
                            }
                            
                            // Update the record
                            if (!empty($updates)) {
                                $record->update($updates);
                                
                                $message = [];
                                if (isset($updates['seo_title'])) {
                                    $message[] = 'SEO title';
                                }
                                if (isset($updates['file_size'])) {
                                    $message[] = 'file size';
                                }
                                if (isset($updates['mime_type'])) {
                                    $message[] = 'MIME type';
                                }
                                
                                Notification::make()
                                    ->title('Metadata refetched successfully')
                                    ->body('Updated: ' . implode(', ', $message))
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('No updates needed')
                                    ->body('Metadata is already up to date')
                                    ->info()
                                    ->send();
                            }
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Refetch failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                
                Action::make('edit_seo')
                    ->label('Edit SEO')
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('success')
                    ->modalHeading('Edit SEO Title')
                    ->modalDescription(fn (MediaMetadata $record): string => "Optimize SEO for: {$record->file_name}")
                    ->schema([
                        TextInput::make('seo_title')
                            ->label('SEO Title')
                            ->placeholder('Enter SEO-friendly title')
                            ->required()
                            ->default(fn (MediaMetadata $record): ?string => $record->seo_title)
                            ->maxLength(160)
                            ->helperText('SEO-friendly title for search engines (recommended 50-160 characters)')
                            ->live()
                            ->afterStateUpdatedJs(<<<'JS'
                                const count = ($state ?? '').length;
                                const counter = document.querySelector('[data-seo-counter]');
                                if (counter) {
                                    counter.textContent = count + '/160';
                                    counter.style.color = count > 160 ? '#ef4444' : (count < 50 ? '#f59e0b' : '#6b7280');
                                }
                            JS),
                    ])
                    ->action(function (MediaMetadata $record, array $data): void {
                        try {
                            $record->update(['seo_title' => $data['seo_title']]);
                            
                            Notification::make()
                                ->title('SEO title updated')
                                ->body('The SEO title has been updated successfully.')
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Update failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                
                Action::make('rename')
                    ->label('Rename')
                    ->icon('heroicon-o-pencil')
                    ->color('primary')
                    ->modalHeading('Rename File')
                    ->modalDescription(fn (MediaMetadata $record): string => "Current name: {$record->file_name}")
                    ->schema([
                        TextInput::make('new_filename')
                            ->label('New Filename')
                            ->placeholder('Enter new filename with full path')
                            ->required()
                            ->default(fn (MediaMetadata $record): string => $record->file_name)
                            ->helperText('Enter the full path and filename (e.g., uploads/images/photo.jpg)')
                            ->rules(fn ($record) => [
                                'required',
                                'string',
                                function (string $attribute, $value, \Closure $fail) use ($record) {
                                    // Check if file already exists on S3 (only if different from current)
                                    if ($value !== $record->file_name && Storage::disk('s3')->exists($value)) {
                                        $fail('A file with this name already exists.');
                                    }
                                },
                            ]),
                    ])
                    ->action(function (MediaMetadata $record, array $data): void {
                        try {
                            $oldFileName = $record->file_name;
                            $newFileName = $data['new_filename'];

                            // Skip if same name
                            if ($newFileName === $oldFileName) {
                                Notification::make()
                                    ->title('No changes made')
                                    ->body('The filename is the same as before.')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            // Update the parent model's field
                            $model = $record->mediable_type::find($record->mediable_id);
                            if ($model) {
                                $field = $record->mediable_field;

                                // Handle array fields
                                if (is_array($model->{$field})) {
                                    $values = $model->{$field};
                                    $key = array_search($oldFileName, $values);
                                    if ($key !== false) {
                                        $values[$key] = $newFileName;
                                        $model->updateQuietly([$field => $values]);
                                    }
                                } else {
                                    // Single value field
                                    if ($model->{$field} === $oldFileName) {
                                        $model->updateQuietly([$field => $newFileName]);
                                    }
                                }
                            }

                            // Update the metadata record
                            $record->update(['file_name' => $newFileName]);

                            Notification::make()
                                ->title('File renamed successfully')
                                ->body("Database updated from '{$oldFileName}' to '{$newFileName}'.")
                                ->success()
                                ->send();

                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Rename failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('delete_resized')
                    ->label('Delete Resized')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (MediaMetadata $record): bool => str_starts_with($record->mime_type ?? '', 'image/'))
                    ->modalHeading('Delete Resized Versions')
                    ->modalDescription(fn (MediaMetadata $record): string => "Delete all resized versions of: {$record->file_name}")
                    ->modalContent(fn () => view('file-manager::actions.delete-resized-warning'))
                    ->action(function (MediaMetadata $record): void {
                        try {
                            $fileName = $record->file_name;
                            $pathParts = explode('/', $fileName);
                            $name = array_pop($pathParts);
                            $directory = implode('/', $pathParts);

                            // Get configured image sizes
                            $sizes = config('file-manager.image_sizes', []);
                            $deletedCount = 0;

                            foreach (array_keys($sizes) as $size) {
                                $resizedPath = "{$directory}/{$size}/{$name}";
                                if (Storage::disk('s3')->exists($resizedPath)) {
                                    Storage::disk('s3')->delete($resizedPath);
                                    $deletedCount++;
                                }
                            }

                            Notification::make()
                                ->title('Resized versions deleted')
                                ->body("Deleted {$deletedCount} resized versions")
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Deletion failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->requiresConfirmation(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageMediaMetadata::route('/'),
            'image-processor' => Pages\ImageProcessor::route('/image-processor'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    /**
     * Compress a single media record
     */
    protected static function compressMediaRecord(
        MediaMetadata $record,
        array $data,
        ImageCompressionService $compressionService
    ): array {
        $fileName = $record->file_name;

        // Download the original file from S3
        $originalContent = Storage::disk('s3')->get($fileName);
        $tempPath = sys_get_temp_dir() . '/' . uniqid('compress_') . '.tmp';
        file_put_contents($tempPath, $originalContent);

        // Determine the output format
        $outputFormat = $data['format'];
        if ($outputFormat === 'preserve') {
            $extension = pathinfo($fileName, PATHINFO_EXTENSION);
            $outputFormat = match (strtolower($extension)) {
                'jpg', 'jpeg' => 'jpg',
                'png' => 'png',
                'webp' => 'webp',
                'avif' => 'avif',
                default => 'webp',
            };
        }

        // Build the new file name
        $pathInfo = pathinfo($fileName);
        $directory = $pathInfo['dirname'];
        $filenameWithoutExt = $pathInfo['filename'];
        $newExtension = match ($outputFormat) {
            'jpg' => 'jpg',
            'png' => 'png',
            'webp' => 'webp',
            'avif' => 'avif',
            default => 'webp'
        };
        $newFileName = $directory . '/' . $filenameWithoutExt . '.' . $newExtension;

        // Compress the image
        $result = $compressionService->compressAndSave(
            $tempPath,
            $newFileName,
            (int) $data['quality'],
            null,
            null,
            $outputFormat,
            'contain',
            's3'
        );

        @unlink($tempPath);

        if ($result['success']) {
            // If replacing and format changed, delete old file
            if ($data['replace_original'] && $newFileName !== $fileName) {
                Storage::disk('s3')->delete($fileName);

                // Delete resized versions
                $sizes = config('file-manager.image_sizes', []);
                foreach (array_keys($sizes) as $size) {
                    $resizedPath = "{$directory}/{$size}/{$pathInfo['basename']}";
                    if (Storage::disk('s3')->exists($resizedPath)) {
                        Storage::disk('s3')->delete($resizedPath);
                    }
                }
            }

            // Update metadata
            $updateData = [
                'file_size' => $result['data']['compressed_size'] ?? $record->file_size,
                'metadata' => array_merge($record->metadata ?? [], [
                    'compression' => [
                        'original_size' => $result['data']['original_size'] ?? null,
                        'compressed_size' => $result['data']['compressed_size'] ?? null,
                        'compression_ratio' => $result['data']['compression_ratio'] ?? null,
                        'quality' => (int) $data['quality'],
                        'format' => $outputFormat,
                        'compressed_at' => now()->toIso8601String(),
                    ],
                ]),
            ];

            if ($newFileName !== $fileName) {
                $updateData['file_name'] = $newFileName;

                // Update model field if replacing
                if ($data['replace_original']) {
                    $model = $record->mediable_type::find($record->mediable_id);
                    if ($model) {
                        $field = $record->mediable_field;
                        if (is_array($model->{$field})) {
                            $values = $model->{$field};
                            $key = array_search($fileName, $values);
                            if ($key !== false) {
                                $values[$key] = $newFileName;
                                $model->updateQuietly([$field => $values]);
                            }
                        } else {
                            if ($model->{$field} === $fileName) {
                                $model->updateQuietly([$field => $newFileName]);
                            }
                        }
                    }
                }
            }

            $record->update($updateData);

            if ($data['resize_after']) {
                ResizeImages::dispatch([$newFileName]);
            }

            return [
                'success' => true,
                'original_size' => $result['data']['original_size'],
                'compressed_size' => $result['data']['compressed_size'],
                'saved' => $result['data']['original_size'] - $result['data']['compressed_size'],
                'compression_method' => $result['data']['compression_method'] ?? null,
                'api_fallback_reason' => $result['data']['api_fallback_reason'] ?? null,
            ];
        }

        return [
            'success' => false,
            'message' => $result['message'] ?? 'Compression failed',
        ];
    }

    /**
     * Find all Filament resources for a given model class
     */
    protected static function findResourcesForModel(string $modelClass): array
    {
        $foundResources = [];

        // Get all registered resources from the current panel
        $resources = Filament::getResources();

        // Look for resources that handle this model
        foreach ($resources as $resource) {
            if ($resource::getModel() === $modelClass) {
                $foundResources[] = $resource;
            }
        }

        // If not found in registered resources, try common naming conventions
        if (empty($foundResources)) {
            $modelName = class_basename($modelClass);
            $possibleResources = [
                "App\\Filament\\Resources\\{$modelName}Resource",
                "App\\Filament\\Resources\\{$modelName}\\{$modelName}Resource",
            ];

            foreach ($possibleResources as $resourceClass) {
                if (class_exists($resourceClass) && method_exists($resourceClass, 'getModel')) {
                    if ($resourceClass::getModel() === $modelClass) {
                        $foundResources[] = $resourceClass;
                    }
                }
            }
        }

        return $foundResources;
    }
}
