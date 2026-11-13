# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versioning follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.9] - 2026-11-13

### Fixed

- The back office no longer sends you to the shop's home page after this module is
  installed from its release ZIP. The index.php file in every folder of the package
  redirected and stopped the process also when PrestaShop loaded it while scanning
  `src/ApiPlatform/Resources`, so the back office could not be built.

## [0.3.8] - 2026-11-10

### Changed

- An empty value in a table shows a hyphen instead of a long dash.
- Admin API descriptions no longer use long dashes.

## [0.3.7] - 2026-11-10

### Changed

- Polish translation completed: the texts added since the previous translation are now translated.
- The Polish e-mail about failing consent sync is written in Polish.
- Texts no longer use long dashes.

## [0.3.6] - 2026-11-10

First public release. It gathers the changes of the internal versions 0.3.2 to 0.3.5.

### Added

- A per consent type switch for showing the consent at checkout, independent of its
  kind. Document consents that were already shown at checkout keep being shown; new
  ones start switched off.
- A **Quince consents** section on PrestaShop's own customer page in the back office,
  with the consent history of that customer.

### Changed

- Which `ps_customer` column a consent mirrors (newsletter, partner offers) is set per
  consent type in the back office instead of being fixed in the code. Existing
  installations get the two previous mappings filled in, so nothing stops mirroring.
- The module does not print a data processing note of its own next to the terms
  checkbox at checkout. One was added in 0.3.5 and withdrawn in 0.3.6: the privacy
  wording belongs to PrestaShop's own modules (`psgdpr`, `ps_dataprivacy`). The consent
  history is still recorded for every order and every customer account.
- Author and Composer package renamed for publication: Quince Project,
  `quinceproject/cmp`. Classes are loaded through Composer's autoloader.

## [0.3.1] - 2026-09-06

### Added

- Resources on PrestaShop's Admin API: consent state of a person, the consent log, and
  recording an event. Two scopes, `quince_cmp_consent_read` and `quince_cmp_consent_write`,
  granted on the shop's own API clients screen. The module ships no key mechanism of
  its own.
- An **API** screen: what the module exposes, how to get a token, who currently has
  access, and the conditions PrestaShop imposes before the API answers at all.
- A starter cookie table with the two cookies every PrestaShop shop sets, `PHPSESSID`
  and `PrestaShop-*`, described and filed under the required category.
- Default Consent Mode mapping for the four starter cookie categories. Without it consent
  raised no signal, and Google treats a channel that never says "yes" as switched off.
- `API.md` now ships with the module, and covers the Admin API alongside the PHP,
  JavaScript and webhook interfaces.
- `LICENSE`, `README.md`, `CHANGELOG.md` and `.gitignore`.
- Distance of the floating button from the bottom edge, set separately for desktop and
  mobile. A chat window, a back-to-top button or a sticky cart bar often sits in the same
  corner, and on phones sticky bars are far more common.

### Changed

- One visible entry in the shop menu, under the **Quince project** branch, instead of ten.
  Every screen has its own sidebar once you are inside; ten menu entries bought nothing.
  Existing installations are moved by `upgrade-0.3.1.php`.
- Diagnostics and deployment-status messages are now translation keys with placeholders.
  Assembled with `sprintf` they reached the template with values already inside, so no
  catalogue could ever match them and they stayed English in every language.
- Polish translation catalogue completed: 553 units, none missing.
- `composer.json` declares OSL-3.0, matching `LICENSE`. It said AFL-3.0.

### Fixed

- The API screen crashed on `getRequest()`, which `PrestaShopAdminController` does not have.
- Cards clipped anything that overflowed them, so the language, icon and colour pickers
  were cut off at the card edge.
- Two partials, the "what it does" panel and the banner preview, carried no translation
  domain, so every string in them fell back to English.
