@forelse ($images as $image)
    <img src="{{ $image }}" alt="Image" />
@empty
    <div>
        <p>No images available. Please upload an image.</p>
    </div>
@endforelse
