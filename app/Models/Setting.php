<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    public const CACHE_KEY = 'settings:all';

    protected $fillable = ['key', 'value'];

    protected static function booted(): void
    {
        // Settings are cached forever (see Setting::valueOf). Any change clears that cache.
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    /**
     * Read one setting. All settings are loaded once and cached forever,
     * because they change only when an admin edits them.
     */
    public static function valueOf(string $key, mixed $default = null): mixed
    {
        $all = Cache::rememberForever(self::CACHE_KEY, fn () => static::query()->pluck('value', 'key')->all());

        return $all[$key] ?? $default;
    }
}
