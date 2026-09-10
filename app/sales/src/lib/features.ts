// Feature flags.
//
// MANUAL_STAGE_INPUT — manual controls for pipeline stages beyond Walk-In
// (advance NUP/UTJ/SPR/ACC/Akad/Realisasi, "+ UTJ" on stock, batal). These are
// HIDDEN because those stages will be fed from an external system (integrasi):
// sales only capture Walk-In here. The logic + API routes stay intact — flip
// this flag on to re-enable the manual controls (e.g. for testing before the
// integration is live).
//
// To re-enable without editing code: set NEXT_PUBLIC_MANUAL_STAGE_INPUT=1 in
// .env and rebuild (`./hub.sh rebuild`).
export const MANUAL_STAGE_INPUT = process.env.NEXT_PUBLIC_MANUAL_STAGE_INPUT === "1";
