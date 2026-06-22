// HTTP client untuk Mobile Approver API (J-ERP). Semua panggilan ke backend
// lewat sini; mockService.ts memakai fungsi-fungsi ini. Token disimpan di memori
// (re-login saat app restart — cukup untuk prototipe).

import { API_BASE_URL } from '../config';
import type {
  Queue,
  RequestItem,
  HistoryEntry,
  Decision,
  DecideResult,
  SupportingDoc,
} from './types';

let token: string | null = null;

export function setToken(t: string | null): void {
  token = t;
}

export function getToken(): string | null {
  return token;
}

export interface ApiUser {
  id: number;
  name: string;
  role: string;
  roleKey?: string | null;
  init: string;
}

async function req(path: string, options: RequestInit = {}): Promise<any> {
  const res = await fetch(`${API_BASE_URL}${path}`, {
    ...options,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(options.headers ?? {}),
    },
  });
  const text = await res.text();
  const data = text ? JSON.parse(text) : null;
  if (!res.ok) {
    const msg = (data && (data.message as string)) || `HTTP ${res.status}`;
    throw Object.assign(new Error(msg), { status: res.status, data });
  }
  return data;
}

export async function apiLogin(
  email: string,
  password: string,
): Promise<{ token: string; user: ApiUser }> {
  return req('/auth/login', {
    method: 'POST',
    body: JSON.stringify({ email, password, device: 'expo-approver' }),
  });
}

export async function apiLogout(): Promise<void> {
  try {
    await req('/auth/logout', { method: 'POST' });
  } catch {
    // abaikan — token tetap dibuang sisi app
  }
}

export async function apiQueue(): Promise<Queue> {
  return req('/approvals');
}

export async function apiRequest(id: string): Promise<RequestItem> {
  return req(`/approvals/${id}`);
}

export async function apiDocs(id: string): Promise<SupportingDoc[]> {
  return req(`/approvals/${id}/docs`);
}

export async function apiDecide(
  id: string,
  action: Decision,
  reason?: string,
): Promise<DecideResult> {
  return req(`/approvals/${id}/decision`, {
    method: 'POST',
    body: JSON.stringify({ action, reason }),
  });
}

export async function apiReview(
  id: string,
  ok: boolean,
  reason?: string,
): Promise<DecideResult> {
  return req(`/approvals/${id}/review`, {
    method: 'POST',
    body: JSON.stringify({ ok, reason }),
  });
}

export async function apiDecideMany(
  ids: string[],
  action: Decision,
  reason?: string,
): Promise<{ ok: boolean; count: number }> {
  return req('/approvals/decisions', {
    method: 'POST',
    body: JSON.stringify({ ids: ids.map((i) => Number(i)), action, reason }),
  });
}

export async function apiHistory(): Promise<HistoryEntry[]> {
  return req('/approvals/history');
}

export async function apiSignature(): Promise<{ sig: string }> {
  return req('/approvals/signature');
}
