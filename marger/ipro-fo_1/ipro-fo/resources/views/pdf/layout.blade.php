<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>{{ $title ?? 'Document' }}</title>
<style>
@page { size: A4; margin: 20mm 15mm; }
body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 11pt; color: #0f172a; line-height: 1.5; }
h1 { font-size: 18pt; margin: 0 0 12px; color: #b35a00; }
h2 { font-size: 14pt; margin: 16px 0 8px; color: #b35a00; }
.header { border-bottom: 2px solid #ff8c00; padding-bottom: 12px; margin-bottom: 18px; }
.header .company { font-size: 14pt; font-weight: bold; }
.header .meta { font-size: 9pt; color: #64748b; }
.doc-info { display: table; width: 100%; margin: 14px 0; font-size: 10pt; }
.doc-info .row { display: table-row; }
.doc-info .label, .doc-info .value { display: table-cell; padding: 4px 0; }
.doc-info .label { font-weight: 600; width: 130px; color: #475569; }
table { width: 100%; border-collapse: collapse; margin: 12px 0; }
th, td { padding: 8px 10px; text-align: left; border-bottom: 1px solid #e2e8f0; }
th { background: #f1f5f9; font-size: 9pt; text-transform: uppercase; color: #475569; }
.total-row td { border-top: 2px solid #0f172a; font-weight: bold; font-size: 12pt; }
.mono { font-family: 'JetBrains Mono', Consolas, monospace; }
.right { text-align: right; }
.muted { color: #64748b; font-size: 9pt; }
.signature { margin-top: 60px; }
.signature .name { margin-top: 70px; border-top: 1px solid #0f172a; padding-top: 4px; font-weight: 600; display: inline-block; min-width: 200px; }
.footer { margin-top: 28px; text-align: center; font-size: 8pt; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 12px; }
</style>
</head>
<body>

<div class="header">
    <div class="company">PT. MITRA USAHA PROPERTINDO</div>
    <div class="meta">Java Residence · Bandung · ipro-fo.mup.id</div>
</div>

@yield('content')

<div class="footer">
    Dokumen ini dicetak dari Java Core System v2.0 · {{ now()->format('d-m-Y H:i') }}
</div>

</body>
</html>
