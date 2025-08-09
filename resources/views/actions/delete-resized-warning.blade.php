<div class="p-4 bg-warning-50 dark:bg-warning-900/10 rounded-lg">
    <div class="flex">
        <div class="flex-shrink-0">
            <svg class="h-5 w-5 text-warning-400" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
            </svg>
        </div>
        <div class="ml-3">
            <h3 class="text-sm font-medium text-warning-800 dark:text-warning-200">
                Warning
            </h3>
            <div class="mt-2 text-sm text-warning-700 dark:text-warning-300">
                <p>This action will delete all resized versions of this image from the following directories:</p>
                <ul class="list-disc list-inside mt-2">
                    @foreach(array_keys(config('file-manager.image_sizes', [])) as $size)
                        <li>{{ $size }}/</li>
                    @endforeach
                </ul>
                <p class="mt-2">The original image will be preserved. You can regenerate the resized versions by using the "Resize" action.</p>
            </div>
        </div>
    </div>
</div>