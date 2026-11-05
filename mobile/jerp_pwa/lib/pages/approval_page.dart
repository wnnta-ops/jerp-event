import 'package:flutter/material.dart';

import '../config.dart';
import '../inbox/inbox_store.dart';
import '../inbox/widgets.dart';
import 'detail_page.dart';

enum Saring { semua, urgent, reguler }

class ApprovalPage extends StatelessWidget {
  const ApprovalPage({super.key, required this.store, required this.saring, required this.gantiSaring});
  final InboxStore store;
  final Saring saring;
  final ValueChanged<Saring> gantiSaring;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(backgroundColor: oranye, foregroundColor: Colors.white, title: const Text('Approval inbox')),
      body: ListenableBuilder(
        listenable: store,
        builder: (context, _) {
          final daftar = store.perlu.where((i) => switch (saring) {
                Saring.semua => true,
                Saring.urgent => i.urgent,
                Saring.reguler => !i.urgent,
              }).toList();
          return RefreshIndicator(
            onRefresh: store.muatInbox,
            child: ListView(
              padding: const EdgeInsets.all(16),
              children: [
                SegmentedButton<Saring>(
                  segments: [
                    ButtonSegment(value: Saring.semua, label: Text('Semua (${store.perlu.length})')),
                    ButtonSegment(value: Saring.urgent, label: Text('Urgent (${store.urgent})')),
                    ButtonSegment(value: Saring.reguler, label: Text('Reguler (${store.reguler})')),
                  ],
                  selected: {saring},
                  showSelectedIcon: false,
                  onSelectionChanged: (s) => gantiSaring(s.first),
                ),
                const SizedBox(height: 12),
                if (store.error != null && store.perlu.isEmpty)
                  PesanError(pesan: store.error!, ulang: store.muatInbox)
                else if (store.memuat && store.perlu.isEmpty)
                  const Padding(padding: EdgeInsets.all(48), child: Center(child: CircularProgressIndicator()))
                else if (daftar.isEmpty)
                  const PesanKosong(ikon: Icons.task_alt, judul: 'Tidak ada yang menunggu Anda', isi: 'Tarik ke bawah untuk memuat ulang.')
                else
                  for (final item in daftar)
                    KartuInbox(
                      item: item,
                      onTap: () => Navigator.of(context).push(
                        MaterialPageRoute(builder: (_) => DetailPage(store: store, id: item.id)),
                      ),
                    ),
              ],
            ),
          );
        },
      ),
    );
  }
}
