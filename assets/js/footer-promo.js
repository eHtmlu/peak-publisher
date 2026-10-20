// Footer promo - the day's promotion in the page footer (AdminUI::render_peak_publisher()).
// The server renders both notes and shows the one of the UTC day it rendered on; a tab that
// stays open for days turns the page at midnight UTC. The rule is the server's
// (footer_promo_index()): the day's number since the epoch, even or odd, picks the note, so
// every reader sees the same one on a given day. The timer waits for the next midnight; a tab
// in the background may have slept past it, so a return to visibility reads the day anew.
(() => {
    const DAY = 86400000;
    const notes = Array.from(document.querySelectorAll('.pblsh--footer__promo [data-pblsh-promo]'));
    if (notes.length === 0) return;
    let timer = null;
    const turn = () => {
        const now = Date.now();
        const index = Math.floor(now / DAY) % 2;
        notes.forEach((note) => { note.hidden = Number(note.dataset.pblshPromo) !== index; });
        clearTimeout(timer);
        timer = setTimeout(turn, DAY - (now % DAY) + 1000);
    };
    turn();
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') turn(); });
})();
