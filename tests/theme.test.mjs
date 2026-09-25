import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { initializeTheme, themeStorageKey } from '../resources/js/theme.js';

function browserFixture({ saved = null, systemDark = false, blocked = false } = {}) {
    const browser = new EventTarget();
    const system = new EventTarget();
    system.matches = systemDark;
    const classes = new Set();
    const root = { style: {}, classList: {
        toggle(name, active) { if (active) classes.add(name); else classes.delete(name); },
        contains(name) { return classes.has(name); },
    } };
    browser.Event = Event;
    browser.document = { documentElement: root };
    browser.matchMedia = () => system;
    browser.localStorage = {
        getItem(key) { assert.equal(key, themeStorageKey); if (blocked) throw Error('blocked'); return saved; },
        setItem(key, value) { assert.equal(key, themeStorageKey); if (blocked) throw Error('blocked'); saved = value; },
    };
    return { browser, root, system, saved: () => saved };
}

test('saved choice overrides system and persists toggles across reloads', () => {
    const fixture = browserFixture({ saved: 'light', systemDark: true });
    const theme = initializeTheme(fixture.browser);
    assert.equal(fixture.root.style.colorScheme, 'light');
    theme.setTheme('dark');
    assert.equal(fixture.root.classList.contains('dark'), true);
    assert.equal(fixture.saved(), 'dark');
    const reload = browserFixture({ saved: fixture.saved() });
    initializeTheme(reload.browser);
    assert.equal(reload.root.style.colorScheme, 'dark');
    theme.setTheme('light');
    assert.equal(fixture.root.classList.contains('dark'), false);
});

test('follows system until a manual choice and ignores invalid choices', () => {
    const fixture = browserFixture({ saved: 'invalid' });
    const theme = initializeTheme(fixture.browser);
    fixture.system.matches = true;
    fixture.system.dispatchEvent(new Event('change'));
    assert.equal(fixture.root.style.colorScheme, 'dark');
    theme.setTheme('light');
    theme.setTheme('invalid');
    fixture.system.dispatchEvent(new Event('change'));
    assert.equal(fixture.root.style.colorScheme, 'light');
});

test('storage denial does not prevent switching themes or notify failures', () => {
    const fixture = browserFixture({ blocked: true });
    let notifications = 0;
    fixture.browser.addEventListener('theme-changed', () => notifications++);
    const theme = initializeTheme(fixture.browser);
    theme.setTheme('dark');
    assert.equal(fixture.root.style.colorScheme, 'dark');
    assert.equal(notifications, 2);
});

test('synchronizes preferences across tabs and respects storage reset', () => {
    const fixture = browserFixture();
    initializeTheme(fixture.browser);
    const change = (key, newValue) => {
        const event = new Event('storage');
        Object.assign(event, { key, newValue });
        fixture.browser.dispatchEvent(event);
    };
    change(themeStorageKey, 'dark');
    assert.equal(fixture.root.style.colorScheme, 'dark');
    change('unrelated', 'light');
    assert.equal(fixture.root.style.colorScheme, 'dark');
    change(null, null);
    assert.equal(fixture.root.style.colorScheme, 'light');
});

test('theme controls and branded layouts compile', async () => {
    const { parse, compileTemplate } = await import('@vue/compiler-sfc');
    for (const file of ['Components/ThemeToggle', 'Components/ApplicationLogo', 'Layouts/GuestLayout', 'Layouts/AuthenticatedLayout', 'Pages/Catalog/Index']) {
        const filename = new URL(`../resources/js/${file}.vue`, import.meta.url);
        const source = readFileSync(filename, 'utf8');
        const { descriptor, errors } = parse(source);
        assert.deepEqual(errors, []);
        assert.deepEqual(compileTemplate({ source: descriptor.template.content, filename: filename.pathname, id: file }).errors, []);
        if (file.startsWith('Layouts/') || file.startsWith('Pages/')) assert.match(source, /<ThemeToggle/);
        if (file.endsWith('ApplicationLogo')) assert.match(source, /digital-library-logo\.png/);
        if (file === 'Pages/Catalog/Index') assert.match(source, /v-model="form.search" class="min-w-0/);
    }
});
