# Approver App — Developer Handoff

A cross-platform (iOS + Android) **front-end prototype** of the approvals app,
built with **Expo SDK 55 + React Native + TypeScript**. It mirrors the approver
side: heads/owners review and approve/reject requests on mobile.

> **Status: front-end only.** All data is local mock data. There is **no backend,
> no real auth, no push, no e-signature** yet. The whole app talks to ONE module
> (`src/data/mockService.ts`) — that's the seam where the real system plugs in.

## Run it

```bash
npm install
npx expo start        # scan QR with Expo Go, or press i / a / w
```

Requirements: Node 18+, Expo SDK 55. Web preview: `npx expo start --web`.

## How the two systems connect

The **request website** (where people submit permissions/approvals) is the source
of truth. This app is a **client** of it. You do **not** merge this React Native
code into the website — instead, the website (or its backend) exposes an **API**,
and this app's `mockService` is reimplemented against it.

### The contract to implement
- **`src/data/types.ts`** — data shapes: `RequestItem`, `HistoryEntry`,
  `SupportingDoc`, `BppPreview`, `BppSigner`, roles, decisions.
- **`src/data/mockService.ts`** — the functions the UI depends on. Back each with
  a real endpoint (signatures can stay sync or become async; `decide`/`review`/
  `decideMany` already return Promises):

| Function | Purpose | Suggested API |
|---|---|---|
| `getQueue(role)` | requests awaiting a role (needs/done) | `GET /approvals?role=` |
| `getRequest(id)` | one request | `GET /approvals/:id` |
| `getDocs(id)` | supporting documents | `GET /approvals/:id/docs` |
| `decide(id, action, reason?)` | approve / reject | `POST /approvals/:id/decision` |
| `review(id, ok, reason?)` | reviewer marks docs sesuai / minta revisi | `POST /approvals/:id/review` |
| `decideMany(ids, action, reason?)` | bulk approve / reject | `POST /approvals/decisions` |
| `addDoc(id, doc)` / `removeDoc(id, i)` | reviewer attach / remove a doc | `POST` / `DELETE /approvals/:id/docs` |
| `getHistory()` | past decisions | `GET /approvals/history` |
| `getSignature(role)` | stamped signature specimen | from user profile |
| `buildBppPreview(id)` | the printed BPP (replace with `sendToInternalSystem`) | internal print system |

The UI only ever imports `mockService` (via `src/state/AppContext.tsx`), so once
these functions call real endpoints, **no screens change**.

## Project map

```
src/
  data/        types.ts · seed.ts (mock data) · mockService.ts (the seam)
  state/       AppContext.tsx (reactive binding over the service)
  navigation/  root stack (auth gate) + bottom tabs
  components/   RequestCard, Pipeline, DocRow, Signature, ...
  screens/      Login, Home, Inbox, Detail, Biometric, Reject, Result,
                Bpp, DocPreview, History, HistoryDetail, Profile
  theme/        tokens.ts (colors, type/deadline color codes)
  utils/        sla.ts (deadline buckets + day/hour formatting)
```

## Behaviors worth knowing
- **Roles** (demo switch in Profile): GM, Director, Owner 1 & 2 (parallel
  approval), Reviewer (checks documents, doesn't approve).
- **Request types**: Memo / BPP / Kasbon / Kebutuhan Divisi / Others — left stripe
  color-coded; deadline tints the card (≤3d red, 4–7d amber, else white).
- **Multi-select** bulk approve (one biometric) / reject (one reason).
- **Biometric** approve uses `expo-local-authentication` (simulated fallback).
- Offline simulation (Profile) → approve shows error + retry.
