<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Build;
use App\Models\CaptureJob;
use App\Models\Image;
use App\Services\CaptureService;
use App\Services\ImageStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkerController extends Controller
{
    public function __construct(private readonly CaptureService $capture, private readonly ImageStore $store) {}

    /** @return array<string, mixed> */
    private function workerInfo(Request $request): array
    {
        return $request->validate([
            'workerId' => ['required', 'string', 'max:128'],
            'browsers' => ['array'],
            'browsers.*' => ['in:chromium,firefox,webkit'],
            'arch' => ['nullable', 'string'],
            'image' => ['nullable', 'string', 'max:255'],
            'browserVersion' => ['nullable', 'string', 'max:64'],
        ]);
    }

    public function lease(Request $request): JsonResponse|Response
    {
        $payload = $this->capture->lease($this->workerInfo($request));

        return $payload ? response()->json($payload) : response()->noContent();
    }

    public function heartbeat(Request $request, CaptureJob $job): JsonResponse
    {
        $ok = $this->capture->heartbeat($job, (string) $request->input('workerId'));

        return response()->json(['ok' => $ok], $ok ? 200 : 409);
    }

    public function results(Request $request, CaptureJob $job): JsonResponse
    {
        $data = $request->validate([
            'workerId' => ['required', 'string'],
            'results' => ['array'],
            'results.*.storyId' => ['required', 'string'],
            'results.*.status' => ['required', 'in:captured,skipped,error'],
            'complete' => ['boolean'],
            'error' => ['nullable', 'string'],
        ]);
        if ($job->status !== 'leased' || $job->worker_id !== $data['workerId']) {
            return response()->json(['message' => 'Lease lost'], 409);
        }
        $this->capture->recordResults($job, $data['workerId'], $request->input('results', []), (bool) ($data['complete'] ?? false), $data['error'] ?? null);

        return response()->json(['ok' => true]);
    }

    /** The build's Storybook as a zip, so workers can serve it locally (one download per build). */
    public function storybookArchive(Build $build): StreamedResponse
    {
        $disk = $this->store->disk();
        abort_unless($disk->exists($build->storybookZipPath()), 404);

        return $disk->download($build->storybookZipPath(), "storybook-{$build->id}.zip", ['Content-Type' => 'application/zip']);
    }

    /** POST {shas: []} -> {missing: []} */
    public function missingImages(Request $request): JsonResponse
    {
        $shas = $request->validate(['shas' => ['array', 'max:1000'], 'shas.*' => ['string', 'size:64']])['shas'] ?? [];
        $have = Image::whereIn('sha256', $shas)->pluck('sha256')->all();

        return response()->json(['missing' => array_values(array_diff($shas, $have))]);
    }

    public function putImage(Request $request, string $sha): JsonResponse
    {
        try {
            $image = $this->store->put($request->getContent(), $sha);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['sha256' => $image->sha256, 'width' => $image->width, 'height' => $image->height], 201);
    }

    public function showImage(string $sha): Response
    {
        $bytes = $this->store->get($sha);
        abort_if($bytes === null, 404);

        return response($bytes, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=31536000, immutable']);
    }
}
