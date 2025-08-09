<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Filament\Resources;

use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Kirantimsina\FileManager\Filament\Resources\MediaMetadataResource\Pages;
use Kirantimsina\FileManager\Models\MediaMetadata;
use Filament\Actions\ViewAction;

class MediaMetadataResource extends Resource
{
    protected static ?string $model = MediaMetadata::class;

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
        return Cache::remember(
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
                    ->searchable()
                    ->copyable(),
                TextColumn::make('mediable_field')
                    ->label('Field')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('file_name')
                    ->label('File Name')
                    ->searchable()
                    ->limit(50)
                    ->tooltip(fn ($state) => $state),
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
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageMediaMetadata::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }
}