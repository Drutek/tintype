<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Personal access token. Only a hash is stored; the plaintext is shown once.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $token_prefix
 * @property string $token_hash
 * @property Carbon|null $last_used_at
 * @property Carbon $created_at
 * @property-read User $user
 */
class ApiToken extends Model
{
    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array{0: self, 1: string} the token and its plaintext */
    public static function issue(User $user, string $name): array
    {
        $prefix = 'tu_'.Str::lower(Str::random(8));
        $plain = $prefix.'_'.Str::random(40);
        $token = self::create(['user_id' => $user->id, 'name' => $name, 'token_prefix' => $prefix, 'token_hash' => hash('sha256', $plain)]);

        return [$token, $plain];
    }

    public static function findByToken(?string $plain): ?self
    {
        if (! $plain || ! preg_match('/^(tu_[a-z0-9]{8})_/', $plain, $m)) {
            return null;
        }
        $token = self::with('user')->where('token_prefix', $m[1])->first();

        return $token && hash_equals($token->token_hash, hash('sha256', $plain)) ? $token : null;
    }
}
