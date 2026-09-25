const fs = require('node:fs/promises');
const path = require('node:path');
const { execFile } = require('node:child_process');
const { promisify } = require('node:util');
const execute = promisify(execFile);
const marker = '; Pustaka Desktop folder status';

async function setFolderStatus(folder, state) {
    if (process.platform !== 'win32') return false;
    if (!['synced', 'syncing', 'paused', 'error'].includes(state)) throw new Error('Status folder tidak valid.');
    const stat = await fs.lstat(folder);
    if (!stat.isDirectory() || stat.isSymbolicLink()) throw new Error('Folder status harus folder asli.');
    const iniPath = path.join(folder, 'desktop.ini');
    let previous = null;
    try {
        if ((await fs.lstat(iniPath)).isSymbolicLink()) throw new Error('desktop.ini merupakan link.');
        previous = await fs.readFile(iniPath);
        if (!previous.toString('utf16le').includes(marker)) return false;
    } catch (error) { if (error.code !== 'ENOENT') throw error; }
    // Icons live outside the synced folder, so status changes never become uploads.
    // app.asar is not understood by Explorer; copy icons to the application's userData.
    const { app } = require('electron');
    const iconDirectory = path.join(app.getPath('userData'), 'folder-icons');
    await fs.mkdir(iconDirectory, { recursive: true });
    const icon = path.join(iconDirectory, 'digital-library-' + state + '.ico');
    await fs.copyFile(path.join(__dirname, 'folder-' + state + '.ico'), icon);
    const content = Buffer.from('\ufeff' + marker + '\r\n[.ShellClassInfo]\r\nIconResource=' + icon + ',0\r\nInfoTip=Digital Library: ' + state + '\r\n', 'utf16le');
    if (previous?.equals(content)) return true;
    if (previous) await execute('attrib.exe', ['-s', '-h', iniPath], { windowsHide: true });
    await fs.writeFile(iniPath, content, { flag: previous ? 'w' : 'wx' });
    await execute('attrib.exe', ['+s', '+h', iniPath], { windowsHide: true });
    await execute('attrib.exe', ['+r', folder], { windowsHide: true });
    return true;
}

module.exports = { setFolderStatus };
