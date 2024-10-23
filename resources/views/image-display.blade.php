@foreach ($images as $image)
    <img src="{{ asset('storage/.'$image) }}" alt="Image" />
@endforeach