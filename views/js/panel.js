/**
 * Quince CMP: warstwa panelu.
 *
 * Własna kopia skryptu z `quince_engine`, nie odnośnik do niego: moduł ma
 * działać w sklepie, w którym silnika nie ma.
 *
 * Przechwytuje komunikaty PrestaShopa i pokazuje je jako toast w prawym górnym rogu.
 * Natywne alerty rozpychają układ: wchodzą nad treść, przesuwają wszystko w dół
 * i zostają na ekranie do przeładowania. Toast informuje i znika.
 */
(function () {
    'use strict';

    var TYPES = {
        'alert-success': 'success',
        'alert-danger':  'danger',
        'alert-warning': 'warning',
        'alert-info':    'info'
    };

    var ICONS = {
        success: 'M5.3 8.2l1.8 1.8 3.6-4.4M14.5 8A6.5 6.5 0 111.5 8a6.5 6.5 0 0113 0z',
        danger:  'M8 5v4m0 3h.01M14.5 8A6.5 6.5 0 111.5 8a6.5 6.5 0 0113 0z',
        warning: 'M8 6v3m0 2.5h.01M8.9 2.6l6 10.4a1 1 0 01-.9 1.5H2a1 1 0 01-.9-1.5l6-10.4a1 1 0 011.8 0z',
        info:    'M8 7v4m0-6.5h.01M14.5 8A6.5 6.5 0 111.5 8a6.5 6.5 0 0113 0z'
    };

    function stack() {
        var el = document.querySelector('.qc-toasts');
        if (!el) {
            el = document.createElement('div');
            el.className = 'qc-toasts';
            document.body.appendChild(el);
        }
        return el;
    }

    /**
     * Przepisuje treść alertu PrestaShopa, zachowując jej strukturę.
     * Komunikaty bywają wielopunktowe (lista błędów walidacji) albo zawierają odnośnik
     * do miejsca, w którym trzeba coś poprawić. Spłaszczenie do jednego zdania gubi
     * jedno i drugie. Przepuszczamy wyłącznie znaczniki z listy: alert idzie do nas
     * jako gotowy HTML, więc wstawienie go bez filtra byłoby otwarciem na wstrzyknięcie.
     */
    var ALLOWED = {
        A: ['href', 'target', 'rel'],
        STRONG: [], B: [], EM: [], I: [], U: [], SMALL: [], CODE: [], BR: [],
        UL: [], OL: [], LI: [], P: [], SPAN: [], DIV: []
    };

    function sanitize(node) {
        Array.prototype.slice.call(node.childNodes).forEach(function (child) {
            if (child.nodeType === Node.TEXT_NODE) {
                return;
            }
            if (child.nodeType !== Node.ELEMENT_NODE) {
                child.remove();
                return;
            }

            var allowed = ALLOWED[child.tagName];
            if (!allowed) {
                // Znacznik odpada, ale jego treść zostaje. Inaczej zniknąłby cały komunikat
                // opakowany w nieznany kontener.
                while (child.firstChild) {
                    node.insertBefore(child.firstChild, child);
                }
                child.remove();
                return;
            }

            Array.prototype.slice.call(child.attributes).forEach(function (attr) {
                if (allowed.indexOf(attr.name) === -1) {
                    child.removeAttribute(attr.name);
                }
            });

            // Odnośniki tylko na zwykłe adresy, javascript: i data: wypadają.
            var href = child.getAttribute && child.getAttribute('href');
            if (href && !/^(https?:|\/|#|mailto:)/i.test(href.trim())) {
                child.removeAttribute('href');
            }

            sanitize(child);
        });

        return node;
    }

    /**
     * @param {string|Node} message tekst albo gotowy węzeł z treścią komunikatu
     */
    function toast(message, type) {
        var el = document.createElement('div');
        el.className = 'qc-toast qc-toast--' + type;
        el.setAttribute('role', type === 'danger' ? 'alert' : 'status');
        el.innerHTML =
            '<svg class="qc-toast-icon" width="16" height="16" viewBox="0 0 16 16" fill="none" ' +
            'stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' +
            '<path d="' + ICONS[type] + '"/></svg>' +
            '<span class="qc-toast-text"></span>' +
            '<button type="button" class="qc-toast-close" aria-label="Close">&times;</button>';
        var body = el.querySelector('.qc-toast-text');
        if (typeof message === 'string') {
            body.textContent = message;
        } else {
            body.appendChild(message);
        }

        var close = function () {
            el.classList.add('is-leaving');
            setTimeout(function () { el.remove(); }, 200);
        };
        el.querySelector('.qc-toast-close').addEventListener('click', close);

        stack().appendChild(el);
        requestAnimationFrame(function () { el.classList.add('is-visible'); });

        // Błędy zostają: użytkownik musi je przeczytać i zwykle coś poprawić.
        if (type !== 'danger') {
            setTimeout(close, 4500);
        }
    }

    /**
     * Mierzy, jak wysoko sięgają przyklejone elementy PrestaShopa, i zapisuje wynik
     * w --qc-top-offset. Sztywna liczba nie wystarcza: na górze siedzi czarny pasek,
     * breadcrumb i tytuł strony, a ich łączna wysokość zmienia się z długością tytułu
     * i szerokością okna.
     */
    function syncTopOffset() {
        var root = document.querySelector('.qc-root');
        if (!root) {
            return;
        }

        var bottom = 0;
        document.querySelectorAll('body *').forEach(function (el) {
            if (el.closest('.qc-root')) {
                return;                        // nasze własne elementy nie liczą się
            }
            var pos = getComputedStyle(el).position;
            if (pos !== 'fixed' && pos !== 'sticky') {
                return;
            }
            var r = el.getBoundingClientRect();

            // Paski układają się piętrowo: czarny z wyszukiwarką stoi na top: 0,
            // biały z tytułem strony dopiero pod nim. Dlatego liczy się najniższa
            // krawędź wszystkiego, co trzyma się górnej części ekranu, a nie tylko
            // elementu stojącego na samej górze.
            if (r.height > 0 && r.top >= -2 && r.top < 240
                && r.bottom > bottom && r.bottom < window.innerHeight / 2) {
                bottom = r.bottom;
            }
        });

        document.documentElement.style.setProperty('--qc-top-offset', Math.round(bottom) + 'px');
    }

    /**
     * Mierzy, co przykleja się do DOŁU ekranu: w praktyce pasek debugowania Symfony,
     * który w trybie deweloperskim zasłaniałby pasek akcji. Ma inną wysokość na
     * desktopie i na telefonie, a na produkcji nie ma go wcale.
     *
     * Bez paska zostaje sam prześwit, więc akcje nie wiszą wysoko nad krawędzią.
     */
    function syncBottomOffset() {
        var GAP = 14;
        var height = 0;

        document.querySelectorAll('body *').forEach(function (el) {
            if (el.closest('.qc-root') || el.closest('.qc-toasts')) {
                return;                        // nasze elementy się nie liczą
            }
            var pos = getComputedStyle(el).position;
            if (pos !== 'fixed' && pos !== 'sticky') {
                return;
            }
            var r = el.getBoundingClientRect();
            // Przyklejone do dolnej krawędzi, o sensownej wysokości i szerokości
            if (r.height > 0 && r.height < 200
                && Math.abs(r.bottom - window.innerHeight) < 4
                && r.width > window.innerWidth * 0.5
                && r.height > height) {
                height = r.height;
            }
        });

        document.documentElement.style.setProperty('--qc-bottom-offset', Math.round(height + GAP) + 'px');
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Działamy tylko na stronach Quince, żeby nie zmieniać zachowania reszty panelu.
        if (!document.querySelector('.qc-root')) {
            return;
        }

        var sync = function () { syncTopOffset(); syncBottomOffset(); };

        sync();
        window.addEventListener('resize', sync);
        // Paski doklejają się dopiero po przewinięciu, więc pomiar się powtarza.
        window.addEventListener('scroll', sync, { passive: true });
        // Pasek debugowania Symfony montuje się asynchronicznie, już po DOMContentLoaded.
        setTimeout(sync, 400);
        setTimeout(sync, 1500);

        document.querySelectorAll('.alert').forEach(function (alert) {
            if (alert.closest('.qc-root')) {
                return;                       // nasze własne alerty zostawiamy w spokoju
            }

            var type = null;
            Object.keys(TYPES).forEach(function (cls) {
                if (alert.classList.contains(cls)) {
                    type = TYPES[cls];
                }
            });
            if (!type) {
                return;
            }

            // Bierzemy całą treść alertu (z listami błędów i odnośnikami), odrzucając
            // wyłącznie sterowanie samego alertu (przycisk zamknięcia, ikony materialowe).
            var clone = alert.cloneNode(true);
            clone.querySelectorAll('button, .close, svg, .material-icons').forEach(function (n) { n.remove(); });

            var content = document.createDocumentFragment();
            var source = clone.querySelector('.alert-text') || clone;
            while (source.firstChild) {
                content.appendChild(source.firstChild);
            }
            sanitize(content);

            if (content.textContent.trim()) {
                toast(content, type);
                alert.remove();
            }
        });
    });

    window.quinceToast = toast;
})();

/**
 * Nawigacja na telefonie.
 *
 * Poniżej 767 px lista pozycji jest schowana, a nad treścią stoi przycisk
 * z nazwą bieżącej strony. Obsługa siedzi tutaj, a nie w Alpine, bo Alpine
 * ładują poszczególne widoki, a nawigacja ma działać na każdym z nich.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var toggle = document.querySelector('.qc-nav-mobile');
        var shell = document.querySelector('.qc-shell');

        if (!toggle || !shell) {
            return;
        }

        toggle.addEventListener('click', function () {
            var open = shell.classList.toggle('qc-nav-open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        // Wybór pozycji przenosi na inną stronę, ale gdy ktoś dotknie pozycji
        // zaplanowanej albo bieżącej, nic się nie dzieje i lista zostaje otwarta.
        // Zamknięcie po dotknięciu poza nią jest wtedy jedynym wyjściem.
        document.addEventListener('click', function (event) {
            if (!shell.classList.contains('qc-nav-open')) {
                return;
            }

            if (!event.target.closest('#qc-sidenav') && !event.target.closest('.qc-nav-mobile')) {
                shell.classList.remove('qc-nav-open');
                toggle.setAttribute('aria-expanded', 'false');
            }
        });
    });

    /*
     * Potwierdzenie przed operacją, której nie da się cofnąć.
     *
     * Natywne `confirm` zamiast własnego okna: blokuje wysłanie formularza bez
     * przechwytywania zdarzenia, więc przycisk działa tak samo, gdy skrypt się
     * nie wczyta.
     */
    document.addEventListener('click', function (event) {
        var el = event.target.closest('[data-qc-confirm]');

        if (el && !window.confirm(el.getAttribute('data-qc-confirm'))) {
            event.preventDefault();
        }
    });
})();
