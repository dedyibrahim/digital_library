const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const { randomUUID } = require('node:crypto');
const { synchronize, digest, safePath, validateRelative, writeDownload } = require('../src/sync.cjs');
const { serverUrl } = require('../src/api.cjs');

async function setup(t) {
    const folder = await fs.mkdtemp(path.join(os.tmpdir(), 'pustaka-sync-test-'));
    t.after(() => fs.rm(folder, { recursive: true, force: true }));
    const files = new Map();
    const bases = {};
    let uploads = 0;
    const put = (name, content) => files.set(name, { uuid: randomUUID(), sync_path: name, sync_hash: digest(Buffer.from(content)), file_size: Buffer.byteLength(content), bytes: Buffer.from(content) });
    const api = {
        list: async () => [...files.values()],
        download: async id => [...files.values()].find(file => file.uuid === id).bytes,
        upload: async (_root, name, bytes, base) => {
            const current = files.get(name);
            if (current && current.sync_hash !== base && current.sync_hash !== digest(bytes)) throw Object.assign(new Error('conflict'), { status: 409 });
            uploads++; put(name, bytes); return files.get(name);
        },
    };
    const run = () => synchronize({ folder, root: randomUUID(), bases, api, report: () => {}, checkpoint: async () => {} });
    return { folder, files, bases, api, run, put, uploads: () => uploads };
}

test('uploads nested and zero-byte files, skips identical content, updates changes', async t => {
    const c = await setup(t);
    await fs.mkdir(path.join(c.folder, 'nested'));
    await fs.writeFile(path.join(c.folder, 'nested', 'file.txt'), 'first');
    await fs.writeFile(path.join(c.folder, 'empty.txt'), '');
    await c.run();
    assert.equal(c.files.size, 2);
    await c.run(); assert.equal(c.uploads(), 2);
    await fs.writeFile(path.join(c.folder, 'nested', 'file.txt'), 'changed');
    await c.run(); assert.equal(c.uploads(), 3);
    assert.equal(c.files.get('nested/file.txt').bytes.toString(), 'changed');
});

test('downloads new server files and updates unchanged local content', async t => {
    const c = await setup(t); c.put('folder/a.txt', 'one');
    await c.run();
    assert.equal(await fs.readFile(path.join(c.folder, 'folder/a.txt'), 'utf8'), 'one');
    c.put('folder/a.txt', 'two'); await c.run();
    assert.equal(await fs.readFile(path.join(c.folder, 'folder/a.txt'), 'utf8'), 'two');
});

test('preserves both copies on concurrent local and server modifications', async t => {
    const c = await setup(t);
    await fs.writeFile(path.join(c.folder, 'a.txt'), 'base'); await c.run();
    await fs.writeFile(path.join(c.folder, 'a.txt'), 'local'); c.put('a.txt', 'server');
    await c.run();
    assert.equal(await fs.readFile(path.join(c.folder, 'a.txt'), 'utf8'), 'local');
    const conflict = [...c.files.keys()].find(name => name.includes('konflik server'));
    assert.ok(conflict); assert.equal(c.files.get(conflict).bytes.toString(), 'server');
    assert.equal(c.files.get('a.txt').bytes.toString(), 'local');
});

test('failed upload retries without recording success or deleting the original', async t => {
    const c = await setup(t);
    await fs.writeFile(path.join(c.folder, 'a.txt'), 'retained');
    const upload = c.api.upload; c.api.upload = async () => { throw new Error('offline'); };
    await assert.rejects(c.run(), /offline/); assert.deepEqual(c.bases, {});
    c.api.upload = upload; await c.run(); assert.equal(c.files.size, 1);
});

test('missing local files are restored and remote deletions do not delete local files', async t => {
    const c = await setup(t); c.put('a.txt', 'backup'); await c.run();
    await fs.unlink(path.join(c.folder, 'a.txt')); await c.run();
    assert.equal(await fs.readFile(path.join(c.folder, 'a.txt'), 'utf8'), 'backup');
    c.files.clear(); await c.run(); assert.equal(c.files.size, 1);
});

test('rejects traversal, Windows aliases, and non-HTTPS remote servers', () => {
    for (const value of ['../escape', '/etc/passwd', 'C:/test', 'a\\b', 'folder/../file', 'NUL.txt', 'file:stream', 'file.']) assert.throws(() => validateRelative(value));
    assert.equal(serverUrl('http://127.0.0.1:8000'), 'http://127.0.0.1:8000');
    assert.equal(serverUrl('https://library.example.com'), 'https://library.example.com');
    for (const value of ['http://example.com', 'https://user:pass@example.com', 'file:///tmp', 'https://example.com/api']) assert.throws(() => serverUrl(value));
});

test('download never overwrites an unexpected local file', async t => {
    const c = await setup(t); await fs.writeFile(path.join(c.folder, 'a.txt'), 'precious');
    await assert.rejects(writeDownload(c.folder, 'a.txt', Buffer.from('new')));
    await assert.rejects(writeDownload(c.folder, 'a.txt', Buffer.from('new'), digest(Buffer.from('old'))));
    assert.equal(await fs.readFile(path.join(c.folder, 'a.txt'), 'utf8'), 'precious');
});

test('junction cannot escape the selected root', async t => {
    const c = await setup(t);
    const outside = await fs.mkdtemp(path.join(os.tmpdir(), 'pustaka-outside-test-'));
    t.after(() => fs.rm(outside, { recursive: true, force: true }));
    await fs.symlink(outside, path.join(c.folder, 'link'), 'junction');
    await assert.rejects(safePath(c.folder, 'link/secret.txt'), /link/i);
});

test('progress reaches 100 only after uploads are acknowledged and hashes verified', async t => {
    const c = await setup(t);
    await fs.writeFile(path.join(c.folder, 'a.txt'), 'first');
    await fs.writeFile(path.join(c.folder, 'b.txt'), 'second');
    const updates = [];
    const upload = c.api.upload;
    c.api.upload = async (root, name, bytes, base, progress) => {
        progress(0.5); progress(0.95);
        assert.ok(updates.at(-1).percent < 100);
        return upload(root, name, bytes, base);
    };
    await synchronize({ folder: c.folder, root: randomUUID(), bases: c.bases, api: c.api, report: () => {}, checkpoint: async () => {}, progress: value => updates.push(value) });
    assert.equal(updates.at(-1).percent, 100);
    assert.equal(updates.at(-1).completed, 2);
    assert.ok(updates.some(value => value.percent > 0 && value.percent < 100));
});

test('skipped files are not reported as completely synced', async t => {
    const c = await setup(t);
    await fs.symlink(c.folder, path.join(c.folder, 'ignored-link'), 'junction');
    const result = await c.run();
    assert.equal(result.complete, false);
});
