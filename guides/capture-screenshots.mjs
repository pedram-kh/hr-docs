#!/usr/bin/env node
/**
 * Re-capture the super_admin user-guide screenshots against staging.
 *
 * One command, from anywhere:
 *   bash hr-docs/guides/capture.sh
 *
 * Staging has no standing fixed OTP (STAGING_FIXED_OTP_CODE is empty).
 * Login uses the same path as hr-docs/infra/compose/otp.sh: request a code,
 * then read it out of the hr-backend container log (MAIL_MAILER=log).
 * That needs the staging SSH key. Nothing here is a production credential.
 *
 * Overrides: STAGING_BASE_URL, STAGING_SSH, STAGING_SSH_KEY,
 * STAGING_COMPOSE_FILE, ADMIN_EMAIL, EMPLOYEE_EMAIL,
 * STAGING_ADMIN_TOKEN, STAGING_EMPLOYEE_TOKEN (skip the mail-log OTP;
 * request-code is limited to 1/minute and 5/hour per address).
 * CAPTURE=admin or CAPTURE=chat runs only that half.
 */
import { execFile } from 'node:child_process';
import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';
import { chromium } from 'playwright';

const execFileAsync = promisify(execFile);
const ROOT = path.dirname(fileURLToPath(import.meta.url));
const OUT = path.join(ROOT, 'screenshots');

const BASE = process.env.STAGING_BASE_URL ?? 'http://52.211.251.235';
const SSH = process.env.STAGING_SSH ?? 'ubuntu@52.211.251.235';
const SSH_KEY = process.env.STAGING_SSH_KEY ?? `${process.env.HOME}/.hr-staging/hr-staging-ec2-key.pem`;
const COMPOSE = process.env.STAGING_COMPOSE_FILE ?? '/opt/hr-staging/docker-compose.staging.yml';
const ADMIN = process.env.ADMIN_EMAIL ?? 'admin@hr-staging.internal';
const EMPLOYEE = process.env.EMPLOYEE_EMAIL ?? 'employee@hr-staging.internal';

const VIEW = { width: 1440, height: 900 };

async function requestCode(email) {
  const res = await fetch(`${BASE}/api/auth/request-code`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ email }),
  });
  if (res.status === 429) return 'throttled';
  if (!res.ok) throw new Error(`request-code ${email} → ${res.status}`);
  return 'sent';
}

/** Newest 6-digit code rendered for this recipient. Mirrors otp.sh's markup anchor. */
async function readCode(email) {
  const remote = `docker compose -f ${COMPOSE} exec -T hr-backend sh -c 'tail -n 500 storage/logs/laravel.log; tail -n 500 storage/logs/laravel-$(date +%F).log 2>/dev/null || true'`;
  let stdout;
  try {
    ({ stdout } = await execFileAsync(
      'ssh',
      ['-i', SSH_KEY, '-o', 'StrictHostKeyChecking=accept-new', SSH, remote],
      { maxBuffer: 8 * 1024 * 1024 },
    ));
  } catch (err) {
    throw new Error(`could not read the staging mail log (${err instanceof Error ? err.message.split('\n')[0] : 'ssh failed'})`);
  }
  const text = stdout.replace(/\r/g, '').replace(/=\n/g, '');
  const records = [];
  let rec = '';
  let toOk = false;
  let subjOk = false;
  const flush = () => {
    if (toOk && subjOk) records.push(rec);
  };
  const arm = (line) => {
    if (line === `To: ${email}`) toOk = true;
    if (line === 'Subject: Your HR Platform login code') subjOk = true;
  };
  for (const line of text.split('\n')) {
    // [^\]] — a bare [^]] is an empty class in JS and swallows the bracket.
    if (/^\[[0-9]{4}-[0-9]{2}-[0-9]{2} [^\]]+\] [^:]+: /.test(line)) {
      flush();
      rec = line.replace(/^\[[0-9]{4}-[0-9]{2}-[0-9]{2} [^\]]+\] [^:]+: /, '');
      toOk = false;
      subjOk = false;
      arm(rec);
    } else {
      rec += `\n${line}`;
      arm(line);
    }
  }
  flush();
  const block = records.at(-1) ?? '';
  const line = block.split('\n').find((l) => /letter-spacing:\s*6px/.test(l)) ?? '';
  const match = line.match(/letter-spacing:\s*6px[^>]*>\s*([0-9]{6})/);
  if (!match) throw new Error(`no login code in the log for ${email}`);
  return match[1];
}

async function verify(email, code) {
  const res = await fetch(`${BASE}/api/auth/verify-code`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ email, code }),
  });
  const body = await res.json().catch(() => ({}));
  if (!res.ok || !body.token) return null;
  return body.token;
}

async function login(email) {
  const preset = email === ADMIN ? process.env.STAGING_ADMIN_TOKEN : process.env.STAGING_EMPLOYEE_TOKEN;
  if (preset) return preset;
  const existing = await readCode(email).catch(() => null);
  if (existing) {
    const token = await verify(email, existing);
    if (token) return token;
  }
  let sent = await requestCode(email);
  if (sent === 'throttled') {
    await new Promise((r) => setTimeout(r, 65000));
    sent = await requestCode(email);
  }
  if (sent !== 'sent') throw new Error(`request-code ${email} still throttled`);
  let code = null;
  let logged = false;
  for (let i = 0; i < 8; i++) {
    await new Promise((r) => setTimeout(r, 1000));
    try {
      const next = await readCode(email);
      if (next && next !== existing) {
        code = next;
        break;
      }
    } catch (err) {
      if (!logged) {
        console.error(err instanceof Error ? err.message.split('\n')[0] : 'read failed');
        logged = true;
      }
    }
  }
  if (!code) throw new Error(`no fresh login code for ${email}`);
  const token = await verify(email, code);
  if (!token) throw new Error(`verify-code ${email} rejected the code from the log`);
  return token;
}

async function shoot(page, name) {
  const file = path.join(OUT, name);
  await page.screenshot({ path: file, fullPage: false });
  console.log(name);
  return file;
}

async function gotoAdmin(page, hash) {
  const url = hash ? `${BASE}/admin${hash}` : `${BASE}/admin`;
  await page.goto(url, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('h2', { timeout: 20000 });
  await page.waitForTimeout(600);
}

async function adminShots(token) {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    viewport: VIEW,
    colorScheme: 'light',
    locale: 'es-ES',
  });
  await context.addInitScript((tok) => {
    localStorage.setItem('hr_token', tok);
    localStorage.setItem('hr-locale', 'es');
    localStorage.removeItem('hr-admin-sidebar-collapsed');
  }, token);
  const page = await context.newPage();

  await gotoAdmin(page, '');
  await page.waitForSelector('text=Jerarquía', { timeout: 20000 });
  await shoot(page, '01-map-jerarquia.png');

  await page.getByRole('tab', { name: 'Grafo' }).click();
  await shootGrafo(page);

  await gotoAdmin(page, '#view=documents');
  await page.waitForSelector('table.docs-table', { timeout: 20000 });
  await shoot(page, '03-documents.png');

  const docRow = page.locator('table.docs-table tbody tr').first();
  if (await docRow.count()) {
    await docRow.click();
    await page.waitForSelector('role=dialog', { timeout: 20000 });
    await page.waitForTimeout(800);
    await shoot(page, '04-document-detail.png');
    await page.getByRole('button', { name: 'Close' }).click();
  }

  const filtros = page.getByRole('button', { name: 'Filtros' });
  if ((await filtros.getAttribute('aria-expanded')) !== 'true') await filtros.click();
  const status = page.locator('.filter-toolbar-filters select.select').first();
  await status.selectOption({ label: 'Under review' });
  await page.waitForTimeout(800);
  await shoot(page, '05-filter-toolbar.png');

  await gotoAdmin(page, '#view=review');
  await page.waitForSelector('text=AI tagging', { timeout: 20000 });
  await page.waitForTimeout(800);
  await shoot(page, '10-review-ai-tagging.png');

  await page.getByRole('button', { name: 'Reference facts' }).click();
  await page.waitForTimeout(800);
  await shoot(page, '11-review-reference-facts.png');
  const factRow = page.locator('table.docs-table tbody tr').first();
  if ((await factRow.count()) && (await factRow.locator('td').count()) > 1) {
    await factRow.click();
    await page.waitForSelector('role=dialog', { timeout: 20000 });
    await page.waitForTimeout(800);
    await shoot(page, '12-review-fact-panel.png');
    await page.keyboard.press('Escape').catch(() => {});
    const close = page.getByRole('button', { name: 'Close' });
    if (await close.count()) await close.first().click().catch(() => {});
  }

  await page.getByRole('button', { name: 'Groups', exact: true }).click();
  await page.waitForTimeout(800);
  await shoot(page, '13-review-groups.png');

  await page.getByRole('button', { name: 'Vocabulary proposals' }).click();
  await page.waitForTimeout(800);
  await shoot(page, '14-review-vocabulary.png');

  await page.getByRole('button', { name: 'Expiry', exact: true }).click();
  await page.waitForTimeout(800);
  await shoot(page, '15-review-expiry.png');

  await gotoAdmin(page, '#view=escalations');
  await page.waitForSelector('.board', { timeout: 20000 });
  await page.waitForTimeout(600);
  await shoot(page, '20-escalations-board.png');
  const card = page.locator('.board-card').first();
  if (await card.count()) {
    await card.click();
    await page.waitForSelector('role=dialog', { timeout: 20000 });
    await page.waitForTimeout(800);
    await shoot(page, '21-escalation-card.png');
  }

  await gotoAdmin(page, '#view=history');
  await page.waitForSelector('table.docs-table', { timeout: 20000 });
  await page.waitForTimeout(600);
  await shoot(page, '22-history.png');
  await shootHistoryTrace(page);

  await gotoAdmin(page, '#view=analytics');
  await page.waitForTimeout(1200);
  await shoot(page, '30-analytics.png');

  await gotoAdmin(page, '#view=coverage');
  await page.waitForTimeout(1200);
  await shoot(page, '31-cobertura.png');

  await gotoAdmin(page, '#view=quality');
  await page.waitForTimeout(1000);
  await shoot(page, '32-calidad.png');

  await gotoAdmin(page, '#view=directory');
  await shootDirectory(page);

  await gotoAdmin(page, '#view=admins');
  await page.waitForTimeout(800);
  await shoot(page, '41-admins.png');

  await gotoAdmin(page, '#view=guardrails');
  await page.waitForTimeout(800);
  await shoot(page, '42-guardrails.png');

  await gotoAdmin(page, '#view=settings');
  await page.waitForTimeout(800);
  await shoot(page, '43-settings.png');

  await browser.close();
}

const FIXTURE_EMAIL = /@(example\.com|hr-staging\.internal)$/i;
const NAVARRA = 'test-navarra@example.com';
const VACACIONES = '¿Cuántos días de vacaciones me corresponden al año?';
const ESCALATION_Q = 'Quiero hablar con una persona de Recursos Humanos';

async function shootGrafo(page) {
  await page.waitForSelector('text=Fucsia = IA sin verificar', { timeout: 30000 });
  await page.waitForTimeout(1200);
  await page.locator('.grafo-caption').evaluate((el) => el.scrollIntoView({ block: 'end', inline: 'nearest' }));
  await page.waitForTimeout(400);
  const box = await page.locator('.grafo-caption').boundingBox();
  if (!box || box.y < 0 || box.y + 12 > VIEW.height) {
    throw new Error('grafo legend is outside the frame');
  }
  await shoot(page, '02-map-grafo.png');
}

async function shootHistoryTrace(page) {
  await page.getByLabel('Resultado').selectOption({ label: 'Solo respondidas' });
  await page.waitForTimeout(800);
  const rows = page.locator('table.docs-table tbody tr', { hasText: 'Respondida' });
  const count = await rows.count();
  if (!count) throw new Error('no answered conversation to open');
  for (let i = 0; i < Math.min(count, 6); i++) {
    await rows.nth(i).click();
    await page.waitForSelector('text=Solo lectura', { timeout: 20000 });
    const toggles = page.locator('summary.trace-toggle');
    if (!(await toggles.count())) {
      await page.getByRole('button', { name: 'Cerrar' }).click();
      continue;
    }
    await page.locator('.detail-body').evaluate((body) => {
      const node = body.querySelector('.trace');
      if (node) body.scrollTop = node.offsetTop - 8;
    });
    const traceCount = await toggles.count();
    for (let t = 0; t < traceCount; t++) {
      await toggles.nth(t).click();
      if (await page.locator('.timeline-action', { hasText: 'Ámbito resuelto' }).count()) break;
    }
    await page.locator('.timeline-action').first().waitFor({ timeout: 8000 });
    await page.locator('.detail-body').evaluate((body) => {
      const step = [...body.querySelectorAll('.timeline-action')].find((el) =>
        (el.textContent ?? '').includes('Ámbito resuelto'),
      );
      const target = step ?? body.querySelector('.timeline-action');
      if (!target) return;
      const top = target.getBoundingClientRect().top - body.getBoundingClientRect().top + body.scrollTop;
      body.scrollTop = Math.max(0, top - 12);
    });
    await page.waitForTimeout(300);
    const visible = await page.locator('.timeline-action').evaluateAll((nodes) =>
      nodes
        .filter((el) => {
          const r = el.getBoundingClientRect();
          return r.top >= 0 && r.bottom <= window.innerHeight && r.height > 8;
        })
        .map((el) => (el.textContent ?? '').trim()),
    );
    if (!visible.some((label) => label.includes('Ámbito resuelto')) || visible.length < 3) {
      await page.getByRole('button', { name: 'Cerrar' }).click();
      continue;
    }
    await shoot(page, '23-history-trace.png');
    console.log('trace steps in frame:', visible.join(' | '));
    return;
  }
  throw new Error('no answered conversation with a legible trace');
}

async function shootDirectory(page) {
  await page.waitForSelector('table.docs-table', { timeout: 20000 });
  const search = page.getByLabel('Buscar empleados');
  await search.fill('test');
  await Promise.all([
    page.waitForResponse((res) => res.url().includes('/admin/employees') && res.ok()),
    search.press('Enter'),
  ]);
  await page.waitForTimeout(400);
  const emails = (await page.locator('table.docs-table tbody tr td:nth-child(2)').allTextContents())
    .map((s) => s.trim())
    .filter((s) => s.includes('@'));
  const foreign = emails.filter((email) => !FIXTURE_EMAIL.test(email));
  if (!emails.length) throw new Error('directory search "test" returned no rows');
  if (foreign.length) throw new Error(`non-fixture address in frame: ${foreign.join(', ')}`);
  await shoot(page, '40-directory.png');
  console.log('directory emails:', emails.join(', '));
}

function employeeContext(token) {
  return chromium.launch({ headless: true }).then(async (browser) => {
    const context = await browser.newContext({ viewport: VIEW, colorScheme: 'light', locale: 'es-ES' });
    await context.addInitScript((tok) => {
      localStorage.setItem('hr_token', tok);
      localStorage.setItem('hr-locale', 'es');
    }, token);
    const page = await context.newPage();
    return { browser, page };
  });
}

async function openChat(page) {
  await page.goto(`${BASE}/app`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('.chat-empty, textarea.chat-input', { timeout: 20000 });
  await page.waitForTimeout(800);
}

async function shootWelcome(page) {
  await openChat(page);
  if (!(await page.locator('.chat-empty').count())) {
    throw new Error('chat is not empty; welcome screen is not showing');
  }
  const prompts = [
    VACACIONES,
    '¿Cuántos días de permiso tengo por matrimonio?',
    '¿Cuánto dura el periodo de prueba en mi convenio?',
    '¿Cuál es mi jornada anual?',
    ESCALATION_Q,
  ];
  for (const prompt of prompts) {
    if (!(await page.getByRole('button', { name: prompt }).count())) {
      throw new Error(`welcome is missing the prompt: ${prompt}`);
    }
  }
  await shoot(page, '50-chat-welcome.png');
}

async function ask(page, question) {
  const box = page.locator('textarea.chat-input');
  await box.fill(question);
  await page.getByRole('button', { name: 'Enviar', exact: true }).click();
}

async function shootAnswer(page) {
  await openChat(page);
  // Count source lines first. A later `.last()` matches an older turn immediately,
  // and `text=Fuentes` also matches the word "fuentes" inside answer prose.
  // The employee chat does not render the admin Fuentes list (Sprint 10a);
  // the source line is "Basado en:".
  const before = await page.locator('.answer-source-line').count();
  await ask(page, VACACIONES);
  const source = page.locator('.answer-source-line').nth(before);
  await source.waitFor({ timeout: 120000 });
  await page.getByRole('button', { name: 'Enviar', exact: true }).waitFor({ timeout: 20000 });
  const question = page.locator('.chat-bubble--user', { hasText: VACACIONES }).last();
  await source.evaluate((el) => el.scrollIntoView({ block: 'center', behavior: 'instant' }));
  await page.waitForTimeout(250);
  const qBox = await question.boundingBox();
  const sBox = await source.boundingBox();
  const composer = await page.locator('.chat-input-bar').boundingBox();
  const limit = composer ? composer.y - 8 : VIEW.height - 80;
  if (!qBox || qBox.y < 0 || !sBox || sBox.y < 0 || sBox.y + sBox.height > limit) {
    throw new Error('vacation answer and Basado en are not together in the frame');
  }
  const basado = (await source.innerText()).includes('Basado en:');
  if (!basado) throw new Error('source line is not Basado en');
  await shoot(page, '51-chat-answer.png');
}

async function shootEscalation(page) {
  await ask(page, ESCALATION_Q);
  const badge = page.locator('.chat-bubble.escalation .badge', { hasText: 'Escalado a Recursos Humanos' }).last();
  await badge.waitFor({ timeout: 120000 });
  // Chat follows the list end with a smooth scroll, which is still moving
  // when the badge first appears. Re-pin until that animation loses.
  let box = null;
  for (let i = 0; i < 8; i++) {
    await badge.evaluate((el) => el.scrollIntoView({ block: 'center', behavior: 'instant' }));
    await page.waitForTimeout(300);
    box = await badge.boundingBox();
    if (box && box.y >= 0 && box.y <= VIEW.height - 40) break;
  }
  if (!box || box.y < 0 || box.y > VIEW.height - 40) throw new Error('escalation badge is outside the frame');
  await shoot(page, '52-chat-escalation.png');
}

async function apiGet(token, path) {
  const res = await fetch(`${BASE}/api${path}`, {
    headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
  });
  if (!res.ok) throw new Error(`${path} → ${res.status}`);
  return res.json();
}

async function emptyFixtureEmail(adminToken) {
  const employees = await apiGet(adminToken, '/admin/employees?q=test');
  const rows = employees.data ?? [];
  const names = new Set();
  let page = 1;
  for (;;) {
    const hist = await apiGet(adminToken, `/admin/history/conversations?page=${page}`);
    for (const row of hist.data ?? []) {
      if (row.employee?.full_name) names.add(row.employee.full_name);
    }
    if (page >= (hist.last_page ?? 1)) break;
    page += 1;
  }
  const candidate = rows.find(
    (row) => FIXTURE_EMAIL.test(row.email) && row.email !== NAVARRA && !names.has(row.full_name),
  );
  if (!candidate) throw new Error('no empty fixture employee for the welcome screen');
  return candidate.email;
}

async function gapShots() {
  console.log(`admin login ${ADMIN}`);
  const adminToken = await login(ADMIN);
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: VIEW, colorScheme: 'light', locale: 'es-ES' });
  await context.addInitScript((tok) => {
    localStorage.setItem('hr_token', tok);
    localStorage.setItem('hr-locale', 'es');
    localStorage.removeItem('hr-admin-sidebar-collapsed');
  }, adminToken);
  const page = await context.newPage();
  await gotoAdmin(page, '#view=map&tab=grafo');
  await shootGrafo(page);
  await gotoAdmin(page, '#view=history');
  await page.waitForSelector('table.docs-table', { timeout: 20000 });
  await shootHistoryTrace(page);
  await gotoAdmin(page, '#view=directory');
  await shootDirectory(page);
  const welcomeEmail = await emptyFixtureEmail(adminToken);
  await browser.close();

  console.log(`welcome login ${welcomeEmail}`);
  const welcomeToken = await login(welcomeEmail);
  const welcome = await employeeContext(welcomeToken);
  try {
    await shootWelcome(welcome.page);
  } finally {
    await welcome.browser.close();
  }

  console.log(`answer login ${NAVARRA}`);
  const navarraToken = await login(NAVARRA);
  const chat = await employeeContext(navarraToken);
  try {
    await shootAnswer(chat.page);
    await shootEscalation(chat.page);
  } finally {
    await chat.browser.close();
  }
}

async function main() {
  await mkdir(OUT, { recursive: true });
  const only = process.env.CAPTURE;
  if (only === 'gaps' || only === 'answer') {
    if (only === 'answer') {
      console.log(`answer login ${NAVARRA}`);
      const navarraToken = await login(NAVARRA);
      const chat = await employeeContext(navarraToken);
      try {
        await shootAnswer(chat.page);
      } finally {
        await chat.browser.close();
      }
    } else {
      await gapShots();
    }
    console.log(`done → ${OUT}`);
    return;
  }
  if (only !== 'chat') {
    console.log(`admin login ${ADMIN}`);
    const adminToken = await login(ADMIN);
    await adminShots(adminToken);
  }
  if (only !== 'admin') {
    console.log(`employee login ${EMPLOYEE}`);
    const employeeToken = await login(EMPLOYEE);
    const chat = await employeeContext(employeeToken);
    try {
      await openChat(chat.page);
      if (await chat.page.locator('.chat-empty').count()) await shootWelcome(chat.page);
      await shootAnswer(chat.page);
      await shootEscalation(chat.page);
    } finally {
      await chat.browser.close();
    }
  }
  console.log(`done → ${OUT}`);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
