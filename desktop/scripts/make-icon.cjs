const fs = require('node:fs');
const path = require('node:path');
const { Resvg } = require('@resvg/resvg-js');

// Shared brand asset for web, executable, tray and folder icons.
const source = fs.readFileSync(path.join(__dirname, '../../public/digital-library-logo.png'));
const logo = `<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 48 48"><image width="48" height="48" href="data:image/png;base64,${source.toString('base64')}"/></svg>`;
const output = path.join(__dirname, '../src');
const badges = {
    synced: '<circle cx="37" cy="37" r="10" fill="#16a34a" stroke="white" stroke-width="2"/><path d="m32 37 3 3 6-7" fill="none" stroke="white" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/>',
    syncing: '<circle cx="37" cy="37" r="10" fill="#0284c7" stroke="white" stroke-width="2"/><path d="M32 35a5 5 0 0 1 9-1m0-3v3h-3m4 5a5 5 0 0 1-9 1m0 3v-3h3" fill="none" stroke="white" stroke-width="1.7" stroke-linecap="round"/>',
    paused: '<circle cx="37" cy="37" r="10" fill="#d97706" stroke="white" stroke-width="2"/><path d="M34 32v10m6-10v10" stroke="white" stroke-width="3"/>',
    error: '<circle cx="37" cy="37" r="10" fill="#dc2626" stroke="white" stroke-width="2"/><path d="M37 31v7m0 3v2" stroke="white" stroke-width="3"/>',
};
function render(svg, size) { return new Resvg(svg, { fitTo: { mode: 'width', value: size } }).render().asPng(); }
function ico(svg) {
    const sizes = [16, 24, 32, 48, 64, 128, 256];
    const images = sizes.map(size => render(svg, size));
    const directory = Buffer.alloc(6 + sizes.length * 16);
    directory.writeUInt16LE(1, 2); directory.writeUInt16LE(sizes.length, 4);
    let offset = directory.length;
    sizes.forEach((size, index) => {
        const pos = 6 + index * 16;
        directory[pos] = size % 256; directory[pos + 1] = size % 256;
        directory.writeUInt16LE(1, pos + 4); directory.writeUInt16LE(32, pos + 6);
        directory.writeUInt32LE(images[index].length, pos + 8); directory.writeUInt32LE(offset, pos + 12);
        offset += images[index].length;
    });
    return Buffer.concat([directory, ...images]);
}
fs.writeFileSync(path.join(output, 'icon.png'), render(logo, 256));
fs.writeFileSync(path.join(output, 'icon.ico'), ico(logo));
fs.writeFileSync(path.join(__dirname, '../../public/digital-library-icon.png'), render(logo, 192));
fs.writeFileSync(path.join(__dirname, '../../public/favicon.ico'), ico(logo));
for (const [state, badge] of Object.entries(badges)) fs.writeFileSync(path.join(output, 'folder-' + state + '.ico'), ico(logo.replace('</svg>', badge + '</svg>')));
