import 'package:flutter/material.dart';

import '../auth/session.dart';
import '../config.dart';
import '../dashboard/dashboard_store.dart';
import '../dashboard/kartu_grafik.dart';
import '../inbox/inbox_store.dart';
import '../inbox/widgets.dart';
import 'approval_page.dart';

class HomePage extends StatelessWidget {
  const HomePage({
    super.key,
    required this.session,
    required this.store,
    required this.dashboard,
    required this.muatDashboard,
    required this.bukaApproval,
  });
  final Session session;
  final InboxStore store;
  final DashboardStore dashboard;
  final Future<void> Function() muatDashboard;
  final ValueChanged<Saring> bukaApproval;

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);
    final user = session.user!;
    return Scaffold(
      appBar: AppBar(
        backgroundColor: oranye,
        foregroundColor: Colors.white,
        title: Text('Halo, ${user.name.split(' ').first}'),
      ),
      body: ListenableBuilder(
        listenable: Listenable.merge([store, dashboard]),
        builder: (context, _) => RefreshIndicator(
          onRefresh: () => Future.wait([store.muatInbox(), muatDashboard()]),
          child: ListView(padding: const EdgeInsets.all(16), children: [
            Text('Menunggu persetujuan Anda', style: tema.textTheme.titleSmall),
            const SizedBox(height: 8),
            Row(children: [
              Expanded(child: _angka(context, 'Urgent', store.urgent, true, () => bukaApproval(Saring.urgent))),
              const SizedBox(width: 12),
              Expanded(child: _angka(context, 'Reguler', store.reguler, false, () => bukaApproval(Saring.reguler))),
            ]),
            if (store.error != null) ...[
              const SizedBox(height: 8),
              Text(store.error!, style: TextStyle(color: tema.colorScheme.error)),
            ],
            ..._dashboard(context),
          ]),
        ),
      ),
    );
  }

  /// Dashboard BO — hanya tampil bila user punya izin dashboard.{grup}.view di BO.
  List<Widget> _dashboard(BuildContext context) {
    final tema = Theme.of(context);
    if (dashboard.error != null && dashboard.allowed == null) {
      return [const SizedBox(height: 24), PesanError(pesan: dashboard.error!, ulang: muatDashboard)];
    }
    if (dashboard.allowed == null) {
      return const [Padding(padding: EdgeInsets.all(32), child: Center(child: CircularProgressIndicator()))];
    }
    if (dashboard.allowed == false) return const [];
    return [
      const SizedBox(height: 24),
      Row(children: [
        Expanded(child: Text('Dashboard', style: tema.textTheme.titleMedium)),
        if (dashboard.periode != null) Text(dashboard.periode!, style: tema.textTheme.bodySmall),
      ]),
      for (final g in dashboard.grup) ...[
        const SizedBox(height: 12),
        Text(g.judul, style: tema.textTheme.labelLarge?.copyWith(color: const Color(0xFFC2410C))),
        const SizedBox(height: 6),
        for (final k in g.charts)
          if (dashboard.charts[k] is Map<String, dynamic>) KartuGrafik(kunci: k, data: dashboard.charts[k] as Map<String, dynamic>),
      ],
    ];
  }

  Widget _angka(BuildContext context, String label, int n, bool urgent, VoidCallback onTap) {
    return Card(
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: BorderSide(color: urgent ? const Color(0xFFFDBA74) : Theme.of(context).colorScheme.outlineVariant),
      ),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(label, style: Theme.of(context).textTheme.bodySmall),
            const SizedBox(height: 4),
            Text(
              store.memuat && store.perlu.isEmpty ? '…' : '$n',
              style: TextStyle(fontSize: 28, fontWeight: FontWeight.w600, color: urgent ? const Color(0xFFC2410C) : null),
            ),
          ]),
        ),
      ),
    );
  }
}
