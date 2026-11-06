import 'package:flutter/material.dart';

import '../auth/session.dart';
import '../config.dart';
import '../dashboard/dashboard_store.dart';
import '../dashboard/kartu_grafik.dart';
import '../inbox/inbox_models.dart';
import '../inbox/inbox_store.dart';
import '../inbox/widgets.dart';
import 'approval_page.dart';
import 'detail_page.dart';

/// Home: header oranye → kartu "Menunggu Anda" → Perlu tindakan → Dashboard (bila ada izin BO).
class HomePage extends StatefulWidget {
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
  State<HomePage> createState() => _HomePageState();
}

class _HomePageState extends State<HomePage> {
  /// Grup dashboard yang sedang dipilih (key); null = grup pertama.
  String? _grup;

  InboxStore get store => widget.store;
  DashboardStore get dashboard => widget.dashboard;

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);
    return Scaffold(
      body: ListenableBuilder(
        listenable: Listenable.merge([store, dashboard]),
        builder: (context, _) => RefreshIndicator(
          onRefresh: () => Future.wait([store.muatInbox(), widget.muatDashboard()]),
          child: ListView(padding: EdgeInsets.zero, children: [
            // Latar oranye berhenti 44 px sebelum bawah → kartu "Menunggu Anda" setengah menumpang di atasnya.
            Stack(children: [
              Positioned(
                left: 0,
                right: 0,
                top: 0,
                bottom: 44,
                child: DecoratedBox(
                  decoration: BoxDecoration(color: warnaHeader(context), borderRadius: const BorderRadius.vertical(bottom: Radius.circular(24))),
                ),
              ),
              Column(children: [
                _header(context),
                Padding(padding: const EdgeInsets.symmetric(horizontal: 16), child: _menunggu(context)),
              ]),
            ]),
            const SizedBox(height: 8),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                if (store.error != null) ...[
                  Text(store.error!, style: TextStyle(color: tema.colorScheme.error)),
                  const SizedBox(height: 8),
                ],
                ..._perluTindakan(context),
                ..._dashboard(context),
              ]),
            ),
          ]),
        ),
      ),
    );
  }

  Widget _header(BuildContext context) {
    final user = widget.session.user!;
    final jam = DateTime.now().hour;
    final salam = jam < 11 ? 'Selamat pagi' : (jam < 15 ? 'Selamat siang' : (jam < 18 ? 'Selamat sore' : 'Selamat malam'));
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 16, 12, 20),
      child: SafeArea(
        bottom: false,
        child: Row(children: [
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(salam, style: TextStyle(color: Colors.white.withValues(alpha: 0.85), fontSize: 13)),
              const SizedBox(height: 2),
              Text('Halo, ${user.name.split(' ').first}',
                  style: const TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.w600)),
            ]),
          ),
          IconButton(
            tooltip: 'Approval inbox',
            color: Colors.white,
            onPressed: () => widget.bukaApproval(Saring.semua),
            icon: Badge(
              isLabelVisible: store.urgent > 0,
              backgroundColor: Colors.white,
              smallSize: 8,
              child: const Icon(Icons.notifications_none),
            ),
          ),
        ]),
      ),
    );
  }

  /// Satu angka besar (semua yang menunggu) + label urgent / reguler yang bisa diketuk.
  Widget _menunggu(BuildContext context) {
    final tema = Theme.of(context);
    final memuat = store.memuat && store.perlu.isEmpty;
    final gelap = tema.brightness == Brightness.dark;
    return Card(
      margin: EdgeInsets.zero,
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: () => widget.bukaApproval(Saring.semua),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 14),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('Menunggu Anda', style: tema.textTheme.bodySmall?.copyWith(color: tema.colorScheme.onSurfaceVariant)),
            Row(crossAxisAlignment: CrossAxisAlignment.baseline, textBaseline: TextBaseline.alphabetic, children: [
              Text(memuat ? '…' : '${store.perlu.length}', style: const TextStyle(fontSize: 32, fontWeight: FontWeight.w600)),
              const SizedBox(width: 6),
              Text('dokumen', style: TextStyle(color: tema.colorScheme.onSurfaceVariant)),
            ]),
            const SizedBox(height: 6),
            Row(children: [
              _label('${store.urgent} urgent', gelap ? const Color(0xFF3B2326) : const Color(0xFFFEE2E2),
                  gelap ? const Color(0xFFF2A7A7) : const Color(0xFFB91C1C), () => widget.bukaApproval(Saring.urgent)),
              const SizedBox(width: 8),
              _label('${store.reguler} reguler', tema.colorScheme.surfaceContainerHighest, tema.colorScheme.onSurfaceVariant,
                  () => widget.bukaApproval(Saring.reguler)),
            ]),
          ]),
        ),
      ),
    );
  }

  Widget _label(String teks, Color latar, Color warna, VoidCallback onTap) {
    return Material(
      color: latar,
      borderRadius: BorderRadius.circular(20),
      child: InkWell(
        borderRadius: BorderRadius.circular(20),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
          child: Text(teks, style: TextStyle(color: warna, fontSize: 12, fontWeight: FontWeight.w500)),
        ),
      ),
    );
  }

  /// 3 dokumen teratas (urgent dulu) → langsung ke detail.
  List<Widget> _perluTindakan(BuildContext context) {
    final tema = Theme.of(context);
    final teratas = [...store.perlu.where((i) => i.urgent), ...store.perlu.where((i) => !i.urgent)].take(3).toList();
    return [
      _judul(context, 'Perlu tindakan', aksi: store.perlu.length > 3 ? 'Semua' : null, onAksi: () => widget.bukaApproval(Saring.semua)),
      if (teratas.isEmpty)
        Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Text(store.memuat ? 'Memuat…' : 'Tidak ada dokumen yang menunggu Anda.',
                style: tema.textTheme.bodySmall?.copyWith(color: tema.colorScheme.onSurfaceVariant)),
          ),
        )
      else
        Card(
          child: Column(children: [
            for (final (n, i) in teratas.indexed) ...[
              if (n > 0) const Divider(height: 1, indent: 16, endIndent: 16),
              _baris(context, i),
            ],
          ]),
        ),
    ];
  }

  Widget _baris(BuildContext context, InboxItem i) {
    final tema = Theme.of(context);
    return ListTile(
      dense: true,
      leading: Container(
        width: 8,
        height: 8,
        decoration: BoxDecoration(shape: BoxShape.circle, color: i.urgent ? const Color(0xFFDC2626) : const Color(0xFFA8A29E)),
      ),
      minLeadingWidth: 8,
      title: Text(i.judul, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w500)),
      subtitle: Text([i.type, i.department].whereType<String>().where((s) => s.isNotEmpty).join(' · '),
          maxLines: 1, overflow: TextOverflow.ellipsis),
      trailing: Text(rupiah(i.amount), style: tema.textTheme.bodySmall?.copyWith(fontWeight: FontWeight.w600)),
      onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => DetailPage(store: store, id: i.id))),
    );
  }

  Widget _judul(BuildContext context, String teks, {String? aksi, VoidCallback? onAksi, String? kanan}) {
    final tema = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.only(top: 16, bottom: 6),
      child: Row(children: [
        Expanded(child: Text(teks, style: tema.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w600))),
        if (kanan != null) Text(kanan, style: tema.textTheme.bodySmall?.copyWith(color: tema.colorScheme.onSurfaceVariant)),
        if (aksi != null)
          TextButton(
            style: TextButton.styleFrom(visualDensity: VisualDensity.compact, foregroundColor: aksenTeks(context)),
            onPressed: onAksi,
            child: Text(aksi),
          ),
      ]),
    );
  }

  /// Dashboard BO — hanya tampil bila user punya izin dashboard.{grup}.view di BO. Satu grup sekaligus.
  List<Widget> _dashboard(BuildContext context) {
    if (dashboard.error != null && dashboard.allowed == null) {
      return [const SizedBox(height: 24), PesanError(pesan: dashboard.error!, ulang: widget.muatDashboard)];
    }
    if (dashboard.allowed == null) {
      return const [Padding(padding: EdgeInsets.all(32), child: Center(child: CircularProgressIndicator()))];
    }
    if (dashboard.allowed == false || dashboard.grup.isEmpty) return const [];
    final aktif = dashboard.grup.where((g) => g.key == _grup).firstOrNull ?? dashboard.grup.first;
    return [
      _judul(context, 'Dashboard', kanan: dashboard.periode),
      if (dashboard.grup.length > 1)
        // Bisa digeser ke samping; chip boleh tergambar sampai tepi layar (tanda masih ada pilihan lain).
        SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          clipBehavior: Clip.none,
          child: Row(children: [
            for (final g in dashboard.grup)
              Padding(
                padding: const EdgeInsets.only(right: 8),
                child: ChoiceChip(
                  label: Text(g.judul),
                  selected: g == aktif,
                  showCheckmark: false,
                  selectedColor: latarPilih(context),
                  labelStyle: TextStyle(
                    color: g == aktif ? teksPilih(context) : null,
                    fontWeight: g == aktif ? FontWeight.w600 : null,
                  ),
                  onSelected: (_) => setState(() => _grup = g.key),
                ),
              ),
          ]),
        ),
      const SizedBox(height: 8),
      for (final k in aktif.charts)
        if (dashboard.charts[k] is Map<String, dynamic>) KartuGrafik(kunci: k, data: dashboard.charts[k] as Map<String, dynamic>),
    ];
  }
}
