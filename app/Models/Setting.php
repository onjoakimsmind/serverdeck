<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'encrypted'];

    protected function casts(): array
    {
        return [
            'encrypted' => 'boolean',
        ];
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $setting = static::query()->where('key', $key)->first();

        if (! $setting || $setting->value === null) {
            return $default;
        }

        if (! $setting->encrypted) {
            return $setting->value;
        }

        try {
            return Crypt::decryptString($setting->value);
        } catch (DecryptException) {
            return $default;
        }
    }

    public static function put(string $key, ?string $value, bool $encrypted = false): void
    {
        if ($value === null || $value === '') {
            static::query()->where('key', $key)->delete();

            return;
        }

        static::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => $encrypted ? Crypt::encryptString($value) : $value,
                'encrypted' => $encrypted,
            ],
        );
    }
}
