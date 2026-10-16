<?php

namespace App\Services\GitHub;

use App\Models\AppSetting;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * GitHub App created per install via the manifest flow. Credentials live
 * (encrypted) in app_settings under `github_app`.
 */
class GitHubApp
{
    /** @return array<string, mixed>|null */
    public function credentials(): ?array
    {
        $creds = AppSetting::get('github_app');

        return is_array($creds) && ! empty($creds['id']) && ! empty($creds['pem']) ? $creds : null;
    }

    public function configured(): bool
    {
        return $this->credentials() !== null;
    }

    public function apiUrl(string $path): string
    {
        return rtrim((string) config('tintype.github.api_url'), '/').'/'.ltrim($path, '/');
    }

    public function jwt(): string
    {
        $creds = $this->credentials() ?? throw new RuntimeException('GitHub App is not configured');
        $now = time();

        return JWT::encode(['iat' => $now - 60, 'exp' => $now + 540, 'iss' => (string) $creds['id']], (string) $creds['pem'], 'RS256');
    }

    private function http(string $token, string $scheme = 'token'): PendingRequest
    {
        return Http::withHeaders([
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'tintype',
        ])->withToken($token, $scheme === 'bearer' ? 'Bearer' : 'token')->timeout(20);
    }

    public function asApp(): PendingRequest
    {
        return $this->http($this->jwt(), 'bearer');
    }

    public function installationToken(int $installationId): string
    {
        return Cache::remember("github-installation-token-{$installationId}", 50 * 60, function () use ($installationId) {
            $res = $this->asApp()->post($this->apiUrl("app/installations/{$installationId}/access_tokens"));
            $res->throw();

            return (string) $res->json('token');
        });
    }

    public function asInstallation(int $installationId): PendingRequest
    {
        return $this->http($this->installationToken($installationId));
    }

    public function installationForRepository(string $repository): ?int
    {
        $res = $this->asApp()->get($this->apiUrl("repos/{$repository}/installation"));

        return $res->successful() ? (int) $res->json('id') : null;
    }

    /** Exchange a manifest-flow code for app credentials and store them. */
    public function completeManifest(string $code): array
    {
        $res = Http::withHeaders(['Accept' => 'application/vnd.github+json', 'User-Agent' => 'tintype'])
            ->post($this->apiUrl("app-manifests/{$code}/conversions"));
        $res->throw();
        $data = $res->json();
        $creds = [
            'id' => $data['id'],
            'slug' => $data['slug'],
            'name' => $data['name'] ?? $data['slug'],
            'html_url' => $data['html_url'] ?? null,
            'pem' => $data['pem'],
            'webhook_secret' => $data['webhook_secret'] ?? null,
            'client_id' => $data['client_id'] ?? null,
            'client_secret' => $data['client_secret'] ?? null,
            'owner' => $data['owner']['login'] ?? null,
        ];
        AppSetting::put('github_app', $creds);

        return $creds;
    }

    public function verifyWebhook(string $payload, ?string $signature): bool
    {
        $secret = (string) ($this->credentials()['webhook_secret'] ?? '');
        if ($secret === '' || ! $signature) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $payload, $secret), $signature);
    }

    public function installUrl(): ?string
    {
        $slug = $this->credentials()['slug'] ?? null;

        return $slug ? rtrim((string) config('tintype.github.web_url'), '/')."/apps/{$slug}/installations/new" : null;
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        $base = rtrim((string) config('app.url'), '/');

        return [
            'name' => 'Tintype '.parse_url($base, PHP_URL_HOST),
            'url' => $base,
            'hook_attributes' => ['url' => $base.'/github/webhook', 'active' => true],
            'redirect_url' => $base.'/admin/github/callback',
            'callback_urls' => [$base.'/auth/github/callback'],
            'public' => false,
            'default_permissions' => [
                'checks' => 'write',
                'pull_requests' => 'read',
                'contents' => 'read',
                'metadata' => 'read',
            ],
            'default_events' => ['check_run', 'pull_request', 'push'],
        ];
    }

    public static function ensureOk(Response $res, string $what): Response
    {
        if ($res->failed()) {
            throw new RuntimeException("GitHub {$what} failed: {$res->status()} {$res->body()}");
        }

        return $res;
    }
}
