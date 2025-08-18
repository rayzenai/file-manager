<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Filament\Resources\MediaMetadataResource\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\Storage;
use Kirantimsina\FileManager\Filament\Resources\MediaMetadataResource;
use Kirantimsina\FileManager\Services\ImageCompressionService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ImageProcessor extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $resource = MediaMetadataResource::class;

    protected string $view = 'file-manager::pages.image-processor';

    protected static ?string $title = 'Image Processor';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationLabel = 'Image Processor';

    public ?array $data = [];

    public ?string $processedImageUrl = null;

    public ?string $processedImagePath = null;

    public ?array $processingStats = null;

    public function mount(): void
    {
        $this->form->fill([
            'format' => 'webp',
            'quality' => '85',
            'compression_method' => 'auto',
            'resize_mode' => 'contain',
        ]);
    }

    protected function getFormStatePath(): ?string
    {
        return 'data';
    }

    protected function getFormSchema(): array
    {
        return [
            Grid::make(2)
                ->schema([
                    Section::make('Upload Image')
                        ->description('Select an image to process')
                        ->schema([
                            FileUpload::make('image')
                                ->label('Image File')
                                ->image()
                                ->imagePreviewHeight('300')
                                ->maxSize(10240) // 10MB
                                ->acceptedFileTypes([
                                    'image/jpeg',
                                    'image/jpg',
                                    'image/png',
                                    'image/webp',
                                    'image/avif',
                                ])
                                ->required()
                                ->live()
                                ->afterStateUpdated(fn () => $this->resetProcessedImage())
                                ->helperText('Maximum file size: 10MB. Supported formats: JPEG, PNG, WebP, AVIF'),
                        ])
                        ->columnSpan(1),

                    Section::make('Processing Options')
                        ->description('Configure how to process your image')
                        ->schema([
                            Select::make('format')
                                ->label('Output Format')
                                ->options([
                                    'original' => 'Keep Original Format',
                                    'webp' => 'WebP (Best compression)',
                                    'jpg' => 'JPEG (Universal compatibility)',
                                    'png' => 'PNG (Lossless)',
                                    'avif' => 'AVIF (Smallest size)',
                                ])
                                ->default('webp')
                                ->required()
                                ->helperText('Choose the output format for your processed image'),

                            Select::make('quality')
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
                                ->required()
                                ->visible(fn ($get) => ! in_array($get('format'), ['png']))
                                ->helperText('Lower quality = smaller file size'),

                            Grid::make(2)
                                ->schema([
                                    TextInput::make('width')
                                        ->label('Width (px)')
                                        ->numeric()
                                        ->placeholder('Auto')
                                        ->helperText('Leave empty to maintain aspect ratio'),

                                    TextInput::make('height')
                                        ->label('Height (px)')
                                        ->numeric()
                                        ->placeholder('Auto')
                                        ->helperText('Leave empty to maintain aspect ratio'),
                                ]),

                            Select::make('resize_mode')
                                ->label('Resize Mode')
                                ->options([
                                    'contain' => 'Contain (Fit within dimensions)',
                                    'cover' => 'Cover (Fill dimensions)',
                                    'crop' => 'Crop (Exact dimensions)',
                                ])
                                ->default('contain')
                                ->visible(fn ($get) => $get('width') || $get('height'))
                                ->helperText('How to handle resizing when dimensions are specified'),

                            Toggle::make('remove_background')
                                ->label('Remove Background')
                                ->helperText('Uses AI to remove the background (requires Cloud Run API)')
                                ->visible(function () {
                                    // Show if bg_removal API is configured OR if primary API is the Cloud Run endpoint
                                    $bgRemovalUrl = config('file-manager.compression.api.bg_removal.url');
                                    $primaryUrl = config('file-manager.compression.api.url');
                                    
                                    // Check if either bg_removal is configured or primary API is Cloud Run
                                    return !empty($bgRemovalUrl) || 
                                           (str_contains($primaryUrl ?? '', 'image-processor-ao25z3a2tq-el.a.run.app'));
                                }),

                            Select::make('compression_method')
                                ->label('Processing Method')
                                ->options(function () {
                                    $options = [];
                                    
                                    $hasLambda = !empty(config('file-manager.compression.api.url'));
                                    $hasCloudRun = !empty(config('file-manager.compression.api.bg_removal.url'));
                                    
                                    if ($hasLambda || $hasCloudRun) {
                                        $options['auto'] = 'Auto (Use best available API, fallback to GD)';
                                    }
                                    
                                    if ($hasLambda) {
                                        $options['api'] = 'Lambda API (Fast compression)';
                                    }
                                    
                                    if ($hasCloudRun) {
                                        $options['api_bg_removal'] = 'Cloud Run API (Background removal support)';
                                    }
                                    
                                    $options['gd'] = 'GD Library Only (Local processing)';
                                    
                                    return $options;
                                })
                                ->default('auto')
                                ->visible(fn () => !empty(config('file-manager.compression.api.url')) || 
                                                  !empty(config('file-manager.compression.api.bg_removal.url')))
                                ->helperText('Choose which compression method to use'),
                        ])
                        ->columnSpan(1),
                ]),

            Section::make('Processed Image')
                ->description('Your processed image will appear here')
                ->schema([
                    ViewField::make('processed_image_preview')
                        ->view('file-manager::components.processed-image-preview')
                        ->viewData(fn () => [
                            'imageUrl' => $this->processedImageUrl,
                            'stats' => $this->processingStats,
                        ]),
                ])
                ->visible(fn () => $this->processedImageUrl !== null)
                ->columnSpanFull(),
        ];
    }

    public function processImage(): void
    {
        $this->validate();

        $data = $this->form->getState();

        if (! isset($data['image']) || empty($data['image'])) {
            Notification::make()
                ->danger()
                ->title('No image uploaded')
                ->body('Please upload an image to process.')
                ->send();

            return;
        }

        try {
            // Get the temporary uploaded file
            // FileUpload returns a string for single files, array for multiple
            $tempFilePath = is_array($data['image']) 
                ? (array_values($data['image'])[0] ?? null)
                : $data['image'];
                
            if (! $tempFilePath) {
                throw new \Exception('No file path found');
            }

            // Handle Livewire temporary upload
            // First check if this is a Livewire temp upload ID
            $tempDisk = config('livewire.temporary_file_upload.disk', 'local');
            
            // Livewire temp files are stored with a specific path pattern
            $possiblePaths = [
                'livewire-tmp/' . $tempFilePath,
                $tempFilePath,
                'livewire-tmp/' . basename($tempFilePath),
            ];
            
            $actualPath = null;
            foreach ($possiblePaths as $path) {
                if (Storage::disk($tempDisk)->exists($path)) {
                    $actualPath = $path;
                    break;
                }
            }
            
            if (!$actualPath) {
                // Try to find the file by listing the livewire-tmp directory
                $files = Storage::disk($tempDisk)->files('livewire-tmp');
                foreach ($files as $file) {
                    if (str_contains($file, basename($tempFilePath))) {
                        $actualPath = $file;
                        break;
                    }
                }
            }
            
            if (!$actualPath) {
                throw new \Exception('Uploaded file not found. Path checked: ' . $tempFilePath);
            }
            
            // Get the actual file content
            $fileContent = Storage::disk($tempDisk)->get($actualPath);
            
            // Create a temporary file for processing
            $inputPath = sys_get_temp_dir() . '/' . uniqid('process_input_') . '.tmp';
            file_put_contents($inputPath, $fileContent);

            // Determine output format
            $format = $data['format'] ?? 'webp';
            if ($format === 'original') {
                $extension = pathinfo($tempFilePath, PATHINFO_EXTENSION);
                $format = match (strtolower($extension)) {
                    'jpg', 'jpeg' => 'jpg',
                    'png' => 'png',
                    'webp' => 'webp',
                    'avif' => 'avif',
                    default => 'webp',
                };
            }

            // Override compression method if specified
            $originalMethod = config('file-manager.compression.method');
            if (isset($data['compression_method']) && $data['compression_method'] !== 'auto') {
                // Map the UI options to actual compression methods
                $methodMap = [
                    'api' => 'api',  // Lambda API
                    'api_bg_removal' => 'api',  // Cloud Run API (will be handled by removeBg flag)
                    'gd' => 'gd',
                ];
                
                $method = $methodMap[$data['compression_method']] ?? 'api';
                config(['file-manager.compression.method' => $method]);
            }
            
            // Create compression service AFTER config override
            $compressionService = new ImageCompressionService;

            // Perform compression
            $quality = (int) ($data['quality'] ?? 85);
            $width = ! empty($data['width']) ? (int) $data['width'] : null;
            $height = ! empty($data['height']) ? (int) $data['height'] : null;
            $mode = $data['resize_mode'] ?? 'contain';
            $removeBg = $data['remove_background'] ?? false;

            $result = $compressionService->compress(
                $inputPath,
                $quality,
                $height,
                $width,
                $format,
                $mode,
                $removeBg
            );

            // Restore original compression method
            config(['file-manager.compression.method' => $originalMethod]);

            if (! $result['success']) {
                throw new \Exception($result['message'] ?? 'Compression failed');
            }

            // Generate output filename
            $outputFilename = 'processed_' . uniqid() . '.' . $format;
            $outputPath = 'temp/processed/' . $outputFilename;

            // Save processed image to temp storage
            Storage::disk('local')->put($outputPath, $result['data']['compressed_image']);

            // Store the path for download
            $this->processedImagePath = $outputPath;

            // Create a temporary URL for display (convert to base64 data URL for immediate display)
            $base64 = base64_encode($result['data']['compressed_image']);
            $mimeType = match ($format) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'webp' => 'image/webp',
                'avif' => 'image/avif',
                default => 'image/webp',
            };
            $this->processedImageUrl = "data:{$mimeType};base64,{$base64}";

            // Calculate stats
            $originalSize = $result['data']['original_size'] ?? 0;
            $compressedSize = $result['data']['compressed_size'] ?? 0;
            $saved = $originalSize - $compressedSize;
            $ratio = $originalSize > 0 ? round(($saved / $originalSize) * 100, 1) : 0;

            $this->processingStats = [
                'original_size' => $this->formatBytes($originalSize),
                'compressed_size' => $this->formatBytes($compressedSize),
                'saved' => $this->formatBytes($saved),
                'ratio' => $ratio . '%',
                'format' => strtoupper($format),
                'dimensions' => isset($result['data']['width']) && isset($result['data']['height'])
                    ? $result['data']['width'] . ' × ' . $result['data']['height'] . ' px'
                    : 'N/A',
                'method' => $this->getMethodLabel($result['data']['compression_method'] ?? 'unknown'),
            ];

            // Show success notification with details
            $notificationBody = "Size: {$this->processingStats['original_size']} → {$this->processingStats['compressed_size']}<br>";
            $notificationBody .= "Saved: {$this->processingStats['saved']} ({$this->processingStats['ratio']})<br>";
            $notificationBody .= "Method: {$this->processingStats['method']}";

            if (isset($result['data']['api_fallback_reason'])) {
                Notification::make()
                    ->warning()
                    ->title('Image Processed (API Fallback)')
                    ->body($notificationBody . '<br>API Issue: ' . $result['data']['api_fallback_reason'])
                    ->duration(8000)
                    ->send();
            } else {
                Notification::make()
                    ->success()
                    ->title('Image Processed Successfully')
                    ->body($notificationBody)
                    ->duration(5000)
                    ->send();
            }

            // Clean up temp file
            @unlink($inputPath);

        } catch (\Exception $e) {
            Notification::make()
                ->danger()
                ->title('Processing Failed')
                ->body($e->getMessage())
                ->send();

            // Clean up on error
            if (isset($inputPath)) {
                @unlink($inputPath);
            }
        }
    }

    public function downloadProcessedImage(): BinaryFileResponse
    {
        if (! $this->processedImagePath || ! Storage::disk('local')->exists($this->processedImagePath)) {
            Notification::make()
                ->danger()
                ->title('Download Failed')
                ->body('Processed image not found. Please process an image first.')
                ->send();
            abort(404, 'Processed image not found');
        }

        $filePath = Storage::disk('local')->path($this->processedImagePath);
        $fileName = 'processed_image_' . date('Y-m-d_H-i-s') . '.' . pathinfo($this->processedImagePath, PATHINFO_EXTENSION);

        return response()->download($filePath, $fileName);
    }

    public function resetProcessedImage(): void
    {
        // Clean up previous processed image
        if ($this->processedImagePath && Storage::disk('local')->exists($this->processedImagePath)) {
            Storage::disk('local')->delete($this->processedImagePath);
        }

        $this->processedImageUrl = null;
        $this->processedImagePath = null;
        $this->processingStats = null;
    }

    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    protected function getMethodLabel(string $method): string
    {
        return match ($method) {
            'api' => 'API Compression',
            'gd' => 'GD Library',
            'gd_fallback' => 'GD (API Fallback)',
            default => 'Unknown',
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back_to_media')
                ->label('Media Metadata')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn () => MediaMetadataResource::getUrl('index')),
        ];
    }
    
    public static function canAccess(array $parameters = []): bool
    {
        // You can add permission checks here
        return true;
    }
}
