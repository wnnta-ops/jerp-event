'use strict';

// Kunci aplikasi J-ERP Approver — passkey bawaan perangkat (Face ID / sidik jari / PIN layar).
// Dipanggil dari lib/kunci/biometrik_web.dart. Verifikasinya LOKAL (tanpa server): cukup untuk
// memastikan pemilik HP yang membuka app, bukan pengganti login J-ERP.
(function () {
  const b64 = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf)))
    .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  const dariB64 = (s) => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/')), (c) => c.charCodeAt(0));
  const acak = () => crypto.getRandomValues(new Uint8Array(32));

  window.jerpKunci = {
    async tersedia() {
      try {
        return !!(window.PublicKeyCredential
          && await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable());
      } catch (e) {
        return false;
      }
    },

    async daftar(userId, nama) {
      try {
        const cred = await navigator.credentials.create({
          publicKey: {
            challenge: acak(),
            rp: { name: 'J-ERP Approver' },
            user: { id: new TextEncoder().encode('jerp-' + userId), name: nama, displayName: nama },
            pubKeyCredParams: [{ type: 'public-key', alg: -7 }, { type: 'public-key', alg: -257 }],
            authenticatorSelection: { authenticatorAttachment: 'platform', userVerification: 'required', residentKey: 'discouraged' },
            timeout: 60000,
          },
        });
        return cred ? b64(cred.rawId) : null;
      } catch (e) {
        return null;
      }
    },

    async verifikasi(credId) {
      try {
        const a = await navigator.credentials.get({
          publicKey: {
            challenge: acak(),
            allowCredentials: [{ type: 'public-key', id: dariB64(credId) }],
            userVerification: 'required',
            timeout: 60000,
          },
        });
        return !!a;
      } catch (e) {
        return false;
      }
    },
  };
})();
