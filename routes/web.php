<?php

use Illuminate\Support\Facades\Route;
use Kirantimsina\FileManager\Http\Controllers\MediaController;

// Define a route for the mockup controller
Route::get('/media-page/{directory}/{slug}', [MediaController::class, 'index'])
    ->where('slug', '.*')
    ->name('media.page');