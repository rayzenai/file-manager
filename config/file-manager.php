<?php

return [
    'cdn' => env('CDN_URL', env('AWS_URL', env('APP_URL'))),

    'max-upload-height' => '5120', // in pixels

    'max-upload-width' => '5120', // in pixels

    'max-upload-size' => '8192', // in KB - Default for images

    'max-upload-size-image' => '8192', // in KB - Max size for image uploads (8 MB)

    'max-upload-size-video' => '102400', // in KB - Max size for video uploads (100 MB)

    'max-upload-size-document' => '20480', // in KB - Max size for document uploads (20 MB)

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
        
        // Maximum allowed dimensions (hard limits - images will never exceed these)
        'max_height' => env('FILE_MANAGER_MAX_HEIGHT', 2160),
        'max_width' => env('FILE_MANAGER_MAX_WIDTH', 3840),
        
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
        'sd' => 480,    // 480px height for medium images
        'hd' => 720,     // 720px height for large images
        'fhd' => 1080,     // 1080px height for full size
        'qhd' => 1440,    // 1440px height for 2.5K images
        // 'uhd' => 2160,    // 2160px height for 4K images
    ],

    /**
     * Default thumbnail size image to load for MediaColumn components
     * This will be used as the default value for thumbnailSize() method
     */
    'default_thumbnail_size' => env('FILE_MANAGER_DEFAULT_THUMBNAIL_SIZE', 'icon'),
    'default_card_size' => env('FILE_MANAGER_DEFAULT_CARD_SIZE', 'card'),
    /**
     * Video Compression Settings
     */
    'video_compression' => [
        'enabled' => env('FILE_MANAGER_VIDEO_COMPRESSION_ENABLED', true),

        // Compression method: 'ffmpeg' or 'api'
        'method' => env('FILE_MANAGER_VIDEO_COMPRESSION_METHOD', 'ffmpeg'),

        // Output format: 'webm' (recommended) or 'mp4'
        'format' => env('FILE_MANAGER_VIDEO_COMPRESSION_FORMAT', 'webm'),

        // Video codec: 'libvpx-vp9' (WebM/VP9), 'libvpx' (WebM/VP8), 'libx264' (H.264), 'libx265' (H.265/HEVC)
        'video_codec' => env('FILE_MANAGER_VIDEO_CODEC', 'libvpx-vp9'),

        // Audio codec: 'libopus' (WebM), 'libvorbis' (WebM), 'aac' (MP4)
        'audio_codec' => env('FILE_MANAGER_AUDIO_CODEC', 'libopus'),

        // Video bitrate in kbps (lower = smaller file, lower quality)
        'video_bitrate' => env('FILE_MANAGER_VIDEO_BITRATE', 1000),

        // Audio bitrate in kbps
        'audio_bitrate' => env('FILE_MANAGER_AUDIO_BITRATE', 128),

        // Maximum dimensions (videos will be scaled down if larger)
        'max_width' => env('FILE_MANAGER_VIDEO_MAX_WIDTH', 1920),
        'max_height' => env('FILE_MANAGER_VIDEO_MAX_HEIGHT', 1080),

        // Frame rate limit (0 = no limit)
        'frame_rate' => env('FILE_MANAGER_VIDEO_FRAME_RATE', 30),

        // Encoding preset (ultrafast, fast, medium, slow, veryslow)
        // Slower presets give better compression
        'preset' => env('FILE_MANAGER_VIDEO_PRESET', 'medium'),

        // Constant Rate Factor (0-51 for H.264, 0-63 for VP9, lower = better quality)
        'crf' => env('FILE_MANAGER_VIDEO_CRF', 30),

        // Enable two-pass encoding for better quality (slower)
        'two_pass' => env('FILE_MANAGER_VIDEO_TWO_PASS', false),

        // Number of threads to use for encoding
        'threads' => env('FILE_MANAGER_VIDEO_THREADS', 4),

        // FFmpeg binary paths (leave null to use system default)
        'ffmpeg_path' => env('FILE_MANAGER_FFMPEG_PATH'),
        'ffprobe_path' => env('FILE_MANAGER_FFPROBE_PATH'),

        // Processing timeout in seconds (for large videos)
        'timeout' => env('FILE_MANAGER_VIDEO_TIMEOUT', 3600),

        // Generate thumbnail from video
        'generate_thumbnail' => env('FILE_MANAGER_VIDEO_THUMBNAIL', true),
        'thumbnail_time' => env('FILE_MANAGER_VIDEO_THUMBNAIL_TIME', 1.0), // seconds into video

        // Files larger than this will be compressed (in bytes)
        'threshold' => env('FILE_MANAGER_VIDEO_COMPRESSION_THRESHOLD', 5 * 1024 * 1024), // 5MB
    ],

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