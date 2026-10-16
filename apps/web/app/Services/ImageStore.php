<?php

namespace App\Services;

use App\Models\Image;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class ImageStore
{
    public function disk(): Filesystem
    {
        return Storage::disk('tintype');
    }

    public function exists(string $sha): bool
    {
        return Image::whereKey($sha)->exists();
    }

    /**
     * Store PNG bytes under their content hash. Verifies the hash and PNG header.
     */
    public function put(string $bytes, ?string $expectedSha = null): Image
    {
        $sha = hash('sha256', $bytes);
        if ($expectedSha !== null && ! hash_equals($expectedSha, $sha)) {
            throw new InvalidArgumentException('Image hash mismatch');
        }
        if (strlen($bytes) < 24 || substr($bytes, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            throw new InvalidArgumentException('Not a PNG');
        }
        $existing = Image::find($sha);
        if ($existing) {
            return $existing;
        }
        $dims = unpack('Nwidth/Nheight', substr($bytes, 16, 8));
        if ($dims === false) {
            throw new InvalidArgumentException('Not a PNG');
        }
        $this->disk()->put(Image::pathFor($sha), $bytes);

        return Image::firstOrCreate(['sha256' => $sha], [
            'width' => $dims['width'],
            'height' => $dims['height'],
            'bytes' => strlen($bytes),
        ]);
    }

    public function get(string $sha): ?string
    {
        $path = Image::pathFor($sha);

        return $this->disk()->exists($path) ? $this->disk()->get($path) : null;
    }
}
