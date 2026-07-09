<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EwsConfig extends Model
{
    protected $table = 'ews_configs';

    protected $fillable = ['key', 'value'];

    private static ?array $configCache = null;

    /**
     * Get config value by key.
     */
    public static function getVal(string $key, $default = null)
    {
        try {
            if (self::$configCache === null) {
                self::$configCache = self::pluck('value', 'key')->toArray();
            }

            return array_key_exists($key, self::$configCache) ? self::$configCache[$key] : $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /**
     * Set config value by key.
     */
    public static function setVal(string $key, $value)
    {
        return self::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }
}
