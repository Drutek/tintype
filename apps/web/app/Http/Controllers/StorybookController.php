<?php

namespace App\Http\Controllers;

use App\Enums\BuildStatus;
use App\Models\Build;
use App\Models\Organization;
use App\Models\Project;
use App\Services\ImageStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StorybookController extends Controller
{
    private const MIME = [
        'html' => 'text/html; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'mjs' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8', 'json' => 'application/json', 'svg' => 'image/svg+xml', 'png' => 'image/png',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif',
        'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'wasm' => 'application/wasm', 'map' => 'application/json', 'txt' => 'text/plain',
    ];

    public function __construct(private readonly ImageStore $store) {}

    /** Serve a file from a build's static Storybook. Path token is the capability. */
    public function file(Request $request, int $build, string $token, string $path = 'index.html'): Response
    {
        $model = Build::findOrFail($build);
        abort_unless(hash_equals($model->storybookToken(), $token), 404);
        $path = ltrim($path === '' ? 'index.html' : $path, '/');
        abort_if(str_contains($path, '..'), 404);
        $disk = $this->store->disk();
        $full = $model->storybookPath().'/'.$path;
        if (! $disk->exists($full) && $disk->exists($full.'/index.html')) {
            $full .= '/index.html';
        }
        abort_unless($disk->exists($full), 404);
        $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));

        return new StreamedResponse(function () use ($disk, $full) {
            $stream = $disk->readStream($full);
            fpassthru($stream);
            is_resource($stream) && fclose($stream);
        }, 200, [
            'Content-Type' => self::MIME[$ext] ?? 'application/octet-stream',
            'Cache-Control' => in_array($ext, ['html', 'json'], true) ? 'no-cache' : 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Human entry point: latest build on a branch (or a specific build number). */
    public function latest(Request $request, Organization $organization, Project $project): RedirectResponse
    {
        abort_unless($project->organization_id === $organization->id, 404);
        if (! $project->setting('storybook_public')) {
            abort_unless($request->user() && $organization->roleOf($request->user()), 403);
        }
        $query = $project->builds()->whereNotIn('status', [BuildStatus::Uploading->value, BuildStatus::Failed->value, BuildStatus::Canceled->value]);
        if ($number = $request->integer('build')) {
            $query->where('number', $number);
        } else {
            $query->where('branch', $request->string('branch', $project->default_branch)->toString());
        }
        $build = $query->orderByDesc('number')->firstOrFail();
        $suffix = $request->string('path')->toString();

        return redirect($build->storybookUrl().ltrim($suffix, '/'));
    }
}
