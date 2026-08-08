export const dynamic = "force-dynamic";

// Public, view-only Google Sheet. htmlview embeds in an iframe (no X-Frame-Options)
// and preserves the sheet's exact layout (merged headers, formatting) — better than
// a flattened CSV for this multi-header sheet. Override via env if it ever changes.
const SHEET_ID = process.env.GOOGLE_SHEET_ID || "1wVrsZ3JG4Jcy8BGtZoRzFvPY2xwAoLjUgc2nXlFKsTY";
const SHEET_TITLE = process.env.GOOGLE_SHEET_TITLE || "Lembar Data";
const EMBED_URL = `https://docs.google.com/spreadsheets/d/${SHEET_ID}/htmlview?widget=true&headers=false`;
const OPEN_URL = `https://docs.google.com/spreadsheets/d/${SHEET_ID}/htmlview`;

export default function SheetPage() {
  return (
    <div className="mx-auto w-full max-w-[1320px]">
      <div className="mb-1 flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-[18px] font-medium">{SHEET_TITLE}</h1>
        <a href={OPEN_URL} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5 text-[12px]" style={{ color: "var(--color-text-info)" }}>
          <i className="ti ti-external-link" /> Buka di Google Sheets
        </a>
      </div>
      <p className="mb-4 text-xs" style={{ color: "var(--color-text-secondary)" }}>
        Tampilan langsung & baca-saja dari Google Sheet — selalu mengikuti data terbaru.
      </p>
      <div className="overflow-hidden rounded-lg" style={{ border: "0.5px solid var(--color-border-tertiary)", background: "var(--color-background-primary)" }}>
        <iframe
          src={EMBED_URL}
          title={SHEET_TITLE}
          style={{ width: "100%", height: "78vh", border: 0, display: "block" }}
        />
      </div>
    </div>
  );
}
