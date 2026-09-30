import fs from 'node:fs/promises';
import path from 'node:path';

const baseUrl = (process.env.FRONTEND_BASE_URL || 'http://feifeicms-modern.localhost:19101').replace(/\/$/, '');
const outputDir = path.resolve(process.env.FRONTEND_AUDIT_OUTPUT || 'runtime/visual-audit/frontend');
const debugPort = process.env.CHROME_DEBUG_PORT || '9223';
await fs.mkdir(outputDir, { recursive: true });

const response = await fetch(`http://127.0.0.1:${debugPort}/json/new?${encodeURIComponent(baseUrl)}`, { method: 'PUT' });
if (!response.ok) throw new Error(`Unable to create Chrome target: ${response.status}`);
const target = await response.json();
const socket = new WebSocket(target.webSocketDebuggerUrl);
let sequence = 0;
const pending = new Map();
const consoleErrors = [];
const failedResponses = [];
let playerState = null;
await new Promise((resolve, reject) => {
  socket.addEventListener('open', resolve, { once: true });
  socket.addEventListener('error', reject, { once: true });
});
socket.addEventListener('message', (event) => {
  const message = JSON.parse(event.data);
  if (message.id && pending.has(message.id)) {
    const promise = pending.get(message.id);
    pending.delete(message.id);
    message.error ? promise.reject(new Error(message.error.message)) : promise.resolve(message.result || {});
  } else if (message.method === 'Runtime.exceptionThrown') {
    consoleErrors.push(message.params.exceptionDetails?.text || 'Runtime exception');
  } else if (message.method === 'Log.entryAdded' && message.params.entry?.level === 'error') {
    consoleErrors.push(message.params.entry.text);
  } else if (message.method === 'Network.responseReceived' && Number(message.params.response?.status || 0) >= 400) {
    failedResponses.push({ status: Number(message.params.response.status), url: String(message.params.response.url || '') });
  }
});
const command = (method, params = {}) => new Promise((resolve, reject) => {
  const id = ++sequence;
  pending.set(id, { resolve, reject });
  socket.send(JSON.stringify({ id, method, params }));
});
const delay = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));
async function viewport(width, height, mobile = false) {
  await command('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile, screenWidth: width, screenHeight: height });
}
async function capture(name, route, fullPage = true, settleMs = 700) {
  await command('Page.navigate', { url: `${baseUrl}${route}` });
  await delay(settleMs);
  if (name.includes('play-')) {
    const evaluated = await command('Runtime.evaluate', {
      expression: `(() => { const video = document.querySelector('video'); return video ? { readyState: video.readyState, networkState: video.networkState, duration: Number.isFinite(video.duration) ? video.duration : null, currentSrc: video.currentSrc, error: video.error ? video.error.code : null } : null; })()`,
      returnByValue: true,
    });
    playerState = evaluated.result?.value || null;
  }
  const metrics = await command('Page.getLayoutMetrics');
  const size = metrics.cssContentSize || metrics.contentSize;
  const clip = fullPage ? { x: 0, y: 0, width: Math.ceil(size.width), height: Math.ceil(size.height), scale: 1 } : undefined;
  const result = await command('Page.captureScreenshot', { format: 'png', fromSurface: true, captureBeyondViewport: fullPage, ...(clip ? { clip } : {}) });
  await fs.writeFile(path.join(outputDir, `${name}.png`), Buffer.from(result.data, 'base64'));
}

await command('Page.enable');
await command('Runtime.enable');
await command('Log.enable');
await command('Network.enable');
await viewport(1440, 900);
for (const [name, route, settleMs = 700] of [
  ['01-home-1440x900', '/'],
  ['02-category-1440x900', '/list/2'],
  ['03-detail-1440x900', '/vod/4'],
  ['04-play-1440x900', '/vod/play/id/4/sid/0/pid/0.html', 8000],
  ['05-search-1440x900', '/vod/search?wd=%E6%97%A0%E5%8F%AF%E6%9B%BF%E4%BB%A3'],
  ['06-user-login-1440x900', '/user/login'],
]) await capture(name, route, true, settleMs);

await viewport(390, 844, true);
for (const [name, route, settleMs = 700] of [
  ['07-home-mobile-390x844', '/'],
  ['08-detail-mobile-390x844', '/vod/4'],
  ['09-play-mobile-390x844', '/vod/play/id/4/sid/0/pid/0.html', 4000],
]) await capture(name, route, true, settleMs);

const report = { baseUrl, screenshots: 9, desktopViewport: '1440x900 @1x', mobileViewport: '390x844 @1x', consoleErrors: [...new Set(consoleErrors)], failedResponses, playerState };
await fs.writeFile(path.join(outputDir, 'capture-report.json'), `${JSON.stringify(report, null, 2)}\n`);
socket.close();
console.log(JSON.stringify(report));
