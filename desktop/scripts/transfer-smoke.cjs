const { app, dialog } = require('electron');
const fs = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const http = require('node:http');
const { Readable } = require('node:stream');
const { randomUUID } = require('node:crypto');
const assert = require('node:assert/strict');
const { digest } = require('../src/sync.cjs');
app.disableHardwareAcceleration();

async function run() {
    const profile = await fs.mkdtemp(path.join(os.tmpdir(), 'pustaka-transfer-smoke-'));
    const folder = path.join(profile, 'files');
    await fs.mkdir(folder);
    await fs.writeFile(path.join(folder, 'example.txt'), 'desktop transfer check');
    app.setPath('userData', path.join(profile, 'settings'));
    await fs.mkdir(app.getPath('userData'));
    const files = [];
    let revoked = false;
    const server = http.createServer(async (request, response) => {
        try {
            response.setHeader('Content-Type', 'application/json');
            if (request.url.endsWith('/login')) {
                response.end(JSON.stringify({ token: 'test-only-token', user: { id: 1, email: 'smoke@example.test', name: 'Smoke', role: 'user' } })); return;
            }
            assert.equal(request.headers.authorization, 'Bearer test-only-token');
            if (request.url.endsWith('/logout')) { revoked = true; response.end('{}'); return; }
            if (request.method === 'POST') {
                const body = await new Request('http://localhost/upload', { method: 'POST', headers: request.headers, body: Readable.toWeb(request), duplex: 'half' }).formData();
                const bytes = Buffer.from(await body.get('file').arrayBuffer());
                const file = { uuid: randomUUID(), sync_path: body.get('path'), sync_hash: digest(bytes), file_size: bytes.length };
                assert.equal(bytes.toString(), 'desktop transfer check');
                files.push(file); response.end(JSON.stringify({ file })); return;
            }
            response.end(JSON.stringify({ files }));
        } catch (error) { console.error(error); response.statusCode = 500; response.end('{}'); }
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const base = 'http://127.0.0.1:' + server.address().port;
    dialog.showOpenDialog = async () => ({ canceled: false, filePaths: [folder] });
    app.on('browser-window-created', (_event, window) => {
        window.hide();
        window.webContents.on('did-finish-load', async () => {
            try {
                const call = expression => window.webContents.executeJavaScript(expression);
                const login = await call('window.pustaka.login(' + JSON.stringify({ server: base, email: 'smoke@example.test', password: 'test-only' }) + ')');
                assert.equal(login.ok, true, login.error);
                assert.equal((await call('window.pustaka.folder()')).ok, true);
                assert.equal((await call('window.pustaka.toggle()')).ok, true);
                let state;
                for (let attempt = 0; attempt < 100; attempt++) {
                    await new Promise(resolve => setTimeout(resolve, 100));
                    state = (await call('window.pustaka.state()')).data;
                    if (!state.busy && files.length) break;
                }
                assert.equal(files.length, 1, JSON.stringify(state.events));
                assert.equal(files[0].sync_path, 'example.txt');
                assert.equal(state.progress.percent, 100);
                assert.equal(state.progress.phase, 'complete');
                const ini = await fs.readFile(path.join(folder, 'desktop.ini'), 'utf16le');
                assert.ok(ini.includes('pustaka-synced.ico'));
                const settings = JSON.parse(await fs.readFile(path.join(app.getPath('userData'), 'settings.json'), 'utf8'));
                assert.ok(settings.encryptedToken);
                assert.ok(!JSON.stringify(settings).includes('test-only-token'));
                assert.equal((await call('window.pustaka.logout()')).ok, true);
                assert.equal(revoked, true);
                console.log('PASS: Desktop login, native folder selection, real HTTP multipart sync, 100% progress, Windows synced folder icon, encrypted credentials, logout revocation (isolated mock server).');
                server.close(); app.exit(0);
            } catch (error) { console.error(error); server.close(); app.exit(1); }
        });
    });
    setTimeout(() => { console.error('Transfer smoke timed out'); server.close(); app.exit(1); }, 45000).unref();
    require('../src/main.cjs');
}
run().catch(error => { console.error(error); app.exit(1); });
