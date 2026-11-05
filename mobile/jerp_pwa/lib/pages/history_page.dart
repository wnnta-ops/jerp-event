import 'package:flutter/material.dart';

import '../config.dart';
import '../inbox/inbox_models.dart';
import '../inbox/inbox_store.dart';
import '../inbox/widgets.dart';
import 'detail_page.dart';

class HistoryPage extends StatelessWidget {
  const HistoryPage({super.key, required this.store});
  final InboxStore store;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(backgroundColor: oranye, foregroundColor: Colors.white, title: const Text('History')),
      body: ListenableBuilder(
        listenable: store,
        builder: (context, _) => RefreshIndicator(
          onRefresh: store.muatHistory,
          child: ListView(padding: const EdgeInsets.all(16), children: [
            if (store.error != null && store.riwayat.isEmpty)
              PesanError(pesan: store.error!, ulang: store.muatHistory)
            else if (store.memuat && store.riwayat.isEmpty)
              const Padding(padding: EdgeInsets.all(48), child: Center(child: CircularProgressIndicator()))
            else if (store.riwayat.isEmpty)
              const PesanKosong(ikon: Icons.history, judul: 'Belum ada keputusan', isi: 'Dokumen yang Anda setujui atau tolak akan muncul di sini.')
            else
              for (final item in store.riwayat)
                KartuInbox(
                  item: item,
                  onTap: () => Navigator.of(context).push(
                    MaterialPageRoute(builder: (_) => DetailPage(store: store, id: item.id)),
                  ),
                  bawah: _keputusan(context, item),
                ),
          ]),
        ),
      ),
    );
  }

  Widget _keputusan(BuildContext context, InboxItem item) {
    final setuju = item.keputusan == 'approved';
    final warna = setuju ? Colors.green.shade700 : Theme.of(context).colorScheme.error;
    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: Row(children: [
        Icon(setuju ? Icons.check_circle : Icons.cancel, size: 16, color: warna),
        const SizedBox(width: 4),
        Text(setuju ? 'Disetujui' : 'Ditolak', style: TextStyle(color: warna, fontWeight: FontWeight.w600)),
        const Spacer(),
        Text(tanggal(item.diputuskanAt), style: Theme.of(context).textTheme.bodySmall),
      ]),
    );
  }
}
