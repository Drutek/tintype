<?php

namespace App\Http\Controllers;

use App\Services\ImageStore;
use Illuminate\Http\Response;

class ImageController extends Controller
{
    public function show(ImageStore $store, string $sha): Response
    {
        abort_unless(preg_match('/^[0-9a-f]{64}$/', $sha) === 1, 404);
        $bytes = $store->get($sha);
        abort_if($bytes === null, 404);

        return response($bytes, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, max-age=31536000, immutable']);
    }
}
