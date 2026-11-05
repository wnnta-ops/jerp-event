{{-- Lampiran Perubahan Budget (BudgetLampiranController) — lembar mandiri; isi halamannya di
     pdf/partials/lampiran-perubahan-budget-hal. Data dari LampiranPerubahanBudget. --}}
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Lampiran {{ $jenis }} {{ $no }}</title>
<style>
    @page { size: A4; margin: 10mm; }
    * { box-sizing: border-box; }
    body { margin: 0; background: #fff; }
    .noprint { margin: 12px auto; width: 210mm; text-align: right; }
    .noprint button { font: inherit; padding: 6px 14px; border-radius: 6px; border: 1px solid #9ca3af; background: #fff; cursor: pointer; }
    @media print { .noprint { display: none; } }
    @media screen { body { background: #e5e7eb; } .hal { background: #fff; width: 210mm; min-height: 297mm; margin: 0 auto 12px; padding: 10mm; box-shadow: 0 1px 4px rgba(0,0,0,.15); } }
</style>
</head>
<body>
<div class="noprint"><button type="button" onclick="window.print()">Cetak / Simpan PDF</button></div>
@include('pdf.partials.lampiran-perubahan-budget-hal')
</body>
</html>
