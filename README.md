[![Latest Version on Packagist](https://img.shields.io/packagist/v/kirantimsina/file-manager.svg?style=flat-square)](https://packagist.org/packages/kirantimsina/file-manager)
[![Total Downloads](https://img.shields.io/packagist/dt/kirantimsina/file-manager.svg?style=flat-square)](https://packagist.org/packages/kirantimsina/file-manager)

# Laravel File Manager for Filament

A comprehensive Laravel package for **Filament v4** that provides advanced file management with automatic image resizing, compression, media metadata tracking, and seamless S3 integration. Built for high-performance applications handling large volumes of media content.

This package is developed and maintained by **Kiran Timsina** and **RayzenTech**.

## 🚀 What's New in v4.3

### 🎯 Dual API Architecture

**Two specialized APIs for optimal performance:**

-   **AWS Lambda API**: Lightning-fast compression for standard image processing
-   **Google Cloud Run API**: AI-powered background removal and advanced processing

![Dual API Architecture](docs/images/dual-api-architecture.webp)

### 🔧 Enhanced Compression Driver System

Choose your processing method with the new `->driver()` method:

```php
MediaUpload::make('image')
    ->driver('api')  // Use external API
    ->driver('gd')   // Use local GD library
```

![Driver Selection](docs/images/driver-selection.webp)

### 🖼️ Interactive Image Processor

New dedicated page for testing and processing images directly in the admin panel:

-   Test different compression settings
-   Preview before/after results
-   Compare file sizes and quality
-   Test background removal

![Image Processor](docs/images/image-processor.webp)

### 📊 Enhanced Media Metadata Resource

-   **Navigation badges** showing large file counts
-   **Bulk operations** for compression and resizing
-   **Smart routing** to parent resources
-   **Detailed compression statistics**

![Media Metadata Resource](docs/images/metadata-dashboard.webp)

### 🛠️ Improved CLI Commands

Better `populate-metadata` command with:

-   Progress bars for visual feedback
-   Support for both short and full class names
-   Dry-run mode for testing
-   Synchronous processing option

![CLI Progress](docs/images/cli-progress.webp)

## Key Features

### Core Features

-   🖼️ **Automatic Image Resizing** - Generate multiple sizes automatically on upload
-   🗜️ **Smart Compression** - WebP/AVIF conversion with configurable quality settings
-   🎭 **AI Background Removal** - Remove backgrounds from images using Cloud Run API
-   📊 **Media Metadata Tracking** - Track file sizes, dimensions, and compression stats
-   ☁️ **S3 Integration** - Seamless AWS S3 storage with CDN support

### Advanced Processing

-   ⚡ **Dual API System** - Fast Lambda API for compression, Cloud Run for AI features
-   🎨 **Flexible Driver System** - Choose between GD library or external APIs
-   🖼️ **Interactive Processor** - Test and process images directly in admin panel
-   📈 **Bulk Operations** - Process multiple files with detailed progress tracking
-   🔄 **Smart Fallbacks** - Automatic fallback to GD when API unavailable

### Developer Experience

-   🎨 **Custom Filament Components** - MediaUpload and S3Image components
-   🔍 **Advanced Resource Management** - Built-in media metadata interface with bulk actions
-   🚀 **Performance Optimized** - Queue-based processing with chunked operations
-   🔧 **Highly Configurable** - Extensive configuration with environment variables
-   📝 **Comprehensive CLI** - Powerful artisan commands with progress tracking

## About the Developers

**Kiran Timsina** is a full-stack developer specializing in Laravel and Filament applications. Connect on [GitHub](https://github.com/kirantimsina).

**RayzenTech** is a tech startup based in Nepal focused on creating smart business solutions. We specialize in automating complex processes and making them simple, from business automation to robotic process automation. Learn more at [RayzenTech](https://www.rayzentech.com).

---

## Requirements

-   PHP 8.1+
-   Laravel 10.0+
-   Filament 4.0+
-   AWS S3 configured (or S3-compatible storage)

## Installation

1. **Install via Composer:**

    ```bash
    composer require rayzenai/file-manager
    ```

2. **Publish the configuration:**

    ```bash
    php artisan vendor:publish --tag="file-manager-config"
    ```

3. **Publish and run migrations (for media metadata):**

    ```bash
    # Publish migration files to your app
    php artisan vendor:publish --provider="Kirantimsina\FileManager\FileManagerServiceProvider" --tag="file-manager-migrations"

    # Run the migrations
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

    # Compression Method Settings
    FILE_MANAGER_COMPRESSION_METHOD=gd  # 'gd' for built-in PHP processing or 'api' for external service

    # Primary API Settings (Fast compression, no background removal)
    FILE_MANAGER_COMPRESSION_API_URL=https://your-aws-lambda-url.com/process-image
    FILE_MANAGER_COMPRESSION_API_TOKEN=your-api-token
    FILE_MANAGER_COMPRESSION_API_TIMEOUT=30

    # Background Removal API Settings (Slower, supports background removal)
    FILE_MANAGER_BG_REMOVAL_API_URL=https://your-gcp-run-url.com/process-image
    FILE_MANAGER_BG_REMOVAL_API_TOKEN=your-bg-removal-token
    FILE_MANAGER_BG_REMOVAL_API_TIMEOUT=60
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
    'cdn' => env('CDN_URL', env('AWS_URL', env('APP_URL'))),

    // Maximum upload dimensions
    'max-upload-height' => '5120', // pixels
    'max-upload-width' => '5120',  // pixels
    'max-upload-size' => '8192',   // KB

    // Model to directory mappings (supports full class names)
    'model' => [
        'App\Models\User' => 'users',       // Or use User::class
        'App\Models\Product' => 'products', // Or use Product::class
        'App\Models\Blog' => 'blogs',       // Or use Blog::class
        // Add your models here
    ],

    // Image sizes to generate
    // Set to empty array [] to disable automatic resizing completely
    'image_sizes' => [
        'icon' => 64,       // 64px height for small icons
        'small' => 120,     // 120px height for small thumbnails
        'thumb' => 240,     // 240px height for thumbnails
        'card' => 360,      // 360px height for card images
        'medium' => 480,    // 480px height for medium images
        'large' => 720,     // 720px height for large images
        'full' => 1080,     // 1080px height for full size
        'ultra' => 2160,    // 2160px height for ultra HD
    ],

    // Default thumbnail size for MediaColumn components
    'default_thumbnail_size' => env('FILE_MANAGER_DEFAULT_THUMBNAIL_SIZE', 'icon'),

    // Compression settings
    'compression' => [
        'enabled' => true,
        'method' => 'gd', // 'gd' or 'api'
        'auto_compress' => true,
        'quality' => 85,
        'format' => 'webp', // webp, jpeg, png, avif
        'mode' => 'contain', // contain, crop, cover
        'height' => 1080,
        'width' => null, // Auto-calculate
        'threshold' => 100 * 1024, // 100KB

        // API settings for external compression
        'api' => [
            // Primary API (AWS Lambda - fast, no background removal)
            'url' => env('FILE_MANAGER_COMPRESSION_API_URL', ''),
            'token' => env('FILE_MANAGER_COMPRESSION_API_TOKEN', ''),
            'timeout' => 30,

            // Background removal API (Google Cloud Run - slower, supports bg removal)
            'bg_removal' => [
                'url' => env('FILE_MANAGER_BG_REMOVAL_API_URL', ''),
                'token' => env('FILE_MANAGER_BG_REMOVAL_API_TOKEN', ''),
                'timeout' => 60,
            ],
        ],
    ],

    // Media metadata tracking
    'media_metadata' => [
        'enabled' => true,
        'track_file_size' => true,
        'track_dimensions' => true,
        'track_mime_type' => true,
        'model' => \Kirantimsina\FileManager\Models\MediaMetadata::class,
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

    // Define which fields contain images for automatic resizing. Use `public` so that this is accessible by view helpers
    public function hasImagesTraitFields(): array
    {
        return ['image', 'gallery'];
    }
}
```

**Key Features:**

-   Automatically generates multiple sizes when images are saved
-   Handles both single images and arrays of images
-   Smart diffing - only resizes truly new images
-   Automatic cleanup of old images when replaced
-   Queue-based processing for better performance

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
    ->removeBg()                // Remove background (requires API, default: false)
    ->driver('api')             // Compression driver: 'gd' or 'api' (default: from config)
    ->multiple()                // Allow multiple files
    ->directory('custom-dir')   // Custom directory (optional)
```

**Features:**

-   Automatic WebP conversion for better performance
-   Smart compression for files over threshold
-   AI-powered background removal (API only)
-   Metadata tracking with compression stats
-   Supports both images and videos
-   SEO-friendly file naming

#### Background Removal

The `removeBg()` method enables AI-powered background removal for images. This feature **requires external API configuration** (specifically the background removal API endpoint) and is not available with the GD library method.

#### Compression Drivers

You can specify which compression driver to use:

```php
// Use GD library (built-in PHP processing)
MediaUpload::make('image')
    ->driver('gd')

// Use external API service
MediaUpload::make('image')
    ->driver('api')
```

The package supports two external APIs:

-   **Primary API** (AWS Lambda): Fast compression without background removal
-   **Background Removal API** (Google Cloud Run): Slower but supports AI background removal

```php
// Enable background removal with boolean
MediaUpload::make('image')
    ->removeBg(true)

// Or use the convenience method
MediaUpload::make('image')
    ->withoutBackground()

// Dynamic background removal with closure
use Filament\Forms\Components\Toggle;

Toggle::make('remove_bg')
    ->label('Remove Background')
    ->dehydrated(false)  // Don't save to database

MediaUpload::make('image')
    ->removeBg(fn (Get $get) => $get('remove_bg'))
```

**Important:** When using Toggle fields for background removal control, use `dehydrated(false)` or handle the field in `mutateFormDataBeforeCreate()` and `mutateFormDataBeforeSave()` to prevent database errors:

```php
// In your Filament Resource
protected function mutateFormDataBeforeCreate(array $data): array
{
    unset($data['remove_bg']);
    return $data;
}

protected function mutateFormDataBeforeSave(array $data): array
{
    unset($data['remove_bg']);
    return $data;
}
```

### MediaColumn

Display images in tables with modal preview and optional editing capabilities:

```php
use Kirantimsina\FileManager\Tables\Columns\MediaColumn;

// Basic usage for direct model fields
MediaColumn::make(
    field: 'image_file_name',
    size: 'small',
    label: 'Product Image'
)

// With modal for viewing and editing
MediaColumn::make(
    field: 'image_file_name',
    size: 'small',
    showInModal: true,
    label: 'Product Image'
)

// For relationship fields (NEW in v4.3+)
MediaColumn::make(
    field: 'attachment_file_name',
    size: 'extra-small',
    showInModal: true,
    relationship: 'attachments'  // Relationship name
)->label('Attachments')

// Legacy dot notation (still supported)
MediaColumn::make(
    field: 'product.image_file_name',
    size: 'small',
    label: 'Product Image'
)
```

**Parameters:**
- `field`: The field name on the model (or related model when using relationship)
- `size`: Image size preset ('icon', 'small', 'medium', 'large', etc.)
- `showInModal`: Enable modal for viewing/editing (default: false)
- `modalSize`: Size for modal preview images
- `label`: Column label
- `heading`: Modal heading (closure or string)
- `viewCountField`: Field to track view counts
- `showMetadata`: Show file metadata in tooltip
- `relationship`: Name of the Eloquent relationship (for HasMany, HasOne, BelongsTo)

**Features:**
- **Direct field access**: Works with model attributes directly
- **Relationship support**: Access images through Eloquent relationships
- **Dot notation**: Legacy support for nested relationships
- **Modal editing**: View and replace images through modal interface
- **Multiple images**: Handles both single and multiple image fields
- **Smart loading**: Automatically loads relationships to prevent N+1 queries
- **Metadata display**: Optional file size and type information

**Relationship Support (v4.3+):**

The `relationship` parameter allows you to display and manage images from related models:

```php
// In your model
class CartItem extends Model
{
    public function attachments(): HasMany
    {
        return $this->hasMany(AttachmentFile::class);
    }
}

// In your Filament resource
MediaColumn::make(
    field: 'attachment_file_name',  // Field on the AttachmentFile model
    relationship: 'attachments',     // Relationship method name
    showInModal: true
)
```

This will:
- Display all attachment images in the table column
- Allow viewing all images in a modal
- Enable uploading new images that will replace existing attachments
- Handle HasMany, HasOne, and BelongsTo relationships automatically

#### Default Thumbnail Size

You can configure the default thumbnail size for all `MediaColumn` components by setting the `default_thumbnail_size` in your configuration:

```php
// config/file-manager.php
'default_thumbnail_size' => 'thumb', // Use 'thumb' instead of 'icon' as default
```

Or via environment variable:

```env
FILE_MANAGER_DEFAULT_THUMBNAIL_SIZE=thumb
```

Individual columns can still override this default by calling the `thumbnailSize()` method:

```php
MediaColumn::make('image')
    ->thumbnailSize('large') // This overrides the default
```

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

The package includes a powerful Filament resource for comprehensive media management.

![Media Metadata Dashboard](docs/images/metadata-dashboard.webp)

### Dashboard Features

#### Navigation & Monitoring

-   **Smart Navigation Badge**: Real-time count of large files (>500KB)
    -   🔵 Info: 1-50 large files
    -   🟡 Warning: 51-100 large files
    -   🔴 Danger: 100+ large files
-   **Automatic cache refresh** every 5 minutes

#### File Management

-   **Comprehensive file listing** with:
    -   Model type and ID
    -   Field name
    -   File size with human-readable format
    -   Image dimensions (width × height)
    -   MIME type with color-coded badges
    -   Creation and update timestamps

#### Advanced Filtering

-   **Quick Filters**:
    -   Large Files (>500KB)
    -   Very Large Files (>2MB)
-   **Model Type Filter**: Filter by specific models
-   **File Type Filter**: Filter by MIME type
-   **Search**: Find files by name or path

![Filtering Options](docs/images/metadata-filters.webp)

### Individual File Actions

![File Actions Menu](docs/images/file-actions.webp)

1. **Open in Panel**: Navigate directly to the parent resource
2. **Resize**: Generate all configured size variations
3. **Compress**: Apply custom compression settings
4. **Rename**: Update file names in database
5. **Delete Resized**: Remove all size variations

### Bulk Operations

![Bulk Operations](docs/images/bulk-operations.webp)

#### Bulk Compress

-   Select multiple images for compression
-   Choose output format (WebP, AVIF, JPEG, PNG)
-   Set compression quality (50-100%)
-   Option to replace originals
-   Detailed progress reporting

#### Bulk Resize

-   Generate all size variations for selected images
-   Queue-based processing for performance
-   Progress notifications

#### Bulk Delete Resized

-   Remove all resized versions for selected images
-   Confirmation dialog with warnings
-   Batch processing with result summary

### Image Processor Page

The MediaMetadata resource includes a dedicated **Image Processor** page - a powerful tool for testing and optimizing your image processing pipeline.

![Image Processor Interface](docs/images/processor-interface.webp)

#### Features:

**Upload & Process**

-   Drag-and-drop or click to upload images up to 10MB
-   Support for JPEG, PNG, WebP, and AVIF formats
-   Real-time preview of uploaded images

**Processing Options**

-   **Format Selection**: Convert between WebP, JPEG, PNG, and AVIF
-   **Quality Control**: Adjust compression from 50% to 100%
-   **Resizing**: Set custom dimensions with multiple resize modes
-   **Background Removal**: AI-powered background removal (when API configured)

**Compression Methods**

-   **Auto**: Intelligently selects the best available method
-   **Lambda API**: Fast compression via AWS Lambda
-   **Cloud Run API**: Advanced features including background removal
-   **GD Library**: Local processing fallback

**Results & Analytics**

-   Side-by-side comparison of original vs processed
-   Detailed statistics:
    -   Original and compressed file sizes
    -   Space saved (KB and percentage)
    -   Final dimensions
    -   Processing method used
-   Download processed images directly

![Processing Results](docs/images/processor-results.webp)

#### Use Cases:

1. **Test compression settings** before applying to production
2. **Optimize images** for specific use cases
3. **Validate API configuration** and performance
4. **Compare processing methods** (GD vs API)
5. **Generate samples** for documentation

## Service Methods

### Using the FileManager Facade

```php
use App\Models\Product;
use Kirantimsina\FileManager\Facades\FileManager;

// Upload a file
$result = FileManager::upload(
    Product::class,      // model class
    $uploadedFile,       // file
    'summer-sale',       // tag (optional)
    false,               // fit (optional, default: false)
    true,                // resize (optional, default: true)
    false,               // webp (optional, default: false)
    false                // reencode (optional, default: false)
);
$path = $result['file']; // Get the file path from result

// Upload multiple files
$result = FileManager::uploadImages(
    Product::class,      // model class
    $uploadedFiles,      // files array
    'gallery',           // tag (optional)
    false,               // fit (optional)
    true                 // resize (optional)
);
$paths = $result['files']; // Get array of file paths

// Upload base64 encoded image
$result = FileManager::uploadBase64(
    Product::class,      // model class
    $base64String,       // base64 image
    'user-upload',       // tag (optional)
    false,               // fit (optional)
    true                 // resize (optional)
);
$path = $result['file']; // Get the file path from result

// Move temp file without resizing
$path = FileManager::moveTempImageWithoutResize(
    Product::class,      // model class
    'temp/abc123.jpg'    // temp file path
);

// Move temp file with automatic resizing
$path = FileManager::moveTempImage(
    Product::class,      // model class
    'temp/abc123.jpg'    // temp file path
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
$directory = FileManager::getUploadDirectory(Product::class);

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
    disk: 's3',
    removeBg: false  // Set to true for background removal (API only)
);

// Compress with background removal (requires API configuration)
$result = $service->compressAndSave(
    sourcePath: '/tmp/product.jpg',
    destinationPath: 'products/product-no-bg.webp',
    quality: 90,
    height: 720,
    width: null,
    format: 'webp',
    mode: 'contain',
    disk: 's3',
    removeBg: true  // Requires FILE_MANAGER_COMPRESSION_METHOD=api
);

// Compress existing S3 file
$result = $service->compressExisting(
    filePath: 'products/large-image.jpg',
    quality: 80
);
```

**Compression Methods:**

-   **GD Library (`method: 'gd'`)**: Built-in PHP image processing. Supports resizing, format conversion, and basic compression. Does not support background removal.
-   **External API (`method: 'api'`)**: Uses external image processing service. Supports all GD features plus AI-powered background removal. The package intelligently routes requests:
    -   Standard compression requests go to the primary API (faster)
    -   Background removal requests go to the specialized background removal API (slower but more features)
    -   Falls back to GD if API is unavailable

## Queue Jobs

The package uses queued jobs for better performance:

```bash
# Process resize jobs
php artisan queue:work

# Monitor queue
php artisan queue:monitor
```

**Available Jobs:**

-   `ResizeImages` - Generate multiple sizes for uploaded images
-   `DeleteImages` - Clean up images and all their sizes
-   `PopulateMediaMetadataJob` - Populate media metadata for existing images

## Artisan Commands

### Populate Media Metadata

If you have existing images in your database before installing this package, you can populate their metadata:

```bash
# Populate metadata for all configured models
php artisan file-manager:populate-metadata

# Populate metadata for a specific model (supports both short and full class names)
php artisan file-manager:populate-metadata --model=Product
php artisan file-manager:populate-metadata --model="App\Models\Product"

# Populate metadata for a specific field
php artisan file-manager:populate-metadata --model=Product --field=image_file_name

# Process with custom chunk size (default is 1000)
php artisan file-manager:populate-metadata --chunk=500

# Process synchronously without queue (good for testing)
php artisan file-manager:populate-metadata --model=Product --sync --chunk=100

# Dry run to see what would be processed
php artisan file-manager:populate-metadata --dry-run
```

**Improvements in the latest version:**

-   Better model class resolution (supports both short names and full namespaces)
-   Progress bar for tracking processing status
-   Improved error handling and reporting
-   Memory-efficient chunked processing
-   Dry-run mode for testing
-   Synchronous mode for immediate processing

This command will:

1. Scan configured models that use the HasImages trait
2. Process records in batches to avoid memory issues
3. Create MediaMetadata records for existing images
4. Dispatch jobs to handle large datasets efficiently
5. Extract file information including size, mime type, and dimensions

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

### Disabling Automatic Resizing

To completely disable automatic image resizing, set the `image_sizes` config to an empty array:

```php
'image_sizes' => [],
```

This is useful when:
- You want to handle image resizing manually
- You're working with vector graphics or images that shouldn't be resized
- You want to optimize storage by keeping only original images
- You're using an external service for image processing

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

-   Ensure queue workers are running: `php artisan queue:work`
-   Check that model directories are configured in `config/file-manager.php`
-   Verify S3 permissions allow reading and writing

### Duplicate resize jobs

-   Use `moveTempImageWithoutResize()` when the model has `HasImages` trait
-   The trait automatically handles resizing on create/update

### WebP conversion failing

-   Ensure GD or ImageMagick PHP extensions are installed
-   Check PHP memory limit for large images

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

If you discover any security issues, please email timsinakiran@gmail.com instead of using the issue tracker.

## Credits

-   [Kiran Timsina](https://github.com/kirantimsina)
-   [RayzenTech](https://www.rayzentech.com)
-   [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Support

For support, please open an issue on [GitHub](https://github.com/kirantimsina/file-manager/issues).
