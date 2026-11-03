<?php
// Uji manual: urutan default SPR + pengaman nomor urut ganda (jalankan via tinker).
use App\Services\Numerator\NumeratorService;
use Illuminate\Support\Facades\DB;

$f = '{seq:6}/SPR/JR/{ROMAWI}/{YYYY}';
echo 'seqDari: ', json_encode([NumeratorService::seqDari($f, '001690/SPR/JR/X/2026'), NumeratorService::seqDari($f, '2024/XII/PPU.KR/000160')]), PHP_EOL;

DB::beginTransaction();
$id = DB::table('fo_kontrak')->value('id');
$asli = DB::table('fo_kontrak')->where('id', $id)->value('no_kontrak');
DB::table('fo_kontrak')->where('id', $id)->update(['no_kontrak' => '009990/SPR/JR/X/2026']);
echo 'dipakai 9990 : ', var_export(NumeratorService::nomorUrutDipakai('fo_kontrak', 'no_kontrak', $f, 9990), true), PHP_EOL;
echo 'kecuali diri : ', var_export(NumeratorService::nomorUrutDipakai('fo_kontrak', 'no_kontrak', $f, 9990, null, $id), true), PHP_EOL;
echo 'dipakai 9991 : ', var_export(NumeratorService::nomorUrutDipakai('fo_kontrak', 'no_kontrak', $f, 9991), true), PHP_EOL;

// numerator: last_seq 9989 → nomor berikut harus loncat 9990 (sudah dipakai bulan lain) → 9991
$n = DB::table('numerators')->where('code', 'SPR')->first();
DB::table('numerators')->where('id', $n->id)->update(['format' => $f, 'last_seq' => 9989]);
echo 'next         : ', app(NumeratorService::class)->next('SPR'), PHP_EOL;
DB::rollBack();

$q = App\Modules\FO\MarketingSales\Models\Kontrak::query()
    ->orderByRaw("no_kontrak REGEXP '^[0-9]+/SPR/' desc")
    ->orderByRaw("CASE WHEN no_kontrak REGEXP '^[0-9]+/SPR/' THEN CAST(SUBSTRING_INDEX(no_kontrak, '/', 1) AS UNSIGNED) END desc")
    ->orderBy('tgl_kontrak', 'desc')->orderBy('id', 'desc');
echo 'atas  : ', $q->clone()->limit(4)->pluck('no_kontrak')->implode(', '), PHP_EOL;
$total = $q->count();
echo 'batas : ', $q->clone()->offset(1065)->limit(4)->pluck('no_kontrak')->implode(', '), PHP_EOL;
