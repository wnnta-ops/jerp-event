<?php
/**
 * Build Riwayat-Pengembangan: Markdown -> HTML + Word (.doc).
 * Jalankan: php docs/Riwayat-Pengembangan/_build.php
 * PDF dibuat terpisah via Chrome/Edge headless.
 */
require __DIR__.'/../../vendor/autoload.php';

use League\CommonMark\GithubFlavoredMarkdownConverter;

$dir   = __DIR__;
$base  = 'Riwayat-Pengembangan-Java-Core-System';
$title = 'Riwayat Pengembangan — Java Core System';

$converter = new GithubFlavoredMarkdownConverter(['html_input' => 'allow', 'allow_unsafe_links' => false]);

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
  ul, ol { margin: 6px 0 6px 18px; }
CSS;

$md   = file_get_contents("$dir/$base.md");
$body = $converter->convert($md)->getContent();

// HTML
$html = "<!DOCTYPE html><html lang=\"id\"><head><meta charset=\"utf-8\">"
    ."<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"
    ."<title>".htmlspecialchars($title)."</title><style>$css</style></head><body>$body</body></html>";
file_put_contents("$dir/$base.html", $html);

// Word (.doc)
$doc = "<html xmlns:o=\"urn:schemas-microsoft-com:office:office\" xmlns:w=\"urn:schemas-microsoft-com:office:word\" xmlns=\"http://www.w3.org/TR/REC-html40\">"
    ."<head><meta charset=\"utf-8\"><title>".htmlspecialchars($title)."</title>"
    ."<!--[if gte mso 9]><xml><w:WordDocument><w:View>Print</w:View><w:Zoom>100</w:Zoom></w:WordDocument></xml><![endif]-->"
    ."<style>$css</style></head><body>$body</body></html>";
file_put_contents("$dir/$base.doc", $doc);

echo "OK  $base  ->  .html + .doc\n";
