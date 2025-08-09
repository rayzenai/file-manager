[![Latest Version on Packagist](https://img.shields.io/packagist/v/kirantimsina/file-manager.svg?style=flat-square)](https://packagist.org/packages/kirantimsina/file-manager)
[![Total Downloads](https://img.shields.io/packagist/dt/kirantimsina/file-manager.svg?style=flat-square)](https://packagist.org/packages/kirantimsina/file-manager)

# Laravel File Manager for Filament

A comprehensive Laravel package for **Filament v4** that provides advanced file management with automatic image resizing, compression, media metadata tracking, and seamless S3 integration. Built for high-performance applications handling large volumes of media content.

This package is developed and maintained by **Kiran Timsina** and **RayzenTech**.

## Key Features

- 🖼️ **Automatic Image Resizing** - Generate multiple sizes automatically on upload
- 🗜️ **Smart Compression** - WebP conversion with configurable quality settings
- 📊 **Media Metadata Tracking** - Track file sizes, dimensions, and compression stats
- ☁️ **S3 Integration** - Seamless AWS S3 storage with CDN support
- 🎨 **Custom Filament Components** - MediaUpload and S3Image components
- 🔍 **Filament Resource** - Built-in media metadata management interface
- 🚀 **Performance Optimized** - Queue-based processing for large files
- 🔧 **Highly Configurable** - Extensive configuration options

## About the Developers

**Kiran Timsina** is a full-stack developer specializing in Laravel and Filament applications. Connect on [GitHub](https://github.com/kirantimsina).

**RayzenTech** is a tech startup based in Nepal focused on creating smart business solutions. We specialize in automating complex processes and making them simple, from business automation to robotic process automation. Learn more at [RayzenTech](https://www.rayzentech.com).

---

## Requirements

- PHP 8.1+
- Laravel 10.0+
- Filament 4.0+
- AWS S3 configured (or S3-compatible storage)

## Installation

1. **Install via Composer:**

    ```bash
    composer require rayzenai/file-manager
    ```

2. **Publish the configuration:**

    ```bash
    php artisan vendor:publish --tag="file-manager-config"
    ```

3. **Run migrations (for media metadata):**

    ```bash
    php artisan migrate
    ```

4. **Configure your `.env` file:**

    ```env
    # S3 Configuration (Required)
    AWS_ACCESS_KEY_ID=your-key
    AWS_SECRET_ACCESS_KEY=your-secret
    AWS_DEFAULT_REGION=your-region
    AWS_BUCKET=your-bucket
    AWS_URL=https://your-cdn-url.com
    
    # Optional CDN URL (defaults to AWS_URL)
    CDN_URL=https://your-cdn-url.com
    
    # Compression Settings (Optional)
    FILE_MANAGER_COMPRESSION_ENABLED=true
    FILE_MANAGER_COMPRESSION_QUALITY=85
    FILE_MANAGER_COMPRESSION_FORMAT=webp
    ```

5. **Register the plugin in your Filament panel provider:**

    ```php
    use Kirantimsina\FileManager\FileManagerPlugin;
    
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->plugins([
                FileManagerPlugin::make(),
            ]);
    }
    ```

## Configuration

The configuration file `config/file-manager.php` allows you to customize:

```php
return [
    // CDN URL for serving files
    'cdn' => env('CDN_URL', env('AWS_URL')),
    
    // Maximum upload dimensions
    'max-upload-height' => '5120', // pixels
    'max-upload-width' => '5120',  // pixels
    'max-upload-size' => '8192',   // KB
    
    // Model to directory mappings
    'model' => [
        'User' => 'users',
        'Product' => 'products',
        'Blog' => 'blogs',
        // Add your models here
    ],
    
    // Image sizes to generate
    'image_sizes' => [
        'extra-small' => 60,
        'small' => 240,
        'medium' => 480,
        '640px' => 640,
        'large' => 1080,
    ],
    
    // Compression settings
    'compression' => [
        'enabled' => true,
        'method' => 'gd', // or 'api'
        'auto_compress' => true,
        'quality' => 85,
        'format' => 'webp',
        'threshold' => 500 * 1024, // 500KB
    ],
    
    // Media metadata tracking
    'media_metadata' => [
        'enabled' => true,
        'track_file_size' => true,
        'track_dimensions' => true,
        'track_mime_type' => true,
    ],
];
```

## Usage in Models

### Using the HasImages Trait

The `HasImages` trait automatically handles image resizing when images are uploaded or updated:

```php
use Kirantimsina\FileManager\Traits\HasImages;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasImages;
    
    protected $fillable = [
        'name',
        'image',        // Single image field
        'gallery',      // Multiple images field
    ];
    
    protected $casts = [
        'gallery' => 'array', // Cast array fields
    ];
    
    // Define which fields contain images for automatic resizing
    protected function hasImagesTraitFields(): array
    {
        return ['image', 'gallery'];
    }
}
```

**Key Features:**
- Automatically generates multiple sizes when images are saved
- Handles both single images and arrays of images
- Smart diffing - only resizes truly new images
- Automatic cleanup of old images when replaced
- Queue-based processing for better performance

## Usage in Filament Resources

### MediaUpload Component

The `MediaUpload` component extends Filament's `FileUpload` with automatic compression and metadata tracking:

```php
use Kirantimsina\FileManager\Forms\Components\MediaUpload;

MediaUpload::make('image')
    ->label('Product Image')
    ->convertToWebp()           // Convert to WebP (default: true)
    ->quality(90)               // Compression quality (default: 100)
    ->uploadOriginal()          // Upload without resizing (default: true)
    ->useCompression()          // Enable smart compression (default: true)
    ->trackMetadata()           // Track file metadata (default: true)
    ->multiple()                // Allow multiple files
    ->directory('custom-dir')   // Custom directory (optional)
```

**Features:**
- Automatic WebP conversion for better performance
- Smart compression for files over threshold
- Metadata tracking with compression stats
- Supports both images and videos
- SEO-friendly file naming

### S3Image Column

Display images in tables with modal preview:

```php
use Kirantimsina\FileManager\Tables\Columns\S3Image;

S3Image::make('image')
    ->label('Image')
    ->size('medium')     // Use specific size: 'small', 'medium', 'large'
    ->square()           // Square aspect ratio
    ->circular()         // Circular mask
```

## Media Metadata Management

The package includes a built-in Filament resource for managing media metadata:

1. **View all uploaded media** with file sizes, dimensions, and compression stats
2. **Manually trigger operations:**
   - Resize images to generate missing sizes
   - Compress images with custom quality
   - Delete resized versions
3. **Navigate to parent resources** directly from media entries
4. **Search and filter** by model type, field, or file name

## Service Methods

### Using the FileManager Facade

```php
use Kirantimsina\FileManager\Facades\FileManager;

// Upload a file
$path = FileManager::upload(
    model: 'Product',
    file: $uploadedFile,
    tag: 'summer-sale'
);

// Upload multiple files
$paths = FileManager::uploadImages(
    model: 'Product',
    files: $uploadedFiles,
    tag: 'gallery'
);

// Upload base64 encoded image
$path = FileManager::uploadBase64(
    model: 'Product',
    base64Image: $base64String,
    tag: 'user-upload'
);

// Move temp file without resizing
$path = FileManager::moveTempImageWithoutResize(
    model: 'Product',
    tempFile: 'temp/abc123.jpg'
);

// Move temp file with automatic resizing
$path = FileManager::moveTempImage(
    model: 'Product',
    tempFile: 'temp/abc123.jpg'
);

// Delete image and all its sizes
FileManager::deleteImage('products/image.jpg');

// Delete multiple images
FileManager::deleteImagesArray(['products/img1.jpg', 'products/img2.jpg']);

// Get SEO-friendly filename
$filename = FileManager::filename(
    file: $uploadedFile,
    tag: 'product-name',
    extension: 'webp'
);

// Get image URL with specific size
$url = FileManager::getMediaPath('products/image.jpg', 'medium');

// Get CDN URL
$cdnUrl = FileManager::mainMediaUrl();

// Get upload directory for a model
$directory = FileManager::getUploadDirectory('Product');

// Get configured image sizes
$sizes = FileManager::getImageSizes();
```

### Image Compression Service

```php
use Kirantimsina\FileManager\Services\ImageCompressionService;

$service = new ImageCompressionService();

// Compress and save
$result = $service->compressAndSave(
    sourcePath: '/tmp/upload.jpg',
    destinationPath: 'products/compressed.webp',
    quality: 85,
    height: 1080,
    width: null,  // Auto-calculate
    format: 'webp',
    mode: 'contain',
    disk: 's3'
);

// Compress existing S3 file
$result = $service->compressExisting(
    filePath: 'products/large-image.jpg',
    quality: 80
);
```

## Queue Jobs

The package uses queued jobs for better performance:

```bash
# Process resize jobs
php artisan queue:work

# Monitor queue
php artisan queue:monitor
```

**Available Jobs:**
- `ResizeImages` - Generate multiple sizes for uploaded images
- `DeleteImages` - Clean up images and all their sizes

## Helper Functions

```php
// Get image URL with specific size
$url = getImagePath('products/image.jpg', 'medium');

// Get CDN URL
$url = getCdnUrl('products/image.jpg');

// Check if file is an image
$isImage = isImageFile('document.pdf'); // false
```

## Advanced Usage

### Custom Image Sizes

Define custom sizes in your config:

```php
'image_sizes' => [
    'thumbnail' => 150,
    'card' => 400,
    'hero' => 1920,
    // Add your custom sizes
],
```

### Exclude Certain Fields from Resizing

Videos and certain file types are automatically excluded from resizing.

### Handling Nested Arrays

For complex data structures like checkout items:

```php
// The trait handles nested arrays intelligently
$checkout->items = [
    ['product_id' => 1, 'images' => ['image1.jpg', 'image2.jpg']],
    ['product_id' => 2, 'images' => ['image3.jpg']],
];
```

## Troubleshooting

### Images not resizing
- Ensure queue workers are running: `php artisan queue:work`
- Check that model directories are configured in `config/file-manager.php`
- Verify S3 permissions allow reading and writing

### Duplicate resize jobs
- Use `moveTempImageWithoutResize()` when the model has `HasImages` trait
- The trait automatically handles resizing on create/update

### WebP conversion failing
- Ensure GD or ImageMagick PHP extensions are installed
- Check PHP memory limit for large images

## Performance Tips

1. **Use queues** for image processing to avoid blocking requests
2. **Enable compression** for automatic file size optimization
3. **Configure CDN** for faster content delivery
4. **Set appropriate thresholds** to avoid compressing small files
5. **Use WebP format** for 25-35% smaller file sizes

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for recent changes.

## Contributing

Contributions are welcome! Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security

If you discover any security issues, please email kirantimsina3@gmail.com instead of using the issue tracker.

## Credits

- [Kiran Timsina](https://github.com/kirantimsina)
- [RayzenTech](https://www.rayzentech.com)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Support

For support, please open an issue on [GitHub](https://github.com/kirantimsina/file-manager/issues).
