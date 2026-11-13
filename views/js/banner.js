/**
 * Quince CMP: baner i okno preferencji.
 *
 * Rysowane w przeglądarce, nie w szablonie sklepu: motywów jest tyle, ile
 * sklepów, a strona bywa serwowana z pamięci podręcznej i nie zna stanu zgody
 * tego konkretnego odwiedzającego.
 *
 * Warstwa opiera się na `window.QuinceCMP` z `front.js`. Sama nie zapisuje
 * ciasteczek i nie rozmawia z serwerem.
 */
(function () {
    'use strict';

    var cfg = (window.quinceCmpConfig || {}).banner || {};
    var api = window.QuinceCMP;

    if (!api || !cfg.categories || !cfg.categories.length) {
        return;
    }

    var T = cfg.texts || {};
    var C = cfg.colours || {};
    var korzen = null;
    var wybor = null;

    /* ================================================================
       POMOCNICZE
       ================================================================ */

    function el(tag, klasa, tekst) {
        var n = document.createElement(tag);

        if (klasa) { n.className = klasa; }
        if (tekst !== undefined) { n.textContent = tekst; }

        return n;
    }

    function wymagane() {
        return cfg.categories.filter(function (k) { return k.required; })
            .map(function (k) { return k.code; });
    }

    function wszystkie() {
        return cfg.categories.map(function (k) { return k.code; });
    }

    /* ================================================================
       SZKIELET

       Kolory sprzedawcy wchodzą jako zmienne CSS na korzeniu. Reszta arkusza
       jest stała, więc motyw sklepu nie ma czego nadpisać.
       ================================================================ */

    function korzenBanera() {
        if (korzen) {
            return korzen;
        }

        korzen = el('div', 'qcmp-root');
        korzen.setAttribute('data-placement', cfg.placement || 'bar');
        korzen.style.setProperty('--qcmp-accent', C.accent || '#f49000');
        korzen.style.setProperty('--qcmp-on-accent', C.onAccent || '#2b1c00');
        korzen.style.setProperty('--qcmp-surface', C.surface || '#ffffff');
        korzen.style.setProperty('--qcmp-text', C.text || '#1b1d20');
        document.body.appendChild(korzen);

        return korzen;
    }

    function wyczysc() {
        if (korzen) {
            korzen.innerHTML = '';
        }
    }

    /* ================================================================
       WARSTWA PIERWSZA
       ================================================================ */

    function pokazBaner() {
        var root = korzenBanera();
        wyczysc();

        var karta = el('div', 'qcmp-card');
        karta.setAttribute('role', 'dialog');
        karta.setAttribute('aria-modal', 'false');
        karta.setAttribute('aria-labelledby', 'qcmp-title');

        var tytul = el('div', 'qcmp-title', T.title || '');
        tytul.id = 'qcmp-title';
        karta.appendChild(tytul);

        var tresc = el('div', 'qcmp-body');
        tresc.appendChild(document.createTextNode((T.body || '') + ' '));

        if (cfg.policyUrl) {
            var link = el('a', 'qcmp-link', T.policy || 'Privacy policy');
            link.href = cfg.policyUrl;
            tresc.appendChild(link);
        }

        karta.appendChild(tresc);

        // Trzy przyciski o jednakowej wadze. Odrzucenie schowane pod dodatkowym
        // kliknięciem albo w słabszym kolorze to zgoda wymuszona, nie udzielona.
        var akcje = el('div', 'qcmp-actions');
        akcje.appendChild(przycisk(T.reject || 'Reject all', 'qcmp-btn', function () {
            zapisz(wymagane());
        }));
        akcje.appendChild(przycisk(T.prefs || 'Preferences', 'qcmp-btn', pokazPreferencje));
        akcje.appendChild(przycisk(T.accept || 'Accept all', 'qcmp-btn qcmp-btn-primary', function () {
            zapisz(wszystkie());
        }));

        karta.appendChild(akcje);
        root.appendChild(karta);
        root.setAttribute('data-open', 'banner');

        karta.querySelector('button').focus();
    }

    function przycisk(napis, klasa, fn) {
        var b = el('button', klasa, napis);
        b.type = 'button';
        b.addEventListener('click', fn);

        return b;
    }

    /* ================================================================
       WARSTWA DRUGA
       ================================================================ */

    function pokazPreferencje() {
        var root = korzenBanera();
        wyczysc();

        wybor = wybor || api.state().codes.slice();
        wymagane().forEach(function (kod) {
            if (wybor.indexOf(kod) === -1) { wybor.push(kod); }
        });

        var tlo = el('div', 'qcmp-backdrop');
        var okno = el('div', 'qcmp-modal');
        okno.setAttribute('role', 'dialog');
        okno.setAttribute('aria-modal', 'true');
        okno.setAttribute('aria-labelledby', 'qcmp-prefs-title');

        var glowa = el('div', 'qcmp-modal-head');
        var tytul = el('div', 'qcmp-title', T.prefsTitle || '');
        tytul.id = 'qcmp-prefs-title';
        glowa.appendChild(tytul);
        glowa.appendChild(el('div', 'qcmp-body', T.prefsBody || ''));
        okno.appendChild(glowa);

        var lista = el('div', 'qcmp-list');
        cfg.categories.forEach(function (kat) { lista.appendChild(wierszKategorii(kat)); });
        okno.appendChild(lista);

        var stopka = el('div', 'qcmp-modal-foot');
        stopka.appendChild(przycisk(T.reject || 'Reject all', 'qcmp-btn', function () {
            zapisz(wymagane());
        }));
        stopka.appendChild(przycisk(T.save || 'Save my choices', 'qcmp-btn', function () {
            zapisz(wybor);
        }));
        stopka.appendChild(przycisk(T.accept || 'Accept all', 'qcmp-btn qcmp-btn-primary', function () {
            zapisz(wszystkie());
        }));
        okno.appendChild(stopka);

        // Zamknięcie bez decyzji tylko wtedy, gdy decyzja już kiedyś zapadła
        // albo stoimy na stronie polityki. Inaczej okno musi doczekać wyboru.
        if (api.state().decided || cfg.onPolicyPage) {
            var zamknij = przycisk('×', 'qcmp-close', function () { schowaj(); });
            zamknij.setAttribute('aria-label', T.close || 'Close');
            okno.appendChild(zamknij);
        }

        tlo.appendChild(okno);
        root.appendChild(tlo);
        root.setAttribute('data-open', 'modal');

        pulapkaFokusu(okno);
        okno.querySelector('button').focus();
    }

    function wierszKategorii(kat) {
        var wiersz = el('div', 'qcmp-cat');

        var glowa = el('div', 'qcmp-cat-head');
        var opis = el('div');
        opis.appendChild(el('div', 'qcmp-cat-name', kat.name));

        if (kat.cookies.length) {
            var ile = el('button', 'qcmp-cat-toggle', kat.cookies.length + ' ' + (kat.cookies.length === 1 ? 'cookie' : 'cookies'));
            ile.type = 'button';
            opis.appendChild(ile);
        }

        glowa.appendChild(opis);

        if (kat.required) {
            glowa.appendChild(el('span', 'qcmp-always', T.always || 'Always active'));
        } else {
            glowa.appendChild(przelacznik(kat.code));
        }

        wiersz.appendChild(glowa);

        if (kat.cookies.length) {
            var tabela = tabelaCiasteczek(kat.cookies);
            wiersz.appendChild(tabela);
            glowa.querySelector('.qcmp-cat-toggle').addEventListener('click', function () {
                var otwarte = tabela.getAttribute('data-open') === '1';
                tabela.setAttribute('data-open', otwarte ? '0' : '1');
            });
        }

        return wiersz;
    }

    function przelacznik(kod) {
        var etykieta = el('label', 'qcmp-switch');
        var pole = el('input');
        pole.type = 'checkbox';
        pole.checked = wybor.indexOf(kod) !== -1;
        pole.addEventListener('change', function () {
            if (pole.checked) {
                if (wybor.indexOf(kod) === -1) { wybor.push(kod); }
            } else {
                wybor = wybor.filter(function (k) { return k !== kod; });
            }
        });

        etykieta.appendChild(pole);
        etykieta.appendChild(el('span', 'qcmp-switch-track'));

        return etykieta;
    }

    function tabelaCiasteczek(lista) {
        var box = el('div', 'qcmp-cookies');
        box.setAttribute('data-open', '0');

        lista.forEach(function (c) {
            var w = el('div', 'qcmp-cookie');
            w.appendChild(el('code', 'qcmp-cookie-name', c.name));
            w.appendChild(el('span', 'qcmp-cookie-meta', [c.provider, c.lifetime].filter(Boolean).join(' · ')));

            if (c.description) {
                w.appendChild(el('span', 'qcmp-cookie-desc', c.description));
            }

            box.appendChild(w);
        });

        return box;
    }

    /* ================================================================
       PŁYWAJĄCY PRZYCISK
       ================================================================ */

    function pokazPlywajacy() {
        if (!cfg.fab || !cfg.fab.on) {
            return;
        }

        var root = korzenBanera();

        if (root.querySelector('.qcmp-fab')) {
            return;
        }

        var b = el('button', 'qcmp-fab');
        b.type = 'button';
        b.setAttribute('data-position', cfg.fab.position || 'left');
        b.setAttribute('aria-label', cfg.fab.label || 'Cookie settings');

        // Odległość od dolnej krawędzi idzie jako zmienne CSS, a wybór między
        // komputerem a telefonem zostaje w arkuszu. Tak próg szerokości ekranu
        // mieszka w jednym miejscu, a przycisk przesuwa się też przy obróceniu
        // telefonu, bez nasłuchiwania zmian rozmiaru okna.
        if (typeof cfg.fab.offsetDesktop === 'number') {
            b.style.setProperty('--qcmp-fab-bottom', cfg.fab.offsetDesktop + 'px');
        }

        if (typeof cfg.fab.offsetMobile === 'number') {
            b.style.setProperty('--qcmp-fab-bottom-m', cfg.fab.offsetMobile + 'px');
        }
        // Własny znak jest już oczyszczony po stronie serwera, tutaj trafia
        // wprost, bo inaczej nie dałoby się go narysować.
        b.innerHTML = cfg.fab.svg || ikona(cfg.fab.icon);

        if (cfg.fab.mode !== 'icon') {
            b.appendChild(el('span', 'qcmp-fab-label', cfg.fab.label || ''));
        }

        b.addEventListener('click', pokazPreferencje);
        root.appendChild(b);
    }

    function ikona(nazwa) {
        var sciezki = {
            cookie: 'M14.4 8.3A6.5 6.5 0 118 1.5a2.7 2.7 0 003.3 3.1 2.7 2.7 0 003.1 3.7zM5.8 6.4h.01M9.4 9.6h.01M5.9 11.2h.01',
            shield: 'M8 1.8 3 3.6v4.1c0 3 2.1 5.4 5 6.5 2.9-1.1 5-3.5 5-6.5V3.6L8 1.8zM5.8 8l1.6 1.6L10.4 6',
            settings: 'M8 10.2a2.2 2.2 0 100-4.4 2.2 2.2 0 000 4.4zM12.9 8a4.9 4.9 0 01-.06.75l1.4 1.1-1.4 2.4-1.6-.5c-.4.35-.86.63-1.36.83L9.5 14h-3l-.38-1.42a4.9 4.9 0 01-1.36-.83l-1.6.5-1.4-2.4 1.4-1.1A5 5 0 013.1 8c0-.25.02-.5.06-.75l-1.4-1.1 1.4-2.4 1.6.5c.4-.35.86-.63 1.36-.83L6.5 2h3l.38 1.42c.5.2.96.48 1.36.83l1.6-.5 1.4 2.4-1.4 1.1c.04.25.06.5.06.75z',
            eye: 'M1.5 8S4 3.5 8 3.5 14.5 8 14.5 8 12 12.5 8 12.5 1.5 8 1.5 8zM8 9.7A1.7 1.7 0 108 6.3a1.7 1.7 0 000 3.4z',
            info: 'M8 7v4m0-6.5h.01M14.5 8A6.5 6.5 0 111.5 8a6.5 6.5 0 0113 0z',
            user: 'M8 8.2a2.7 2.7 0 100-5.4 2.7 2.7 0 000 5.4zM2.8 14c0-2.5 2.3-4.2 5.2-4.2s5.2 1.7 5.2 4.2'
        };

        return '<svg viewBox="0 0 16 16" width="18" height="18" fill="none" stroke="currentColor" '
            + 'stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            + '<path d="' + (sciezki[nazwa] || sciezki.cookie) + '"/></svg>';
    }

    /* ================================================================
       ZAPIS I ZAMKNIĘCIE
       ================================================================ */

    function zapisz(kody) {
        api.set(kody).then(function () {
            schowaj();
            pokazPlywajacy();
        });
    }

    function schowaj() {
        wyczysc();

        if (korzen) {
            korzen.removeAttribute('data-open');
        }

        wybor = null;
        pokazPlywajacy();
    }

    /**
     * Fokus zamknięty w oknie preferencji.
     *
     * Okno zasłania stronę, więc klawiatura nie może z niego wyjść. Inaczej
     * użytkownik klawiatury wędruje po elementach, których nie widzi.
     */
    function pulapkaFokusu(okno) {
        okno.addEventListener('keydown', function (e) {
            if (e.key !== 'Tab') {
                return;
            }

            var pola = okno.querySelectorAll('button, input, a[href]');

            if (!pola.length) {
                return;
            }

            var pierwszy = pola[0];
            var ostatni = pola[pola.length - 1];

            if (e.shiftKey && document.activeElement === pierwszy) {
                e.preventDefault();
                ostatni.focus();
            } else if (!e.shiftKey && document.activeElement === ostatni) {
                e.preventDefault();
                pierwszy.focus();
            }
        });
    }

    /* ================================================================
       START
       ================================================================ */

    api.open = pokazPreferencje;
    document.addEventListener('quince:open', pokazPreferencje);

    function start() {
        if (cfg.show && !cfg.onPolicyPage) {
            pokazBaner();
        }

        pokazPlywajacy();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
