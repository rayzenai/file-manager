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
        },
        closeModal() {
            document.getElementById('modal').classList.add('hidden');
        },
        prevImage(event) {
            event.stopPropagation();
            this.currentIndex = (this.currentIndex - 1 + this.images.length) % this.images.length;
        },
        nextImage(event) {
            event.stopPropagation();
            this.currentIndex = (this.currentIndex + 1) % this.images.length;
        }
    }" x-data="{
        images: {{ json_encode($this->img) }},
        currentIndex: 0
    }">

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
                class="fixed inset-0 bg-black bg-opacity-80 flex items-center justify-center hidden z-50">
                <button @click="prevImage($event)"
                    class="absolute left-4 top-1/2 transform -translate-y-1/2 text-white bg-gray-700 bg-opacity-50 hover:bg-opacity-75 rounded-full p-2">
                    &#8592;
                </button>

                <div class="flex justify-center items-center w-full max-w-4xl">
                    <img :src="images[currentIndex]" class="max-w-full max-h-screen object-contain">
                </div>

                <button @click="closeModal"
                    class="absolute top-4 right-4 text-white text-3xl bg-gray-700 bg-opacity-50 hover:bg-opacity-75 rounded-full px-2">&times;</button>

                <button @click="nextImage($event)"
                    class="absolute right-4 top-1/2 transform -translate-y-1/2 text-white bg-gray-700 bg-opacity-50 hover:bg-opacity-75 rounded-full p-2">
                    &#8594;
                </button>

                <button @click="prevImage"
                    class="absolute left-4 top-1/2 -translate-y-1/2 text-white bg-gray-700 bg-opacity-50 hover:bg-opacity-75 rounded-full p-2">
                    &#8592;
                </button>
            </div>
        </div>
    </div>

    @livewireScripts
</body>

</html>
