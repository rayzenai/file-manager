<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Livewire;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Kirantimsina\FileManager\Facades\FileManager;
use Livewire\Component;

class MediaPage extends Component
{
    public $img;

    public $title;

    public $directory;

    public $slug;

    public $field;

    public function mount(string $directory, string $slug)
    {
        $this->directory = $directory;
        $this->slug = $slug;

        // Retrieve the field name from the query parameters, defaulting to 'file'
        $this->field = request('field', 'file');

        // Get the model mapping from your config.
        $modelMapping = config('file-manager.model');

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

        // Fetch the record based on the slug.
        $record = $modelClass::where('slug', $slug)->firstOrFail();

        // Get the image/media file from the record.
        $this->img = FileManager::getMediaPath($record->{$this->field});

        // Set the title using the slug.
        $this->title = Str::title(Str::singular(Arr::first(explode('/', $slug))));

        if (request('counter') && Schema::hasColumn($record->getTable(), request('counter'))) {
            $record->increment(request('counter'));
        }
    }

    public function render()
    {
        return view('file-manager::livewire.media-page')->layout('file-manager::layouts.blank');
    }
}
