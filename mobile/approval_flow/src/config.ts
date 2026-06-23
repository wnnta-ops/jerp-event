// API J-ERP — Mobile Approver API base URL.
//
// Default: server PRODUKSI (dipakai saat app dijalankan via docker-compose.mobile.yml
// atau Expo Go menunjuk ke server). HP memanggil API langsung ke domain ini.
//
// Untuk dev lokal, ganti ke:
//  - Web preview komputer sama: http://127.0.0.1:8081/api/mobile
//  - HP Expo Go (satu WiFi):    http://<IP-LAN-komputer>:8081/api/mobile
export const API_BASE_URL = 'https://j-erp.anisaproperti.com/api/mobile';
