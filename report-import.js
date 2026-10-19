/**
 * CGM Board Portal — Monthly Report Importer
 *
 * Staff drop the month-end files (QuickBooks "Statement of Receipts &
 * Disbursements" and "Statement of Financial Position" PDFs, the savings
 * "Footnote 1", and the board minutes). Each file is read in the browser,
 * the numbers are extracted and shown for review, and one click publishes
 * everything to the ledger, the dashboard and the Document Library.
 *
 * Pure parsing functions (RI.parse*) have no DOM dependency so they can be
 * tested on their own. The wizard UI at the bottom relies on board.html globals:
 * api, esc, fmt, openModal, closeModal, Cache, invalidate, MONTH_NAMES.
 */
const RI = (() => {
  const CDN = {
    pdf:    'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js',
    worker: 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js',
    jszip:  'https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js',
  };
  const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  const CATEGORIES = ['Donations','Grants','Fundraiser','Sponsorship','Supplies','Utilities','Program Costs','Admin','Other'];
  const TYPE_LABELS = {
    rd:       'Receipts & Disbursements',
    sfp:      'Financial Position',
    footnote: 'Footnote 1 (savings funds)',
    minutes:  'Meeting minutes',
    other:    'Other document',
  };

  // ── Script loading ─────────────────────────────────────────────────────────
  const loaded = {};
  function loadScript(src) {
    return loaded[src] ??= new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = src; s.onload = resolve;
      s.onerror = () => { delete loaded[src]; reject(new Error('Could not load ' + src)); };
      document.head.appendChild(s);
    });
  }
  async function ensurePdfJs() {
    await loadScript(CDN.pdf);
    const lib = window.pdfjsLib;
    if (!lib.GlobalWorkerOptions.workerSrc) {
      try {
        // Cross-origin workers are blocked, so wrap the CDN worker in a same-origin blob.
        const code = await (await fetch(CDN.worker)).text();
        lib.GlobalWorkerOptions.workerSrc = URL.createObjectURL(new Blob([code], { type: 'text/javascript' }));
      } catch { lib.GlobalWorkerOptions.workerSrc = CDN.worker; }
    }
    return lib;
  }
  async function ensureJSZip() { await loadScript(CDN.jszip); return window.JSZip; }

  // ── Text extraction ────────────────────────────────────────────────────────
  /** PDF → array of text lines (items sharing a baseline are joined left to right). */
  async function pdfLines(buf) {
    const lib = await ensurePdfJs();
    const pdf = await lib.getDocument({ data: new Uint8Array(buf) }).promise;
    const out = [];
    for (let p = 1; p <= pdf.numPages; p++) {
      const items = (await (await pdf.getPage(p)).getTextContent()).items
        .filter(i => i.str && i.str.trim())
        .map(i => ({ s: i.str.trim(), x: i.transform[4], y: i.transform[5] }))
        .sort((a, b) => b.y - a.y || a.x - b.x);
      let line = [], lineY = null;
      const flush = () => { if (line.length) out.push(line.sort((a, b) => a.x - b.x).map(i => i.s).join(' ').replace(/\s+/g, ' ')); line = []; };
      for (const it of items) {
        if (lineY !== null && Math.abs(it.y - lineY) > 3) flush();
        if (!line.length) lineY = it.y;
        line.push(it);
      }
      flush();
    }
    return out;
  }

  /** OXPS (XPS) → lines. Glyph runs are in <Glyphs UnicodeString="…">. */
  async function oxpsLines(buf) {
    const zip = await (await ensureJSZip()).loadAsync(buf);
    const out = [];
    for (const name of Object.keys(zip.files).filter(n => /\.fpage$/i.test(n)).sort()) {
      const xml = await zip.files[name].async('string');
      const runs = [...xml.matchAll(/<Glyphs\b[^>]*?OriginY="([\d.\-]+)"[^>]*?UnicodeString="([^"]*)"|<Glyphs\b[^>]*?UnicodeString="([^"]*)"[^>]*?OriginY="([\d.\-]+)"/g)]
        .map(m => ({ y: parseFloat(m[1] ?? m[4]), s: decodeXml(m[2] ?? m[3]) }));
      let cur = null;
      for (const r of runs) {
        if (cur && Math.abs(cur.y - r.y) <= 3) cur.s += ' ' + r.s;
        else { if (cur) out.push(cur.s.replace(/\s+/g, ' ').trim()); cur = { y: r.y, s: r.s }; }
      }
      if (cur) out.push(cur.s.replace(/\s+/g, ' ').trim());
    }
    return out.filter(Boolean);
  }

  /** DOCX → paragraphs. */
  async function docxParagraphs(buf) {
    const zip = await (await ensureJSZip()).loadAsync(buf);
    const xml = await zip.files['word/document.xml'].async('string');
    return [...xml.matchAll(/<w:p[ >][\s\S]*?<\/w:p>/g)]
      .map(m => decodeXml([...m[0].matchAll(/<w:t[^>]*>([^<]*)<\/w:t>/g)].map(t => t[1]).join('')).trim())
      .filter(Boolean);
  }

  function decodeXml(s) {
    return String(s).replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&apos;/g, "'")
      .replace(/&#(\d+);/g, (_, n) => String.fromCharCode(+n)).replace(/&amp;/g, '&');
  }

  // ── Helpers ────────────────────────────────────────────────────────────────
  const AMT_END = /^(.*?)\s+(-?\(?\$?[\d,]+\.\d{2}\)?)$/;
  function toNum(s) {
    const neg = /^-|^\(/.test(s);
    const n = parseFloat(String(s).replace(/[^0-9.]/g, ''));
    return round2(neg ? -n : n);
  }
  function round2(n) { return Math.round(n * 100) / 100; }
  const MONTH_RE = new RegExp('(' + MONTHS.join('|') + ')\\s+(?:(\\d{1,2}),\\s*)?(\\d{4})', 'i');

  /** Find "September 2026" / "As of September 30, 2026" → { month:'2026-09', end:'2026-09-30' } */
  function findPeriod(lines) {
    for (const l of lines) {
      const m = l.match(MONTH_RE);
      if (!m) continue;
      const mi = MONTHS.findIndex(x => x.toLowerCase() === m[1].toLowerCase());
      const year = +m[3];
      const day = m[2] ? +m[2] : new Date(year, mi + 1, 0).getDate();
      const mm = String(mi + 1).padStart(2, '0');
      return { month: `${year}-${mm}`, end: `${year}-${mm}-${String(day).padStart(2, '0')}` };
    }
    return null;
  }

  // ── Document type detection ────────────────────────────────────────────────
  function detectType(fileName, lines) {
    const head = lines.slice(0, 40).join(' ').toLowerCase();
    const name = fileName.toLowerCase();
    if (/receipts\s*&?\s*(and\s*)?disbursements/.test(head) || /receipts.*disbursements/.test(name)) return 'rd';
    if (/statement of financial position/.test(head) || /financial position/.test(name)) return /footnote/.test(name) ? 'footnote' : 'sfp';
    if (/footnote/.test(name) || /footnote\s*1/.test(head)) return 'footnote';
    if (/minutes/.test(name) || /^common ground minutes/i.test(lines[0] || '')) return 'minutes';
    return 'other';
  }

  // ── Category guess ─────────────────────────────────────────────────────────
  function guessCategory(desc, type) {
    const d = desc.toLowerCase();
    if (/grant/.test(d)) return 'Grants';
    if (/fundrais|popcorn|raffle/.test(d)) return 'Fundraiser';
    if (/sponsor/.test(d)) return 'Sponsorship';
    if (/donation|church|offering|gift/.test(d)) return 'Donations';
    if (/suppl/.test(d)) return 'Supplies';
    if (/utilit|electric|water|internet|sanitation|gas bill/.test(d)) return 'Utilities';
    if (/payroll|insurance|workmen|salar|accounting|legal|bank fee|admin|office/.test(d)) return 'Admin';
    if (/auto|fuel|van|program|enroll|tuition|reading|math|field trip|food|snack|repair|maintenance|building/.test(d)) return 'Program Costs';
    return 'Other';
  }

  // ── Receipts & Disbursements parser ────────────────────────────────────────
  function stripGroupPrefix(leaf, group) {
    const g = group.replace(/\s*expenses?$/i, '');
    for (const prefix of [group, g]) {
      const re = new RegExp('^' + prefix.replace(/[.*+?^${}()|[\]\\\/]/g, '\\$&') + '(?:\\s+expenses?)?\\s*[-–—:]*\\s*', 'i');
      if (re.test(leaf)) { const rest = leaf.replace(re, '').trim(); return rest || 'Other'; }
    }
    return leaf;
  }

  function parseReceipts(lines) {
    const period = findPeriod(lines);
    const entries = [];
    const reported = {};
    let section = null, group = null;

    for (const raw of lines) {
      const line = raw.trim();
      const m = line.match(AMT_END);
      if (!m) {
        // Header line with no amount
        if (/^income$/i.test(line))  { section = 'income';  group = null; }
        else if (/^expense$/i.test(line)) { section = 'expense'; group = null; }
        else if (section && !/^(ordinary income|page \d|cash basis|statement of|common ground)/i.test(line) && !/^\d/.test(line)) group = line;
        continue;
      }
      const label = m[1].trim(), amount = toNum(m[2]);
      if (/^total income$/i.test(label))        { reported.income = amount;  section = null; continue; }
      if (/^total expense$/i.test(label))       { reported.expense = amount; section = null; continue; }
      if (/^net (ordinary )?income$/i.test(label)) { reported.net = amount; continue; }
      if (/^gross profit$/i.test(label))        continue;
      if (/^total\b/i.test(label))              { group = null; continue; }
      if (!section) continue;

      const desc = group && group.toLowerCase() !== label.toLowerCase()
        ? `${group.replace(/\s*expenses?$/i, '').replace(/^payroll$/i, 'Payroll')} — ${stripGroupPrefix(label, group)}`
        : label;
      if (amount === 0) continue;     // QuickBooks prints $0 lines for unused accounts
      entries.push({
        desc: desc.replace(/\s+/g, ' ').trim(),
        type: section,
        amount,
        cat: guessCategory(desc, section),
        include: true,
      });
    }

    const sum = t => round2(entries.filter(e => e.type === t).reduce((s, e) => s + e.amount, 0));
    return { period, entries, reported, calc: { income: sum('income'), expense: sum('expense') } };
  }

  // ── Statement of Financial Position parser ─────────────────────────────────
  function parsePosition(lines) {
    const period = findPeriod(lines);
    const items = [];
    const totals = {};
    let sec = null, sub = null;

    for (const raw of lines) {
      const line = raw.trim();
      const m = line.match(AMT_END);
      if (!m) {
        if (/^assets$/i.test(line))                { sec = 'assets';      sub = null; }
        else if (/^liabilities\s*&\s*equity$/i.test(line)) { sec = 'liabilities'; sub = null; }
        else if (/^liabilities$/i.test(line))      { sec = 'liabilities'; sub = null; }
        else if (/^equity$/i.test(line))           { sec = 'equity';      sub = null; }
        else if (sec && !/^(\d|page|cash basis|as of|statement of|common ground)/i.test(line)) sub = line;
        continue;
      }
      const label = m[1].trim(), amount = toNum(m[2]);
      const key = label.toLowerCase();
      if (key === 'total assets')                    totals.assets = amount;
      else if (key === 'total liabilities')          totals.liabilities = amount;
      else if (key === 'total equity')               totals.equity = amount;
      else if (key === 'total liabilities & equity') totals.liabilitiesEquity = amount;
      else if (key === 'total checking/savings')     totals.cash = amount;
      else if (key === 'total current assets')       totals.currentAssets = amount;
      else if (key === 'total fixed assets')         totals.fixedAssets = amount;
      else if (key === 'total other assets')         totals.otherAssets = amount;
      else if (/^total\b/.test(key))                 { /* sub-totals we don't need */ }
      else if (sec) {
        if (key === 'net income') totals.netIncome = amount;
        items.push({ sec, sub: sub || '', label, amount });
      }
    }
    if (totals.assets != null && totals.cash == null) {
      totals.cash = round2(items.filter(i => /checking|savings|cash/i.test(i.sub + ' ' + i.label) && i.sec === 'assets')
        .reduce((s, i) => s + i.amount, 0));
    }
    const savings = items.find(i => /^savings$/i.test(i.label));
    return { period, items, totals, savings: savings ? savings.amount : null };
  }

  // ── Footnote 1 (restricted / designated savings) ──────────────────────────
  function parseFootnote(lines) {
    const text = lines.join(' ').replace(/\s+/g, ' ');
    const period = findPeriod(lines);
    const funds = [];
    for (const m of text.matchAll(/(?:^|\s)\d{1,2}\.\s+([A-Za-z][^$]*?)\s+\$\s*([\d,]+\.\d{2})/g)) {
      funds.push({ name: m[1].trim(), amount: toNum(m[2]) });
    }
    const grab = re => { const m = text.match(re); return m ? toNum(m[1]) : null; };
    return {
      period,
      funds,
      totalSavings:      grab(/In the \$\s*([\d,]+\.\d{2}) total/i),
      totalDesignated:   grab(/Total designated[^$]*\$\s*([\d,]+\.\d{2})/i),
      totalUndesignated: grab(/Total undesignated[^$]*\$\s*([\d,]+\.\d{2})/i),
    };
  }

  // ── Minutes ────────────────────────────────────────────────────────────────
  function parseMinutes(paras) {
    const period = findPeriod(paras.slice(0, 5));
    return { period, title: paras[0] || 'Meeting Minutes', preview: paras.slice(1, 4).join(' ').slice(0, 240) };
  }

  /** Read one File → { kind, parsed, period, ... } (never throws; errors land in .error). */
  async function analyze(file) {
    const ext = (file.name.split('.').pop() || '').toLowerCase();
    const res = { file, name: file.name, ext, kind: 'other', parsed: null, error: null };
    try {
      const buf = await file.arrayBuffer();
      let lines = null;
      if (ext === 'pdf')       lines = await pdfLines(buf);
      else if (ext === 'oxps') lines = await oxpsLines(buf);
      else if (ext === 'docx') lines = await docxParagraphs(buf);
      else res.error = 'Preview not available for this file type — it will be filed in the Document Library as-is.';

      if (lines) {
        res.lines = lines;
        res.kind = detectType(file.name, lines);
        if (res.kind === 'rd')            res.parsed = parseReceipts(lines);
        else if (res.kind === 'sfp')      res.parsed = parsePosition(lines);
        else if (res.kind === 'footnote') res.parsed = parseFootnote(lines);
        else if (res.kind === 'minutes')  res.parsed = parseMinutes(lines);
      } else if (/footnote/i.test(file.name)) {
        res.kind = 'footnote';
        res.error = 'This older Word format can’t be read automatically — type the fund amounts in below.';
        res.parsed = { period: null, funds: [], totalSavings: null, totalDesignated: null, totalUndesignated: null };
      } else if (/minutes/i.test(file.name)) {
        res.kind = 'minutes';
      }
    } catch (e) { res.error = 'Could not read this file: ' + e.message; }
    return res;
  }

  return { analyze, parseReceipts, parsePosition, parseFootnote, parseMinutes, detectType, guessCategory,
           pdfLines, oxpsLines, docxParagraphs, MONTHS, CATEGORIES, TYPE_LABELS, round2 };
})();

if (typeof module !== 'undefined') module.exports = RI;

// ════════════════════════════════════════════════════════════════════════════
//  WIZARD UI (browser only)
// ════════════════════════════════════════════════════════════════════════════
(function () {
  if (typeof document === 'undefined') return;

  const S = { files: [], month: '', replace: true, busy: false };

  const $ = id => document.getElementById(id);
  const money = n => (n == null || isNaN(n)) ? '—' : fmt(n);
  const rdFile  = () => S.files.find(f => f.kind === 'rd' && f.parsed);
  const sfpFile = () => S.files.find(f => f.kind === 'sfp' && f.parsed);
  const fnFile  = () => S.files.find(f => f.kind === 'footnote' && f.parsed);

  function ensureModal() {
    if ($('import-modal')) return;
    const wrap = document.createElement('div');
    wrap.className = 'modal-bg';
    wrap.id = 'import-modal';
    wrap.innerHTML = `
      <div class="modal" style="max-width:880px;">
        <div class="modal-header">
          <div>
            <div class="modal-title">Upload Monthly Reports</div>
            <div style="font-size:12px;color:var(--mid);margin-top:2px;">Drop the month-end files — the numbers fill in automatically. You review before anything is saved.</div>
          </div>
          <button class="modal-close" onclick="ReportImport.close()">✕</button>
        </div>
        <div class="modal-body">
          <div id="ri-drop" class="ri-drop" tabindex="0">
            <div style="font-size:34px;line-height:1">📥</div>
            <div style="font-weight:800;color:var(--navy);margin-top:6px;">Drag &amp; drop files here, or click to browse</div>
            <div style="font-size:12px;color:var(--mid);margin-top:4px;">Receipts &amp; Disbursements · Statement of Financial Position · Footnote 1 · Board minutes (PDF, XPS, Word)</div>
            <input type="file" id="ri-input" multiple accept=".pdf,.oxps,.docx,.doc,.png,.jpg,.jpeg" style="display:none">
          </div>
          <div id="ri-files"></div>
          <div id="ri-review"></div>
          <div class="form-actions" id="ri-actions" style="display:none;">
            <button class="btn btn-ghost" onclick="ReportImport.close()">Cancel</button>
            <button class="btn btn-teal" id="ri-publish" onclick="ReportImport.publish()">Publish to Dashboard</button>
          </div>
        </div>
      </div>`;
    document.body.appendChild(wrap);

    const style = document.createElement('style');
    style.textContent = `
      .ri-drop{border:2px dashed var(--border);border-radius:16px;padding:26px 16px;text-align:center;cursor:pointer;transition:all .2s;background:#fafaf8;}
      .ri-drop:hover,.ri-drop.over{border-color:var(--teal);background:rgba(27,196,196,.06);}
      .ri-file{display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid var(--border);border-radius:12px;margin-top:8px;flex-wrap:wrap;}
      .ri-file .nm{flex:1;min-width:160px;font-size:13px;font-weight:700;word-break:break-word;}
      .ri-file select{padding:6px 8px;border:1.5px solid var(--border);border-radius:8px;font-size:12px;}
      .ri-note{font-size:12px;color:var(--mid);width:100%;}
      .ri-warn{font-size:12px;color:#b45300;width:100%;}
      .ri-sec{margin-top:22px;}
      .ri-sec h3{font-family:var(--F);font-weight:900;font-size:15px;color:var(--navy);margin-bottom:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
      .ri-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;}
      .ri-kpi{background:var(--bg);border-radius:12px;padding:12px;text-align:center;}
      .ri-kpi b{display:block;font-family:var(--F);font-size:18px;color:var(--navy);}
      .ri-kpi span{font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--mid);}
      .ri-tbl{width:100%;border-collapse:collapse;font-size:13px;}
      .ri-tbl th{text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--mid);padding:6px 4px;}
      .ri-tbl td{padding:4px;border-top:1px solid var(--border);}
      .ri-tbl input,.ri-tbl select{width:100%;padding:6px 8px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit;}
      .ri-tbl input.amt{text-align:right;width:110px;}
      .ri-check{padding:10px 14px;border-radius:12px;font-size:13px;margin:10px 0;}
      .ri-check.ok{background:rgba(68,187,51,.1);color:#2c7a1f;}
      .ri-check.bad{background:rgba(255,102,51,.12);color:#b4400f;}
      @media(max-width:640px){.ri-tbl .cat-col{display:none}}
      @media(max-width:900px){.db-reports-row{grid-template-columns:1fr !important}}`;
    document.head.appendChild(style);

    const drop = $('ri-drop'), input = $('ri-input');
    drop.onclick = () => input.click();
    drop.ondragover = e => { e.preventDefault(); drop.classList.add('over'); };
    drop.ondragleave = () => drop.classList.remove('over');
    drop.ondrop = e => { e.preventDefault(); drop.classList.remove('over'); addFiles(e.dataTransfer.files); };
    input.onchange = () => { addFiles(input.files); input.value = ''; };
  }

  async function addFiles(fileList) {
    const list = [...fileList];
    if (!list.length) return;
    const holder = $('ri-files');
    holder.insertAdjacentHTML('beforeend', `<div class="ri-note" id="ri-reading" style="margin-top:10px;">Reading ${list.length} file${list.length > 1 ? 's' : ''}…</div>`);
    for (const f of list) {
      if (S.files.some(x => x.name === f.name && x.file.size === f.size)) continue;   // same file dropped twice
      S.files.push(await RI.analyze(f));
    }
    $('ri-reading')?.remove();
    autoPickMonth();
    render();
  }

  function autoPickMonth() {
    if (S.month) return;
    for (const f of [rdFile(), sfpFile(), fnFile(), ...S.files]) {
      const p = f && f.parsed && f.parsed.period;
      if (p) { S.month = p.month; return; }
    }
  }

  function monthEnd(month) {
    const [y, m] = month.split('-').map(Number);
    return `${month}-${String(new Date(y, m, 0).getDate()).padStart(2, '0')}`;
  }
  function monthTitle(month) {
    const [y, m] = month.split('-').map(Number);
    return `${RI.MONTHS[m - 1]} ${y}`;
  }

  // ── Rendering ──────────────────────────────────────────────────────────────
  function render() {
    renderFiles();
    renderReview();
    $('ri-actions').style.display = S.files.length ? 'flex' : 'none';
  }

  function renderFiles() {
    $('ri-files').innerHTML = S.files.map((f, i) => `
      <div class="ri-file">
        <span style="font-size:20px">${f.ext === 'docx' || f.ext === 'doc' ? '📝' : '📄'}</span>
        <span class="nm">${esc(f.name)}</span>
        <select onchange="ReportImport.setKind(${i}, this.value)">
          ${Object.entries(RI.TYPE_LABELS).map(([k, v]) => `<option value="${k}" ${f.kind === k ? 'selected' : ''}>${v}</option>`).join('')}
        </select>
        <button class="btn btn-danger btn-sm" onclick="ReportImport.removeFile(${i})">Remove</button>
        ${f.error ? `<div class="ri-warn">${esc(f.error)}</div>` : ''}
      </div>`).join('');
  }

  function renderReview() {
    const el = $('ri-review');
    if (!S.files.length) { el.innerHTML = ''; return; }
    const rd = rdFile(), sfp = sfpFile(), fn = fnFile();
    const existing = (Cache.finance || []).filter(e => e.date && e.date.startsWith(S.month)).length;
    let html = `
      <div class="ri-sec">
        <h3>Reporting month
          <input type="month" value="${esc(S.month)}" onchange="ReportImport.setMonth(this.value)"
                 style="padding:6px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;">
        </h3>
        ${!S.month ? '<div class="ri-check bad">Pick the reporting month so the entries are dated correctly.</div>' : ''}
      </div>`;

    if (rd) {
      const e = rd.parsed.entries;
      const inc = RI.round2(e.filter(x => x.include && x.type === 'income').reduce((s, x) => s + x.amount, 0));
      const exp = RI.round2(e.filter(x => x.include && x.type === 'expense').reduce((s, x) => s + x.amount, 0));
      const rep = rd.parsed.reported;
      const incOK = rep.income == null || Math.abs(rep.income - inc) < 0.005;
      const expOK = rep.expense == null || Math.abs(rep.expense - exp) < 0.005;
      html += `
        <div class="ri-sec">
          <h3>Income &amp; expenses <span class="badge badge-teal">${e.filter(x => x.include).length} entries</span></h3>
          <div class="ri-grid">
            <div class="ri-kpi"><b style="color:var(--green)">${money(inc)}</b><span>Total income</span></div>
            <div class="ri-kpi"><b style="color:var(--orange)">${money(exp)}</b><span>Total expenses</span></div>
            <div class="ri-kpi"><b>${money(RI.round2(inc - exp))}</b><span>Net income</span></div>
          </div>
          ${(incOK && expOK)
            ? `<div class="ri-check ok">✓ Totals match the report${rep.income != null ? ` (income ${money(rep.income)}, expenses ${money(rep.expense)})` : ''}.</div>`
            : `<div class="ri-check bad">⚠ Totals don’t match the report (report says income ${money(rep.income)}, expenses ${money(rep.expense)}). Check the rows below before publishing.</div>`}
          ${existing ? `
            <label style="display:flex;gap:8px;align-items:center;font-size:13px;margin-bottom:8px;">
              <input type="checkbox" ${S.replace ? 'checked' : ''} onchange="ReportImport.setReplace(this.checked)">
              ${monthTitle(S.month)} already has ${existing} ledger ${existing === 1 ? 'entry' : 'entries'} — replace them with this report (recommended, avoids double counting)
            </label>` : ''}
          <div style="overflow-x:auto"><table class="ri-tbl">
            <thead><tr><th></th><th>Description</th><th class="cat-col">Category</th><th>Type</th><th style="text-align:right">Amount</th></tr></thead>
            <tbody>${e.map((x, i) => `
              <tr style="${x.include ? '' : 'opacity:.4'}">
                <td><input type="checkbox" ${x.include ? 'checked' : ''} onchange="ReportImport.setRow(${i},'include',this.checked)"></td>
                <td><input value="${esc(x.desc)}" onchange="ReportImport.setRow(${i},'desc',this.value)"></td>
                <td class="cat-col"><select onchange="ReportImport.setRow(${i},'cat',this.value)">
                  ${RI.CATEGORIES.map(c => `<option ${c === x.cat ? 'selected' : ''}>${c}</option>`).join('')}</select></td>
                <td><select onchange="ReportImport.setRow(${i},'type',this.value)">
                  <option value="income" ${x.type === 'income' ? 'selected' : ''}>Income</option>
                  <option value="expense" ${x.type === 'expense' ? 'selected' : ''}>Expense</option></select></td>
                <td style="text-align:right"><input class="amt" type="number" step="0.01" value="${x.amount}" onchange="ReportImport.setRow(${i},'amount',this.value)"></td>
              </tr>`).join('')}
            </tbody>
          </table></div>
        </div>`;
    }

    if (sfp || fn) {
      const t = sfp ? sfp.parsed.totals : {};
      const fp = fn ? fn.parsed : null;
      const savings = fp && fp.totalSavings != null ? fp.totalSavings : (sfp ? sfp.parsed.savings : null);
      const designated = fp ? (fp.totalDesignated ?? RI.round2(fp.funds.reduce((s, f) => s + f.amount, 0))) : null;
      html += `
        <div class="ri-sec">
          <h3>Financial position${S.month ? ' — as of ' + monthTitle(S.month) : ''}</h3>
          ${sfp ? `<div class="ri-grid">
            <div class="ri-kpi"><b>${money(t.cash)}</b><span>Cash in bank</span></div>
            <div class="ri-kpi"><b>${money(t.assets)}</b><span>Total assets</span></div>
            <div class="ri-kpi"><b>${money(t.liabilities)}</b><span>Liabilities</span></div>
            <div class="ri-kpi"><b>${money(t.equity)}</b><span>Total equity</span></div>
          </div>
          ${t.assets != null && t.liabilitiesEquity != null && Math.abs(t.assets - t.liabilitiesEquity) > 0.005
            ? '<div class="ri-check bad">⚠ Assets don’t equal liabilities + equity in this report.</div>'
            : '<div class="ri-check ok">✓ Balance sheet balances.</div>'}` : ''}
          ${fp ? `
            <div style="margin-top:12px;font-weight:800;font-size:13px;color:var(--navy);">Restricted savings (Footnote 1)</div>
            <table class="ri-tbl"><tbody>
              ${fp.funds.map((f, i) => `<tr>
                <td><input value="${esc(f.name)}" onchange="ReportImport.setFund(${i},'name',this.value)"></td>
                <td style="text-align:right"><input class="amt" type="number" step="0.01" value="${f.amount}" onchange="ReportImport.setFund(${i},'amount',this.value)"></td>
                <td style="width:40px"><button class="btn btn-danger btn-sm" onclick="ReportImport.removeFund(${i})">✕</button></td></tr>`).join('')}
            </tbody></table>
            <button class="btn btn-ghost btn-sm" style="margin-top:6px" onclick="ReportImport.addFund()">+ Add fund</button>
            <div class="ri-grid" style="margin-top:12px;">
              <div class="ri-kpi"><b>${money(savings)}</b><span>Total savings</span></div>
              <div class="ri-kpi"><b>${money(designated)}</b><span>Designated (restricted)</span></div>
              <div class="ri-kpi"><b>${money(savings != null && designated != null ? RI.round2(savings - designated) : fp.totalUndesignated)}</b><span>Undesignated (available)</span></div>
            </div>` : ''}
        </div>`;
    }

    const filed = S.files.filter(f => f.kind !== 'other' || true);
    html += `
      <div class="ri-sec">
        <h3>Filed in Document Library</h3>
        <div style="font-size:13px;color:var(--dark);line-height:1.7;">
          ${filed.map(f => `📎 <b>${esc(docName(f))}</b> <span style="color:var(--mid)">· ${esc(docCat(f))}</span>`).join('<br>')}
        </div>
      </div>`;
    el.innerHTML = html;
  }

  // ── Document naming ────────────────────────────────────────────────────────
  function docName(f) {
    const mt = S.month ? monthTitle(S.month) : '';
    switch (f.kind) {
      case 'rd':       return `${mt} Receipts & Disbursements`.trim();
      case 'sfp':      return `Statement of Financial Position - ${mt}`.trim();
      case 'footnote': return `Footnote 1 to Statement of Financial Position - ${mt}`.trim();
      case 'minutes': {
        const p = f.parsed && f.parsed.period;
        return `Board Meeting Minutes${p ? ' - ' + monthTitle(p.month) : mt ? ' - ' + mt : ''}`;
      }
      default: return f.name.replace(/\.[^.]+$/, '');
    }
  }
  function docCat(f) {
    return f.kind === 'minutes' ? 'Meeting Minutes' : f.kind === 'other' ? 'Other' : 'Financial Report';
  }
  function docDate(f) {
    if (f.kind === 'minutes' && f.parsed && f.parsed.period) {
      const m = f.lines && f.lines.slice(0, 4).join(' ').match(/(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2}),\s*(\d{4})/i);
      if (m) return `${m[3]}-${String(RI.MONTHS.findIndex(x => x.toLowerCase() === m[1].toLowerCase()) + 1).padStart(2, '0')}-${m[2].padStart(2, '0')}`;
    }
    return S.month ? monthEnd(S.month) : null;
  }

  // ── Publish ────────────────────────────────────────────────────────────────
  function toBase64(file) {
    return new Promise((resolve, reject) => {
      const r = new FileReader();
      r.onload = () => resolve(r.result);
      r.onerror = () => reject(new Error('Could not read ' + file.name));
      r.readAsDataURL(file);
    });
  }

  async function publish() {
    if (S.busy) return;
    if (!S.month) { alert('Please pick the reporting month first.'); return; }
    const rd = rdFile(), sfp = sfpFile(), fn = fnFile();
    const entries = rd ? rd.parsed.entries.filter(e => e.include && e.desc.trim() && e.amount !== 0 && !isNaN(e.amount)) : [];
    const date = monthEnd(S.month);

    let position = null;
    if (sfp || fn) {
      const p = sfp ? sfp.parsed : { items: [], totals: {} };
      const f = fn ? fn.parsed : null;
      const savings = f && f.totalSavings != null ? f.totalSavings : p.savings;
      const designated = f ? (f.totalDesignated ?? RI.round2(f.funds.reduce((s, x) => s + x.amount, 0))) : null;
      position = {
        period_end: date,
        totals: p.totals,
        items: p.items,
        savings,
        funds: f ? f.funds : [],
        designated,
        undesignated: savings != null && designated != null ? RI.round2(savings - designated) : (f ? f.totalUndesignated : null),
      };
    }

    const nothing = !entries.length && !position && !S.files.length;
    if (nothing) { alert('Nothing to publish yet.'); return; }

    const btn = $('ri-publish');
    S.busy = true; btn.disabled = true; btn.textContent = 'Publishing…';
    try {
      const files = [];
      for (const f of S.files) {
        files.push({
          name: docName(f), cat: docCat(f), date: docDate(f),
          notes: f.kind === 'minutes' ? 'Uploaded with ' + monthTitle(S.month) + ' reports' : 'Imported from ' + f.name,
          fileName: f.name, fileData: await toBase64(f.file),
        });
      }
      const res = await api('import_monthly_report', {
        month: S.month, replace: !!S.replace, entries: entries.map(e => ({ ...e, date })), position, files,
        notes: monthTitle(S.month) + ' — Receipts & Disbursements',
      });
      invalidate('finance', 'docs', 'positions');
      close();
      await ReportImport.refresh();
      if (typeof sendNotification === 'function') {
        sendNotification('💰 Finance', monthTitle(S.month) + ' reports published',
          `${res.entries} ledger entries and ${res.docs} document${res.docs === 1 ? '' : 's'} added.`);
      }
      alert(`✓ ${monthTitle(S.month)} published: ${res.entries} ledger entries, ${res.position ? 'financial position, ' : ''}${res.docs} document${res.docs === 1 ? '' : 's'} filed.`);
      S.files = []; S.month = ''; S.replace = true;
    } catch (e) {
      alert('Could not publish: ' + e.message);
    } finally {
      S.busy = false; btn.disabled = false; btn.textContent = 'Publish to Dashboard';
    }
  }

  function open(files) {
    ensureModal();
    openModal('import-modal');
    render();
    if (files && files.length) addFiles(files);
  }
  function close() { closeModal('import-modal'); }

  // ── Dashboard widgets ──────────────────────────────────────────────────────
  function shortDate(iso) {
    const [y, m, d] = iso.split('-').map(Number);
    return `${RI.MONTHS[m - 1].slice(0, 3)} ${d}, ${y}`;
  }

  /** "Current Balance" tile: real cash from the latest financial position, else the ledger net. */
  function setBalanceStat(ledgerNet, positions) {
    positions = positions || Cache.positions || [];
    const el = $('ds-balance'), lbl = $('ds-balance-lbl');
    if (!el) return;
    const cash = positions.length ? positions[0].totals?.cash : null;
    if (cash != null) {
      el.textContent = fmt(cash, true);
      if (lbl) lbl.textContent = 'Cash in Bank · ' + shortDate(positions[0].period_end);
    } else {
      el.textContent = fmt(ledgerNet, true);
      if (lbl) lbl.textContent = 'Current Balance';
    }
  }

  function renderPositionCard(positions) {
    const el = $('db-position-card');
    if (!el) return;
    const head = `<div class="sc-header"><div><div class="sc-title">Financial Position</div>
        <div class="sc-sub">${positions.length ? 'As of ' + shortDate(positions[0].period_end) : 'From the Statement of Financial Position'}</div></div>
        <button class="btn btn-ghost btn-sm" onclick="ReportImport.open()">Update</button></div>`;
    if (!positions.length) {
      el.innerHTML = head + `<div class="empty" style="padding:30px 0"><div class="ei" style="font-size:32px">🏦</div>
        <p>No financial position yet.<br>Upload the Statement of Financial Position to see cash, assets and restricted funds here.</p></div>`;
      return;
    }
    const p = positions[0], prev = positions[1], t = p.totals || {};
    const delta = prev && prev.totals?.cash != null && t.cash != null ? RI.round2(t.cash - prev.totals.cash) : null;
    const kpi = (v, l, extra = '') => `<div class="ri-kpi"><b>${money(v)}</b><span>${l}</span>${extra}</div>`;
    const deltaHtml = delta == null ? '' : `<div style="font-size:11px;font-weight:700;color:${delta >= 0 ? 'var(--green)' : 'var(--orange)'};margin-top:2px;">${delta >= 0 ? '▲' : '▼'} ${money(Math.abs(delta))} vs ${RI.MONTHS[+prev.period_end.slice(5, 7) - 1].slice(0, 3)}</div>`;

    let savingsHtml = '';
    if (p.savings != null && p.designated != null && p.savings > 0) {
      const pct = Math.max(0, Math.min(100, p.designated / p.savings * 100));
      savingsHtml = `
        <div style="margin-top:16px;">
          <div style="display:flex;justify-content:space-between;font-size:12px;font-weight:700;color:var(--navy);margin-bottom:6px;">
            <span>Savings ${money(p.savings)}</span>
            <span style="color:var(--mid)">${money(p.undesignated)} available</span>
          </div>
          <div style="height:10px;border-radius:6px;background:rgba(27,196,196,.25);overflow:hidden;">
            <div style="width:${pct}%;height:100%;background:var(--orange);"></div>
          </div>
          <div style="margin-top:8px;font-size:12px;color:var(--dark);line-height:1.7;">
            ${(p.funds || []).map(f => `<span style="display:flex;justify-content:space-between;"><span>🔒 ${esc(f.name)}</span><b>${money(f.amount)}</b></span>`).join('')}
          </div>
        </div>`;
    }
    el.innerHTML = head + `
      <div class="ri-grid">
        ${kpi(t.cash, 'Cash in bank', deltaHtml)}
        ${kpi(t.assets, 'Total assets')}
        ${kpi(t.liabilities, 'Liabilities')}
        ${kpi(t.equity, 'Net assets')}
      </div>${savingsHtml}
      <div style="font-size:11px;color:var(--mid);margin-top:12px;">Updated ${esc(p.created_at ? shortDate(p.created_at.slice(0, 10)) : '')}${p.added_by ? ' by ' + esc(p.added_by) : ''}</div>`;
  }

  // Dashboard drop zone: dropping files straight onto the card opens the wizard with them loaded
  function wireDashboardDrop() {
    const z = $('db-drop');
    if (!z || z.dataset.wired) return;
    z.dataset.wired = '1';
    z.ondragover = e => { e.preventDefault(); z.classList.add('over'); };
    z.ondragleave = () => z.classList.remove('over');
    z.ondrop = e => { e.preventDefault(); z.classList.remove('over'); open(e.dataTransfer.files); };
  }

  window.ReportImport = {
    open, close, publish, setBalanceStat, renderPositionCard,
    refresh: async () => {
      if (typeof renderDashboard === 'function') await renderDashboard();
      if (typeof renderFinance === 'function' && $('page-finance')?.classList.contains('active')) await renderFinance();
      if (typeof renderDocs === 'function' && $('page-documents')?.classList.contains('active')) await renderDocs();
    },
    setKind(i, kind) {
      const f = S.files[i]; if (!f) return;
      f.kind = kind;
      if (f.lines) {
        f.parsed = kind === 'rd' ? RI.parseReceipts(f.lines) : kind === 'sfp' ? RI.parsePosition(f.lines)
                 : kind === 'footnote' ? RI.parseFootnote(f.lines) : kind === 'minutes' ? RI.parseMinutes(f.lines) : null;
      } else if (kind === 'footnote' && !f.parsed) {
        f.parsed = { period: null, funds: [], totalSavings: null, totalDesignated: null, totalUndesignated: null };
      }
      autoPickMonth(); render();
    },
    removeFile(i) { S.files.splice(i, 1); render(); },
    setMonth(v) { S.month = v; render(); },
    setReplace(v) { S.replace = v; },
    setRow(i, k, v) {
      const e = rdFile().parsed.entries[i];
      e[k] = k === 'amount' ? parseFloat(v) || 0 : v;
      renderReview();
    },
    setFund(i, k, v) { const f = fnFile().parsed.funds[i]; f[k] = k === 'amount' ? parseFloat(v) || 0 : v; renderReview(); },
    addFund() { fnFile().parsed.funds.push({ name: 'New fund', amount: 0 }); renderReview(); },
    removeFund(i) { fnFile().parsed.funds.splice(i, 1); renderReview(); },
  };

  ensureModal();
  wireDashboardDrop();
})();
