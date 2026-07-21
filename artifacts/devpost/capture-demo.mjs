import { spawn } from 'node:child_process';
import { mkdir, writeFile } from 'node:fs/promises';

const chromePath = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const outputDirectory = process.argv[2];
const sessionCookie = process.env.DEMO_SESSION_COOKIE;
const baseUrl = 'http://127.0.0.1:8018';
const debuggingPort = 9237;

if (!outputDirectory || !sessionCookie) {
    throw new Error('Output directory and demo session cookie are required.');
}

await mkdir(outputDirectory, { recursive: true });

const chrome = spawn(chromePath, [
    '--headless=new',
    '--disable-gpu',
    '--disable-extensions',
    '--disable-background-networking',
    '--hide-scrollbars',
    '--no-first-run',
    '--no-default-browser-check',
    `--remote-debugging-port=${debuggingPort}`,
    `--user-data-dir=${process.env.TEMP}\\mentrovia-devpost-capture`,
    '--window-size=1440,900',
    'about:blank',
], { stdio: 'ignore' });

const sleep = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

async function fetchJson(url, options = {}) {
    const response = await fetch(url, options);

    if (!response.ok) {
        throw new Error(`${response.status} ${response.statusText}: ${url}`);
    }

    return response.json();
}

async function waitForDebugger() {
    for (let attempt = 0; attempt < 60; attempt++) {
        try {
            return await fetchJson(`http://127.0.0.1:${debuggingPort}/json/version`);
        } catch {
            await sleep(250);
        }
    }

    throw new Error('Chrome DevTools endpoint did not become ready.');
}

await waitForDebugger();

const tabs = await fetchJson(`http://127.0.0.1:${debuggingPort}/json`);
const tab = tabs.find((candidate) => candidate.type === 'page');

if (!tab) {
    throw new Error('Chrome did not expose a page target.');
}

const socket = new WebSocket(tab.webSocketDebuggerUrl);
await new Promise((resolve, reject) => {
    socket.addEventListener('open', resolve, { once: true });
    socket.addEventListener('error', reject, { once: true });
});

let commandId = 0;
const pendingCommands = new Map();

socket.addEventListener('message', (event) => {
    const message = JSON.parse(event.data);

    if (!message.id || !pendingCommands.has(message.id)) {
        return;
    }

    const { resolve, reject } = pendingCommands.get(message.id);
    pendingCommands.delete(message.id);

    if (message.error) {
        reject(new Error(message.error.message));
    } else {
        resolve(message.result);
    }
});

function command(method, params = {}) {
    const id = ++commandId;

    return new Promise((resolve, reject) => {
        pendingCommands.set(id, { resolve, reject });
        socket.send(JSON.stringify({ id, method, params }));
    });
}

async function evaluate(expression) {
    const result = await command('Runtime.evaluate', {
        expression,
        awaitPromise: true,
        returnByValue: true,
    });

    if (result.exceptionDetails) {
        throw new Error(result.exceptionDetails.text ?? 'Runtime evaluation failed.');
    }

    return result.result.value;
}

async function waitForPage() {
    for (let attempt = 0; attempt < 80; attempt++) {
        const ready = await evaluate("document.readyState === 'complete' && document.body && document.body.innerText.length > 100");

        if (ready) {
            await sleep(1100);
            return;
        }

        await sleep(150);
    }

    throw new Error('Page did not finish rendering.');
}

async function sanitizeVisibleData() {
    await evaluate(`(() => {
        const replacements = [
            [/Monahan Enterprises LP/gi, 'Lone Star Countertops'],
            [/Monahan Enterprises/gi, 'Lone Star Countertops'],
            [/brian@kgtech\\.co/gi, 'alex@example.com'],
            [/\\bBrian\\b/g, 'Alex']
        ];
        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        let node;
        while ((node = walker.nextNode())) {
            let value = node.nodeValue;
            for (const [pattern, replacement] of replacements) {
                value = value.replace(pattern, replacement);
            }
            node.nodeValue = value;
        }
        for (const element of document.querySelectorAll('input, textarea')) {
            let value = element.value;
            for (const [pattern, replacement] of replacements) {
                value = value.replace(pattern, replacement);
            }
            element.value = value;
        }
        window.scrollTo({ top: 0, behavior: 'instant' });
        return document.body.innerText.slice(0, 250);
    })()`);
}

async function navigateAndCapture(path, filename) {
    await command('Page.navigate', { url: `${baseUrl}${path}` });
    await waitForPage();
    await sanitizeVisibleData();
    await sleep(250);

    const screenshot = await command('Page.captureScreenshot', {
        format: 'png',
        fromSurface: true,
        captureBeyondViewport: false,
    });

    await writeFile(`${outputDirectory}\\${filename}`, Buffer.from(screenshot.data, 'base64'));
}

try {
    await command('Page.enable');
    await command('Runtime.enable');
    await command('Network.enable');
    await command('Emulation.setDeviceMetricsOverride', {
        width: 1440,
        height: 900,
        deviceScaleFactor: 1,
        mobile: false,
    });
    await command('Network.setCookie', {
        name: 'mentrovia-session',
        value: sessionCookie,
        url: baseUrl,
        path: '/',
        httpOnly: true,
        secure: false,
        sameSite: 'Lax',
    });

    await navigateAndCapture('/dashboard', '02-dashboard.png');
    await navigateAndCapture('/roadmap', '03-roadmap.png');
    await navigateAndCapture('/advisor', '04-advisor.png');
    await navigateAndCapture('/guides', '05-guides.png');
    await navigateAndCapture('/branding', '06-branding.png');
    await navigateAndCapture('/settings/ai/trust', '07-ai-trust.png');
} finally {
    socket.close();
    chrome.kill();
}
