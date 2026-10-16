<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Encrypted key/value settings managed from the UI (e.g. GitHub App credentials).
 */
class AppSetting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'encrypted'];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $row = self::find($key);

        return $row ? json_decode((string) $row->value, true) : $default;
    }

    public static function put(string $key, mixed $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => json_encode($value)]);
    }
}
