const fs = require('node:fs/promises');
const path = require('node:path');
const { createHash, randomUUID } = require('node:crypto');

const MAX_SIZE = 100 * 1024 * 1024;
const digest = (data) => createHash('sha256').update(data).digest('hex');

function validateRelative(relative) {
    if (typeof relative !== 'string' || relative.length > 1024 || !relative.length) throw new Error('Path tidak valid.');
    for (const part of relative.split('/')) {
        if (!part || part === '.' || part === '..' || /[\\:\x00-\x1f\x7f<>"|?*]/.test(part)
            || /[. ]$/.test(part) || /^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\.|$)/i.test(part)) {
            throw new Error('Path tidak aman: ' + relative);
        }
    }
    return relative;
}

async function safePath(root, relative, createParents = false) {
    validateRelative(relative);
    const rootStat = await fs.lstat(root);
    if (!rootStat.isDirectory() || rootStat.isSymbolicLink()) throw new Error('Folder sinkronisasi tidak tersedia atau merupakan link.');
    let current = root;
    const segments = relative.split('/');
    for (let index = 0; index < segments.length; index++) {
        current = path.join(current, segments[index]);
        let stat;
        try { stat = await fs.lstat(current); } catch (error) {
            if (error.code !== 'ENOENT') throw error;
            if (index < segments.length - 1 && createParents) {
                await fs.mkdir(current);
                stat = await fs.lstat(current);
            }
        }
        if (stat?.isSymbolicLink()) throw new Error('Link/junction dilewati: ' + relative);
        if (stat && index < segments.length - 1 && !stat.isDirectory()) throw new Error('Path bukan folder: ' + relative);
    }
    return current;
}

async function scan(root, report = () => {}) {
    const found = new Map();
    const walk = async (relative = '') => {
        const directory = relative ? await safePath(root, relative) : root;
        if ((await fs.lstat(directory)).isSymbolicLink()) throw new Error('Folder link tidak didukung.');
        for (const entry of await fs.readdir(directory, { withFileTypes: true })) {
            const name = relative ? relative + '/' + entry.name : entry.name;
            if (entry.name.startsWith('.pustaka-tmp-') || ['.git', 'node_modules', 'desktop.ini', 'Thumbs.db', '.DS_Store'].includes(entry.name)) continue;
            if (entry.isSymbolicLink()) { report('Dilewati (link): ' + name); continue; }
            try {
                validateRelative(name);
                if (entry.isDirectory()) await walk(name);
                else if (entry.isFile()) {
                    const filename = await safePath(root, name);
                    const before = await fs.stat(filename);
                    if (before.size > MAX_SIZE) { report('Dilewati (>100 MB): ' + name); continue; }
                    const bytes = await fs.readFile(filename);
                    const after = await fs.stat(filename);
                    if (before.size !== after.size || before.mtimeMs !== after.mtimeMs) { report('Menunggu file selesai ditulis: ' + name); continue; }
                    found.set(name, { hash: digest(bytes), size: bytes.length });
                }
            } catch (error) { report('Dilewati ' + name + ': ' + error.message); }
        }
    };
    await walk();
    return found;
}

async function writeDownload(root, relative, bytes, expectedLocalHash) {
    const filename = await safePath(root, relative, true);
    const temporary = path.join(path.dirname(filename), '.pustaka-tmp-' + randomUUID());
    await fs.writeFile(temporary, bytes, { flag: 'wx' });
    try {
        if (expectedLocalHash) {
            await safePath(root, relative);
            if (digest(await fs.readFile(filename)) !== expectedLocalHash) throw new Error('File lokal berubah saat download; akan dicoba lagi.');
            await fs.rename(temporary, filename);
        } else {
            // Exclusive link prevents a download from overwriting a newly created local file.
            await fs.link(temporary, filename);
        }
    } finally { await fs.unlink(temporary).catch(() => {}); }
}

async function synchronize({ folder, root, bases, api, report, checkpoint, stopped = () => false, progress = () => {} }) {
    progress({ phase: 'scanning', percent: 0, completed: 0, total: 0, file: '' });
    let warnings = 0;
    const warn = message => { warnings++; report(message); };
    const manifest = await api.list(root);
    const remote = new Map();
    for (const file of manifest) {
        validateRelative(file.sync_path);
        if (!/^[a-f0-9]{64}$/.test(file.sync_hash) || file.file_size > MAX_SIZE) throw new Error('Metadata server tidak valid.');
        remote.set(file.sync_path, file);
    }
    const local = await scan(folder, warn);
    const names = new Set([...local.keys(), ...remote.keys()]);
    const total = [...names].filter(name => !local.has(name) || local.get(name).hash !== remote.get(name)?.sync_hash).length;
    let completed = 0;
    const update = (file, fraction = 0) => progress({ phase: 'transferring', file, completed, total, percent: total ? Math.min(99, Math.floor((completed + fraction) / total * 100)) : 0 });
    for (const name of names) {
        if (stopped()) return;
        const own = local.get(name);
        const other = remote.get(name);
        if (own && other && own.hash === other.sync_hash) {
            bases[name] = own.hash;
            continue;
        }
        update(name);
        if (other && (!own || (bases[name] === own.hash && bases[name] !== other.sync_hash))) {
            // Do not treat skipped, unreadable, oversized, or still-changing local files as absent.
            if (!own) {
                try { await fs.lstat(await safePath(folder, name)); warn('Download ditunda, file lokal sudah ada: ' + name); continue; }
                catch (error) { if (error.code !== 'ENOENT') throw error; }
            }
            const bytes = await api.download(other.uuid, fraction => update(name, fraction));
            if (digest(bytes) !== other.sync_hash) throw new Error('Checksum download berbeda; akan dicoba lagi.');
            if (stopped()) return;
            await writeDownload(folder, name, bytes, own?.hash);
            bases[name] = other.sync_hash;
            report('Diunduh: ' + name);
        } else if (own) {
            if (other && bases[name] !== other.sync_hash) {
                const bytes = await api.download(other.uuid, fraction => update(name, fraction / 3));
                if (digest(bytes) !== other.sync_hash) throw new Error('Checksum konflik berbeda.');
                const extension = path.posix.extname(name);
                const conflict = name.slice(0, name.length - extension.length) + ' (konflik server ' + randomUUID().slice(0, 8) + ')' + extension;
                if (stopped()) return;
                await writeDownload(folder, conflict, bytes);
                // Preserve the conflicting server version remotely before updating the original.
                await api.upload(root, conflict, bytes, null, fraction => update(name, (1 + fraction) / 3));
                bases[conflict] = other.sync_hash;
                report('Salinan konflik disimpan: ' + conflict);
            }
            const filename = await safePath(folder, name);
            const bytes = await fs.readFile(filename);
            if (digest(bytes) !== own.hash) { warn('Menunggu perubahan selesai: ' + name); continue; }
            if (stopped()) return;
            const conflict = other && bases[name] !== other.sync_hash;
            const uploaded = await api.upload(root, name, bytes, other?.sync_hash || null, fraction => update(name, conflict ? (2 + fraction) / 3 : fraction));
            if (uploaded.sync_hash !== own.hash) throw new Error('Checksum upload berbeda.');
            bases[name] = uploaded.sync_hash;
            report('Diunggah: ' + name);
        }
        completed++;
        update(name);
        await checkpoint();
    }
    await checkpoint();
    progress({ phase: warnings ? 'incomplete' : 'complete', file: '', completed, total, percent: warnings ? (total ? Math.min(99, Math.floor(completed / total * 100)) : 0) : 100 });
    return { complete: warnings === 0, warnings };
}

module.exports = { synchronize, scan, validateRelative, safePath, writeDownload, digest, MAX_SIZE };
