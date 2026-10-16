<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Content-addressed PNG stored at images/ab/cd/<sha>.png on the tintype disk.
 *
 * @property string $sha256
 * @property int $width
 * @property int $height
 * @property int $bytes
 */
class Image extends Model
{
    protected $primaryKey = 'sha256';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    public static function pathFor(string $sha): string
    {
        return 'images/'.substr($sha, 0, 2).'/'.substr($sha, 2, 2).'/'.$sha.'.png';
    }

    public static function urlFor(?string $sha): ?string
    {
        return $sha ? route('images.show', $sha) : null;
    }
}
