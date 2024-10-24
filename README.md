[![Latest Version on Packagist](https://img.shields.io/packagist/v/rayzenai/file-manager.svg?style=flat-square)](https://packagist.org/packages/rayzenai/file-manager)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/rayzenai/file-manager/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/rayzenai/file-manager/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/rayzenai/file-manager/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/rayzenai/file-manager/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/rayzenai/file-manager.svg?style=flat-square)](https://packagist.org/packages/rayzenai/file-manager)

# RayzenAI File Manager for Filament

The **RayzenAI File Manager** is a Laravel package built specifically for **Filament**, providing a user-friendly, SEO-optimized way to manage images and files in your application. It integrates seamlessly with Filament, making it easy to handle file uploads, retrieval, and organization, all while ensuring SEO best practices.

This package is developed and maintained by **RayzenTech**.

## About RayzenTech

**RayzenTech** is a tech startup based in Nepal focused on creating smart business solutions. We specialize in automating complex processes and making them simple, from business automation to robotic process automation. Our goal is to make life easier with innovative technology.

Learn more about us at [RayzenTech](https://www.rayzentech.com).

---

## Installation

Follow these steps to install the package in your Filament-powered Laravel application:

1. Install the package via Composer:

    ```php
    composer require rayzenai/file-manager
    ```

2. Publish the configuration file with:

    ```php
    php artisan vendor:publish --tag="file-manager-config"
    ```

    This will generate a configuration file `file-manager.php` in config folder where you can customize the file manager settings.

3. Define the models and their corresponding path names in the configuration file. Example:

    ```php
    <?php

    return [
        'User' => 'users', // Define other models and their path names here according to your project
    ];
    ```

---

## Usage in Model

To use the **RayzenAI File Manager** in your Filament project, you need to implement the `HasImages` interface in the models that handle file management. Here's how:

1. **Use the `HasImages` Trait:**

   In the model where you want to manage files, use the `HasImages` trait. This allows your model to manage file uploads and retrievals easily.

   Example:

    ```php

    use Kirantimsina\FileManager\Traits\HasImages;

    class YourModel extends Model implements InterfacesHasImages
    {
        use HasFactory, HasImages;

        protected $guarded = ['id']; // This protects the 'id' field

        // We define the 'images' field as an array
        protected $casts = [
            'images' => 'array',
        ];

        // Specify which fields in this model will handle images
        protected function hasImagesTraitFields(): array
        {
            return ['images'];
        }
    }
    ```

### Explanation:

- **`HasImages Interface`**: This interface tells the package that this model will manage images or files.
- **`HasImages Trait`**: This trait provides the actual methods for uploading, retrieving, and storing images.
- **`$casts`**: We cast the `images` field to an array so you can easily store multiple image paths in one field.
- **`hasImagesTraitFields`**: This method defines which fields (in this case, `images`) will be used to handle images.

After setting this up, your Filament-powered model will be ready to handle file uploads, organize them efficiently, and ensure everything is SEO-friendly.

---

## Usage in Resource

### ImageUpload

The `ImageUpload` component works similarly to Filament's `FileUpload`, but with additional customizations that enhance file management. It systematically saves your files and generates SEO-friendly URLs. Essentially, it extends Filament's `FileUpload` functionality to improve its usability and performance.

```php
ImageUpload::make('images')
    ->label('Images'),
```

### S3Image
The `S3Image` is an enhanced version of the `ImageColumn`. It displays images in a table, and when an image is clicked, a larger view opens in a side modal. Like ImageColumn, it is built on top of Filament but includes additional customization features to improve the user experience.


```php
S3Image::make('images')
     ->label('Images'),
```

# Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [kirantimsina](https://github.com/rayzenai)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.



