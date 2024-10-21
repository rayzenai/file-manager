<?php

namespace Kirantimsina\FileManager\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Kirantimsina\FileManager\FileManager
 */
class FileManager extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Kirantimsina\FileManager\FileManager::class;
    }
}
