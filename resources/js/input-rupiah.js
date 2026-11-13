// Mirrors App\Support\Rupiah::baca: drop whitespace and a leading "Rp", thousands dots and the part after the comma; what is left must be digits.
// Returns digits ('' = empty) or null when the text is not a Rupiah amount (the server then answers with the exact message).
export function bacaRupiah(teks) {
    const t = String(teks ?? '').replace(/\s+/g, '').replace(/^rp/i, '');
    const m = t.match(/^([\d.]*)(?:,(\d*))?$/);
    if (!m) return null;
    const digit = m[1].replace(/\./g, '');
    if (digit === '') return t === '' || t === '.' ? '' : null;
    return digit.replace(/^0+(?=\d)/, '');
}

export const formatRupiah = (digit) => (digit === '' ? '' : 'Rp' + digit.replace(/\B(?=(\d{3})+(?!\d))/g, '.'));

// x-ui.input-rupiah: the visible input shows "Rp600.000"; the hidden wire:model input only ever holds pure digits or '' (or the raw text when it is not an amount).
export default () => ({
    saat(e) {
        const el = e.target;
        const titik = el.selectionStart ?? el.value.length;
        const digitKiri = (el.value.slice(0, titik).match(/\d/g) ?? []).length;
        // Typing before our own "Rp" prefix (Home, then a digit) leaves it mid-text: drop it once and parse again.
        const digit = bacaRupiah(el.value) ?? (/\d.*rp/i.test(el.value.replace(/\s/g, '')) ? bacaRupiah(el.value.replace(/rp/i, '')) : null);
        this.kirim(digit ?? el.value.trim());
        if (digit === null) return; // keep what was typed; the server shows the field error
        el.value = formatRupiah(digit);
        let pos = 0, n = 0;
        while (pos < el.value.length && n < digitKiri) if (/\d/.test(el.value[pos++])) n++;
        el.setSelectionRange(pos, pos);
    },
    kirim(nilai) {
        const h = this.$refs.nilai;
        if (h.value === nilai) return;
        h.value = nilai;
        h.dispatchEvent(new Event('input', { bubbles: true }));
    },
});
