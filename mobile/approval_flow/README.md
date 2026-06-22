# Approver App — front-end prototype

A runnable cross-platform (iOS + Android) prototype of the approvals app, built
with **Expo (React Native) + TypeScript**. It mirrors the screens, flow, and
states of `../approver_app_prototype.html`.

Front-end only: **local in-memory mock data, no backend, no web sync, no real
push, no real auth** (login is tap-to-enter). All mock data lives behind one
isolated service so the real API can drop in later without touching the UI.

## Run it on your phone (Expo Go)

1. Install **Expo Go** from the App Store / Play Store.
2. From this folder:
   ```bash
   npm install      # first time only
   npx expo start
   ```
3. Scan the QR code in the terminal with Expo Go (Android) or the Camera app (iOS).

Simulators: `npx expo start` then press `i` (iOS) or `a` (Android). Web smoke
test: `npx expo start --web`.

## What to try

- **Login** — tap Sign in / SSO / Face ID (any enters).
- **Home** — Awaiting you / Urgent now counts, menu tiles, top-priority peek.
- **Approvals** — Needs you / In chain / Done tabs, loading skeleton, empty states.
- **A request** — open a fund or document item, see the pipeline tracker and the
  **Dokumen pendukung** (supporting docs) list; tap a doc for a **preview**.
  - **Approve** → biometric confirm (real Face ID / fingerprint, simulated
    fallback on devices without it) → synced screen. An L2 item forwards to the
    next head. For a **fund** request the synced screen shows
    **"TTD di-stamp → eProperty"** and a **"Lihat BPP"** button.
  - **BPP preview** — mock Bukti Permohonan Pembayaran with the signature row
    stamped (per `bpp_digital_mockup.html`); "Print / Kirim FAT".
  - **Reject** → pick a reason + note → rejected screen.
- **History** — tap any past decision to open a read-only tracker.
- **Profile** — switch role **GM / Director / Owners** (changes the queue);
  toggle **Simulate offline**, then approve to see the **error + retry** flow;
  **Tanda tangan saya** (your registered signature specimen); sign out.

## Architecture

```
src/
  data/
    types.ts         Domain types (the UI contract)
    seed.ts          Mock seed data (3 roles, fund + doc, chains, history)
    mockService.ts   ← THE swap-in seam.
                       getQueue / getRequest / getDocs / decide / getHistory
                       getSignature / buildBppPreview (eProperty/e-sign seam)
  state/AppContext   Reactive React binding over the service (no data of its own)
  theme/tokens.ts    Colors / tiers / SLA / spacing, mirrored from the prototype
  components/         Reusable UI (RequestCard, Pipeline, Skeleton, Toggle, …)
  navigation/         Root stack (auth gate) + custom bottom tab bar
  screens/            One file per screen
```

**The seam:** the UI only ever calls `mockService` (via `AppContext`). To wire a
real backend, reimplement the exported functions in `src/data/mockService.ts`
against your API — `decide()` is already async — and the screens don't change.
The BPP/e-signature integration plugs in the same place: `buildBppPreview(id)`
becomes a real `sendToEproperty(approvalId, signatureImage, meta)` call, and
`getSignature(role)` returns the head's stored specimen — no UI change.
