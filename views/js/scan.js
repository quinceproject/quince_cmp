/**
 * Quince CMP — zbieracz skanera.
 *
 * Ładowany wyłącznie w trybie skanowania, uruchomionym z panelu. Melduje, co
 * sklep zapisał w przeglądarce i do kogo wysłał żądanie.
 *
 * Nie da się tego ustalić z serwera: nagłówek `Set-Cookie` pokazuje tylko to,
 * co zapisał sam PrestaShop, a większość ciasteczek pomiarowych i reklamowych
 * powstaje w przeglądarce i nigdy nie przechodzi przez PHP.
 */
(function () {
    'use strict';

    var cfg = window.quinceCmpScan || {};

    if (!cfg.endpoint) {
        return;
    }

    var zameldowane = {};

    function dodaj(lista, rodzaj, nazwa, domena) {
        var klucz = rodzaj + '|' + nazwa;

        if (!nazwa || zameldowane[klucz]) {
            return;
        }

        zameldowane[klucz] = true;
        lista.push({ kind: rodzaj, name: nazwa, domain: domena || '' });
    }

    function zbierz() {
        var lista = [];

        document.cookie.split(';').forEach(function (para) {
            dodaj(lista, 'cookie', para.split('=')[0].trim(), location.hostname);
        });

        try {
            for (var i = 0; i < localStorage.length; i++) {
                dodaj(lista, 'localStorage', localStorage.key(i), location.hostname);
            }

            for (var j = 0; j < sessionStorage.length; j++) {
                dodaj(lista, 'sessionStorage', sessionStorage.key(j), location.hostname);
            }
        } catch (e) {
            // Prywatny tryb przeglądarki potrafi zablokować dostęp do magazynu.
            // Brak dostępu nie jest błędem skanu — reszta melduje się dalej.
        }

        // Żądanie do obcego serwera samo w sobie przekazuje adres IP i nagłówki,
        // nawet jeśli nie zapisze ciasteczka. Dla rejestru czynności to też jest
        // przekazanie danych, więc melduje się razem z resztą.
        try {
            performance.getEntriesByType('resource').forEach(function (wpis) {
                var host = new URL(wpis.name, location.href).hostname;

                if (host && host !== location.hostname) {
                    dodaj(lista, 'request', host, host);
                }
            });
        } catch (e) {
            // Starsze przeglądarki bez `performance.getEntriesByType`.
        }

        return lista;
    }

    function melduj(koncowy) {
        var lista = zbierz();

        if (!lista.length) {
            return;
        }

        var dane = new FormData();
        dane.append('page', location.pathname);
        dane.append('findings', JSON.stringify(lista));

        // Przy opuszczaniu strony `fetch` bywa przerwany; `sendBeacon` dowozi
        // meldunek także wtedy, gdy dokument już się zamyka.
        if (koncowy && navigator.sendBeacon) {
            navigator.sendBeacon(cfg.endpoint, dane);

            return;
        }

        fetch(cfg.endpoint, { method: 'POST', credentials: 'same-origin', body: dane })
            .catch(function () { /* meldunek jest najlepszym staraniem, nie warunkiem */ });
    }

    // Ciasteczka pojawiają się w różnych chwilach: część przy starcie, część
    // po załadowaniu tagów, część dopiero po interakcji. Stąd kilka meldunków.
    window.addEventListener('load', function () {
        melduj(false);
        setTimeout(function () { melduj(false); }, 2000);
        setTimeout(function () { melduj(false); }, 6000);
    });

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            melduj(true);
        }
    });

    // Pasek widoczny tylko dla osoby skanującej — inaczej łatwo zapomnieć,
    // że sklep chodzi z odblokowanymi wszystkimi usługami.
    window.addEventListener('load', function () {
        var pasek = document.createElement('div');
        pasek.className = 'qcmp-scanbar';
        pasek.textContent = cfg.label || 'Cookie scan in progress: all services are temporarily unblocked for this browser.';
        document.body.appendChild(pasek);
    });
})();
