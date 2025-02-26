<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Kirantimsina\FileManager\Facades\FileManager;

class MediaController extends Controller
{
    public function index(string $directory, string $slug)
    {

        // Get the model mapping from your config.
        $modelMapping = $modelMapping = config('file-manager.model');

        // Reverse the mapping to find the fully qualified class name.
        $modelClass = null;

        foreach ($modelMapping as $className => $alias) {

            if ($alias === $directory) {
                // Assuming your models reside in the App\Models namespace.
                $modelClass = "App\\Models\\{$className}";
                break;
            }
        }

        if (! $modelClass || ! class_exists($modelClass)) {
            abort(404, 'Invalid model type.');
        }

        // Retrieve the field name from the query parameters, defaulting to 'file'
        $field = request('field', 'file');

        // Fetch the record based on the slug.
        $record = $modelClass::where('slug', $slug)->firstOrFail();

        // Get the image/media file from the record.
        $img = FileManager::getMediaPath($record->{$field});

        // Return the view using your specified configuration.
        return view('file-manager::media-page')->with([
            'img' => $img,
            'title' => Str::title(Str::singular(Arr::first(explode('/', $slug)))),
        ]);
    }
}
