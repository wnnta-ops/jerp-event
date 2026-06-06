import './bootstrap';

// Theme bootstrap — apply persisted theme before paint to avoid FOUC
(function () {
    const saved = localStorage.getItem('jerp-theme');
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    const mode = saved ?? (prefersDark ? 'dark' : 'light');
    document.documentElement.classList.toggle('dark', mode === 'dark');
})();

// Livewire 3 already bundles Alpine + @alpinejs/{focus, persist, morph,
// collapse, intersect, anchor}. We must NOT re-register them or it throws
// "Cannot redefine property" and kills wire:model bindings.
document.addEventListener('alpine:init', () => {
    window.Alpine.store('theme', {
        get current() {
            return document.documentElement.classList.contains('dark') ? 'dark' : 'light';
        },
        toggle() {
            const next = this.current === 'dark' ? 'light' : 'dark';
            document.documentElement.classList.toggle('dark', next === 'dark');
            localStorage.setItem('jerp-theme', next);
        },
    });
});
