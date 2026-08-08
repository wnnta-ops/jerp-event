import path from "path";

/**
 * Where fulfillment/request documents are written. Local dev uses a project
 * folder; on serverless (Vercel) the only writable path is the OS temp dir, so
 * set REQUEST_DOCS_DIR=/tmp/request-docs there. Files in /tmp persist only on a
 * warm instance — fine for the demo, swap to Blob/S3 for permanent storage.
 */
export const REQUEST_DOCS_DIR =
  process.env.REQUEST_DOCS_DIR || path.join(process.cwd(), "storage", "request-docs");
