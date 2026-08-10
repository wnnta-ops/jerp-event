// App di-serve di balik jerp-nginx pada basePath /legal. Next otomatis mem-prefix
// <Link> & router, TAPI TIDAK <a href>/<img src>/<iframe src> absolut (mis. URL
// file /uploads/... atau endpoint /api/...). Helper ini mem-prefix-nya agar tak 404.
export const BASE_PATH = "/legal";

export function withBase(url: string | null | undefined): string {
  if (!url) return "";
  if (/^(https?:|blob:|data:)/i.test(url)) return url;                 // eksternal/blob/data
  if (url === BASE_PATH || url.startsWith(BASE_PATH + "/")) return url; // sudah ber-prefix
  return url.startsWith("/") ? BASE_PATH + url : url;                  // relatif absolut → prefix
}
