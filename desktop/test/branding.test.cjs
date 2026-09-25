const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { Resvg } = require('@resvg/resvg-js');

const root = path.join(__dirname, '../..');
const read = file => fs.readFileSync(path.join(root, file));
const source = read('public/digital-library-logo.png');
const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 48 48"><image width="48" height="48" href="data:image/png;base64,${source.toString('base64')}"/></svg>`;
const render = size => new Resvg(svg, { fitTo: { mode: 'width', value: size } }).render();

test('desktop and favicon are rendered from the supplied shared logo', () => {
    assert.deepEqual(read('desktop/src/icon.png'), render(256).asPng());
    assert.deepEqual(read('public/digital-library-icon.png'), render(192).asPng());
    assert.deepEqual(read('public/favicon.ico'), read('desktop/src/icon.ico'));
    const pixels = render(48).pixels;
    assert.equal(pixels[3], 0, 'corner remains transparent');
    assert.ok(pixels.some((value, index) => index % 4 === 3 && value > 0), 'logo is not blank');
});

test('Windows icons contain every required resolution and distinct status badges', () => {
    const application = read('desktop/src/icon.ico');
    for (const name of ['icon', 'folder-synced', 'folder-syncing', 'folder-paused', 'folder-error']) {
        const icon = read(`desktop/src/${name}.ico`);
        assert.equal(icon.readUInt16LE(2), 1);
        assert.equal(icon.readUInt16LE(4), 7);
        [16, 24, 32, 48, 64, 128, 256].forEach((size, index) => {
            const entry = 6 + index * 16;
            assert.equal(icon[entry] || 256, size);
            const length = icon.readUInt32LE(entry + 8);
            const offset = icon.readUInt32LE(entry + 12);
            assert.ok(offset + length <= icon.length);
            assert.equal(icon.subarray(offset + 1, offset + 4).toString(), 'PNG');
            if (name === 'icon') assert.deepEqual(icon.subarray(offset, offset + length), render(size).asPng());
        });
        if (name !== 'icon') assert.notDeepEqual(icon, application);
    }
});

test('installer branding changes without changing the existing application identity', () => {
    const pkg = JSON.parse(read('desktop/package.json'));
    const lock = JSON.parse(read('desktop/package-lock.json'));
    assert.equal(pkg.name, 'pustaka-desktop');
    assert.equal(pkg.build.appId, 'id.pustaka.desktop');
    assert.equal(pkg.build.productName, 'Digital Library');
    assert.equal(pkg.version, lock.version);
    assert.equal(pkg.version, lock.packages[''].version);
    assert.equal(pkg.build.win.signAndEditExecutable, true);
    assert.equal(pkg.build.win.icon, 'src/icon.ico');
    assert.equal(pkg.build.nsis.installerIcon, 'src/icon.ico');
    assert.equal(pkg.build.nsis.uninstallerIcon, 'src/icon.ico');
    assert.match(read('desktop/src/index.html').toString(), /<title>Digital Library<\/title>/);
});

test('web branding uses the shared asset and Vue templates compile', () => {
    const { parse, compileTemplate } = require('../../node_modules/@vue/compiler-sfc');
    assert.match(read('resources/js/Components/ApplicationLogo.vue').toString(), /src="\/digital-library-logo.png"/);
    assert.match(read('resources/views/app.blade.php').toString(), /digital-library-icon.png/);
    for (const file of ['Components/ApplicationLogo', 'Layouts/GuestLayout', 'Layouts/AuthenticatedLayout', 'Pages/Catalog/Index']) {
        const filename = `resources/js/${file}.vue`;
        const parsed = parse(read(filename).toString(), { filename });
        assert.deepEqual(parsed.errors, []);
        const compiled = compileTemplate({ source: parsed.descriptor.template.content, filename, id: file });
        assert.deepEqual(compiled.errors, []);
    }
});
