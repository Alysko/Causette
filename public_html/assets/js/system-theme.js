(() => {
    const systemTheme = window.matchMedia('(prefers-color-scheme: dark)');
    const root = document.documentElement;
    const applyTheme = ({ matches }) => {
        root.dataset.theme = matches ? 'dark' : 'light';
        root.classList.toggle('dark', matches);
    };
    applyTheme(systemTheme);
    systemTheme.addEventListener('change', applyTheme);
})();
