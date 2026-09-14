import type { Role, Stage } from "@prisma/client";
import { STAGE_INDEX, SALES_MAX_INDEX } from "./constants";

// Non-linear pipeline: after Follow-Up you may go to NUP OR straight to UTJ.
// NUP is optional, not a mandatory step before UTJ.
export const TRANSITIONS: Record<Stage, Stage[]> = {
  WI: ["FOLLOW_UP"],
  FOLLOW_UP: ["NUP", "UTJ"],
  NUP: ["UTJ"],
  UTJ: ["SPR"],
  SPR: ["ACC"],
  ACC: ["AKAD"],
  AKAD: ["REALISASI"],
  REALISASI: [],
};

/** Stages a given role is allowed to move a deal INTO, from its current stage. */
export function allowedTargets(role: Role, current: Stage): Stage[] {
  const targets = TRANSITIONS[current];
  return targets.filter((t) => {
    const ti = STAGE_INDEX[t];
    if (role === "MANAGEMENT") return true;
    // Sales own moves that land at or before SPR (idx <= 4).
    if (role === "SALES") return ti <= SALES_MAX_INDEX;
    // Collection own moves that land after SPR.
    if (role === "COLLECTION") return ti > SALES_MAX_INDEX;
    return false;
  });
}

export function canTransition(role: Role, current: Stage, target: Stage): boolean {
  return allowedTargets(role, current).includes(target);
}
