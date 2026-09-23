// Sprint 11b plan measurement (§A.1) — AST-based count of user-facing string
// literals in hr-frontend/src, via the TypeScript compiler API already
// installed as a devDependency (no new deps). Read-only: parses source files
// into an AST and walks it; writes nothing.
//
// What it counts, per file:
//   jsxText      — non-whitespace JSXText nodes (rendered text between tags)
//   jsxAttr      — string literals on a small allowlist of user-facing JSX
//                  attributes (aria-label, alt, title, placeholder)
//   objectLiteral — string literals that are the VALUE of a property in an
//                  object literal whose property KEY does not look technical
//                  (heuristic below) — this is how label maps
//                  (STATE_BADGE, TYPE_LABEL, SUB_OUTCOME_LABELS, ...) surface
//   templateOrCall — string literals/no-substitution template literals passed
//                  directly to setError/setErr/throw new Error(...), or a
//                  template literal containing a space (prose, not a key)
//
// What it deliberately does NOT count (false-positive traps, confirmed by
// spot-reading every file this sprint touches):
//   - import/require paths, JSX tag/attribute NAMES, className/id/key/type/
//     name/htmlFor/data-*/role/href/src/rel/target values
//   - object property KEYS (only VALUES are counted)
//   - single-character/punctuation-only JSXText ("·", "—", "(", ")", NBSP)
//   - string literals that are one bare technical token with no space and no
//     accented/uppercase-Spanish content (e.g. 'active', 'draft', 'map') —
//     these are enum values, not prose; the manual pass in the plan lists the
//     label maps that DO need translating even though their KEYS are enums
//
// This is a blunt instrument by design — see plan.md §A.1 for the manual
// reconciliation pass (spot-checking every file's totals against a straight
// read) and for what the false-positive/negative rate actually was.
import ts from 'typescript';
import { readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import { join, relative } from 'node:path';

const SRC = process.argv[2] ?? 'src';
const EXCLUDE_DIRS = new Set(['__tests__', '__snapshots__', 'node_modules']);
const ATTR_ALLOWLIST = new Set(['aria-label', 'alt', 'title', 'placeholder']);
const TECHNICAL_KEY_RE = /^(id|key|type|name|className|htmlFor|role|href|src|rel|target|kind|status|state|code|cls|view|tab|method|headers|Content-Type|Accept|Authorization|path|url)$/i;

function isTestFile(f) {
  return /\.test\.tsx?$/.test(f) || /\.d\.ts$/.test(f);
}

function walk(dir, out = []) {
  for (const entry of readdirSync(dir)) {
    if (EXCLUDE_DIRS.has(entry)) continue;
    const p = join(dir, entry);
    const s = statSync(p);
    if (s.isDirectory()) walk(p, out);
    else if (/\.tsx?$/.test(entry) && !isTestFile(entry)) out.push(p);
  }
  return out;
}

// Strips emoji/pictographic symbols (U+2190-27BF arrows/misc, U+1F300-1FAFF
// pictographs, U+2600-26FF misc symbols) so an icon-only JSX child
// (`<span>⚙</span>`) doesn't count as a "string". Real accented Spanish text
// is untouched (outside these ranges).
function stripIcons(s) {
  return s.replace(/[\u2190-\u27BF\u{1F300}-\u{1FAFF}\u2600-\u26FF]/gu, '');
}

function looksLikeProse(text) {
  const t = stripIcons(text).trim();
  if (t.length === 0) return false;
  if (/^[·—\-–|:.,()%/#&✕✓×+\[\]]+$/.test(t)) return false; // pure punctuation/separators
  if (/^\{.*\}$/.test(t)) return false; // stray leftover brace text
  return true;
}

function isBareTechnicalToken(s) {
  // one word, no space, no accented/uppercase-after-lowercase Spanish-looking content
  if (/\s/.test(s)) return false;
  if (/[À-ÿ]/.test(s)) return false; // has an accented char -> treat as prose-ish, don't exclude
  return /^[a-z][a-z0-9_.:-]*$/.test(s);
}

function analyzeFile(filePath) {
  const text = readFileSync(filePath, 'utf8');
  const sf = ts.createSourceFile(filePath, text, ts.ScriptTarget.Latest, true, filePath.endsWith('x') ? ts.ScriptKind.TSX : ts.ScriptKind.TS);
  const counts = { jsxText: 0, jsxAttr: 0, objectLiteral: 0, templateOrCall: 0 };
  const samples = [];
  const allStrings = [];

  function pushSample(bucket, s) {
    if (samples.length < 6) samples.push(`${bucket}:${JSON.stringify(s.slice(0, 60))}`);
  }

  // Sentences split by an inline `{expr}` (e.g. `<p>Hello {name}, hi</p>`)
  // produce MULTIPLE JsxText sibling nodes for ONE translatable string. Count
  // once per JSX element by concatenating its direct JsxText children
  // (ignoring the expression in between) rather than once per JsxText node.
  function handleChildren(children) {
    let joined = '';
    for (const c of children) {
      if (ts.isJsxText(c)) joined += c.text;
    }
    if (looksLikeProse(joined)) {
      counts.jsxText++;
      const clean = joined.trim().replace(/\s+/g, ' ');
      pushSample('jsxText', clean);
      allStrings.push(clean);
    }
  }

  function visit(node) {
    if ((ts.isJsxElement(node) || ts.isJsxFragment(node)) && node.children) {
      handleChildren(node.children);
    }
    if (ts.isJsxAttribute(node)) {
      const attrName = node.name.getText(sf);
      if (ATTR_ALLOWLIST.has(attrName) && node.initializer && ts.isStringLiteral(node.initializer)) {
        counts.jsxAttr++;
        pushSample('jsxAttr', node.initializer.text);
        allStrings.push(node.initializer.text);
      }
    } else if (ts.isPropertyAssignment(node) && ts.isStringLiteral(node.initializer)) {
      const keyText = ts.isIdentifier(node.name) || ts.isStringLiteral(node.name) ? node.name.getText(sf).replace(/^['"]|['"]$/g, '') : '';
      const val = node.initializer.text;
      if (/^--[a-z]/.test(val)) {
        // CSS custom-property NAME used as a value (graphColors.ts's
        // NODE_STATE_TOKEN = { scope: '--accent', ... }) — not user-facing
        // text under any circumstance, exclude unconditionally.
      } else if (!TECHNICAL_KEY_RE.test(keyText) && val.length > 0 && !isBareTechnicalToken(val)) {
        counts.objectLiteral++;
        pushSample('objectLiteral', `${keyText}=${val}`);
        allStrings.push(val);
      } else if (val.length > 0 && /[À-ÿ]/.test(val)) {
        // Spanish-accented value even under a "technical-looking" key (e.g. label maps
        // keyed by enum strings like `sensitive_topic.pattern_baseline`) — still prose.
        counts.objectLiteral++;
        pushSample('objectLiteral', `${keyText}=${val}`);
        allStrings.push(val);
      }
    } else if (ts.isCallExpression(node)) {
      const callee = node.expression.getText(sf);
      if (/setError|setErr|Error$/.test(callee) || callee === 'Error') {
        // Walk every descendant literal in the argument list, not just direct
        // args — the dominant real pattern is
        // `setError(err instanceof ApiError ? err.message : 'fallback text')`,
        // a ConditionalExpression, so the literal is nested one level down.
        const seen = new Set();
        function collect(n) {
          if ((ts.isStringLiteral(n) || ts.isNoSubstitutionTemplateLiteral(n)) && looksLikeProse(n.text) && !isBareTechnicalToken(n.text)) {
            if (!seen.has(n.text)) {
              seen.add(n.text);
              counts.templateOrCall++;
              pushSample('templateOrCall', n.text);
              allStrings.push(n.text);
            }
          }
          ts.forEachChild(n, collect);
        }
        for (const arg of node.arguments) collect(arg);
      }
    }
    ts.forEachChild(node, visit);
  }

  visit(sf);
  return { counts, samples, allStrings };
}

const files = walk(SRC).sort();
const rows = [];
let totals = { jsxText: 0, jsxAttr: 0, objectLiteral: 0, templateOrCall: 0 };

const uniqueStrings = new Set();
for (const f of files) {
  const { counts, samples, allStrings } = analyzeFile(f);
  const total = counts.jsxText + counts.jsxAttr + counts.objectLiteral + counts.templateOrCall;
  if (total > 0) {
    rows.push({ file: relative(process.cwd(), f), ...counts, total, samples });
    for (const k of Object.keys(totals)) totals[k] += counts[k];
    for (const s of allStrings) uniqueStrings.add(s);
  }
}

// Dictionary-size estimate (§B.4): raw char count of the UNIQUE Spanish
// source strings found (dedup — many labels like "Verificado"/"Aprobado"
// repeat across components and collapse to one dictionary key), gzipped as a
// single blob to approximate real compression on a real dictionary file
// (short repeated strings compress far better as one file than estimated
// per-string).
import { gzipSync } from 'node:zlib';
const uniqueList = [...uniqueStrings];
const rawChars = uniqueList.reduce((a, s) => a + s.length, 0);
const blob = uniqueList.join('\n');
const gz = gzipSync(Buffer.from(blob, 'utf8'), { level: 9 }).length;

rows.sort((a, b) => b.total - a.total);
const grandTotal = Object.values(totals).reduce((a, b) => a + b, 0);

console.log('file,jsxText,jsxAttr,objectLiteral,templateOrCall,total');
for (const r of rows) {
  console.log(`${r.file},${r.jsxText},${r.jsxAttr},${r.objectLiteral},${r.templateOrCall},${r.total}`);
}
console.log('---');
console.log(`TOTALS jsxText=${totals.jsxText} jsxAttr=${totals.jsxAttr} objectLiteral=${totals.objectLiteral} templateOrCall=${totals.templateOrCall} GRAND_TOTAL=${grandTotal}`);
console.log(`Files with >0: ${rows.length} / ${files.length} scanned`);
console.log(`Unique strings: ${uniqueList.length} (of ${grandTotal} matches); raw chars=${rawChars}; gzip(es-only blob)=${gz}B`);
console.log(`Round-trip estimate for 2 locales (es+en, same key count, similar avg length): ~${gz * 2}B gzip`);

writeFileSync('count-strings-output.json', JSON.stringify({ totals, grandTotal, rows }, null, 2));
