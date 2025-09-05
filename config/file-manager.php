<?php

return [
    'cdn' => env('CDN_URL', env('AWS_URL', env('APP_URL'))),

    'max-upload-height' => '5120', // in pixels

    'max-upload-width' => '5120', // in pixels

    'max-upload-size' => '8192', // in KB

    // The ModelName => path syntax is used to store the uploaded files in the respective folder
    'model' => [
        'App\Models\User' => 'users', // Or use User::class
        'App\Models\Mockup' => 'mockups', // Mockup::class
        'App\Models\Product' => 'products', // Product::class
    ],

    /**
     * Image Compression Settings
     */
    'compression' => [
        'enabled' => env('FILE_MANAGER_COMPRESSION_ENABLED', true),
        
        // Compression method: 'gd' or 'api'
        'method' => env('FILE_MANAGER_COMPRESSION_METHOD', 'gd'),
        
        // Automatically compress on upload
        'auto_compress' => env('FILE_MANAGER_AUTO_COMPRESS', true),
        
        // Compression quality (1-100)
        'quality' => env('FILE_MANAGER_COMPRESSION_QUALITY', 85),
        
        // Output format: webp, jpeg, png, avif
        'format' => env('FILE_MANAGER_COMPRESSION_FORMAT', 'webp'),
        
        // Resize mode: contain, crop, cover
        'mode' => env('FILE_MANAGER_COMPRESSION_MODE', 'contain'),
        
        // Default dimensions
        'height' => env('FILE_MANAGER_COMPRESSION_HEIGHT', 2160),
        'width' => env('FILE_MANAGER_COMPRESSION_WIDTH', null),
        
        // Files larger than this will be compressed (in bytes)
        'threshold' => env('FILE_MANAGER_COMPRESSION_THRESHOLD', 100 * 1024), // 100KB
        
        // API settings (if using external compression API)
        'api' => [
            // Primary API (AWS Lambda - fast, no background removal)
            'url' => env('FILE_MANAGER_COMPRESSION_API_URL', ''),
            'token' => env('FILE_MANAGER_COMPRESSION_API_TOKEN', ''),
            'timeout' => env('FILE_MANAGER_COMPRESSION_API_TIMEOUT', 30),
            
            // Background removal API (Google Cloud Run - slower, supports bg removal)
            'bg_removal' => [
                'url' => env('FILE_MANAGER_BG_REMOVAL_API_URL', ''),
                'token' => env('FILE_MANAGER_BG_REMOVAL_API_TOKEN', ''),
                'timeout' => env('FILE_MANAGER_BG_REMOVAL_API_TIMEOUT', 60),
            ],
        ],
    ],

    /**
     * Media Metadata Settings
     */
    'media_metadata' => [
        'enabled' => env('FILE_MANAGER_METADATA_ENABLED', true),
        
        // Track file size
        'track_file_size' => env('FILE_MANAGER_TRACK_FILE_SIZE', true),
        
        // Track image dimensions
        'track_dimensions' => env('FILE_MANAGER_TRACK_DIMENSIONS', true),
        
        // Track MIME type
        'track_mime_type' => env('FILE_MANAGER_TRACK_MIME_TYPE', true),
        
        // Model to use for metadata
        'model' => \Kirantimsina\FileManager\Models\MediaMetadata::class,
    ],

    /**
     * Image Resize Settings
     * Define the sizes that should be created when an image is uploaded
     * Format: 'size_name' => height in pixels
     * The width will be calculated automatically to maintain aspect ratio
     * 
     * Set to empty array [] to disable automatic resizing completely:
     * 'image_sizes' => [],
     */
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

    /**
     * Default thumbnail size image to load for MediaColumn components
     * This will be used as the default value for thumbnailSize() method
     */
    'default_thumbnail_size' => env('FILE_MANAGER_DEFAULT_THUMBNAIL_SIZE', 'icon'),

    /**
     * Cache Control Settings for Images
     * Configure browser and CDN caching for uploaded images
     */
    'cache' => [
        // Enable cache headers for images
        'enabled' => env('FILE_MANAGER_CACHE_ENABLED', true),
        
        // Cache duration in seconds (default: 31536000 = 1 year)
        // Common values:
        // 3600 = 1 hour
        // 86400 = 1 day
        // 604800 = 1 week
        // 2592000 = 30 days
        // 31536000 = 1 year
        'max_age' => env('FILE_MANAGER_CACHE_MAX_AGE', 31536000),
        
        // Cache control directive
        // Options: 'public' (can be cached by browsers and CDNs)
        //          'private' (only cached by browsers, not CDNs)
        'visibility' => env('FILE_MANAGER_CACHE_VISIBILITY', 'public'),
        
        // Whether to use immutable directive (tells browsers the file will never change)
        // This is recommended for versioned/hashed filenames
        'immutable' => env('FILE_MANAGER_CACHE_IMMUTABLE', true),
    ],
];