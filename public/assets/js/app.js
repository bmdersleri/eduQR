// eduQR app-level JS — theme toggle with persistence.
// Loaded as <script type="module"> from layouts. Keep dependency-free.

const THEME_KEY = 'eduqr-theme';
const root = document.documentElement;

/** Resolve the stored theme, falling back to the OS preference. */
function preferredTheme() {
    const saved = localStorage.getItem(THEME_KEY);
    if (saved === 'dark' || saved === 'light') {
        return saved;
    }
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

/** Apply a theme to <html> and sync every toggle button's label. */
function applyTheme(theme) {
    if (theme === 'dark') {
        root.setAttribute('data-theme', 'dark');
    } else {
        root.removeAttribute('data-theme');
    }

    document.querySelectorAll('[data-theme-toggle]').forEach((btn) => {
        const label = btn.querySelector('[data-theme-label], span');
        if (!label) {
            return;
        }
        // When in dark mode the button offers to switch to light, and vice versa.
        const next = theme === 'dark' ? btn.dataset.labelLight : btn.dataset.labelDark;
        if (next) {
            label.textContent = next;
        }
        btn.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
    });
}

function toggleTheme() {
    const next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    localStorage.setItem(THEME_KEY, next);
    applyTheme(next);
}

// Sync labels on load (the no-flash inline script already set the attribute).
applyTheme(root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light');

document.querySelectorAll('[data-theme-toggle]').forEach((btn) => {
    btn.addEventListener('click', toggleTheme);
});

// Follow OS changes only while the user has no explicit choice stored.
window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
    if (!localStorage.getItem(THEME_KEY)) {
        applyTheme(e.matches ? 'dark' : 'light');
    }
});
