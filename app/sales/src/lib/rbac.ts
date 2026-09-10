import type { Role, Stage } from "@prisma/client";
import { SALES_MAX_INDEX, STAGE_INDEX } from "./constants";

// Central permission matrix — mirrors §6 of the brief.
export const can = {
  // Input WI / Follow-up / NUP / UTJ / SPR
  inputSalesStages: (role: Role) => role === "SALES" || role === "MANAGEMENT",
  // Advance ACC / Akad / Realisasi
  advanceCollection: (role: Role) => role === "COLLECTION" || role === "MANAGEMENT",
  // Batal + refund (Collection executes; Sales can only request)
  batal: (role: Role) => role === "COLLECTION" || role === "MANAGEMENT",
  // Stock: input UTJ on Open Stock, manual status override
  editStock: (role: Role) => role === "SALES" || role === "MANAGEMENT",
  // Rotasi koordinator <-> bendera (Sales edit, Collection view-only)
  editRotation: (role: Role) => role === "SALES" || role === "MANAGEMENT",
  // Targets, reference/master data, users
  manageAdmin: (role: Role) => role === "MANAGEMENT",
  // Everyone can view reports/dashboards
  viewReports: (_role: Role) => true,
};

/**
 * Whether `role` may advance a deal that is currently at `stage`.
 * Sales own 0..4 (advancing up to SPR). Collection owns 5..7.
 */
export function canAdvanceStage(role: Role, stage: Stage): boolean {
  const idx = STAGE_INDEX[stage];
  if (role === "MANAGEMENT") return idx < 7;
  if (role === "SALES") return idx < SALES_MAX_INDEX; // can move up to reach SPR (idx 4)
  if (role === "COLLECTION") return idx >= SALES_MAX_INDEX && idx < 7;
  return false;
}
