/**
 * Single notification seam (spec §8). v1 delivers in-app (the alerts inbox);
 * email/push are stubbed here behind one interface so a real backend (portal
 * notifications, SMTP, web-push) wires in at exactly one place later.
 */
export type NotifyChannel = "inapp" | "email" | "push";

export interface Notification {
  to: string; // user id / email
  channel: NotifyChannel;
  subject: string;
  body: string;
}

export async function dispatch(n: Notification): Promise<void> {
  // TODO(portal): replace with real email/push transport.
  if (process.env.NODE_ENV !== "production") {
    console.log(`[notify:${n.channel}] → ${n.to} :: ${n.subject}`);
  }
}
