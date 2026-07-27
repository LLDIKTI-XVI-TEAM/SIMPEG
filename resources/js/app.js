import './bootstrap';
import Alpine from 'alpinejs';
import * as Turbo from "@hotwired/turbo";

window.Alpine = Alpine;
Alpine.start();

// Tampilkan skeleton loading saat transisi halaman dengan Turbo
document.addEventListener("turbo:visit", () => {
    const mainContent = document.getElementById('main-content');
    const skeleton = document.getElementById('global-skeleton');
    
    if (skeleton && mainContent) {
        mainContent.classList.add('hidden');
        skeleton.classList.remove('hidden');
    }
});
