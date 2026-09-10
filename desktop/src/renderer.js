const $ = id => document.getElementById(id);
function render(state) {
    $('login-panel').hidden = !!state.user;
    $('sync-panel').hidden = !state.user;
    $('account').textContent = state.user ? state.user.email : 'Belum login';
    $('folder-path').textContent = state.folder || 'Belum ada folder dipilih.';
    $('status').textContent = state.status;
    const progress = state.progress;
    $('progress-percent').textContent = progress.phase === 'scanning' ? '…' : progress.percent + '%';
    if (progress.phase === 'scanning') $('progress-bar').removeAttribute('value');
    else $('progress-bar').value = progress.percent;
    $('progress-label').textContent = progress.phase === 'complete' ? '✓ Semua file tersinkron' : progress.phase === 'scanning' ? 'Memeriksa folder…' : progress.completed + ' / ' + progress.total + ' file selesai';
    $('progress-file').textContent = progress.file;
    $('toggle').textContent = state.paused ? 'Mulai sinkronisasi' : 'Jeda sinkronisasi';
    $('choose-folder').disabled = state.busy;
    $('logout').disabled = state.busy;
    $('sync').disabled = state.busy || state.paused || !state.folder;
    $('toggle').disabled = !state.folder;
    $('auto-start').checked = state.autoStart;
    $('events').replaceChildren(...state.events.map(event => {
        const item = document.createElement('li');
        const time = document.createElement('time');
        time.textContent = event.time;
        item.append(time, document.createTextNode(event.message));
        return item;
    }));
}
async function act(action) {
    $('error').hidden = true;
    try {
        const result = await action();
        if (!result.ok) throw new Error(result.error);
        if (result.data?.status) render(result.data);
    } catch (error) { $('error').textContent = error.message; $('error').hidden = false; }
}
$('login-form').addEventListener('submit', async event => {
    event.preventDefault();
    $('login-button').disabled = true;
    await act(() => window.pustaka.login({ server: $('server').value, email: $('email').value, password: $('password').value }));
    $('password').value = '';
    $('login-button').disabled = false;
});
for (const [id, action] of Object.entries({ 'choose-folder': 'folder', 'open-folder': 'openFolder', toggle: 'toggle', sync: 'sync', logout: 'logout', web: 'openWeb' })) {
    $(id).addEventListener('click', () => act(() => window.pustaka[action]()));
}
$('auto-start').addEventListener('change', event => act(() => window.pustaka.autoStart(event.target.checked)));
window.pustaka.onStatus(render);
act(async () => {
    const result = await window.pustaka.state();
    if (result.ok) $('server').value = result.data.server;
    return result;
});
