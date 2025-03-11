<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @vite('resources/css/app.css')
    @livewireStyles
</head>

<body class="m-0 p-0">
    <div class="flex justify-center w-full">
        <img src="{{ $img[0] }}" class="w-full h-auto object-cover" alt="Scrollable full width image" />
    </div>
    @livewireScripts
</body>

</html>
