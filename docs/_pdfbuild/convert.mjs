import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join, basename } from 'node:path';
import { fileURLToPath } from 'node:url';
import MarkdownIt from 'markdown-it';
import puppeteer from 'puppeteer';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DOCS = join(__dirname, '..');

// Mermaid dist (bundled locally so rendering works offline).
const mermaidJs = readFileSync(
  join(__dirname, 'node_modules', 'mermaid', 'dist', 'mermaid.min.js'),
  'utf8',
);

const files = [
  '00_README.md',
  '01_BRD.md',
  '02_PRD.md',
  '03_ERD.md',
  '04_BusinessFlow.md',
  '05_NFR_dan_Kebutuhan_Teknis.md',
];

const md = new MarkdownIt({ html: true, linkify: true, typographer: true });

// Render ```mermaid fences as <pre class="mermaid"> so the browser draws them;
// everything else uses the default fenced-code renderer.
const defaultFence = md.renderer.rules.fence.bind(md.renderer.rules);
md.renderer.rules.fence = (tokens, idx, options, env, self) => {
  const token = tokens[idx];
  if ((token.info || '').trim().toLowerCase() === 'mermaid') {
    return `<pre class="mermaid">${md.utils.escapeHtml(token.content)}</pre>`;
  }
  return defaultFence(tokens, idx, options, env, self);
};

const css = `
  @page { size: A4; margin: 18mm 16mm; }
  * { box-sizing: border-box; }
  body { font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
         font-size: 11pt; line-height: 1.5; color: #1f2328; max-width: 100%; }
  h1 { font-size: 20pt; border-bottom: 2px solid #2da44e; padding-bottom: 6px; margin-top: 0; color:#1a7f37; }
  h2 { font-size: 15pt; border-bottom: 1px solid #d0d7de; padding-bottom: 4px; margin-top: 22px; }
  h3 { font-size: 12.5pt; margin-top: 18px; }
  h4 { font-size: 11.5pt; }
  code { background: #f6f8fa; padding: 1px 5px; border-radius: 4px; font-size: 9.5pt;
         font-family: "Cascadia Code", Consolas, monospace; }
  pre { background: #f6f8fa; padding: 12px; border-radius: 6px; overflow-x: auto; font-size: 9pt; }
  pre code { background: none; padding: 0; }
  pre.mermaid { background: #fff; text-align: center; page-break-inside: avoid; }
  table { border-collapse: collapse; width: 100%; margin: 12px 0; font-size: 9.5pt;
          page-break-inside: avoid; }
  th, td { border: 1px solid #d0d7de; padding: 6px 9px; text-align: left; vertical-align: top; }
  th { background: #f6f8fa; }
  tr:nth-child(even) { background: #fbfcfd; }
  blockquote { border-left: 4px solid #d0d7de; margin: 12px 0; padding: 2px 14px; color: #57606a;
               background:#f6f8fa; }
  a { color: #0969da; text-decoration: none; }
  hr { border: none; border-top: 1px solid #d0d7de; margin: 20px 0; }
  h1, h2, h3, h4 { page-break-after: avoid; }
  img, svg { max-width: 100%; }
`;

function htmlFor(title, body) {
  return `<!DOCTYPE html><html lang="id"><head><meta charset="utf-8">
<title>${title}</title><style>${css}</style></head>
<body><article>${body}</article>
<script>${mermaidJs}</script>
<script>
  window.__mermaidDone = false;
  (async () => {
    try {
      mermaid.initialize({ startOnLoad: false, theme: 'neutral', securityLevel: 'loose',
        flowchart: { useMaxWidth: true }, er: { useMaxWidth: true } });
      await mermaid.run({ querySelector: 'pre.mermaid' });
    } catch (e) { console.error('mermaid error', e); }
    window.__mermaidDone = true;
  })();
</script></body></html>`;
}

const browser = await puppeteer.launch({
  headless: 'new',
  args: ['--no-sandbox', '--disable-setuid-sandbox'],
});

const outDir = join(DOCS, 'pdf');
mkdirSync(outDir, { recursive: true });

for (const f of files) {
  const src = readFileSync(join(DOCS, f), 'utf8');
  const title = basename(f, '.md');
  const body = md.render(src);
  const html = htmlFor(title, body);

  const page = await browser.newPage();
  await page.setContent(html, { waitUntil: 'networkidle0' });
  await page.waitForFunction('window.__mermaidDone === true', { timeout: 60000 }).catch(() => {});
  // settle fonts/svg
  await new Promise((r) => setTimeout(r, 400));

  const out = join(outDir, title + '.pdf');
  await page.pdf({
    path: out,
    format: 'A4',
    printBackground: true,
    margin: { top: '18mm', bottom: '18mm', left: '16mm', right: '16mm' },
    displayHeaderFooter: true,
    headerTemplate: '<span></span>',
    footerTemplate:
      '<div style="width:100%;font-size:8px;color:#888;padding:0 16mm;display:flex;justify-content:space-between;">' +
      `<span>${title}</span><span>eProperty — MUP/Java Residence</span>` +
      '<span class="pageNumber"></span>/<span class="totalPages"></span></div>',
  });
  await page.close();
  console.log('PDF: ' + out);
}

await browser.close();
console.log('Done.');
