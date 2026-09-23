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
    if (/^\[[0-9]{4}-[0-9]{2}-[0-9]{2}[^]]*\] [^:]+: /.test(line)) {
      flush();
      rec = line.replace(/^\[[0-9]{4}-[0-9]{2}-[0-9]{2}[^]]*\] [^:]+: /, '');
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
  await page.waitForSelector('text=Fucsia = IA sin verificar', { timeout: 30000 });
  await page.waitForTimeout(1500);
  await page.locator('.grafo-caption').evaluate((el) => el.scrollIntoView({ block: 'end' }));
  await page.waitForTimeout(400);
  await shoot(page, '02-map-grafo.png');

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
  const histRow = page.locator('table.docs-table tbody tr').first();
  if ((await histRow.count()) && (await histRow.locator('td').count()) > 1) {
    await histRow.click();
    await page.waitForSelector('text=Solo lectura', { timeout: 20000 });
    const trace = page.locator('summary.trace-toggle').first();
    if (await trace.count()) {
      await trace.click();
      await page.locator('.timeline-action').first().waitFor({ timeout: 5000 }).catch(() => {});
      await page.waitForTimeout(300);
    }
    await shoot(page, '23-history-trace.png');
  }

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
  await page.waitForSelector('table.docs-table', { timeout: 20000 });
  const search = page.getByLabel('Buscar empleados');
  await search.fill('test');
  await search.press('Enter');
  await page.waitForTimeout(800);
  await shoot(page, '40-directory.png');

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

async function chatShots(token) {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    viewport: VIEW,
    colorScheme: 'light',
    locale: 'es-ES',
  });
  await context.addInitScript((tok) => {
    localStorage.setItem('hr_token', tok);
    localStorage.setItem('hr-locale', 'es');
  }, token);
  const page = await context.newPage();
  await page.goto(`${BASE}/app`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('textarea, .chat-empty, .chat-thread, form', { timeout: 20000 }).catch(() => {});
  await page.waitForTimeout(1500);

  const welcome = page.locator('.chat-empty');
  if (await welcome.count()) {
    await shoot(page, '50-chat-welcome.png');
    await page.getByRole('button', { name: '¿Cuántos días de vacaciones me corresponden al año?' }).click();
  } else {
    await shoot(page, '50-chat-welcome.png');
    console.log('note: employee session already has messages; 50 is the hydrated thread, not the empty welcome');
    const box = page.locator('textarea, input[placeholder*="pregunta"]');
    await box.first().fill('¿Cuántos días de vacaciones me corresponden al año?');
    await page.getByRole('button', { name: 'Enviar' }).click();
  }

  await page.waitForSelector('text=Pensando…', { timeout: 10000 }).catch(() => {});
  await page.waitForSelector('text=Pensando…', { state: 'detached', timeout: 120000 }).catch(() => {});
  await page.waitForTimeout(1000);
  await shoot(page, '51-chat-answer.png');

  const input = page.locator('textarea, input[placeholder*="pregunta"]').first();
  await input.fill('Quiero hablar con una persona de Recursos Humanos');
  await page.getByRole('button', { name: 'Enviar' }).click();
  await page.waitForSelector('text=Escalado a Recursos Humanos', { timeout: 120000 }).catch(() => {});
  await page.waitForTimeout(800);
  await shoot(page, '52-chat-escalation.png');

  await browser.close();
}

async function main() {
  await mkdir(OUT, { recursive: true });
  const only = process.env.CAPTURE;
  if (only !== 'chat') {
    console.log(`admin login ${ADMIN}`);
    const adminToken = await login(ADMIN);
    await adminShots(adminToken);
  }
  if (only !== 'admin') {
    console.log(`employee login ${EMPLOYEE}`);
    const employeeToken = await login(EMPLOYEE);
    await chatShots(employeeToken);
  }
  console.log(`done → ${OUT}`);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
