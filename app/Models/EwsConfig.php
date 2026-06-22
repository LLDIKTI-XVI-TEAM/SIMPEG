<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EwsConfig extends Model
{
    protected $table = 'ews_configs';

    protected $fillable = ['key', 'value'];

    /**
     * Get config value by key.
     */
    public static function getVal(string $key, $default = null)
    {
        $config = self::where('key', $key)->first();
        return $config ? $config->value : $default;
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
