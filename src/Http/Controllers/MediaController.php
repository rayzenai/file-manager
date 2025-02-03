<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Kirantimsina\FileManager\Facades\FileManager;

class MediaController extends Controller
{
    public function index(string $slug)
    {
        $img = FileManager::getMediaPath($slug);

        // Write the code to update views
        return view('file-manager::media-page')->with(
            [
                'img' => $img,
                'title' => Str::title(Str::singular(Arr::first(explode('/', $slug)))),
            ]
        );
    }
}
