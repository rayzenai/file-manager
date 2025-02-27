<?php

declare(strict_types=1);

namespace Kirantimsina\FileManager\Livewire;

use Livewire\Component;

class MediaModal extends Component
{
    public $images = [];

    public function mount($images)
    {
        $this->images = $images;
    }

    public function render()
    {
        return view('livewire.media-modal');
    }
}
