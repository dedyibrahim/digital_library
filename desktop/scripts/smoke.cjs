const { app } = require('electron');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const assert = require('node:assert/strict');

// Isolated profile: smoke checks never access the user's saved desktop session.
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'pustaka-smoke-'));
app.setPath('userData', profile);
app.disableHardwareAcceleration();
app.on('browser-window-created', (_event, window) => {
    window.hide();
    window.webContents.on('did-finish-load', async () => {
        try {
            const result = await window.webContents.executeJavaScript('window.pustaka.state()');
            assert.equal(result.ok, true);
            assert.equal(result.data.user, null);
            const folder = await window.webContents.executeJavaScript('window.pustaka.folder()');
            assert.equal(folder.ok, false);
            const isolation = await window.webContents.executeJavaScript('({node: typeof require, hidden: document.getElementById("sync-panel").hidden, title: document.title})');
            assert.equal(isolation.node, 'undefined');
            assert.equal(isolation.hidden, true);
            assert.equal(isolation.title, 'Digital Library');
            const brand = await window.webContents.executeJavaScript('({loaded: document.querySelector(".brand img").naturalWidth, text: document.querySelector(".brand").textContent})');
            assert.ok(brand.loaded > 0);
            assert.match(brand.text, /Digital Library/);
            await new Promise(resolve => setTimeout(resolve, 1000));
            const screenshot = await window.capturePage(undefined, { stayHidden: true });
            fs.mkdirSync(path.join(__dirname, '../dist'), { recursive: true });
            fs.writeFileSync(path.join(__dirname, '../dist/smoke.png'), screenshot.toPNG());
            console.log('PASS: Electron renderer, isolated preload IPC, login gate, and screenshot.');
            app.exit(0);
        } catch (error) { console.error(error); app.exit(1); }
    });
});
setTimeout(() => { console.error('Smoke check timed out'); app.exit(1); }, 30000).unref();
require('../src/main.cjs');
