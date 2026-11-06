import 'package:flutter/material.dart';

import '../auth/session.dart';
import '../dashboard/dashboard_store.dart';
import '../inbox/inbox_store.dart';
import '../kunci/kunci_store.dart';
import '../prefs.dart';
import '../push/push_browser.dart';
import 'approval_page.dart';
import 'detail_page.dart';
import 'history_page.dart';
import 'home_page.dart';
import 'profile_page.dart';

/// Kerangka setelah login: 4 tab bawah (Home, Approval, History, Profil).
class ShellPage extends StatefulWidget {
  const ShellPage({super.key, required this.session, required this.prefs, required this.kunci});
  final Session session;
  final Preferensi prefs;
  final KunciStore kunci;

  @override
  State<ShellPage> createState() => _ShellPageState();
}

class _ShellPageState extends State<ShellPage> {
  late final InboxStore _store = InboxStore(widget.session.api);
  late final DashboardStore _dashboard = DashboardStore(widget.session.api);
  late int _tab = widget.prefs.tabAwal;
  Saring _saring = Saring.semua;

  @override
  void initState() {
    super.initState();
    _store.muatInbox();
    _store.muatHistory();
    _muatDashboard();
    _bukaDariNotifikasi();
    // APK: notifikasi FCM diketuk → langsung ke detail dokumennya.
    PushBrowser.bukaDokumen = (id) {
      if (!mounted) return;
      setState(() => _tab = 1);
      Navigator.of(context).push(MaterialPageRoute(builder: (_) => DetailPage(store: _store, id: id)));
    };
    PushBrowser.pesanMasuk = (judul, isi, id) {
      if (!mounted) return;
      _store.muatInbox();
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        behavior: SnackBarBehavior.floating,
        duration: const Duration(seconds: 6),
        content: Text([judul, isi].where((t) => t.isNotEmpty).join('\n')),
        action: id == null ? null : SnackBarAction(label: 'Buka', onPressed: () => PushBrowser.bukaDokumen?.call(id)),
      ));
    };
  }

  /// Notifikasi diketuk → app dibuka dengan ?buka=ID → langsung ke detail dokumen itu (sekali).
  static bool _sudahDibuka = false;
  void _bukaDariNotifikasi() {
    final id = int.tryParse(Uri.base.queryParameters['buka'] ?? '');
    if (id == null || _sudahDibuka) return;
    _sudahDibuka = true;
    _tab = 1;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) Navigator.of(context).push(MaterialPageRoute(builder: (_) => DetailPage(store: _store, id: id)));
    });
  }

  Future<void> _muatDashboard() => _dashboard.muat(dari: widget.prefs.dashboardDari);

  @override
  void dispose() {
    PushBrowser.bukaDokumen = null;
    PushBrowser.pesanMasuk = null;
    _store.dispose();
    _dashboard.dispose();
    super.dispose();
  }

  void _bukaApproval(Saring s) => setState(() {
        _saring = s;
        _tab = 1;
      });

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: IndexedStack(index: _tab, children: [
        HomePage(session: widget.session, store: _store, dashboard: _dashboard, muatDashboard: _muatDashboard, bukaApproval: _bukaApproval),
        ApprovalPage(store: _store, saring: _saring, gantiSaring: (s) => setState(() => _saring = s)),
        HistoryPage(store: _store),
        ProfilePage(session: widget.session, prefs: widget.prefs, kunci: widget.kunci, periodeBerubah: _muatDashboard),
      ]),
      bottomNavigationBar: ListenableBuilder(
        listenable: _store,
        builder: (context, _) => DecoratedBox(
          decoration: BoxDecoration(border: Border(top: BorderSide(color: Theme.of(context).colorScheme.outlineVariant))),
          child: NavigationBar(
            selectedIndex: _tab,
            onDestinationSelected: (i) => setState(() => _tab = i),
            destinations: [
              const NavigationDestination(icon: Icon(Icons.home_outlined), selectedIcon: Icon(Icons.home), label: 'Home'),
              NavigationDestination(
                icon: Badge(
                  isLabelVisible: _store.perlu.isNotEmpty,
                  label: Text('${_store.perlu.length}'),
                  child: const Icon(Icons.inbox_outlined),
                ),
                selectedIcon: const Icon(Icons.inbox),
                label: 'Approval',
              ),
              const NavigationDestination(icon: Icon(Icons.history), label: 'History'),
              const NavigationDestination(icon: Icon(Icons.person_outline), selectedIcon: Icon(Icons.person), label: 'Profil'),
            ],
          ),
        ),
      ),
    );
  }
}
