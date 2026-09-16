import { requireSession, ok, handleError } from "@/lib/api";
import { getNotifications } from "@/lib/notifications";

export const dynamic = "force-dynamic";

// GET notifikasi (customer perlu di-follow-up) untuk user yang login.
export async function GET() {
  try {
    const session = await requireSession();
    const data = await getNotifications(
      session.user.id,
      session.user.name ?? "",
      session.user.role,
      session.user.salesId ?? null,
    );
    return ok(data);
  } catch (e) {
    return handleError(e);
  }
}
