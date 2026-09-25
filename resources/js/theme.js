export const themeStorageKey = 'digital-library-theme';

export function initializeTheme(browser = window) {
    const root = browser.document.documentElement;
    const systemTheme = browser.matchMedia('(prefers-color-scheme: dark)');
    let preference = null;
    try {
        preference = browser.localStorage.getItem(themeStorageKey);
    } catch { /* Storage may be disabled by browser privacy settings. */ }
    if (!['light', 'dark'].includes(preference)) preference = null;

    function apply() {
        const theme = preference || (systemTheme.matches ? 'dark' : 'light');
        root.classList.toggle('dark', theme === 'dark');
        root.style.colorScheme = theme;
        browser.dispatchEvent(new browser.Event('theme-changed'));
    }

    function setTheme(theme) {
        if (!['light', 'dark'].includes(theme)) return;
        preference = theme;
        try { browser.localStorage.setItem(themeStorageKey, theme); } catch { /* Keep the in-memory choice. */ }
        apply();
    }

    systemTheme.addEventListener('change', apply);
    browser.addEventListener('storage', event => {
        if (event.key !== themeStorageKey && event.key !== null) return;
        preference = ['light', 'dark'].includes(event.newValue) ? event.newValue : null;
        apply();
    });
    apply();
    return { setTheme };
}
