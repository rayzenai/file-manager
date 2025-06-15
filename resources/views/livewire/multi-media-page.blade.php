<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    <style>
        .hover-scale:hover {
            transform: scale(1.05);
        }
    </style>
</head>

<body class="bg-gray-50 min-h-screen py-6">
    <div x-data="{
        images: {{ json_encode($this->img) }},
        currentIndex: 0,
        showModal(index) {
            this.currentIndex = index;
            document.getElementById('modal').classList.remove('hidden');
            document.body.classList.add('overflow-hidden'); // Prevent body scroll
        },
        closeModal() {
            document.getElementById('modal').classList.add('hidden');
            document.body.classList.remove('overflow-hidden'); // Restore body scroll
        },
        prevImage(event) {
            event.stopPropagation();
            this.currentIndex = (this.currentIndex - 1 + this.images.length) % this.images.length;
        },
        nextImage(event) {
            event.stopPropagation();
            this.currentIndex = (this.currentIndex + 1) % this.images.length;
        }
    }" @keydown.escape="closeModal()">

        <div class="container mx-auto px-4">
            <h1 class="text-2xl font-semibold mb-6">{{ $title }}</h1>

            @if (count($this->img) === 1)
                <div class="flex justify-center w-full cursor-pointer" @click="showModal(0)">
                    <img src="{{ $this->img[0] }}"
                        class="w-full h-auto object-cover rounded-lg shadow-lg hover:shadow-xl transition-shadow duration-200"
                        alt="Image" />
                </div>
            @else
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <template x-for="(image, index) in images" :key="index">
                        <div class="rounded-lg overflow-hidden shadow-md hover:shadow-xl transition-transform duration-200 hover-scale cursor-pointer"
                            @click="showModal(index)">
                            <img :src="image" alt="Image" class="w-full h-64 object-cover">
                        </div>
                    </template>
                </div>
            @endif

            <!-- Modal -->
            <div id="modal"
                class="fixed inset-0 bg-black bg-opacity-80 flex items-center justify-center hidden z-50"
                @click="closeModal()">

                <!-- Modal Content Container -->
                <div class="relative flex justify-center items-center w-full max-w-4xl mx-4" @click.stop>
                    <!-- Main Image -->
                    <img :src="images[currentIndex]" class="max-w-full max-h-[90vh] object-contain rounded-lg"
                        @click.stop>

                    <!-- Navigation Buttons (only show if multiple images) -->
                    <template x-if="images.length > 1">
                        <div>
                            <!-- Previous Button -->
                            <button @click="prevImage($event)"
                                class="absolute left-4 top-1/2 transform -translate-y-1/2 text-white bg-gray-700 bg-opacity-50 hover:bg-opacity-75 rounded-full p-3 transition-all duration-200">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M15 19l-7-7 7-7"></path>
                                </svg>
                            </button>

                            <!-- Next Button -->
                            <button @click="nextImage($event)"
                                class="absolute right-4 top-1/2 transform -translate-y-1/2 text-white bg-gray-700 bg-opacity-50 hover:bg-opacity-75 rounded-full p-3 transition-all duration-200">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M9 5l7 7-7 7"></path>
                                </svg>
                            </button>
                        </div>
                    </template>
                </div>

                <!-- Close Button -->
                <button @click="closeModal()"
                    class="absolute top-4 right-4 text-white text-3xl bg-gray-700 bg-opacity-50 hover:bg-opacity-75 rounded-full w-12 h-12 flex items-center justify-center transition-all duration-200">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12">
                        </path>
                    </svg>
                </button>

                <!-- Image Counter (only show if multiple images) -->
                <template x-if="images.length > 1">
                    <div
                        class="absolute bottom-4 left-1/2 transform -translate-x-1/2 text-white bg-gray-700 bg-opacity-50 px-3 py-1 rounded-full text-sm">
                        <span x-text="currentIndex + 1"></span> / <span x-text="images.length"></span>
                    </div>
                </template>
            </div>
        </div>
    </div>

    @livewireScripts
</body>

</html>
