<?php

namespace App\Providers;

use App\Services\OAuth\OAuthKeys;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\ResourceServer;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureOAuth();
    }

    /** OAuth for MCP clients: the user approves a client in the browser (see routes/ai.php). */
    protected function configureOAuth(): void
    {
        foreach ([AuthorizationServer::class, ResourceServer::class] as $server) {
            $this->app->beforeResolving($server, fn () => OAuthKeys::load());
        }
        Passport::authorizationView('mcp.authorize');
        Passport::tokensExpireIn(CarbonImmutable::now()->addDay());
        Passport::refreshTokensExpireIn(CarbonImmutable::now()->addDays(90));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
