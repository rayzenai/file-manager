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

    <script>
        function showModal(src) {
            const modal = document.getElementById('modal');
            const modalImg = document.getElementById('modal-img');
            modalImg.src = src;
            modal.classList.remove('hidden');
        }

        function closeModal() {
            document.getElementById('modal').classList.add('hidden');
        }
    </script>
</head>

<body class="bg-gray-50 min-h-screen py-6">

    <div class="container mx-auto px-4">
        <h1 class="text-2xl font-semibold mb-6">{{ $title }}</h1>

        @if (count($this->img) === 1)
            <div class="flex justify-center w-full cursor-pointer" onclick="showModal('{{ $this->img[0] }}')">
                <img src="{{ $this->img[0] }}"
                    class="w-full h-auto object-cover rounded-lg shadow-lg hover:shadow-xl transition-shadow duration-200"
                    alt="Image" />
            </div>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach ($this->img as $image)
                    <div class="rounded-lg overflow-hidden shadow-md hover:shadow-xl transition-transform duration-200 hover-scale cursor-pointer"
                        onclick="showModal('{{ $image }}')">
                        <img src="{{ $image }}" alt="Image" class="w-full h-64 object-cover">
                    </div>
                @endforeach
            </div>
        @endif

        <!-- Modal -->
        <div id="modal" class="fixed inset-0 bg-black bg-opacity-70 flex items-center justify-center hidden"
            onclick="closeModal()">
            <img id="modal-img" class="max-w-full max-h-screen" alt="Modal Image">
        </div>
    </div>

    @livewireScripts
</body>

</html>
