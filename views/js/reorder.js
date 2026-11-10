/**
 * Kolejność rodzajów zgód na liście.
 *
 * Przeciąganie robi Alpine (`x-sort` z paczki alpine-sort) — ten sam
 * mechanizm, co rozstawianie elementów strony głównej w `quince_engine`.
 * Samo przeciągnięcie niczego nie zapisuje: dopiero ten skrypt, tuż przed
 * wysłaniem formularza, spisuje aktualny układ DOM (po ewentualnym
 * przeciągnięciu) do ukrytego pola `order`.
 */
(function () {
    'use strict';

    var formularz = document.getElementById('reorder-form');
    var lista = document.querySelector('[data-quince-reorder]');

    if (!formularz || !lista) {
        return;
    }

    formularz.addEventListener('submit', function () {
        var id = [].map.call(lista.querySelectorAll('[data-id]'), function (wiersz) {
            return wiersz.getAttribute('data-id');
        });

        formularz.querySelector('[name="order"]').value = id.join(',');
    });
}());
