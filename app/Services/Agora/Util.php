<?php

namespace App\Services\Agora;

class Util
{
    public static function packUint16(int $x): string
    {
        return pack('v', $x);
    }

    public static function unpackUint16(string &$data): int
    {
        $up = unpack('v', substr($data, 0, 2));
        $data = substr($data, 2);

        return $up[1];
    }

    public static function packUint32(int $x): string
    {
        return pack('V', $x);
    }

    public static function unpackUint32(string &$data): int
    {
        $up = unpack('V', substr($data, 0, 4));
        $data = substr($data, 4);

        return $up[1];
    }

    public static function packString(string $str): string
    {
        return self::packUint16(strlen($str)).$str;
    }

    public static function unpackString(string &$data): string
    {
        $len = self::unpackUint16($data);
        $up = unpack('C*', substr($data, 0, $len));
        $data = substr($data, $len);

        return implode(array_map('chr', $up));
    }

    public static function packMapUint32(array $arr): string
    {
        ksort($arr);
        $kv = '';
        foreach ($arr as $key => $val) {
            $kv .= self::packUint16($key).self::packUint32($val);
        }

        return self::packUint16(count($arr)).$kv;
    }
}
