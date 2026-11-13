import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';
import kotakMasuk from './kotak-masuk';
import kalenderPkpt from './kalender';
import countUp from './countup';
import inputRupiah from './input-rupiah';
import { grafikRealisasi, grafikStatus } from './grafik';

// Livewire's bundled Alpine already includes intersect, focus and sort; do not register them again.

// Mobile off-canvas sidebar: the hamburger lives in the header, the sidebar inside @persist('sidebar').
Alpine.store('nav', { open: false });

// Livewire keeps data-current in sync on persisted links; mirror it to aria-current for screen readers.
document.addEventListener('livewire:navigated', () => {
    Alpine.store('nav').open = false;
    document.querySelectorAll('[data-nav-link]').forEach((a) =>
        a.hasAttribute('data-current') ? a.setAttribute('aria-current', 'page') : a.removeAttribute('aria-current'));
});

Alpine.data('kotakMasuk', kotakMasuk);
Alpine.data('kalenderPkpt', kalenderPkpt);
Alpine.data('countUp', countUp);
Alpine.data('inputRupiah', inputRupiah);
Alpine.data('grafikRealisasi', grafikRealisasi);
Alpine.data('grafikStatus', grafikStatus);

Livewire.start();
