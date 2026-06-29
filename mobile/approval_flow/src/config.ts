// API J-ERP — Mobile Approver API base URL.
//
// Diambil dari env Expo `EXPO_PUBLIC_API_BASE_URL` (di-set di docker-compose.mobile.yml
// dari FO_URL server). Default AMAN = localhost, BUKAN produksi — supaya tester tidak
// tanpa sengaja mengubah data produksi.
//
//  - docker-compose.mobile.yml: EXPO_PUBLIC_API_BASE_URL=${FO_URL}/api/mobile
//  - Dev HP Expo Go (satu WiFi): set ke http://<IP-LAN-komputer>:8081/api/mobile
const fromEnv = (process.env.EXPO_PUBLIC_API_BASE_URL ?? '').trim();
export const API_BASE_URL = fromEnv.length > 0 ? fromEnv : 'http://127.0.0.1:8081/api/mobile';
