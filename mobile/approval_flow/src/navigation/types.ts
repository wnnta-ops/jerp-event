// Navigation param lists. Detail/Reject/Result/Biometric are pushed full-screen
// over the tabs (the prototype hides the tab bar on those views).

import type { Decision, RequestType, HistoryEntry } from '../data/types';

// Snapshot of the request's display fields, captured at decision time. The item
// leaves the queue once decided, so the Result screen can't re-fetch it.
export interface ResultSnapshot {
  type: RequestType;
  label: string; // amount or document title
  requester: string;
  forward: string | null;
}

export type RootStackParamList = {
  Login: undefined;
  Main: undefined; // bottom tabs
  Detail: { id: string; readOnly?: boolean };
  Biometric: { id?: string; ids?: string[] };
  Reject: { id?: string; ids?: string[]; mode?: 'reject' | 'revision' };
  Result: { kind: 'approved' | 'rejected' | 'error' | 'reviewed' | 'revision'; id?: string; ids?: string[]; reason?: string; snap?: ResultSnapshot; bulk?: number };
  HistoryDetail: { entry: HistoryEntry };
  DocPreview: { id: string; index: number };
  Bpp: { id: string };
};

export type MainTabParamList = {
  Home: undefined;
  Approvals: undefined;
  History: undefined;
  Profile: undefined;
};

export type { Decision };
