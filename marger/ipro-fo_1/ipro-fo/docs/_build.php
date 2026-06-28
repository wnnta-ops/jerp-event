<?php
/**
 * Generator dokumen: Markdown -> HTML + Word (.doc).
 * Jalankan dari root: php docs/_build.php
 * (PDF dibuat terpisah via Edge/Chrome headless di langkah berikutnya.)
 */
require __DIR__.'/../vendor/autoload.php';

use League\CommonMark\GithubFlavoredMarkdownConverter;

$root = dirname(__DIR__);
$docs = __DIR__;

// 1) Gabung PRD utama + diagram menjadi satu file lengkap.
$prdFull = file_get_contents("$root/PRD.md")."\n".file_get_contents("$docs/_diagrams.md");
file_put_contents("$docs/PRD-Java-Core-System.md", $prdFull);

$converter = new GithubFlavoredMarkdownConverter([
    'html_input' => 'allow',
    'allow_unsafe_links' => false,
]);

$css = <<<CSS
  @page { size: A4; margin: 16mm 14mm; }
  * { box-sizing: border-box; }
  body { font-family: "Segoe UI", Arial, sans-serif; font-size: 11px; line-height: 1.5; color: #1f2937; max-width: 940px; margin: 0 auto; padding: 24px; }
  h1 { font-size: 22px; color: #0f766e; border-bottom: 3px solid #0f766e; padding-bottom: 6px; margin-top: 28px; }
  h2 { font-size: 16px; color: #115e59; border-bottom: 1px solid #d1d5db; padding-bottom: 4px; margin-top: 24px; }
  h3 { font-size: 13px; color: #1e40af; margin-top: 18px; }
  h4 { font-size: 12px; color: #374151; }
  table { border-collapse: collapse; width: 100%; margin: 10px 0; font-size: 10px; page-break-inside: avoid; }
  th, td { border: 1px solid #cbd5e1; padding: 5px 8px; text-align: left; vertical-align: top; }
  th { background: #f1f5f9; font-weight: 600; }
  tr:nth-child(even) td { background: #f8fafc; }
  code { background: #f1f5f9; padding: 1px 4px; border-radius: 3px; font-family: Consolas, monospace; font-size: 10px; }
  pre { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; overflow-x: auto; }
  blockquote { border-left: 4px solid #14b8a6; background: #f0fdfa; margin: 8px 0; padding: 6px 12px; color: #334155; }
  a { color: #0d9488; }
  hr { border: none; border-top: 1px solid #e2e8f0; margin: 20px 0; }
  .mermaid { background: #fff; text-align: center; margin: 14px 0; page-break-inside: avoid; }
  ul, ol { margin: 6px 0 6px 18px; }
CSS;

/** Ubah blok ```mermaid (yang dirender CommonMark jadi <pre><code class="language-mermaid">) menjadi <div class="mermaid">. */
function fixMermaid(string $html): string {
    return preg_replace_callback(
        '~<pre><code class="language-mermaid">(.*?)</code></pre>~s',
        fn ($m) => '<div class="mermaid">'.html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5).'</div>',
        $html
    );
}

$targets = [
    'PRD-Java-Core-System'     => 'PRD — Java Core System',
    'Spesifikasi-Modul-Detail' => 'Spesifikasi Modul Detail — Java Core System',
    'Diagram-Alur-Modul'       => 'Diagram Alur per Modul — Java Core System',
    'Ringkasan-Eksekutif'      => 'Ringkasan Eksekutif — Java Core System',
];

foreach ($targets as $base => $title) {
    $md   = file_get_contents("$docs/$base.md");
    $body = $converter->convert($md)->getContent();
    $bodyHtml = fixMermaid($body);

    // ---- HTML (dengan diagram Mermaid via CDN) ----
    $mermaidCdn = 'https://cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.min.js';
    $htmlPage = "<!DOCTYPE html><html lang=\"id\"><head><meta charset=\"utf-8\">"
        ."<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"
        ."<title>".htmlspecialchars($title)."</title><style>$css</style></head><body>"
        ."$bodyHtml"
        ."<script src=\"$mermaidCdn\"></script>"
        ."<script>mermaid.initialize({startOnLoad:true, theme:'neutral', securityLevel:'loose'});</script>"
        ."</body></html>";
    file_put_contents("$docs/$base.html", $htmlPage);

    // ---- WORD (.doc) — Word membuka HTML; diagram diganti catatan ----
    $bodyDoc = preg_replace('~<div class="mermaid">.*?</div>~s',
        '<p style="border:1px solid #cbd5e1;background:#f8fafc;padding:8px;color:#475569"><i>[Diagram alur/ERD — lihat berkas versi HTML atau PDF]</i></p>',
        $bodyHtml);
    $docPage = "<html xmlns:o=\"urn:schemas-microsoft-com:office:office\" xmlns:w=\"urn:schemas-microsoft-com:office:word\" xmlns=\"http://www.w3.org/TR/REC-html40\">"
        ."<head><meta charset=\"utf-8\"><title>".htmlspecialchars($title)."</title>"
        ."<!--[if gte mso 9]><xml><w:WordDocument><w:View>Print</w:View><w:Zoom>100</w:Zoom></w:WordDocument></xml><![endif]-->"
        ."<style>$css</style></head><body>$bodyDoc</body></html>";
    file_put_contents("$docs/$base.doc", $docPage);

    echo "OK  $base  ->  .md/.html/.doc\n";
}
echo "Selesai membuat MD/HTML/DOC.\n";
