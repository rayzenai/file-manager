<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ $title }}</title>
</head>

<body>
    <div class="flex  justify-center w-full">
        <img style="
                /* Center and scale the image nicely */
                background-position: center;
                background-repeat: no-repeat;
                object-fit: cover;
                background-size: contain;"
            class="sm:w-full md:w-[1600px]" src="{{ $img }}" />
    </div>

</body>

</html>
