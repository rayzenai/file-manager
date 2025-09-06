<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
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
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
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
use Kirantimsina\FileManager\Facades\FileManager;
use Kirantimsina\FileManager\Filament\Resources\MediaMetadataResource\Pages;
use Kirantimsina\FileManager\Jobs\ResizeImages;
use Kirantimsina\FileManager\Models\MediaMetadata;
use Kirantimsina\FileManager\Services\ImageCompressionService;
use Kirantimsina\FileManager\Services\MetadataRefreshService;

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
                Section::make('Media Preview')
                    ->schema([
                        TextEntry::make('file_name')
                            ->label('Thumbnail')
                            ->formatStateUsing(function ($state, MediaMetadata $record) {
                                // Use FileManager to get the thumbnail path with configured size
                                $thumbnailSize = config('file-manager.default_card_size', 'thumbnail');
                                $thumbnailPath = FileManager::getMediaPath($state, $thumbnailSize);

                                // Check if FileManager returned a full URL or just a path
                                $thumbnailUrl = str_starts_with($thumbnailPath, 'http')
                                    ? $thumbnailPath
                                    : Storage::disk('s3')->url($thumbnailPath);

                                $fullImageUrl = str_starts_with($state, 'http')
                                    ? $state
                                    : Storage::disk('s3')->url($state);

                                return "<a href='{$fullImageUrl}' target='_blank' class='block'>
                                    <img src='{$thumbnailUrl}' 
                                         alt='Thumbnail' 
                                         class='max-w-[200px] max-h-[200px] object-contain rounded-lg shadow-sm hover:shadow-md transition-shadow cursor-pointer'
                                         style='width: auto; height: auto;' />
                                </a>";
                            })
                            ->html()
                            ->visible(fn (MediaMetadata $record): bool => str_starts_with($record->mime_type ?? '', 'image/')),

                        Grid::make(1)
                            ->schema([
                                TextEntry::make('file_name')
                                    ->label('Media Link')
                                    ->formatStateUsing(function ($state, MediaMetadata $record) {
                                        $fileName = basename($state);

                                        if (str_starts_with($record->mime_type ?? '', 'image/')) {
                                            return "🖼️ {$fileName}";
                                        } elseif (str_starts_with($record->mime_type ?? '', 'video/')) {
                                            return "🎥 {$fileName}";
                                        } elseif (str_starts_with($record->mime_type ?? '', 'application/pdf')) {
                                            return "📄 {$fileName}";
                                        } else {
                                            return "📁 {$fileName}";
                                        }
                                    })
                                    ->url(fn ($state) => Storage::disk('s3')->url($state))
                                    ->openUrlInNewTab()
                                    ->copyable()
                                    ->copyMessage('Media URL copied!')
                                    ->copyMessageDuration(3000),

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
                                    })
                                    ->badge()
                                    ->color('info'),

                                TextEntry::make('mime_type')
                                    ->label('Type')
                                    ->badge()
                                    ->color(fn (string $state): string => match (true) {
                                        str_starts_with($state, 'image/') => 'success',
                                        str_starts_with($state, 'video/') => 'warning',
                                        str_starts_with($state, 'application/pdf') => 'danger',
                                        default => 'gray',
                                    }),
                            ])
                            ->extraAttributes(['style' => 'padding-left: 1rem;']),
                    ])
                    ->columns(2),

                Section::make('Model Information')
                    ->schema([
                        TextEntry::make('mediable_type')
                            ->label('Model Type')
                            ->formatStateUsing(fn (string $state): string => class_basename($state)),
                        TextEntry::make('mediable_id')
                            ->label('Model ID')
                            ->numeric(),
                        TextEntry::make('mediable_field')
                            ->label('Field'),
                    ])
                    ->columns(3),

                Section::make('File Details')
                    ->schema([
                        TextEntry::make('file_name')
                            ->label('File Path')
                            ->copyable(),
                        TextEntry::make('width')
                            ->label('Width')
                            ->formatStateUsing(fn ($state) => $state ? $state . 'px' : '-')
                            ->visible(fn (MediaMetadata $record): bool => str_starts_with($record->mime_type ?? '', 'image/')),
                        TextEntry::make('height')
                            ->label('Height')
                            ->formatStateUsing(fn ($state) => $state ? $state . 'px' : '-')
                            ->visible(fn (MediaMetadata $record): bool => str_starts_with($record->mime_type ?? '', 'image/')),
                        TextEntry::make('seo_title')
                            ->label('SEO Title')
                            ->formatStateUsing(fn ($state) => $state ?: 'No SEO title set')
                            ->badge()
                            ->color('primary')
                            ->copyable(),

                        Actions::make([
                            Action::make('edit_seo_title')
                                ->label('Edit SEO')
                                ->icon('heroicon-o-pencil')
                                ->link()
                                ->color('success')
                                ->modalHeading('Edit SEO Title')
                                ->modalDescription(fn (MediaMetadata $record): string => "Optimize SEO for: {$record->file_name}")
                                ->schema([
                                    TextInput::make('seo_title')
                                        ->label('SEO Title')
                                        ->placeholder('Enter SEO-friendly title')
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
                                })
                                ->fillForm(fn (MediaMetadata $record): array => [
                                    'seo_title' => $record->seo_title,
                                ]),

                            Action::make('refetch_metadata')
                                ->label('Refresh Metadata')
                                ->icon('heroicon-o-arrow-path')
                                ->color('info')
                                ->link()
                                ->action(function (MediaMetadata $record): void {
                                    $service = new MetadataRefreshService;
                                    
                                    // Simple refresh using model as source (most common case)
                                    $result = $service->refreshSingle($record, 'model');

                                    if ($result['success']) {
                                        if ($result['updated'] ?? false) {
                                            Notification::make()
                                                ->title('Metadata refreshed')
                                                ->body($result['message'])
                                                ->success()
                                                ->duration(5000)
                                                ->send();
                                        } else {
                                            Notification::make()
                                                ->title('No updates needed')
                                                ->body($result['message'])
                                                ->info()
                                                ->duration(3000)
                                                ->send();
                                        }
                                    } else {
                                        Notification::make()
                                            ->title('Refresh failed')
                                            ->body($result['message'])
                                            ->danger()
                                            ->duration(5000)
                                            ->send();
                                    }
                                }),
                        ]),
                    ])
                    ->columns(2),

                Section::make('Additional Information')
                    ->schema([
                        TextEntry::make('metadata')
                            ->label('Additional Metadata')
                            ->formatStateUsing(fn ($state) => $state ? json_encode($state, JSON_PRETTY_PRINT) : 'No additional metadata')
                            ->columnSpanFull(),
                        TextEntry::make('created_at')
                            ->label('Created')
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->label('Last Updated')
                            ->dateTime(),
                    ])
                    ->columns(2)
                    ->collapsible(),
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
                    ->toggleable()
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

                                $hasApi = ! empty(config('file-manager.compression.api.url'));

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
                                return match ($configMethod) {
                                    'gd' => 'gd',
                                    'api' => 'auto',  // 'api' in config means auto (with fallback)
                                    default => 'auto'
                                };
                            })
                            ->visible(fn () => ! empty(config('file-manager.compression.api.url')))
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

                BulkAction::make('bulk_refetch')
                    ->label('Refetch Metadata')
                    ->deselectRecordsAfterCompletion()
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Refetch Metadata')
                    ->modalDescription(function (Collection $records): string {
                        $service = new MetadataRefreshService;
                        $check = $service->checkBulkDiscrepancies($records);

                        $description = "Refetch metadata for {$records->count()} selected items";

                        if ($check['has_any_discrepancy']) {
                            $description .= "\n\n⚠️ {$check['discrepancy_count']} items have filename discrepancies!";
                            if (! empty($check['examples'])) {
                                $description .= "\nExamples:\n• " . implode("\n• ", $check['examples']);
                            }
                        }

                        return $description;
                    })
                    ->schema(function (Collection $records): array {
                        $service = new MetadataRefreshService;
                        $check = $service->checkBulkDiscrepancies($records);

                        // Only show source selection if there are discrepancies
                        if ($check['has_any_discrepancy']) {
                            return [
                                Radio::make('source')
                                    ->label('Choose which file names to use')
                                    ->options([
                                        'model' => 'Use parent model field values (will update metadata filenames)',
                                        'metadata' => 'Keep current metadata filenames',
                                    ])
                                    ->default('model')
                                    ->helperText("{$check['discrepancy_count']} out of {$check['total_count']} items have filename discrepancies.")
                                    ->required(),
                            ];
                        }

                        // No discrepancies, no need to show selection
                        return [];
                    })
                    ->action(function (Collection $records, array $data): void {
                        $service = new MetadataRefreshService;

                        // Check for discrepancies to determine source
                        $check = $service->checkBulkDiscrepancies($records);
                        $source = $check['has_any_discrepancy'] ? ($data['source'] ?? 'model') : 'model';

                        $result = $service->refreshBulk($records, $source);

                        // Build notification body
                        $notificationBody = "Processed {$result['success_count']} items successfully.";

                        if ($result['updated_count'] > 0) {
                            $notificationBody .= "\nUpdated {$result['updated_count']} items.";

                            // Show first few details
                            if (! empty($result['details'])) {
                                $notificationBody .= "\n\n**Changes:**\n";
                                $detailsToShow = array_slice($result['details'], 0, 3);
                                foreach ($detailsToShow as $detail) {
                                    $notificationBody .= "• {$detail}\n";
                                }
                                if (count($result['details']) > 3) {
                                    $remaining = count($result['details']) - 3;
                                    $notificationBody .= "• ...and {$remaining} more\n";
                                }
                            }
                        }

                        if ($result['failed_count'] > 0) {
                            $notificationBody .= "\n\n**Failed ({$result['failed_count']}):**\n";
                            $failedToShow = array_slice($result['failed_records'], 0, 3);
                            foreach ($failedToShow as $failed) {
                                $notificationBody .= "• {$failed}\n";
                            }
                            if (count($result['failed_records']) > 3) {
                                $remaining = count($result['failed_records']) - 3;
                                $notificationBody .= "• ...and {$remaining} more\n";
                            }
                        }

                        Notification::make()
                            ->title('Bulk refetch completed')
                            ->body($notificationBody)
                            ->success()
                            ->duration(10000)
                            ->send();
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

                // Navigation Group
                ActionGroup::make([
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
                ])
                    ->hiddenLabel()
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->button(),

                // Image Processing Group
                ActionGroup::make([
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

                                    $hasApi = ! empty(config('file-manager.compression.api.url'));

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
                                    return match ($configMethod) {
                                        'gd' => 'gd',
                                        'api' => 'auto',  // 'api' in config means auto (with fallback)
                                        default => 'auto'
                                    };
                                })
                                ->visible(fn () => ! empty(config('file-manager.compression.api.url')))
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
                ])
                    ->label('Processing')
                    ->icon('heroicon-o-photo')
                    ->color('info')
                    ->visible(fn (MediaMetadata $record): bool => str_starts_with($record->mime_type ?? '', 'image/'))
                    ->button(),

                // Data Management Group
                ActionGroup::make([
                    Action::make('refetch')
                        ->label('Refetch Metadata')
                        ->icon('heroicon-o-arrow-path')
                        ->color('info')
                        ->modalHeading('Refetch Metadata')
                        ->modalDescription(function (MediaMetadata $record): string {
                            $service = new MetadataRefreshService;
                            $discrepancy = $service->checkDiscrepancy($record);

                            if ($discrepancy['has_discrepancy']) {
                                $description = "⚠️ Discrepancy detected!\n";

                                if ($discrepancy['is_array'] ?? false) {
                                    $description .= 'Model field contains an array with ' . count($discrepancy['array_files']) . " files:\n";
                                    foreach (array_slice($discrepancy['array_files'], 0, 5) as $i => $file) {
                                        $description .= '  ' . ($i + 1) . '. ' . $file . "\n";
                                    }
                                    if (count($discrepancy['array_files']) > 5) {
                                        $description .= '  ... and ' . (count($discrepancy['array_files']) - 5) . " more\n";
                                    }
                                    $description .= "\nMetadata record: {$discrepancy['metadata_value']}";
                                } else {
                                    $description .= "Model field: {$discrepancy['model_value']}\n";
                                    $description .= "Metadata: {$discrepancy['metadata_value']}";
                                }

                                return $description;
                            }

                            return "Refetch metadata for: {$record->file_name}";
                        })
                        ->schema(function (MediaMetadata $record): array {
                            $service = new MetadataRefreshService;
                            $discrepancy = $service->checkDiscrepancy($record);

                            // Only show source selection if there's a discrepancy
                            if ($discrepancy['has_discrepancy']) {
                                $options = [];

                                if ($discrepancy['is_array'] ?? false) {
                                    // For arrays, show each file as an option
                                    $arrayFiles = $discrepancy['array_files'] ?? [];
                                    if (! empty($arrayFiles)) {
                                        foreach ($arrayFiles as $file) {
                                            $shortName = strlen($file) > 60 ? '...' . substr($file, -57) : $file;
                                            $options['array:' . $file] = 'Model array: ' . $shortName;
                                        }
                                    }
                                    $options['metadata'] = 'Keep metadata: ' . $discrepancy['metadata_value'];
                                } else {
                                    // For single values, show both options
                                    $options['model'] = "Use model's value: " . ($discrepancy['model_value'] ?? 'unknown');
                                    $options['metadata'] = "Use metadata's value: " . ($discrepancy['metadata_value'] ?? 'unknown');
                                }

                                return [
                                    Radio::make('source')
                                        ->label('Choose which file to use')
                                        ->options($options)
                                        ->default(array_key_first($options))
                                        ->helperText('Select the correct file to use for refreshing metadata.')
                                        ->required(),
                                ];
                            }

                            // No discrepancy, no need to show selection
                            return [];
                        })
                        ->action(function (MediaMetadata $record, array $data): void {
                            $service = new MetadataRefreshService;

                            // Check for discrepancy to determine source
                            $discrepancy = $service->checkDiscrepancy($record);

                            if ($discrepancy['has_discrepancy']) {
                                $selectedSource = $data['source'] ?? 'model';

                                // Handle array selection
                                if (str_starts_with($selectedSource, 'array:')) {
                                    // Extract the selected file from the array
                                    $selectedFile = substr($selectedSource, 6);
                                    // Update the metadata to use this specific file
                                    $record->update(['file_name' => $selectedFile]);
                                    // Now refresh using this file
                                    $result = $service->refreshSingle($record, 'metadata');
                                } else {
                                    // Regular model or metadata source
                                    $result = $service->refreshSingle($record, $selectedSource);
                                }
                            } else {
                                // No discrepancy, use model
                                $result = $service->refreshSingle($record, 'model');
                            }

                            if ($result['success']) {
                                if ($result['updated'] ?? false) {
                                    Notification::make()
                                        ->title('Metadata refetched successfully')
                                        ->body($result['message'])
                                        ->success()
                                        ->send();
                                } else {
                                    Notification::make()
                                        ->title('No updates needed')
                                        ->body($result['message'])
                                        ->info()
                                        ->send();
                                }
                            } else {
                                Notification::make()
                                    ->title('Refetch failed')
                                    ->body($result['message'])
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
                        ->label('Rename File')
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
                                ->rules([
                                    'required',
                                    'string',
                                ])
                                ->suffixAction(
                                    Action::make('check_file')
                                        ->label('Check')
                                        ->icon('heroicon-o-magnifying-glass')
                                        ->color('info')
                                        ->action(function ($state, $set) {
                                            if (! $state) {
                                                Notification::make()
                                                    ->title('No filename provided')
                                                    ->body('Please enter a filename first')
                                                    ->warning()
                                                    ->send();

                                                return;
                                            }

                                            try {
                                                $fileExists = Storage::disk('s3')->exists($state);

                                                if ($fileExists) {
                                                    Notification::make()
                                                        ->title('File exists')
                                                        ->body("✅ The file '{$state}' already exists on S3. Renaming will update metadata to point to this existing file.")
                                                        ->success()
                                                        ->duration(8000)
                                                        ->send();
                                                } else {
                                                    Notification::make()
                                                        ->title('File does not exist')
                                                        ->body("❌ The file '{$state}' does not exist on S3. This will be a true rename operation.")
                                                        ->info()
                                                        ->duration(8000)
                                                        ->send();
                                                }
                                            } catch (\Exception $e) {
                                                Notification::make()
                                                    ->title('Unable to check file')
                                                    ->body("Error checking S3: {$e->getMessage()}")
                                                    ->danger()
                                                    ->send();
                                            }
                                        })
                                ),
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

                                $fileExists = Storage::disk('s3')->exists($newFileName);

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

                                $notificationTitle = $fileExists
                                    ? 'Metadata updated to existing file'
                                    : 'File renamed successfully';

                                $notificationBody = $fileExists
                                    ? "Metadata updated to point to existing file '{$newFileName}'."
                                    : "Database updated from '{$oldFileName}' to '{$newFileName}'.";

                                Notification::make()
                                    ->title($notificationTitle)
                                    ->body($notificationBody)
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
                ])
                    ->label('Management')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->color('success')
                    ->button(),
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

        // Compress the image - use configured dimensions if available
        $result = $compressionService->compressAndSave(
            $tempPath,
            $newFileName,
            (int) $data['quality'],
            config('file-manager.compression.height') ? (int) config('file-manager.compression.height') : null,
            config('file-manager.compression.width') ? (int) config('file-manager.compression.width') : null,
            $outputFormat,
            config('file-manager.compression.mode', 'contain'),
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
