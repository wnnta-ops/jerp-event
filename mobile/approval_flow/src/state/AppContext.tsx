// AppContext — reactive session state for the UI.
//
// This holds NO mock data of its own. It mirrors what mockService exposes and
// re-reads from the service after every mutation, then re-renders. The service
// remains the single swappable seam; this is just the React binding.

import React, { createContext, useContext, useMemo, useState, useCallback } from 'react';
import * as api from '../data/mockService';
import type { Queue, RoleKey, RoleProfile, HistoryEntry, Decision, DecideResult, RequestItem, SupportingDoc, BppPreview } from '../data/types';

interface AppState {
  authed: boolean;
  role: RoleKey;
  profile: RoleProfile;
  queue: Queue;
  history: HistoryEntry[];
  offline: boolean;
  reasons: string[];

  login: (email: string, password: string) => Promise<void>;
  signOut: () => void;
  switchRole: (role: RoleKey) => void;
  toggleOffline: () => void;
  getRequest: (id: string) => RequestItem | undefined;
  getDocs: (id: string) => SupportingDoc[];
  getBrowsableDocs: () => SupportingDoc[];
  addDoc: (id: string, doc: SupportingDoc) => void;
  removeDoc: (id: string, index: number) => void;
  getSignature: (role?: RoleKey) => string;
  buildBppPreview: (id: string) => BppPreview;
  decide: (id: string, action: Decision, reason?: string) => Promise<DecideResult>;
  review: (id: string, ok: boolean, reason?: string) => Promise<DecideResult>;
  decideMany: (ids: string[], action: Decision, reason?: string) => Promise<{ ok: boolean; count: number; offline?: boolean }>;
  revisionReasons: string[];
  refresh: () => void;
}

const AppCtx = createContext<AppState | null>(null);

export function AppProvider({ children }: { children: React.ReactNode }) {
  const [authed, setAuthed] = useState(false);
  // Snapshot bumped whenever service state changes, to force a refresh of derived data.
  const [, setTick] = useState(0);
  const bump = useCallback(() => setTick((t) => t + 1), []);

  const role = api.getRole();
  const profile = api.getProfile();
  const queue = api.getQueue();
  const history = api.getHistory();
  const offline = api.isOffline();
  const reasons = api.getReasons();
  const revisionReasons = api.getRevisionReasons();

  const login = useCallback(async (email: string, password: string) => {
    await api.signIn(email, password);
    setAuthed(true);
    bump();
  }, [bump]);

  // Pull-to-refresh: tarik ulang queue + history dari server lalu re-render.
  const reload = useCallback(() => {
    api.hydrate().then(bump).catch(() => bump());
  }, [bump]);

  const signOut = useCallback(() => {
    api.signOut();
    setAuthed(false);
    bump();
  }, [bump]);

  const switchRole = useCallback((r: RoleKey) => {
    api.setRole(r);
    bump();
  }, [bump]);

  const toggleOffline = useCallback(() => {
    api.setOffline(!api.isOffline());
    bump();
  }, [bump]);

  const getRequest = useCallback((id: string) => api.getRequest(id), []);
  const getDocs = useCallback((id: string) => api.getDocs(id), []);
  const getBrowsableDocs = useCallback(() => api.getBrowsableDocs(), []);
  const addDoc = useCallback((id: string, doc: SupportingDoc) => { api.addDoc(id, doc); bump(); }, [bump]);
  const removeDoc = useCallback((id: string, index: number) => { api.removeDoc(id, index); bump(); }, [bump]);
  const getSignature = useCallback((r?: RoleKey) => api.getSignature(r), []);
  const buildBppPreview = useCallback((id: string) => api.buildBppPreview(id), []);

  const decide = useCallback(
    async (id: string, action: Decision, reason?: string) => {
      const result = await api.decide(id, action, reason);
      bump();
      return result;
    },
    [bump],
  );

  const review = useCallback(
    async (id: string, ok: boolean, reason?: string) => {
      const result = await api.review(id, ok, reason);
      bump();
      return result;
    },
    [bump],
  );

  const decideMany = useCallback(
    async (ids: string[], action: Decision, reason?: string) => {
      const result = await api.decideMany(ids, action, reason);
      bump();
      return result;
    },
    [bump],
  );

  const value = useMemo<AppState>(
    () => ({
      authed, role, profile, queue, history, offline, reasons, revisionReasons,
      login, signOut, switchRole, toggleOffline, getRequest, getDocs, getBrowsableDocs, addDoc, removeDoc, getSignature, buildBppPreview, decide, review, decideMany, refresh: reload,
    }),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [authed, role, profile, queue, history, offline, reasons, revisionReasons, login, signOut, switchRole, toggleOffline, getRequest, getDocs, getBrowsableDocs, addDoc, removeDoc, getSignature, buildBppPreview, decide, review, decideMany, reload],
  );

  return <AppCtx.Provider value={value}>{children}</AppCtx.Provider>;
}

export function useApp(): AppState {
  const ctx = useContext(AppCtx);
  if (!ctx) throw new Error('useApp must be used within AppProvider');
  return ctx;
}
