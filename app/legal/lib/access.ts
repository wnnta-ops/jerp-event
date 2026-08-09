import { type AccessLevel, type Role } from "./enums";

/**
 * Feature 4 tiering (spec §7): same engine, variable chain length, sized by the
 * document's access_level and the requested access_type. Reserve the Director's
 * signature for genuinely high-stakes cases (physical-original checkout).
 */
export const ACCESS_TYPES = ["view", "download", "checkout_original"] as const;
export type AccessType = (typeof ACCESS_TYPES)[number];

export const ACCESS_TYPE_LABELS: Record<AccessType, string> = {
  view: "Lihat",
  download: "Unduh salinan",
  checkout_original: "Pinjam original fisik",
};

export const REQUEST_STATUSES = ["pending", "pending_external", "approved", "rejected", "fulfilled"] as const;
export type RequestStatus = (typeof REQUEST_STATUSES)[number];

export const REQUEST_STATUS_LABELS: Record<RequestStatus, string> = {
  pending: "Menunggu",
  pending_external: "Menunggu approval eksternal",
  approved: "Disetujui",
  rejected: "Ditolak",
  fulfilled: "Selesai · diserahkan",
};

export const AUDIT_ACTION_LABELS: Record<string, string> = {
  created: "Permintaan dibuat",
  auto_approved: "Disetujui otomatis (Sales-visible)",
  approved: "Disetujui",
  rejected: "Ditolak",
  notified: "Dinotifikasi",
  fulfilled: "Dokumen diserahkan",
  uploaded: "Dokumen diunggah",
  forwarded_external: "Diteruskan ke approval eksternal",
  external_approved: "Disetujui (approval eksternal)",
  external_rejected: "Ditolak (approval eksternal)",
};

/**
 * Approval is split in two:
 *   • IN-APP chain (handled here) is always PIC → Head of Legal.
 *   • For a physical-original checkout, after Head of Legal signs off the request
 *     is handed off to a SEPARATE approval app (GM → Direktur → Owner). That part
 *     is open-ended — see lib/external-approval.ts + POST /api/external-approval.
 * A "download/view" copy is fully approved once Head of Legal signs.
 */
export const IN_APP_CHAIN: Role[] = ["LEGAL_PIC", "HEAD_OF_LEGAL"];

/** External (separate-app) chain for physical checkout — labels only, not app roles. */
export const EXTERNAL_CHAIN_LABELS = ["GM", "Direktur", "Owner"] as const;

export function isPhysical(accessType: AccessType): boolean {
  return accessType === "checkout_original";
}

/** The in-app approval roles for a manual request (same for every access type). */
export function inAppChainRoles(_accessType: AccessType): Role[] {
  return IN_APP_CHAIN;
}

/** The two segments of the chain shown to the requester. */
export function chainSummaryParts(accessType: AccessType): { internal: string; external: string | null } {
  return {
    internal: "PIC → Head of Legal",
    external: isPhysical(accessType) ? EXTERNAL_CHAIN_LABELS.join(" → ") : null,
  };
}

/**
 * The ordered roles for a request's chain. An empty array means "no approval
 * needed" (auto-approve). Physical-original checkout always runs the full chain;
 * otherwise Sales-visible is auto, and everything else is PIC + Head of Legal.
 */
export function chainRolesFor(accessLevel: AccessLevel, accessType: AccessType): Role[] {
  if (accessType === "checkout_original") {
    return ["LEGAL_PIC", "HEAD_OF_LEGAL", "GENERAL_MANAGER", "DIRECTOR"];
  }
  if (accessLevel === "SalesVisible") {
    return []; // auto-approve (or PIC auto)
  }
  // LegalOnly / Leadership copy (view/download)
  return ["LEGAL_PIC", "HEAD_OF_LEGAL"];
}

/** Human-readable one-liner for the chain a request will follow. */
export function chainSummary(accessLevel: AccessLevel, accessType: AccessType): string {
  const roles = chainRolesFor(accessLevel, accessType);
  if (roles.length === 0) return "Tanpa persetujuan (Sales-visible)";
  const names: Record<Role, string> = {
    SYSADMIN: "Sysadmin",
    LEGAL_PIC: "PIC",
    HEAD_OF_LEGAL: "Head of Legal",
    GENERAL_MANAGER: "GM",
    DIRECTOR: "Direktur",
    REQUESTER: "Pemohon",
  };
  return roles.map((r) => names[r]).join(" → ");
}
