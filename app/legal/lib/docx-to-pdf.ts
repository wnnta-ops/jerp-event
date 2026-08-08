import { execFile } from "child_process";
import { promisify } from "util";
import { mkdtemp, writeFile, readFile, rm } from "fs/promises";
import { existsSync } from "fs";
import { tmpdir } from "os";
import path from "path";

const execFileP = promisify(execFile);

// Where to find the LibreOffice "soffice" binary. Set SOFFICE_PATH to override.
const CANDIDATES = [
  process.env.SOFFICE_PATH,
  "/Applications/LibreOffice.app/Contents/MacOS/soffice", // macOS (Homebrew cask)
  "/usr/bin/soffice", // Linux
  "/usr/local/bin/soffice",
  "/opt/homebrew/bin/soffice",
].filter(Boolean) as string[];

function resolveSoffice(): string | null {
  for (const c of CANDIDATES) {
    try {
      if (existsSync(c)) return c;
    } catch {
      /* ignore */
    }
  }
  return null;
}

export function hasLibreOffice(): boolean {
  return resolveSoffice() !== null;
}

/**
 * Convert a .docx buffer to a pixel-faithful PDF using headless LibreOffice.
 * Returns null if LibreOffice isn't installed or the conversion fails, so the
 * caller can fall back to a simpler renderer.
 */
export async function docxToPdf(docx: Buffer): Promise<Buffer | null> {
  const bin = resolveSoffice();
  if (!bin) return null;

  let dir: string | null = null;
  try {
    dir = await mkdtemp(path.join(tmpdir(), "lo-"));
    const inPath = path.join(dir, "doc.docx");
    await writeFile(inPath, docx);
    // Per-call user profile avoids lock clashes between concurrent conversions.
    const profile = `file://${path.join(dir, "profile")}`;
    await execFileP(
      bin,
      ["--headless", `-env:UserInstallation=${profile}`, "--convert-to", "pdf", "--outdir", dir, inPath],
      { timeout: 60_000 },
    );
    return await readFile(path.join(dir, "doc.pdf"));
  } catch (e) {
    console.error("[docx-to-pdf] LibreOffice gagal:", (e as Error).message);
    return null;
  } finally {
    if (dir) await rm(dir, { recursive: true, force: true }).catch(() => {});
  }
}
