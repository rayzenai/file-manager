@if($imageUrl)
    <div class="space-y-4">
        <!-- Image Preview -->
        <div class="relative bg-gray-100 dark:bg-gray-800 rounded-lg p-4">
            <img 
                src="{{ $imageUrl }}" 
                alt="Processed Image" 
                class="max-w-full h-auto mx-auto rounded-lg shadow-lg"
                style="max-height: 500px;"
            />
        </div>

        <!-- Processing Stats -->
        @if($stats)
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                <div class="bg-white dark:bg-gray-800 rounded-lg p-3 border border-gray-200 dark:border-gray-700">
                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">Original Size</div>
                    <div class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $stats['original_size'] }}</div>
                </div>
                
                <div class="bg-white dark:bg-gray-800 rounded-lg p-3 border border-gray-200 dark:border-gray-700">
                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">Compressed Size</div>
                    <div class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $stats['compressed_size'] }}</div>
                </div>
                
                <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-3 border border-green-200 dark:border-green-700">
                    <div class="text-xs text-green-600 dark:text-green-400 uppercase tracking-wide">Saved</div>
                    <div class="text-lg font-semibold text-green-700 dark:text-green-300">{{ $stats['saved'] }}</div>
                </div>
                
                <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-3 border border-blue-200 dark:border-blue-700">
                    <div class="text-xs text-blue-600 dark:text-blue-400 uppercase tracking-wide">Reduction</div>
                    <div class="text-lg font-semibold text-blue-700 dark:text-blue-300">{{ $stats['ratio'] }}</div>
                </div>
                
                <div class="bg-white dark:bg-gray-800 rounded-lg p-3 border border-gray-200 dark:border-gray-700">
                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">Format</div>
                    <div class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $stats['format'] }}</div>
                </div>
                
                <div class="bg-white dark:bg-gray-800 rounded-lg p-3 border border-gray-200 dark:border-gray-700">
                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">Dimensions</div>
                    <div class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $stats['dimensions'] }}</div>
                </div>
                
                <div class="bg-white dark:bg-gray-800 rounded-lg p-3 border border-gray-200 dark:border-gray-700 md:col-span-2">
                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">Method</div>
                    <div class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $stats['method'] }}</div>
                </div>
            </div>
        @endif
    </div>
@else
    <div class="text-center py-12 bg-gray-50 dark:bg-gray-800 rounded-lg">
        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
        </svg>
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">No processed image yet</p>
        <p class="text-xs text-gray-400 dark:text-gray-500">Upload and process an image to see the result here</p>
    </div>
@endif