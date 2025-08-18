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

        // Get the model mapping from your config.
        $modelMapping = config('file-manager.model');

        // Reverse the mapping to find the fully qualified class name.
        $modelClass = null;
        foreach ($modelMapping as $className => $alias) {
            if ($alias === $directory) {
                // Check if $className is already a fully qualified class name
                if (class_exists($className)) {
                    $modelClass = $className;
                } else {
                    // Fallback: Assuming your models reside in the App\Models namespace.
                    $modelClass = "App\\Models\\{$className}";
                }
                break;
            }
        }

        if (! $modelClass || ! class_exists($modelClass)) {
            abort(404, 'Invalid model type.');
        }

        // Fetch the record based on the slug.
        $record = $modelClass::where('slug', $slug)->firstOrFail();

        if (request('field')) {
            $this->field = [request('field')];
        } else {
            $this->field = $record->hasImagesTraitFields();
            // TODO: Right now, we are showing only one image. Instead, we need to show all by fetching all the fields.
        }

        // Get the image/media file from the record.
        $images = [];
        foreach ($this->field as $field) {
            foreach (collect($record->{$field}) as $image) {
                // We are collecting again since this can also be an json type field
                $images[] = FileManager::getMediaPath($image);
            }
        }
        $this->img = $images;

        // Set the title using the slug.
        $this->title = Str::title(Str::singular(Arr::first(explode('/', $slug))));

        if (request('counter') && Schema::hasColumn($record->getTable(), request('counter'))) {
            $record->increment(request('counter'));
        }
    }

    public function render()
    {
        if (count($this->img) === 1) {
            return view('file-manager::livewire.single-media-page')->layout('file-manager::layouts.blank');
        }

        return view('file-manager::livewire.multi-media-page')->layout('file-manager::layouts.blank');
    }
}
