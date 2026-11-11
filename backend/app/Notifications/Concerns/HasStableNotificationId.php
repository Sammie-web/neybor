<?php

namespace App\Notifications\Concerns;

trait HasStableNotificationId
{
    protected function useStableNotificationId(string $key): void
    {
        $namespace = hex2bin('8fcd5ddc1fd44ca89e2fe5cd7a2d1557');
        $hash = substr(sha1($namespace.$key), 0, 32);
        $hash[12] = '5';
        $hash[16] = dechex((hexdec($hash[16]) & 0x3) | 0x8);
        $this->id = implode('-', [
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12),
        ]);
    }
}
