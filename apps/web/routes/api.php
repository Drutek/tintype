<?php

use App\Http\Controllers\Api\BuildController;
use App\Http\Controllers\Internal\WorkerController;
use App\Http\Middleware\AuthenticateProjectToken;
use App\Http\Middleware\AuthenticateWorker;
use Illuminate\Support\Facades\Route;

// CLI API (project token)
Route::prefix('v1')->middleware(AuthenticateProjectToken::class)->group(function () {
    Route::post('builds', [BuildController::class, 'store'])->name('api.builds.store');
    Route::get('stories/{storyId}/status', [BuildController::class, 'storyStatus'])->name('api.stories.status');
    Route::post('baseline-commits', [BuildController::class, 'baselineCommits'])->name('api.baseline-commits');
    Route::get('builds/{build:id}', [BuildController::class, 'show'])->name('api.builds.show');
    Route::put('builds/{build:id}/storybook', [BuildController::class, 'uploadStorybook'])->name('api.builds.storybook');
    Route::post('builds/{build:id}/uploaded', [BuildController::class, 'uploaded'])->name('api.builds.uploaded');
    Route::post('builds/{build:id}/cancel', [BuildController::class, 'cancel'])->name('api.builds.cancel');
});

// Capture worker API (shared worker token)
Route::prefix('internal/v1')->middleware(AuthenticateWorker::class)->group(function () {
    Route::post('jobs/lease', [WorkerController::class, 'lease'])->name('internal.jobs.lease');
    Route::post('jobs/{job}/heartbeat', [WorkerController::class, 'heartbeat'])->name('internal.jobs.heartbeat');
    Route::post('jobs/{job}/results', [WorkerController::class, 'results'])->name('internal.jobs.results');
    Route::get('builds/{build:id}/storybook.zip', [WorkerController::class, 'storybookArchive'])->name('internal.builds.storybook');
    Route::post('images/missing', [WorkerController::class, 'missingImages'])->name('internal.images.missing');
    Route::put('images/{sha}', [WorkerController::class, 'putImage'])->where('sha', '[0-9a-f]{64}')->name('internal.images.put');
    Route::get('images/{sha}', [WorkerController::class, 'showImage'])->where('sha', '[0-9a-f]{64}')->name('internal.images.show');
});
