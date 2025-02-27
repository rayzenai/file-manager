<?php

use Illuminate\Support\Facades\Route;
use Kirantimsina\FileManager\Livewire\MediaPage;

// Define a route for the mockup controller
Route::get('/media-page/{directory}/{slug}', MediaPage::class)
    ->where('slug', '.*')
    ->name('media.page');