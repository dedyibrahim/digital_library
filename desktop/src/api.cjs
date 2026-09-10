const { MAX_SIZE } = require('./sync.cjs');

function serverUrl(value) {
    const url = new URL(value);
    if (url.username || url.password || url.search || url.hash || url.pathname !== '/') throw new Error('Gunakan alamat dasar server, tanpa path atau kredensial.');
    if (url.protocol !== 'https:' && !(url.protocol === 'http:' && ['127.0.0.1', 'localhost', '[::1]'].includes(url.hostname))) {
        throw new Error('Server harus HTTPS. HTTP hanya diizinkan untuk localhost.');
    }
    return url.origin;
}

function createApi(server, token = '') {
    const base = serverUrl(server) + '/api/v1/desktop/';
    const request = async (endpoint, options = {}, binary = false, progress = () => {}) => {
        const response = await fetch(base + endpoint, {
            ...options, redirect: 'error', signal: AbortSignal.timeout(120000),
            headers: { Accept: 'application/json', ...(token ? { Authorization: 'Bearer ' + token } : {}), ...options.headers },
        });
        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            const error = new Error(response.status === 401 ? 'Sesi berakhir. Silakan login kembali.' : (Object.values(data.errors || {}).flat()[0] || data.message || 'Server gagal: ' + response.status));
            error.status = response.status;
            throw error;
        }
        if (!binary) return response.json();
        const chunks = [];
        let size = 0;
        const total = Number(response.headers.get('content-length'));
        for await (const chunk of response.body) {
            size += chunk.length;
            if (size > MAX_SIZE) throw new Error('Download melebihi 100 MB.');
            chunks.push(Buffer.from(chunk));
            progress(total > 0 ? Math.min(0.95, size / total * 0.95) : 0);
        }
        return Buffer.concat(chunks);
    };
    return {
        login: (email, password, device_name) => request('login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email, password, device_name }) }),
        me: () => request('me'),
        logout: () => request('logout', { method: 'POST' }),
        list: async (root) => (await request('files?root=' + encodeURIComponent(root))).files,
        download: (uuid, progress) => request('files/' + encodeURIComponent(uuid) + '/content', {}, true, progress),
        upload: async (root, relative, bytes, baseHash, progress = () => {}) => {
            const body = new FormData();
            body.set('root', root);
            body.set('path', relative);
            if (baseHash) body.set('base_hash', baseHash);
            body.set('file', new Blob([bytes]), relative.split('/').pop());
            const encoded = new Response(body);
            const payload = new Uint8Array(await encoded.arrayBuffer());
            let sent = 0;
            const stream = new ReadableStream({
                pull(controller) {
                    if (sent === payload.length) { controller.close(); return; }
                    const chunk = payload.subarray(sent, Math.min(sent + 65536, payload.length));
                    sent += chunk.length;
                    // Reserve the last 5% for the server response and checksum verification.
                    progress(sent / payload.length * 0.95);
                    controller.enqueue(chunk);
                },
            });
            return (await request('files', {
                method: 'POST', body: stream, duplex: 'half',
                headers: { 'Content-Type': encoded.headers.get('content-type'), 'Content-Length': String(payload.length) },
            })).file;
        },
    };
}

module.exports = { createApi, serverUrl };
