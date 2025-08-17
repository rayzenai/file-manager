<x-filament-panels::page>
    <form wire:submit="processImage">
        {{ $this->form }}

        <div class="flex items-center gap-4 mt-6">
            <x-filament::button type="submit" wire:loading.attr="disabled">
                <x-filament::loading-indicator class="h-5 w-5" wire:loading wire:target="processImage" />
                <span wire:loading.remove wire:target="processImage">Process Image</span>
                <span wire:loading wire:target="processImage">Processing...</span>
            </x-filament::button>

            @if($processedImagePath)
                <x-filament::button 
                    type="button" 
                    wire:click="downloadProcessedImage"
                    color="success"
                    icon="heroicon-o-arrow-down-tray"
                >
                    Download Processed Image
                </x-filament::button>

                <x-filament::button 
                    type="button" 
                    wire:click="resetProcessedImage"
                    color="gray"
                    icon="heroicon-o-x-mark"
                >
                    Clear
                </x-filament::button>
            @endif
        </div>
    </form>
</x-filament-panels::page>