# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versioning follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.4.9] - 2026-11-27

### Added

- **Support**: the screen opens with a link to the project on Ko-fi and a block that
  invites you to report bugs, suggestions and remarks in the module's GitHub
  repository, with a link to the project website.

## [0.4.8] - 2026-11-25

### Added

- The Cookies screen says under "Cookie table" how to show the table on a CMS page
  with `[quince_cookies]`.

### Fixed

- **Updating to 0.4.7 no longer warns that `hookFilterCmsContent` is not defined,**
  and no longer stops in debug mode. The upgrade script registers the hook without
  asking the old module class, which is still loaded while the update runs. Shops
  where the 0.4.7 update did not finish get the hook registered by this update.

## [0.4.7] - 2026-11-25

### Added

- **Cookie list on a CMS page.** `[quince_cookies]` in the content of a CMS page (for
  example the privacy policy) is replaced with the cookie table, grouped by category,
  with the name, provider, lifetime and purpose of each cookie, in the visitor's
  language. The upgrade script registers the `filterCmsContent` hook.

## [0.4.6] - 2026-11-24

### Added

- **New cookie lifetime "until deleted"** for entries that never expire on their own,
  such as `localStorage`. It has no number and no longer has to be entered as
  "session". Moving settings between shops carries it over.

### Fixed

- **Cookie lifetimes are shown in the shop's language**, with the right plural form
  ("1 miesiąc", "2 miesiące", "12 miesięcy"), in the banner and in the cookie list in
  the back office. Before, the banner always said "12 months", "session".
- **"Privacy policy", "Always active" and "Close" in the banner are translated.**
  Other languages can translate them in the module's `Shop` domain.
- Lifetime units in the cookie form have Polish names.

No upgrade script.

## [0.4.5] - 2026-11-16

### Fixed

- **A cookie category can no longer be required at checkout or mirrored into the
  customer account.** The "Where it appears" tab of a cookie consent no longer shows
  "Show in checkout" or "Mirror in customer account", and saving a cookie consent
  always keeps both off. No upgrade script: installed cookie consents never had them on.

## [0.4.4] - 2026-11-16

### Changed

- New module logo: the letter Q in the project gradient.
- Files in `src/` stop when opened directly instead of through PrestaShop.

## [0.4.3] - 2026-11-15

### Security

- **A service script is held back even when it also mentions Google.** Google tags are
  still never held back (Consent Mode controls them), but a script that loads another
  listed service next to a Google tag, in the same `<script>` block, now waits for
  consent like any other. Before, any mention of a Google address in the script let the
  whole script run.
- **The custom floating button icon is read as SVG, not as HTML.** The browser now
  parses the icon with the same kind of parser that cleaned it on the server, and the
  cleaning also removes CDATA sections, which the two parsers read differently.

### Fixed

- **A custom icon for the floating button is saved.** Before, the uploaded icon was
  accepted with a success message but stored empty, so the shop kept showing the built-in
  icon. Icons need to be uploaded again after this update.

## [0.4.2] - 2026-11-15

### Security

- **The update from the admin installs only a package with a checksum.** A release
  listed without a SHA-256 checksum is refused. Before, such a release was installed
  without checking that the downloaded package is the one that was published.

## [0.4.1] - 2026-11-15

### Fixed

- Sending to the external system works for a host without an IPv4 address. A failed
  IPv4 lookup was read as an empty address and refused every such host.

## [0.4.0] - 2026-11-15

### Security

- **Newsletter consent is recorded only when PrestaShop accepts the subscription:**
  after a direct sign-up, or after the confirmation link when the shop asks for one.
  Before, it was recorded from the form alone, for any address anyone typed in, and
  an unsubscription was recorded as consent. An unsubscription now records the
  consent as withdrawn, and only for an address that is on the list. The visitor's
  browser is linked to the address only after the confirmation link.
- **The consent endpoint accepts only requests from the banner script.** It needs the
  banner's header and refuses requests the browser marks as cross-site. Before, a
  form on another site could set a visitor's consent to everything. Requests are also
  rate-limited when Quince Engine is installed.
- **Choices of guests without an account are no longer lost from the log.** Unchanged
  choices were compared with the last guest's entry instead of this guest's.
- **Links in consent clauses are escaped at checkout** and accept only `http(s)`,
  `mailto`, `tel`, CMS pages and relative addresses. Before, a link could add
  attributes to the checkout page or run a script through `javascript:` written as an
  HTML entity.
- Retrying a sync event and ignoring a scan finding are POST actions with a security
  token. Retry applies only to outgoing events that were not sent.
- An employee with read-only access to Sync sees that the signing keys exist, not the
  keys. Adding past consents to the queue needs edit access.
- The CSV export of the consent log neutralises cells that a spreadsheet would read
  as formulas.

### Added

- Events sent to the external system carry `verified`: false when the e-mail address
  was typed into a form and nobody confirmed it.

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
