<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\Auth\GitHubLoginController;
use App\Http\Controllers\Auth\OidcLoginController;
use App\Http\Controllers\BuildController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GitHubWebhookController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\PullRequestController;
use App\Http\Controllers\StorybookController;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return User::query()->exists() ? redirect()->route('login') : redirect()->route('register');
})->name('home');

// Static Storybook per build; the path token is the capability (used by capture workers).
Route::get('storybooks/{build}/{token}/{path?}', [StorybookController::class, 'file'])
    ->where(['build' => '[0-9]+', 'path' => '.*'])
    ->name('storybook.file');

Route::middleware('guest')->group(function () {
    Route::get('auth/github', [GitHubLoginController::class, 'redirect'])->name('auth.github');
    Route::get('auth/github/callback', [GitHubLoginController::class, 'callback'])->name('auth.github.callback');
    Route::get('auth/oidc', [OidcLoginController::class, 'redirect'])->name('auth.oidc');
    Route::get('auth/oidc/callback', [OidcLoginController::class, 'callback'])->name('auth.oidc.callback');
});

Route::get('signed-images/{sha}', [ImageController::class, 'show'])->middleware('signed')->name('images.signed');

Route::post('github/webhook', GitHubWebhookController::class)
    ->withoutMiddleware(ValidateCsrfToken::class)
    ->name('github.webhook');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('images/{sha}', [ImageController::class, 'show'])->name('images.show');

    Route::get('admin', [AdminController::class, 'index'])->name('admin.index');
    Route::get('admin/github/callback', [AdminController::class, 'githubCallback'])->name('admin.github.callback');
    Route::delete('admin/github', [AdminController::class, 'githubForget'])->name('admin.github.forget');

    Route::post('orgs', [OrganizationController::class, 'store'])->name('organizations.store');
    Route::prefix('orgs/{organization}')->group(function () {
        Route::get('/', [OrganizationController::class, 'show'])->name('organizations.show');
        Route::post('members', [OrganizationController::class, 'addMember'])->name('organizations.members.store');
        Route::delete('members/{user}', [OrganizationController::class, 'removeMember'])->name('organizations.members.destroy');
        Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');

        Route::prefix('projects/{project}')->group(function () {
            Route::get('/', [ProjectController::class, 'show'])->name('projects.show');
            Route::get('library', [ProjectController::class, 'library'])->name('projects.library');
            Route::get('settings', [ProjectController::class, 'settings'])->name('projects.settings');
            Route::put('settings', [ProjectController::class, 'update'])->name('projects.update');
            Route::post('token', [ProjectController::class, 'rotateToken'])->name('projects.token');
            Route::post('rebaseline', [ProjectController::class, 'rebaseline'])->name('projects.rebaseline');
            Route::delete('/', [ProjectController::class, 'destroy'])->name('projects.destroy');

            Route::get('builds/{build}', [BuildController::class, 'show'])->whereNumber('build')->name('builds.show');
            Route::post('builds/{build}/review', [BuildController::class, 'review'])->whereNumber('build')->name('builds.review');
            Route::post('builds/{build}/comments', [BuildController::class, 'comment'])->whereNumber('build')->name('builds.comments.store');
            Route::patch('builds/{build}/comments/{comment}', [BuildController::class, 'resolveComment'])->whereNumber('build')->name('builds.comments.resolve');

            Route::get('pulls', [PullRequestController::class, 'index'])->name('pulls.index');
            Route::get('pulls/{number}', [PullRequestController::class, 'show'])->whereNumber('number')->name('pulls.show');
            Route::post('pulls/{number}/assign', [PullRequestController::class, 'assign'])->whereNumber('number')->name('pulls.assign');
            Route::post('pulls/{number}/review', [PullRequestController::class, 'review'])->whereNumber('number')->name('pulls.review');
        });
    });
});

// Hosted Storybook entry point (redirects to the tokenised static path). Public projects need no login.
Route::get('orgs/{organization}/projects/{project}/storybook', [StorybookController::class, 'latest'])->name('storybook.latest');

require __DIR__.'/settings.php';
