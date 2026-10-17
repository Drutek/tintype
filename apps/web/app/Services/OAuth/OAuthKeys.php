<?php

namespace App\Services\OAuth;

use App\Models\AppSetting;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * RSA keys that sign OAuth access tokens (MCP clients). PASSPORT_PRIVATE_KEY /
 * PASSPORT_PUBLIC_KEY win when set; otherwise a pair is generated on first use and
 * stored encrypted in app settings, so every web replica shares it without files.
 */
class OAuthKeys
{
    private const SETTING = 'oauth_keys';

    public static function load(): void
    {
        if (config('passport.private_key') && config('passport.public_key')) {
            return;
        }
        $keys = AppSetting::get(self::SETTING) ?? self::generate();
        config(['passport.private_key' => $keys['private'], 'passport.public_key' => $keys['public']]);
    }

    /** @return array{private: string, public: string} */
    private static function generate(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 4096, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if (! $key || ! openssl_pkey_export($key, $private) || ! ($details = openssl_pkey_get_details($key))) {
            throw new RuntimeException('Could not generate OAuth keys: '.openssl_error_string());
        }
        $keys = ['private' => $private, 'public' => $details['key']];
        try {
            // Insert only: when two replicas race, the first pair wins and both use it.
            AppSetting::create(['key' => self::SETTING, 'value' => json_encode($keys)]);
        } catch (QueryException) {
            return AppSetting::get(self::SETTING);
        }

        return $keys;
    }
}
