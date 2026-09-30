import fs from 'node:fs/promises';
import path from 'node:path';

const baseUrl = (process.env.ADMIN_BASE_URL || 'http://feifeicms-modern.localhost:19101').replace(/\/$/, '');
const username = process.env.ADMIN_AUDIT_USERNAME || '';
const password = process.env.ADMIN_AUDIT_PASSWORD || '';
const outputDir = path.resolve(process.env.ADMIN_AUDIT_OUTPUT || 'runtime/visual-audit/admin');
const debugPort = process.env.CHROME_DEBUG_PORT || '9223';
const auditMediaId = Number.parseInt(process.env.ADMIN_AUDIT_MEDIA_ID || '', 10);
const auditEditMediaId = Number.parseInt(process.env.ADMIN_AUDIT_EDIT_MEDIA_ID || '', 10);
const auditCollectionSourceId = Number.parseInt(process.env.ADMIN_AUDIT_COLLECTION_SOURCE_ID || '', 10);
const auditScenarioId = Number.parseInt(process.env.ADMIN_AUDIT_SCENARIO_ID || '', 10);

if (!username || !password) {
  throw new Error('ADMIN_AUDIT_USERNAME and ADMIN_AUDIT_PASSWORD are required.');
}

await fs.mkdir(outputDir, { recursive: true });

const targetResponse = await fetch(`http://127.0.0.1:${debugPort}/json/new?${encodeURIComponent(`${baseUrl}/admin.php`)}`, { method: 'PUT' });
if (!targetResponse.ok) {
  throw new Error(`Unable to create Chrome target: ${targetResponse.status}`);
}
const target = await targetResponse.json();
const socket = new WebSocket(target.webSocketDebuggerUrl);

let sequence = 0;
const pending = new Map();
const consoleErrors = [];
const interactionChecks = {};
let screenshotCount = 0;

await new Promise((resolve, reject) => {
  socket.addEventListener('open', resolve, { once: true });
  socket.addEventListener('error', reject, { once: true });
});

socket.addEventListener('message', (event) => {
  const message = JSON.parse(event.data);
  if (message.id && pending.has(message.id)) {
    const { resolve, reject } = pending.get(message.id);
    pending.delete(message.id);
    if (message.error) reject(new Error(message.error.message));
    else resolve(message.result || {});
    return;
  }
  if (message.method === 'Runtime.exceptionThrown') {
    consoleErrors.push(message.params.exceptionDetails?.text || 'Runtime exception');
  }
  if (message.method === 'Log.entryAdded' && message.params.entry?.level === 'error') {
    consoleErrors.push(message.params.entry.text);
  }
});

function command(method, params = {}) {
  return new Promise((resolve, reject) => {
    const id = ++sequence;
    pending.set(id, { resolve, reject });
    socket.send(JSON.stringify({ id, method, params }));
  });
}

const delay = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

async function viewport(width, height, mobile = false) {
  await command('Emulation.setDeviceMetricsOverride', {
    width,
    height,
    deviceScaleFactor: 1,
    mobile,
    screenWidth: width,
    screenHeight: height,
  });
}

async function navigate(url) {
  await command('Page.navigate', { url });
  await delay(450);
}

async function capture(filename, fullPage = true) {
  let clip;
  if (fullPage) {
    const metrics = await command('Page.getLayoutMetrics');
    const size = metrics.cssContentSize || metrics.contentSize;
    clip = { x: 0, y: 0, width: Math.ceil(size.width), height: Math.ceil(size.height), scale: 1 };
  }
  const result = await command('Page.captureScreenshot', {
    format: 'png',
    fromSurface: true,
    captureBeyondViewport: fullPage,
    ...(clip ? { clip } : {}),
  });
  await fs.writeFile(path.join(outputDir, filename), Buffer.from(result.data, 'base64'));
  screenshotCount += 1;
}

await command('Page.enable');
await command('Runtime.enable');
await command('Log.enable');
await command('Network.enable');
await command('Network.clearBrowserCookies');
await command('Storage.clearDataForOrigin', { origin: new URL(baseUrl).origin, storageTypes: 'cookies' });
await viewport(1440, 900);
await navigate(`${baseUrl}/admin.php?audit=${Date.now()}`);
await capture('00-login-1440x900.png', false);

const loginResult = await command('Runtime.evaluate', {
  expression: `(() => {
    const user = document.querySelector('input[name="username"]');
    const pass = document.querySelector('input[name="password"]');
    const form = document.querySelector('form');
    if (!user || !pass || !form) return 'login-form-missing';
    user.value = ${JSON.stringify(username)};
    pass.value = ${JSON.stringify(password)};
    form.requestSubmit();
    return 'submitted';
  })()`,
  returnByValue: true,
});
if (loginResult.result?.value !== 'submitted') {
  throw new Error(`Unable to submit login form: ${loginResult.result?.value || 'unknown'}`);
}
await delay(750);

const pages = [
  ['01-dashboard', '/admin'],
  ['02-vod-list', '/admin/vod'],
  ['03-vod-create', '/admin/vod/create'],
  ['04-categories', '/admin/categories'],
  ['05-category-create', '/admin/categories/create'],
  ['06-navigation', '/admin/operations/navigation'],
  ['07-collections', '/admin/collections'],
  ['08-collection-create', '/admin/collections/create'],
  ['09-articles', '/admin/content/articles'],
  ['10-people', '/admin/content/people?kind=person'],
  ['11-topics', '/admin/content/topics'],
  ['12-comments', '/admin/comments'],
  ['13-tags', '/admin/tags'],
  ['14-users', '/admin/users'],
  ['15-user-create', '/admin/users/create'],
  ['16-administrators', '/admin/administrators'],
  ['17-billing', '/admin/billing'],
  ['18-settings', '/admin/settings/base'],
  ['19-system', '/admin/system'],
  ['20-database', '/admin/database'],
  ['20a-scenarios', '/admin/scenarios'],
  ['20b-scenario-create', '/admin/scenarios/create'],
];

if (Number.isInteger(auditScenarioId) && auditScenarioId > 0) {
  pages.push(['20c-scenario-edit-populated', `/admin/scenarios/${auditScenarioId}/edit`]);
}

if (Number.isInteger(auditCollectionSourceId) && auditCollectionSourceId > 0) {
  pages.splice(8, 0, ['08a-collection-resource', `/admin/collections/${auditCollectionSourceId}/resource`]);
}

if (Number.isInteger(auditMediaId) && auditMediaId > 0) {
  pages.push(['21-playback-populated', `/admin/vod/${auditMediaId}/playback`]);
}

if (Number.isInteger(auditEditMediaId) && auditEditMediaId > 0) {
  pages.push(['21a-vod-edit-populated', `/admin/vod/${auditEditMediaId}/edit`]);
}

for (const [name, route] of pages) {
  await navigate(`${baseUrl}${route}`);
  await capture(`${name}-1440x900.png`);
}

await viewport(1710, 740);
await navigate(`${baseUrl}/admin/vod/create`);
await capture('22-wide-vod-create-1710x740.png', false);
if (Number.isInteger(auditEditMediaId) && auditEditMediaId > 0) {
  await navigate(`${baseUrl}/admin/vod/${auditEditMediaId}/edit`);
  await capture('22a-wide-vod-edit-1710x740.png', false);
  await viewport(1107, 605);
  await navigate(`${baseUrl}/admin/vod/${auditEditMediaId}/edit`);
  await capture('22b-reference-vod-edit-1107x605.png', false);
  const tabResult = await command('Runtime.evaluate', {
    expression: `(() => {
      const tabs = [...document.querySelectorAll('[data-editor-tabs] a[href^="#"]')];
      const result = { tabCount: tabs.length, labels: tabs.map((tab) => tab.textContent.trim()), panels: [] };
      tabs.forEach((tab) => {
        tab.click();
        const id = tab.getAttribute('href');
        const panel = document.querySelector(id);
        result.panels.push({ id, visible: Boolean(panel && !panel.hidden) });
      });
      tabs[0]?.click();
      const list = document.querySelector('[data-play-source-list]');
      const before = list?.querySelectorAll('.legacy-play-source-row').length || 0;
      document.querySelector('[data-add-play-source]')?.click();
      const after = list?.querySelectorAll('.legacy-play-source-row').length || 0;
      const row = list?.querySelector('.legacy-play-source-row:last-child');
      const textarea = row?.querySelector('textarea[name="play_urls[]"]');
      if (textarea) textarea.value = 'https://example.invalid/test.m3u8';
      row?.querySelector('[data-play-repair]')?.click();
      result.addSource = { before, after, repaired: textarea?.value || '' };
      row?.remove();
      return result;
    })()`,
    returnByValue: true,
  });
  interactionChecks.legacyVodEditor = tabResult.result?.value || null;
}

await viewport(390, 844, true);
for (const [name, route] of [
  ['23-mobile-dashboard', '/admin'],
  ['24-mobile-vod-list', '/admin/vod'],
  ['25-mobile-vod-create', '/admin/vod/create'],
  ['26-mobile-scenarios', '/admin/scenarios'],
]) {
  await navigate(`${baseUrl}${route}`);
  await capture(`${name}-390x844.png`);
}

const report = {
  baseUrl,
  outputDir,
  desktopViewport: '1440x900 @1x',
  mobileViewport: '390x844 @1x',
  screenshots: screenshotCount,
  consoleErrors: [...new Set(consoleErrors)],
  interactionChecks,
};
await fs.writeFile(path.join(outputDir, 'capture-report.json'), `${JSON.stringify(report, null, 2)}\n`);

socket.close();
console.log(JSON.stringify(report));
