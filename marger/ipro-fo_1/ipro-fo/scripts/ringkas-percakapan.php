<?php

declare(strict_types=1);

/**
 * RINGKAS PERCAKAPAN CLAUDE — gratis, offline, tanpa API/kuota.
 *
 * Membaca transkrip chat Claude (.jsonl) lalu menulis ringkasan rapi ke folder terpisah
 * docs/Riwayat-Percakapan/. Dirancang untuk dijalankan harian via Windows Task Scheduler.
 *
 * Jalankan manual : php scripts/ringkas-percakapan.php
 * Output          : docs/Riwayat-Percakapan/INDEX.md + sesi-<id>.md per percakapan
 */

// ── Lokasi transkrip Claude untuk project ini ──
$transcriptDir = 'C:/Users/SPV ACC MUP/.claude/projects/C--Users-SPV-ACC-MUP-Documents-Claude-FO';
$outDir        = __DIR__.'/../docs/Riwayat-Percakapan';

if (! is_dir($outDir) && ! @mkdir($outDir, 0777, true) && ! is_dir($outDir)) {
    fwrite(STDERR, "Gagal membuat folder output: {$outDir}\n");
    exit(1);
}

$files = glob(rtrim($transcriptDir, '/').'/*.jsonl') ?: [];
if ($files === []) {
    fwrite(STDERR, "Tidak ada transkrip .jsonl di {$transcriptDir}\n");
    exit(1);
}

/** Ambil teks dari content (string atau array blok). */
function extractText(mixed $content): string
{
    if (is_string($content)) {
        return $content;
    }
    if (is_array($content)) {
        $parts = [];
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                $parts[] = (string) ($block['text'] ?? '');
            }
        }
        return implode("\n", $parts);
    }

    return '';
}

/** Rapikan + potong teks panjang agar ringkasan tetap ramping. */
function tidy(string $s, int $max = 280): string
{
    $s = trim(preg_replace('/\s+/', ' ', $s) ?? '');
    if (mb_strlen($s) > $max) {
        $s = mb_substr($s, 0, $max).'…';
    }

    return $s;
}

/** Apakah teks ini layak dianggap "permintaan user" (bukan meta/sistem/tool). */
function isRealUserPrompt(string $text): bool
{
    $t = ltrim($text);
    if ($t === '') {
        return false;
    }
    foreach (['<system-reminder', '<command-name', '<command-message', '<local-command', '<command-args', 'Caveat:', '[Request interrupted', '<task-notification', '<bash-', 'This session is being continued', 'Your task is to create a detailed summary', 'Please continue the conversation from where'] as $skip) {
        if (str_starts_with($t, $skip)) {
            return false;
        }
    }

    return true;
}

function fmtTs(?string $ts): string
{
    if (! $ts) {
        return '—';
    }
    $t = strtotime($ts);

    return $t ? date('d M Y H:i', $t) : $ts;
}

$sessions = [];

foreach ($files as $file) {
    $id    = basename($file, '.jsonl');
    $short = substr($id, 0, 8);

    $fh = @fopen($file, 'r');
    if (! $fh) {
        continue;
    }

    $userPrompts = [];
    $assistantTexts = 0;
    $toolTally   = [];
    $filesTouched = [];
    $firstTs = null;
    $lastTs  = null;
    $cwd     = null;

    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $row = json_decode($line, true);
        if (! is_array($row)) {
            continue;
        }

        $ts = $row['timestamp'] ?? null;
        if ($ts) {
            $firstTs ??= $ts;
            $lastTs = $ts;
        }
        $cwd ??= $row['cwd'] ?? null;

        $type = $row['type'] ?? '';
        $msg  = $row['message'] ?? null;
        $isMeta = (bool) ($row['isMeta'] ?? false);

        if ($type === 'user' && is_array($msg) && ($msg['role'] ?? '') === 'user' && ! $isMeta) {
            $text = extractText($msg['content'] ?? '');
            if (isRealUserPrompt($text)) {
                $userPrompts[] = ['ts' => $ts, 'text' => tidy($text, 400)];
            }
        } elseif ($type === 'assistant' && is_array($msg)) {
            $content = $msg['content'] ?? [];
            $hasText = false;
            if (is_array($content)) {
                foreach ($content as $block) {
                    if (! is_array($block)) {
                        continue;
                    }
                    $bt = $block['type'] ?? '';
                    if ($bt === 'text' && trim((string) ($block['text'] ?? '')) !== '') {
                        $hasText = true;
                    } elseif ($bt === 'tool_use') {
                        $name = (string) ($block['name'] ?? '?');
                        $toolTally[$name] = ($toolTally[$name] ?? 0) + 1;
                        $fp = $block['input']['file_path'] ?? null;
                        if (is_string($fp) && $fp !== '') {
                            $filesTouched[$fp] = true;
                        }
                    }
                }
            }
            if ($hasText) {
                $assistantTexts++;
            }
        }
    }
    fclose($fh);

    // Lewati sesi tanpa permintaan user sama sekali (mis. transkrip kosong).
    if ($userPrompts === [] && $toolTally === []) {
        continue;
    }

    arsort($toolTally);

    $sessions[] = [
        'id' => $id, 'short' => $short, 'file' => basename($file),
        'cwd' => $cwd, 'firstTs' => $firstTs, 'lastTs' => $lastTs,
        'prompts' => $userPrompts, 'assistantTexts' => $assistantTexts,
        'tools' => $toolTally, 'files' => array_keys($filesTouched),
    ];
}

// Urutkan sesi: terbaru di atas.
usort($sessions, fn ($a, $b) => strcmp((string) $b['lastTs'], (string) $a['lastTs']));

$generatedAt = date('d M Y H:i:s');

// ── Tulis file per sesi ──
foreach ($sessions as $s) {
    $md = "# Ringkasan Percakapan — sesi `{$s['short']}`\n\n";
    $md .= "> Dibuat otomatis: {$generatedAt} · File transkrip: `{$s['file']}`\n\n";
    $md .= "| | |\n|---|---|\n";
    $md .= '| Mulai | '.fmtTs($s['firstTs'])." |\n";
    $md .= '| Aktivitas terakhir | '.fmtTs($s['lastTs'])." |\n";
    $md .= '| Direktori | `'.($s['cwd'] ?? '—')."` |\n";
    $md .= '| Jumlah permintaan user | '.count($s['prompts'])." |\n";
    $md .= '| Balasan asisten (berisi teks) | '.$s['assistantTexts']." |\n";
    $md .= '| File disentuh | '.count($s['files'])." |\n\n";

    $md .= "## Daftar Permintaan User (verbatim)\n\n";
    if ($s['prompts'] === []) {
        $md .= "_(tidak ada)_\n\n";
    } else {
        foreach ($s['prompts'] as $i => $p) {
            $n = $i + 1;
            $when = $p['ts'] ? '`'.fmtTs($p['ts']).'` — ' : '';
            $md .= "{$n}. {$when}{$p['text']}\n";
        }
        $md .= "\n";
    }

    if ($s['tools'] !== []) {
        $md .= "## Penggunaan Tool\n\n";
        foreach ($s['tools'] as $name => $n) {
            $md .= "- **{$name}** × {$n}\n";
        }
        $md .= "\n";
    }

    if ($s['files'] !== []) {
        $md .= "## File yang Disentuh (".count($s['files']).")\n\n";
        $shown = array_slice($s['files'], 0, 200);
        foreach ($shown as $f) {
            $md .= '- `'.$f."`\n";
        }
        if (count($s['files']) > 200) {
            $md .= '- … (+'.(count($s['files']) - 200)." lainnya)\n";
        }
        $md .= "\n";
    }

    file_put_contents($outDir.'/sesi-'.$s['short'].'.md', $md);
}

// ── Tulis INDEX.md ──
$totalPrompts = array_sum(array_map(fn ($s) => count($s['prompts']), $sessions));
$idx  = "# Riwayat Percakapan — Java Core System\n\n";
$idx .= "> Diperbarui otomatis: **{$generatedAt}** · Sumber: transkrip Claude lokal · Dibuat tanpa biaya (offline).\n\n";
$idx .= '**'.count($sessions).'** percakapan · **'.$totalPrompts."** permintaan user total.\n\n";
$idx .= "| # | Sesi | Mulai | Terakhir | Permintaan | File | Detail |\n";
$idx .= "|---|------|-------|----------|-----------:|-----:|--------|\n";
foreach ($sessions as $i => $s) {
    $n = $i + 1;
    $idx .= "| {$n} | `{$s['short']}` | ".fmtTs($s['firstTs']).' | '.fmtTs($s['lastTs']).' | '
        .count($s['prompts']).' | '.count($s['files']).' | [buka](./sesi-'.$s['short'].".md) |\n";
}
$idx .= "\n## Cuplikan permintaan terbaru\n\n";
foreach ($sessions as $s) {
    if ($s['prompts'] === []) {
        continue;
    }
    $idx .= "### sesi `{$s['short']}` — ".fmtTs($s['lastTs'])."\n\n";
    foreach (array_slice($s['prompts'], 0, 8) as $p) {
        $idx .= '- '.tidy($p['text'], 160)."\n";
    }
    $idx .= "\n";
}

file_put_contents($outDir.'/INDEX.md', $idx);

$count = count($sessions);
echo "OK - {$count} percakapan diringkas -> {$outDir}\n";
echo "    INDEX.md + {$count} file sesi | {$totalPrompts} permintaan user total | {$generatedAt}\n";
