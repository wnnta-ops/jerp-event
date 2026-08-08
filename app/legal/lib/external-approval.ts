/**
 * SEAM — open-ended integration with the SEPARATE approval app that handles the
 * GM → Direktur → Owner sign-off for physical-original checkout.
 *
 * Flow: this app approves PIC → Head of Legal. When Head of Legal signs off a
 * physical request, the request status becomes "pending_external" and this
 * function is fired to forward it onward. The external app then calls back
 * `POST /api/external-approval` with the Owner's final decision to finalize.
 *
 * To wire the real integration, set env:
 *   EXTERNAL_APPROVAL_WEBHOOK_URL  — the external app's intake endpoint
 *   EXTERNAL_APPROVAL_SECRET       — shared secret (sent as x-approval-secret,
 *                                    and required on the inbound callback)
 * Until set, the request simply rests in "pending_external" (visible as awaiting
 * the external GM → Direktur → Owner sign-off) — nothing is lost.
 */

export type ExternalHandoff = {
  id: string;
  code: string;
  requestedItem: string;
  accessType: string;
  purpose: string;
  neededBy: Date;
  requesterName?: string | null;
};

export async function forwardToExternalApproval(req: ExternalHandoff): Promise<void> {
  const url = process.env.EXTERNAL_APPROVAL_WEBHOOK_URL;
  if (!url) {
    console.info(
      `[external-approval] ${req.code} menunggu approval eksternal (GM → Direktur → Owner). ` +
        `Set EXTERNAL_APPROVAL_WEBHOOK_URL untuk menyambungkan ke aplikasi approval.`,
    );
    return;
  }
  try {
    await fetch(url, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        ...(process.env.EXTERNAL_APPROVAL_SECRET ? { "x-approval-secret": process.env.EXTERNAL_APPROVAL_SECRET } : {}),
      },
      body: JSON.stringify({
        requestId: req.id,
        code: req.code,
        item: req.requestedItem,
        accessType: req.accessType,
        purpose: req.purpose,
        neededBy: req.neededBy,
        requester: req.requesterName ?? null,
        chain: ["GM", "Direktur", "Owner"],
        callback: "POST /api/external-approval { code | requestId, decision: 'approved'|'rejected', note?, by? }",
      }),
    });
  } catch (e) {
    // Best-effort: the request still rests in "pending_external" and can be
    // re-forwarded / finalized via the callback.
    console.error("[external-approval] gagal forward:", e);
  }
}
