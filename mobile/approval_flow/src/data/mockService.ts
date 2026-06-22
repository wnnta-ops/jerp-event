// ─────────────────────────────────────────────────────────────────────────────
// dataService — batas data tunggal (dulu "mockService").
//
// Sekarang DIDUKUNG API J-ERP (Mobile Approver API). UI tetap memanggil fungsi
// yang sama; getter baca dari cache di memori yang di-hydrate dari API setelah
// login & setiap keputusan. Nama file & ekspor dipertahankan agar layar tak
// berubah. Beberapa fitur prototipe (offline sim, reviewer attach doc, BPP)
// masih lokal sampai didukung backend.
// ─────────────────────────────────────────────────────────────────────────────

import {
  apiDecide,
  apiDecideMany,
  apiHistory,
  apiLogin,
  apiLogout,
  apiQueue,
  apiReview,
  apiSignature,
  setToken,
} from './api';
import { REASONS, REVISION_REASONS, BROWSABLE_DOCS, BPP_SIGNERS } from './seed';
import type {
  Queue,
  RequestItem,
  HistoryEntry,
  RoleKey,
  RoleProfile,
  Decision,
  DecideResult,
  SupportingDoc,
  BppPreview,
} from './types';

const clone = <T,>(o: T): T => JSON.parse(JSON.stringify(o));

const DEFAULT_PROFILE: RoleProfile = { name: '—', role: 'Approver', init: '–' };
const EMPTY_QUEUE: Queue = { needs: [], inchain: [], done: [] };

interface Cache {
  token: string | null;
  role: RoleKey; // hanya label tampilan; data nyata ditentukan user login di server
  profile: RoleProfile;
  queue: Queue;
  history: HistoryEntry[];
  signature: string;
  offline: boolean;
}

const cache: Cache = {
  token: null,
  role: 'GM',
  profile: DEFAULT_PROFILE,
  queue: clone(EMPTY_QUEUE),
  history: [],
  signature: '',
  offline: false,
};

// ── Auth / session ───────────────────────────────────────────────────────────

// Login nyata ke J-ERP: email + password → token, profil, lalu hydrate queue.
export async function signIn(email: string, password: string): Promise<void> {
  const { token, user } = await apiLogin(email, password);
  setToken(token);
  cache.token = token;
  cache.profile = { name: user.name, role: user.role, init: user.init };
  try {
    cache.signature = (await apiSignature()).sig || '';
  } catch {
    cache.signature = '';
  }
  await hydrate();
}

// Tarik ulang queue + history dari API ke cache (dipanggil setelah login & tiap keputusan).
export async function hydrate(): Promise<void> {
  const [q, h] = await Promise.all([apiQueue(), apiHistory()]);
  cache.queue = q;
  cache.history = h;
}

export function getRole(): RoleKey {
  return cache.role;
}

export function getProfile(_role: RoleKey = cache.role): RoleProfile {
  return clone(cache.profile);
}

// Pergantian role di Profile hanya kosmetik (data nyata = user login server).
export function setRole(role: RoleKey): void {
  cache.role = role;
}

export function signOut(): void {
  void apiLogout();
  setToken(null);
  cache.token = null;
  cache.profile = DEFAULT_PROFILE;
  cache.queue = clone(EMPTY_QUEUE);
  cache.history = [];
  cache.signature = '';
  cache.offline = false;
}

// ── Offline simulation (lokal, demo) ─────────────────────────────────────────

export function isOffline(): boolean {
  return cache.offline;
}

export function setOffline(value: boolean): void {
  cache.offline = value;
}

// ── Queue / requests ─────────────────────────────────────────────────────────

export function getQueue(_role: RoleKey = cache.role): Queue {
  return clone(cache.queue);
}

export function getRequest(id: string, _role: RoleKey = cache.role): RequestItem | undefined {
  const r = findRequest(id);
  return r ? clone(r) : undefined;
}

// ── Decide / review ──────────────────────────────────────────────────────────

export async function decide(
  id: string,
  action: Decision,
  reason?: string,
): Promise<DecideResult> {
  // Simulasi offline: approve gagal & bisa retry (rejection tetap jalan).
  if (cache.offline && action === 'approved') {
    return { ok: false, offline: true, request: getRequest(id) };
  }
  try {
    const res = await apiDecide(id, action, reason);
    await hydrate();
    return res;
  } catch {
    return { ok: false };
  }
}

export async function review(id: string, ok: boolean, reason?: string): Promise<DecideResult> {
  try {
    const res = await apiReview(id, ok, reason);
    await hydrate();
    return res;
  } catch {
    return { ok: false };
  }
}

export function getRevisionReasons(): string[] {
  return [...REVISION_REASONS];
}

export async function decideMany(
  ids: string[],
  action: Decision,
  reason?: string,
): Promise<{ ok: boolean; count: number; offline?: boolean }> {
  if (cache.offline && action === 'approved') return { ok: false, offline: true, count: 0 };
  try {
    const res = await apiDecideMany(ids, action, reason);
    await hydrate();
    return res;
  } catch {
    return { ok: false, count: 0 };
  }
}

// ── History ──────────────────────────────────────────────────────────────────

export function getHistory(): HistoryEntry[] {
  return clone(cache.history);
}

export function getReasons(): string[] {
  return [...REASONS];
}

// ── Documents / signature / BPP ──────────────────────────────────────────────

// Cari request di queue aktif atau di snapshot keputusan (done/history).
function findRequest(id: string): RequestItem | undefined {
  const inQueue = [...cache.queue.needs, ...cache.queue.inchain].find((r) => r.id === id);
  if (inQueue) return inQueue;
  const done = cache.queue.done.find((d) => d.id === id && d.request);
  if (done?.request) return done.request;
  const hist = cache.history.find((d) => d.id === id && d.request);
  return hist?.request;
}

export function getDocs(id: string): SupportingDoc[] {
  const r = findRequest(id);
  return r?.docs ? clone(r.docs) : [];
}

// Sumber dokumen yang bisa di-browse reviewer (masih mock — belum ada upload nyata).
export function getBrowsableDocs(): SupportingDoc[] {
  return clone(BROWSABLE_DOCS);
}

export function addDoc(id: string, doc: SupportingDoc): SupportingDoc[] {
  const request = findRequest(id);
  if (!request) return [];
  if (!request.docs) request.docs = [];
  request.docs.push({ ...doc });
  return clone(request.docs);
}

export function removeDoc(id: string, index: number): SupportingDoc[] {
  const request = findRequest(id);
  if (!request?.docs) return [];
  request.docs.splice(index, 1);
  return clone(request.docs);
}

// Spesimen tanda tangan approver (dari profil server; kosong bila belum diset).
export function getSignature(_role: RoleKey = cache.role): string {
  return cache.signature;
}

// Preview BPP — masih mock lokal sampai integrasi e-signature/print backend.
export function buildBppPreview(id: string): BppPreview {
  const r = findRequest(id);
  const isFund = r?.type === 'FUND';
  return {
    generatedAt: new Date().toLocaleString('id-ID'),
    date: new Date().toLocaleDateString('id-ID'),
    payee: r ? `${r.requester} · ${r.dept}` : '-',
    uraian: r ? (isFund ? r.purpose ?? '-' : `Akses: ${r.title ?? '-'}`) : '-',
    total: isFund ? r?.amount ?? '-' : '(non-tunai)',
    voucher: 'AP.INV/EXPO/MUP/26/VI/00012',
    terbilang: '—',
    signers: clone(BPP_SIGNERS),
  };
}
