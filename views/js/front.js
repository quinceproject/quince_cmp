/**
 * Quince CMP: warstwa sklepu.
 *
 * Trzy zadania: zapisać wybór odwiedzającego, zwolnić usługi, na które padła
 * zgoda, i wystawić stan innym skryptom na stronie.
 *
 * Bez zależności i bez budowania: plik ma się wykonać przed czymkolwiek, co
 * czeka na zgodę, więc nie może czekać na framework.
 */
(function () {
    'use strict';

    var cfg = window.quinceCmpConfig || {};
    var COOKIE = 'quince_cmp';
    var WERSJA = 1;

    /* ================================================================
       STAN
       ================================================================ */

    function czytaj() {
        var m = document.cookie.match(/(?:^|;\s*)quince_cmp=([^;]*)/);

        if (!m) {
            return null;
        }

        try {
            var dane = JSON.parse(decodeURIComponent(m[1]));

            return dane && dane.v === WERSJA ? dane : null;
        } catch (e) {
            return null;
        }
    }

    function przyznane() {
        var dane = czytaj();

        return dane && Array.isArray(dane.c) ? dane.c : [];
    }

    function zdecydowano() {
        return czytaj() !== null;
    }

    /* ================================================================
       ZWALNIANIE WSTRZYMANYCH USŁUG

       Serwer przepisał stronę: skrypty mają `type="text/plain"`, ramki mają
       adres w `data-qc-src`. Tutaj wracają do życia, ale tylko te, których
       kategoria właśnie dostała zgodę.
       ================================================================ */

    function zwolnijSkrypty(kody) {
        var czekajace = document.querySelectorAll('script[type="text/plain"][data-qc-code]');

        Array.prototype.forEach.call(czekajace, function (stary) {
            if (kody.indexOf(stary.getAttribute('data-qc-code')) === -1) {
                return;
            }

            // Podmiana atrybutu nie wystarcza: przeglądarka wykonuje skrypt tylko
            // przy wstawieniu do drzewa, więc znacznik musi powstać na nowo.
            var nowy = document.createElement('script');

            Array.prototype.forEach.call(stary.attributes, function (a) {
                if (a.name !== 'type' && a.name.indexOf('data-qc-') !== 0) {
                    nowy.setAttribute(a.name, a.value);
                }
            });

            nowy.type = stary.getAttribute('data-qc-type') || 'text/javascript';
            nowy.text = stary.text;

            stary.parentNode.replaceChild(nowy, stary);
        });
    }

    function zwolnijRamki(kody) {
        var czekajace = document.querySelectorAll('iframe[data-qc-src][data-qc-code]');

        Array.prototype.forEach.call(czekajace, function (ramka) {
            if (kody.indexOf(ramka.getAttribute('data-qc-code')) === -1) {
                return;
            }

            var zastepnik = ramka.previousElementSibling;

            if (zastepnik && zastepnik.classList.contains('qcmp-placeholder')) {
                zastepnik.parentNode.removeChild(zastepnik);
            }

            ramka.src = ramka.getAttribute('data-qc-src');
            ramka.removeAttribute('data-qc-src');
            ramka.style.display = '';
        });
    }

    /**
     * Zastępnik w miejscu ramki, której nie wolno pobrać.
     *
     * Pusta luka w treści wygląda jak awaria sklepu. Zastępnik mówi, czego
     * brakuje i co zrobić, żeby to zobaczyć. Zgoda udzielana tutaj dotyczy
     * tylko jednej kategorii, nie wszystkiego naraz.
     */
    function wstawZastepniki() {
        var wstrzymane = document.querySelectorAll('iframe[data-qc-src][data-qc-code]');

        Array.prototype.forEach.call(wstrzymane, function (ramka) {
            if (ramka.previousElementSibling && ramka.previousElementSibling.classList.contains('qcmp-placeholder')) {
                return;
            }

            var kod = ramka.getAttribute('data-qc-code');
            var nazwa = ramka.getAttribute('data-qc-name') || '';

            var box = document.createElement('div');
            box.className = 'qcmp-placeholder';
            box.setAttribute('data-qc-code', kod);

            var tytul = document.createElement('div');
            tytul.className = 'qcmp-placeholder-title';
            tytul.textContent = (cfg.placeholder && cfg.placeholder.title) || 'Content blocked';

            var tresc = document.createElement('div');
            tresc.className = 'qcmp-placeholder-body';
            tresc.textContent = ((cfg.placeholder && cfg.placeholder.body) || 'This content needs your consent.')
                + (nazwa ? ' (' + nazwa + ')' : '');

            var akcje = document.createElement('div');
            akcje.className = 'qcmp-placeholder-actions';

            var zgoda = document.createElement('button');
            zgoda.type = 'button';
            zgoda.className = 'qcmp-btn qcmp-btn-primary';
            zgoda.textContent = (cfg.placeholder && cfg.placeholder.allow) || 'Allow and show';
            zgoda.addEventListener('click', function () {
                zapisz(przyznane().concat([kod]));
            });

            var ustawienia = document.createElement('button');
            ustawienia.type = 'button';
            ustawienia.className = 'qcmp-btn';
            ustawienia.textContent = (cfg.placeholder && cfg.placeholder.settings) || 'Preferences';
            ustawienia.addEventListener('click', function () {
                api.open();
            });

            akcje.appendChild(zgoda);
            akcje.appendChild(ustawienia);
            box.appendChild(tytul);
            box.appendChild(tresc);
            box.appendChild(akcje);

            ramka.style.display = 'none';
            ramka.parentNode.insertBefore(box, ramka);
        });
    }

    /* ================================================================
       SYGNAŁY GOOGLE

       Deklaracja `default` poszła już z serwera, w sekcji <head>. Tutaj idzie
       wyłącznie `update`, po decyzji odwiedzającego.
       ================================================================ */

    function aktualizujGoogle(sygnaly) {
        if (!sygnaly) {
            return;
        }

        window.dataLayer = window.dataLayer || [];

        if (typeof window.gtag !== 'function') {
            window.gtag = function () { window.dataLayer.push(arguments); };
        }

        window.gtag('consent', 'update', sygnaly);
        window.dataLayer.push({ event: 'quince_consent_update', quince_consent: sygnaly });
    }

    /* ================================================================
       ZAPIS
       ================================================================ */

    var sluchacze = [];

    function powiadom(stan) {
        sluchacze.forEach(function (fn) {
            try { fn(stan); } catch (e) { /* jeden zepsuty słuchacz nie blokuje reszty */ }
        });

        document.dispatchEvent(new CustomEvent('quince:consent', { detail: stan }));
    }

    /**
     * Wysyła wybór na serwer i dopiero na jego odpowiedzi opiera dalsze kroki.
     *
     * Serwer jest źródłem prawdy: to on zna kategorie wymagane i to on zapisuje
     * dowód. Odblokowanie usług przed potwierdzeniem zapisu dawałoby stan,
     * którego w dzienniku nie ma.
     */
    function zapisz(kody) {
        var unikalne = kody.filter(function (k, i) { return kody.indexOf(k) === i; });

        return fetch(cfg.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'codes=' + encodeURIComponent(unikalne.join(','))
        })
            .then(function (r) { return r.json(); })
            .then(function (dane) {
                if (!dane || !dane.ok) {
                    return null;
                }

                aktualizujGoogle(dane.signals);
                zwolnijSkrypty(dane.codes);
                zwolnijRamki(dane.codes);
                wstawZastepniki();

                var stan = { codes: dane.codes, signals: dane.signals, decided: true };
                powiadom(stan);

                return stan;
            });
    }

    /* ================================================================
       API DLA INNYCH MODUŁÓW

       Tag manager, miernik czy czat mają pytać stąd, zamiast czytać ciasteczko
       na własną rękę: format ciasteczka jest naszą sprawą i może się zmienić.
       ================================================================ */

    var api = {
        /** Pełny stan: kody kategorii, sygnały Google, czy odwiedzający już wybrał. */
        state: function () {
            return { codes: przyznane(), signals: cfg.signals || {}, decided: zdecydowano() };
        },

        /** Czy jest zgoda na wskazaną kategorię. */
        allowed: function (kod) {
            return przyznane().indexOf(kod) !== -1;
        },

        /**
         * Wywołanie po udzieleniu zgody na kategorię.
         *
         * Jeśli zgoda już jest, wywołanie leci natychmiast. Inaczej skrypt
         * dołączony po decyzji nigdy by się nie doczekał.
         */
        whenAllowed: function (kod, fn) {
            if (api.allowed(kod)) {
                fn();

                return;
            }

            api.on(function (stan) {
                if (stan.codes.indexOf(kod) !== -1) {
                    fn();
                }
            });
        },

        /** Nasłuch zmiany zgody. */
        on: function (fn) {
            if (typeof fn === 'function') {
                sluchacze.push(fn);
            }
        },

        /** Zapisuje wskazany zestaw kategorii. */
        set: function (kody) {
            return zapisz(Array.isArray(kody) ? kody : []);
        },

        /** Otwiera okno preferencji. Podstawiane przez warstwę banera. */
        open: function () {
            document.dispatchEvent(new CustomEvent('quince:open'));
        }
    };

    window.QuinceCMP = api;

    /* ================================================================
       START
       ================================================================ */

    function start() {
        var kody = przyznane();

        if (kody.length) {
            zwolnijSkrypty(kody);
            zwolnijRamki(kody);
        }

        wstawZastepniki();
        powiadom(api.state());
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
