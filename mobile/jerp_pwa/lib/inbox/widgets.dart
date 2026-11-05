import 'package:flutter/material.dart';

import '../config.dart';
import 'inbox_models.dart';

class LabelKategori extends StatelessWidget {
  const LabelKategori({super.key, required this.urgent});
  final bool urgent;

  @override
  Widget build(BuildContext context) {
    final warna = urgent ? const Color(0xFFFDE7D3) : const Color(0xFFE6F1FB);
    final teks = urgent ? const Color(0xFF9A3412) : const Color(0xFF0C447C);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
      decoration: BoxDecoration(color: warna, borderRadius: BorderRadius.circular(6)),
      child: Text(urgent ? 'Urgent' : 'Reguler', style: TextStyle(color: teks, fontSize: 12, fontWeight: FontWeight.w600)),
    );
  }
}

/// Kartu ringkas: kategori, jenis, nominal, judul, dept · pengaju, catatan.
class KartuInbox extends StatelessWidget {
  const KartuInbox({super.key, required this.item, this.onTap, this.bawah});
  final InboxItem item;
  final VoidCallback? onTap;

  /// Baris tambahan di bawah (mis. keputusan di History).
  final Widget? bawah;

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);
    final redup = tema.textTheme.bodySmall?.copyWith(color: tema.colorScheme.onSurfaceVariant);
    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      clipBehavior: Clip.antiAlias,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: BorderSide(color: item.urgent ? const Color(0xFFFDBA74) : tema.colorScheme.outlineVariant),
      ),
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(children: [
                LabelKategori(urgent: item.urgent),
                const SizedBox(width: 8),
                Expanded(child: Text(item.type, style: redup, overflow: TextOverflow.ellipsis)),
                Text(rupiah(item.amount), style: const TextStyle(fontWeight: FontWeight.w600)),
              ]),
              const SizedBox(height: 6),
              Text(item.judul, style: tema.textTheme.titleSmall, maxLines: 2, overflow: TextOverflow.ellipsis),
              const SizedBox(height: 2),
              Text(
                [item.department, item.submitter].where((s) => s != null && s.isNotEmpty).join(' · '),
                style: redup,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
              if (item.catatan != null && item.catatan!.isNotEmpty) ...[
                const SizedBox(height: 4),
                Text('Catatan: ${item.catatan}', style: redup, maxLines: 2, overflow: TextOverflow.ellipsis),
              ],
              if (item.turunan.isNotEmpty) ...[
                const SizedBox(height: 6),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                  decoration: BoxDecoration(color: const Color(0xFFFFF1E6), borderRadius: BorderRadius.circular(12)),
                  child: Text('+ ${item.turunan.length} alokasi/koreksi budget',
                      style: const TextStyle(color: Color(0xFF9A3412), fontSize: 11, fontWeight: FontWeight.w500)),
                ),
              ],
              ?bawah,
            ],
          ),
        ),
      ),
    );
  }
}

class PesanKosong extends StatelessWidget {
  const PesanKosong({super.key, required this.ikon, required this.judul, this.isi});
  final IconData ikon;
  final String judul;
  final String? isi;

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 48, horizontal: 24),
      child: Column(children: [
        Icon(ikon, size: 48, color: oranye.withValues(alpha: 0.6)),
        const SizedBox(height: 12),
        Text(judul, style: tema.textTheme.titleMedium, textAlign: TextAlign.center),
        if (isi != null) ...[
          const SizedBox(height: 4),
          Text(isi!, style: tema.textTheme.bodySmall, textAlign: TextAlign.center),
        ],
      ]),
    );
  }
}

class PesanError extends StatelessWidget {
  const PesanError({super.key, required this.pesan, required this.ulang});
  final String pesan;
  final VoidCallback ulang;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.all(24),
      child: Column(children: [
        Text(pesan, textAlign: TextAlign.center),
        const SizedBox(height: 12),
        OutlinedButton.icon(onPressed: ulang, icon: const Icon(Icons.refresh), label: const Text('Coba lagi')),
      ]),
    );
  }
}
