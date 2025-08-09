# Image Compression & Media Metadata Features

## Overview
This package now includes built-in image compression and media metadata tracking capabilities.

## Configuration

The package configuration has been extended with compression and metadata settings:

```php
// config/file-manager.php

'compression' => [
    'enabled' => true,
    'method' => 'gd',  // 'gd' or 'api'
    'auto_compress' => true,
    'quality' => 85,
    'format' => 'webp',
    'mode' => 'contain',
    'height' => 1080,
    'width' => null,
    'threshold' => 500 * 1024, // 500KB
    
    // If using API method
    'api' => [
        'url' => 'https://your-api.com/compress',
        'token' => 'your-api-token',
        'timeout' => 30,
    ],
],

'media_metadata' => [
    'enabled' => true,
    'track_file_size' => true,
    'track_dimensions' => true,
    'track_mime_type' => true,
],
```

## Environment Variables

You can configure the package using environment variables:

```env
# Compression Settings
FILE_MANAGER_COMPRESSION_ENABLED=true
FILE_MANAGER_COMPRESSION_METHOD=gd  # or 'api'
FILE_MANAGER_AUTO_COMPRESS=true
FILE_MANAGER_COMPRESSION_QUALITY=85
FILE_MANAGER_COMPRESSION_FORMAT=webp
FILE_MANAGER_COMPRESSION_MODE=contain
FILE_MANAGER_COMPRESSION_HEIGHT=1080
FILE_MANAGER_COMPRESSION_THRESHOLD=512000  # in bytes

# API Compression (if using API method)
FILE_MANAGER_COMPRESSION_API_URL=https://your-api.com/compress
FILE_MANAGER_COMPRESSION_API_TOKEN=your-api-token
FILE_MANAGER_COMPRESSION_API_TIMEOUT=30

# Media Metadata
FILE_MANAGER_METADATA_ENABLED=true
FILE_MANAGER_TRACK_FILE_SIZE=true
FILE_MANAGER_TRACK_DIMENSIONS=true
FILE_MANAGER_TRACK_MIME_TYPE=true
```

## Compression Methods

### 1. GD Method (Default)
Uses PHP's built-in GD library with Intervention Image for compression. This is suitable for most use cases and doesn't require external services.

### 2. API Method
Uses an external API service for compression. This is useful if you have a dedicated image processing service. The package will automatically fall back to GD if the API fails.

## Usage

### Basic Usage (Compression & Metadata Enabled by Default)

```php
use Kirantimsina\FileManager\Forms\Components\MediaUpload;
use Kirantimsina\FileManager\Tables\Columns\MediaColumn;

// In your Filament resource
public static function form(Form $form): Form
{
    return $form
        ->schema([
            MediaUpload::make('image_file_name')
                ->label('Product Image')
                ->required(),
        ]);
}

public static function table(Table $table): Table
{
    return $table
        ->columns([
            MediaColumn::make('image_file_name')
                ->label('Image')
                ->showMetadata(true), // Show file size & dimensions in tooltip
        ]);
}
```

### Customizing Compression Settings

```php
MediaUpload::make('image_file_name')
    ->useCompression(true)  // Enable/disable compression
    ->trackMetadata(true)   // Enable/disable metadata tracking
    ->quality(90)           // Override default quality
    ->convertToWebp(true)   // Convert to WebP format
```

### Disabling Features

```php
// Disable compression for specific upload
MediaUpload::make('image_file_name')
    ->useCompression(false)

// Disable metadata tracking
MediaUpload::make('image_file_name')
    ->trackMetadata(false)

// Keep original format (don't convert to WebP)
MediaUpload::make('image_file_name')
    ->keepOriginalFormat()
```

## Media Metadata

The package automatically tracks:
- File size
- MIME type
- Image dimensions (width & height)
- Compression information (if compressed)
- Upload timestamp

### Accessing Metadata

```php
use Kirantimsina\FileManager\Models\MediaMetadata;

// Get metadata for a specific field
$metadata = MediaMetadata::getFor($product, 'image_file_name');

// Access metadata properties
echo $metadata->formatted_size;  // "1.5 MB"
echo $metadata->width;           // 1920
echo $metadata->height;          // 1080
echo $metadata->mime_type;       // "image/webp"

// Compression info (if available)
$compressionInfo = $metadata->metadata['compression'] ?? null;
if ($compressionInfo) {
    echo $compressionInfo['compression_ratio'];  // "45.2%"
    echo $compressionInfo['original_size'];      // 2048000
    echo $compressionInfo['compressed_size'];    // 1126400
}
```

### Media Metadata Model Relationship

Add this to your models to easily access media metadata:

```php
use Kirantimsina\FileManager\Models\MediaMetadata;

class Product extends Model
{
    public function mediaMetadata()
    {
        return $this->morphMany(MediaMetadata::class, 'mediable');
    }
    
    public function getImageMetadata()
    {
        return $this->mediaMetadata()
            ->where('mediable_field', 'image_file_name')
            ->first();
    }
}
```

## Migration

Run the migration to create the media_metadata table:

```bash
php artisan migrate
```

## How It Works

1. **File Upload**: When a file is uploaded through `MediaUpload`:
   - Files over the threshold (default 500KB) are automatically compressed
   - Compression uses either GD or external API based on configuration
   - Images are converted to WebP format by default
   - Metadata is tracked in the `media_metadata` table

2. **Compression Process**:
   - GD Method: Uses Intervention Image to resize and convert
   - API Method: Sends to external API, falls back to GD on failure
   - Maintains aspect ratio with 'contain' mode by default

3. **Metadata Tracking**:
   - Automatically creates/updates metadata records
   - Tracks original and compressed sizes
   - Stores compression ratio for analysis

## Performance Considerations

- **GD Method**: Fast, no external dependencies, limited by PHP memory
- **API Method**: Can handle larger files, adds network latency
- **Automatic Fallback**: API method falls back to GD on failure
- **Caching**: Metadata queries are optimized with indexes

## Troubleshooting

### Images not compressing
- Check if compression is enabled in config
- Verify file size is above threshold
- Check PHP memory limit for GD method
- Verify API credentials for API method

### Metadata not being tracked
- Ensure migration has been run
- Check if metadata tracking is enabled
- Verify model is passed to MediaUpload

### API compression failing
- Check API URL and token
- Verify API timeout setting
- Check network connectivity
- Package will fall back to GD automatically