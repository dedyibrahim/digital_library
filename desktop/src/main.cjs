const { app, BrowserWindow, ipcMain, dialog, safeStorage, Tray, Menu, nativeImage, shell } = require('electron');
const fs = require('node:fs/promises');
const path = require('node:path');
const os = require('node:os');
const { randomUUID } = require('node:crypto');
const { pathToFileURL } = require('node:url');
const { createApi, serverUrl } = require('./api.cjs');
const { synchronize } = require('./sync.cjs');
const { setFolderStatus } = require('./folder-status.cjs');

let window, tray, token, configFile, busy = false, quitting = false, timer;
let config = { server: 'http://127.0.0.1:8000', user: null, accounts: {}, encryptedToken: null, paused: true };
let status = 'Login untuk mulai sinkronisasi.';
const events = [];
let progress = { phase: 'idle', percent: 0, completed: 0, total: 0, file: '' };
const pageUrl = pathToFileURL(path.join(__dirname, 'index.html')).href;
app.setAppUserModelId('id.pustaka.desktop');
const accountKey = () => config.server + '/' + config.user?.id;
const selected = () => {
    const account = config.accounts[accountKey()];
    return account?.folders?.[account.activeFolder];
};
const snapshot = () => ({ server: config.server, user: token ? config.user : null, folder: selected()?.folder || '', root: selected()?.root || '', paused: config.paused, busy, status, progress, events, autoStart: app.getLoginItemSettings().openAtLogin });
const broadcast = () => { if (window && !window.isDestroyed()) window.webContents.send('status', snapshot()); };
const report = (message) => {
    events.unshift({ time: new Date().toLocaleTimeString('id-ID'), message });
    events.splice(80);
    broadcast();
};
let saving = Promise.resolve();
function persist() {
    const serialized = JSON.stringify(config);
    saving = saving.catch(() => {}).then(async () => {
        const temporary = configFile + '.tmp';
        await fs.writeFile(temporary, serialized, { mode: 0o600 });
        await fs.rename(temporary, configFile);
    });
    return saving;
}

async function sync() {
    if (busy || !token || config.paused || !selected()) return;
    busy = true;
    status = 'Memeriksa dan menyinkronkan file…';
    broadcast();
    const setting = selected();
    const mark = async state => {
        try { await setFolderStatus(setting.folder, state); }
        catch (error) { report('Ikon folder belum diperbarui: ' + error.message); }
    };
    try {
        await mark('syncing');
        const result = await synchronize({
            folder: setting.folder, root: setting.root, bases: setting.bases,
            api: createApi(config.server, token), report, checkpoint: persist,
            stopped: () => config.paused || quitting,
            progress: value => {
                if (value.percent === progress.percent && value.phase === progress.phase && value.file === progress.file && value.completed === progress.completed) return;
                progress = value;
                if (window && !window.isDestroyed()) window.setProgressBar(value.phase === 'complete' ? -1 : value.percent / 100);
                broadcast();
            },
        });
        status = config.paused ? 'Sinkronisasi dijeda.' : result?.complete ? 'Semua file tersinkron · ' + new Date().toLocaleTimeString('id-ID') : 'Ada file yang dilewati. Periksa aktivitas terbaru.';
        await mark(config.paused ? 'paused' : result?.complete ? 'synced' : 'error');
    } catch (error) {
        status = error.status === 401 ? error.message : 'Belum tersinkron · mencoba lagi dalam 15 detik.';
        report(error.message);
        await mark('error');
        if (error.status === 401) {
            token = null;
            config.encryptedToken = null;
            config.paused = true;
            await persist();
        }
    } finally { busy = false; if (window && !window.isDestroyed()) window.setProgressBar(-1); broadcast(); }
}

function showWindow() {
    if (window && !window.isDestroyed()) { window.show(); window.focus(); return; }
    window = new BrowserWindow({
        width: 1000, height: 760, minWidth: 760, minHeight: 620,
        title: 'Pustaka Desktop', backgroundColor: '#f6f8fc',
        icon: path.join(__dirname, 'icon.png'), autoHideMenuBar: true,
        webPreferences: { preload: path.join(__dirname, 'preload.cjs'), contextIsolation: true, nodeIntegration: false, sandbox: true },
    });
    window.webContents.setWindowOpenHandler(() => ({ action: 'deny' }));
    window.webContents.on('will-navigate', event => event.preventDefault());
    window.webContents.session.setPermissionRequestHandler((_contents, _permission, callback) => callback(false));
    window.on('close', event => { if (!quitting) { event.preventDefault(); window.hide(); } });
    window.loadFile(path.join(__dirname, 'index.html'));
}

function handle(channel, action) {
    ipcMain.handle(channel, async (event, input) => {
        if (event.sender !== window?.webContents || event.senderFrame !== window.webContents.mainFrame || event.senderFrame.url !== pageUrl) throw new Error('Permintaan tidak diizinkan.');
        try { return { ok: true, data: await action(input) }; }
        catch (error) { return { ok: false, error: error.message }; }
    });
}

async function start() {
    configFile = path.join(app.getPath('userData'), 'settings.json');
    try {
        config = { ...config, ...JSON.parse(await fs.readFile(configFile, 'utf8')) };
        if (config.encryptedToken && safeStorage.isEncryptionAvailable()) token = safeStorage.decryptString(Buffer.from(config.encryptedToken, 'base64'));
    } catch { config.paused = true; }
    handle('state', () => snapshot());
    handle('login', async (data) => {
        if (busy || token) throw new Error('Logout dari sesi saat ini terlebih dahulu.');
        if (!safeStorage.isEncryptionAvailable()) throw new Error('Penyimpanan token aman tidak tersedia pada komputer ini.');
        if (!data || typeof data.email !== 'string' || typeof data.password !== 'string') throw new Error('Isi email dan password.');
        const server = serverUrl(data.server);
        const result = await createApi(server).login(data.email, data.password, os.hostname().slice(0, 100));
        config.server = server;
        config.user = result.user;
        token = result.token;
        config.encryptedToken = safeStorage.encryptString(token).toString('base64');
        config.paused = true;
        status = 'Login berhasil. Pilih folder, lalu mulai sinkronisasi.';
        await persist();
        broadcast();
        return snapshot();
    });
    handle('folder', async () => {
        if (!token || busy) throw new Error('Login dan tunggu sinkronisasi selesai dahulu.');
        config.paused = true;
        const result = await dialog.showOpenDialog(window, { title: 'Pilih folder untuk disinkronkan', properties: ['openDirectory', 'createDirectory'] });
        if (result.canceled) return snapshot();
        const folder = result.filePaths[0];
        const forbidden = [path.parse(folder).root, os.homedir(), app.getPath('userData')];
        if (forbidden.some(item => path.resolve(item).toLowerCase() === path.resolve(folder).toLowerCase())) throw new Error('Pilih subfolder khusus, bukan seluruh drive atau folder home.');
        if ((await fs.lstat(folder)).isSymbolicLink()) throw new Error('Pilih folder asli, bukan link/junction.');
        const account = config.accounts[accountKey()] ??= { folders: {}, activeFolder: null };
        account.folders[folder] ??= { folder, root: randomUUID(), bases: {} };
        account.activeFolder = folder;
        status = 'Folder dipilih. Klik Mulai sinkronisasi.';
        progress = { phase: 'idle', percent: 0, completed: 0, total: 0, file: '' };
        if (!await setFolderStatus(folder, 'paused')) report('Ikon folder kustom yang sudah ada dipertahankan. Status tersedia di aplikasi.');
        await persist(); broadcast(); return snapshot();
    });
    handle('toggle', async () => {
        if (!token || !selected()) throw new Error('Login dan pilih folder dahulu.');
        config.paused = !config.paused;
        if (config.paused && !busy) await setFolderStatus(selected().folder, 'paused');
        status = config.paused ? 'Menjeda setelah transfer aktif selesai…' : 'Sinkronisasi aktif.';
        await persist(); broadcast(); void sync(); return snapshot();
    });
    handle('sync', async () => {
        if (config.paused) throw new Error('Klik Mulai sinkronisasi dahulu.');
        void sync(); return snapshot();
    });
    handle('logout', async () => {
        if (busy) throw new Error('Jeda dan tunggu transfer selesai sebelum logout.');
        if (token) await createApi(config.server, token).logout().catch(error => { if (error.status !== 401) throw error; });
        if (selected()) await setFolderStatus(selected().folder, 'paused').catch(() => {});
        token = null; config.encryptedToken = null; config.user = null; config.paused = true;
        status = 'Anda sudah logout.'; events.length = 0;
        await persist(); broadcast(); return snapshot();
    });
    handle('open-folder', async () => {
        if (!token || !selected()) throw new Error('Pilih folder dahulu.');
        const error = await shell.openPath(selected().folder);
        if (error) throw new Error(error);
    });
    handle('open-web', () => shell.openExternal(serverUrl(config.server)));
    handle('auto-start', (value) => {
        if (typeof value !== 'boolean') throw new Error('Pengaturan tidak valid.');
        app.setLoginItemSettings({ openAtLogin: value, args: ['--background'] });
        return snapshot();
    });
    tray = new Tray(nativeImage.createFromPath(path.join(__dirname, 'icon.png')).resize({ width: 20, height: 20 }));
    tray.setToolTip('Pustaka Desktop');
    tray.setContextMenu(Menu.buildFromTemplate([
        { label: 'Buka Pustaka Desktop', click: showWindow },
        { label: 'Keluar aplikasi', click: () => app.quit() },
    ]));
    tray.on('double-click', showWindow);
    if (!process.argv.includes('--background')) showWindow();
    timer = setInterval(() => void sync(), 15000);
    if (token) { status = 'Sesi tersimpan. Memeriksa koneksi…'; void sync(); }
}

if (!app.requestSingleInstanceLock()) app.quit();
else {
    app.on('second-instance', showWindow);
    app.whenReady().then(start).catch(error => { dialog.showErrorBox('Pustaka Desktop', error.message); app.quit(); });
    app.on('before-quit', () => { quitting = true; clearInterval(timer); });
    app.on('window-all-closed', () => {});
}
