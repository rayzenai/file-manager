<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @vite('resources/css/app.css')
</head>

<body class="h-screen w-screen m-0 p-0">
    <div class="flex justify-center items-center h-full w-full">
        <img src="{{ $img }}" class="w-full h-full object-cover" alt="Full screen image" />
    </div>
</body>

</html>
