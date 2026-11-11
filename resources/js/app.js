/**
 * Back and Forward show what is true now, not what was true when the page was
 * left.
 *
 * Every link here is a wire:navigate link, and Livewire answers Back from a
 * copy of the page it kept in memory, without asking the server anything. An
 * order confirmed on its own page was therefore still "pending" in the queue
 * one press of Back away. The copy is still worth having, since it is what
 * makes Back instant, so it is drawn first and every component on it is then
 * asked to render again.
 *
 * The browser's own back/forward cache does the same to a page reached
 * without wire:navigate, which is what the pageshow listener is for.
 */
let restoredFromHistory = false;

document.addEventListener('livewire:navigate', (event) => {
    restoredFromHistory = Boolean(event.detail?.history && event.detail?.cached);
});

document.addEventListener('livewire:navigated', () => {
    if (!restoredFromHistory) {
        return;
    }

    restoredFromHistory = false;

    refreshEveryComponent();
});

window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
        refreshEveryComponent();
    }
});

function refreshEveryComponent() {
    window.Livewire?.all().forEach((component) => component.$wire.$refresh());
}
