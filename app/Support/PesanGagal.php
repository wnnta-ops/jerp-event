<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Ubah exception jadi pesan yang layak dibaca user. Pesan bisnis (RuntimeException dari
 * service) ditampilkan apa adanya; error database/sistem disembunyikan — detail SQL
 * masuk log dengan kode referensi yang bisa dilaporkan user ke IT.
 */
class PesanGagal
{
    public static function dari(Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            return collect($e->errors())->flatten()->first() ?? 'Data tidak valid.';
        }

        if ($e instanceof RuntimeException && ! $e instanceof PDOException) {
            return $e->getMessage();
        }

        $kode = strtoupper(Str::random(6));
        report(new RuntimeException("[ERR-{$kode}] ".$e->getMessage(), 0, $e));

        $sebab = match (true) {
            $e instanceof QueryException && str_contains($e->getMessage(), 'Data too long') => 'Ada isian yang terlalu panjang untuk disimpan.',
            $e instanceof QueryException && ($e->errorInfo[1] ?? null) === 1062 => 'Data yang sama sudah pernah tersimpan.',
            default => 'Terjadi kesalahan sistem.',
        };

        return "{$sebab} Data tidak disimpan. Silakan hubungi IT dengan kode ERR-{$kode}.";
    }
}
