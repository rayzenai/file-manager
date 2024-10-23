
[![Latest Version on Packagist](https://img.shields.io/packagist/v/rayzenai/file-manager.svg?style=flat-square)](https://packagist.org/packages/rayzenai/file-manager)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/rayzenai/file-manager/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/rayzenai/file-manager/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/rayzenai/file-manager/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/rayzenai/file-manager/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/rayzenai/file-manager.svg?style=flat-square)](https://packagist.org/packages/rayzenai/file-manager)


# About This Package

This package serves as a comprehensive file manager designed to efficiently handle all files on the server. It allows users to upload and retrieve files while ensuring they are stored in an organized manner. 

This package is developed by RayzenTech.

# RayzenTech

RayzenTech is a Nepali tech startup company specializing in building business solutions. We are all about innovation, automation, and making work a breeze. From business process automation to robotic process automation, we turn tricky tasks into easy workflows.

Join us in turning great ideas into simple solutions and making the future brighter, one project at a time.

For more details, reach out here: [www.rayzentech.com](https://www.rayzentech.com)

## Installation

You can install the package via composer:

```bash
composer require rayzenai/file-manager
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="file-manager-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="file-manager-config"
```

```bash
php artisan vendor:publish --tag="path-config"
```

This is the path file which handles models repespective to their tables name.

```php
return [
];
```

Optionally, you can publish the views using

```bash
php artisan vendor:publish --tag="file-manager-views"
```

## Usage

```php
$fileManager = new Kirantimsina\FileManager();
echo $fileManager->echoPhrase('Hello, Kirantimsina!');
```

## Testing

```bash
composer test
```

## Changelog

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
