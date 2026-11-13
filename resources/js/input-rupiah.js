// Mirrors App\Support\Rupiah::baca: drop whitespace and a leading "Rp"/"Rp.", then the part after the comma; dots are thousands groups only
// (digits, or 1-3 digits followed by ".ddd" groups), so "600000.00", "1.5" and "1.2.3" are not amounts.
// Returns digits ('' = empty) or null when the text is not a Rupiah amount (the server then answers with the exact message).
export function bacaRupiah(teks) {
    const t = String(teks ?? '').replace(/\s+/g, '').replace(/^rp\.?/i, '');
    const m = t.match(/^([\d.]*)(?:,(\d*))?$/);
    if (!m) return null;
    if (m[1] === '') return t === '' ? '' : null;
    if (!/^(?:\d+|\d{1,3}(?:\.\d{3})+)$/.test(m[1])) return null;
    return m[1].replace(/\./g, '').replace(/^0+(?=\d)/, '');
}

export const formatRupiah = (digit) => (digit === '' ? '' : 'Rp' + digit.replace(/\B(?=(\d{3})+(?!\d))/g, '.'));

// x-ui.input-rupiah: the wrapper is x-modelable (wire:model sits on it), `digit` holds pure digits or '' (or the raw text when it is not an amount).
// The visible input shows "Rp600.000" and is rewritten from `digit` whenever the model changes underneath it (modal reopened, server reset).
export default () => ({
    digit: '',
    sah: true, // the visible text is our own formatting, so its dots are ours (lenient while typing digits); a paste or a typed "."/"," is parsed strictly
    saat(e) {
        const el = e.target;
        const titik = el.selectionStart ?? el.value.length;
        const digitKiri = (el.value.slice(0, titik).match(/\d/g) ?? []).length;
        const sendiri = this.sah && (/^insertText$/.test(e.inputType ?? '') ? /^\d$/.test(e.data ?? '') : /^delete/.test(e.inputType ?? ''));
        // Typing before our own "Rp" prefix (Home, then a digit) leaves it mid-text: drop it once and parse again.
        const digit = bacaRupiah(sendiri ? el.value.replace(/\./g, '') : el.value) ?? (/\d.*rp/i.test(el.value.replace(/\s/g, '')) ? bacaRupiah(el.value.replace(/rp/i, '')) : null);
        this.sah = digit !== null;
        this.digit = digit ?? el.value.trim();
        if (digit === null) return; // keep what was typed; the server shows the field error
        el.value = formatRupiah(digit);
        let pos = 0, n = 0;
        while (pos < el.value.length && n < digitKiri) if (/\d/.test(el.value[pos++])) n++;
        el.setSelectionRange(pos, pos);
    },
    init() {
        const el = this.$root.querySelector('input[type=text]');
        if (el) this.digit = bacaRupiah(el.value) ?? el.value.trim(); // server-rendered text first, so the first selaras() never blanks it
    },
    selaras(digit) {
        const el = this.$root.querySelector('input[type=text]');
        if (!el) return;
        const sekarang = bacaRupiah(el.value);
        if (sekarang === digit || (sekarang === null && el.value.trim() === digit)) return;
        el.value = /^\d*$/.test(digit) ? formatRupiah(digit) : digit;
        this.sah = true;
    },
});
