# Quince CMP

**Consents, cookies and terms in one place for PrestaShop 9.2.**

Quince CMP keeps every consent in the shop on one list: cookies, marketing, terms and
the consents asked for in forms. It shows the cookie banner, holds services back until
the visitor agrees, sends the Google Consent Mode signals and keeps a log of every
decision together with a hash of the wording that was on screen at that moment.

> Quince CMP is a free, open-source module (OSL-3.0). There is nothing to buy.
> If it saves you time, you can support development, see
> [Supporting the project](#supporting-the-project).

---

## Features

- **A log, not a checkbox.** Every consent and every refusal goes into an append-only
  log with the consent version and a hash of the wording shown at that moment. A
  checkbox proves a click; the log shows what the click referred to.
- **Services held back until consent.** Scripts lose their executable type and frames
  lose their address until the visitor agrees to their category, then the page
  releases them. Google tags are not blocked: they load and limit themselves through
  Consent Mode.
- **Consent Mode v2.** All seven Google signals, defaults everywhere or per region,
  `wait_for_update`, ad data redaction and URL passthrough.
- **Refusing is as easy as agreeing.** Buttons of equal weight, 44 px touch targets,
  keyboard focus kept inside the window, and the browser's Global Privacy Control
  signal honoured without asking (can be switched off).
- **Guests and customers.** Consent given before signing up is tied to the browser. When
  that browser later signs up or logs in, both histories are linked to the customer.
- **Consent types with versions.** Cookie categories, form consents and documents such
  as the terms, each with its own version history. A consent can be shown at checkout,
  and can mirror a native customer field (newsletter, partner offers).
- **Cookie table and scanner.** A list of the shop's cookies by category, and a scanner
  that opens the shop in a browser with everything unblocked and reports the cookies,
  storage keys and third-party hosts it finds.
- **Consent history on the customer page.** A **Quince consents** section on
  PrestaShop's own customer page in the back office.
- **Log screen and CSV export,** with filters by consent, source and date.
- **Exchange with an external system.** Signed webhooks out and in, with retries and a
  queue you can inspect, plus resources on PrestaShop's own Admin API with two OAuth2
  scopes. See [API.md](API.md).
- **Retention.** Events older than the period set in the settings are deleted, with a
  separate, shorter period for guests the shop does not know.
- **Updates from the back office.** The Support screen checks for a newer release and
  installs it on a click, after checking the package hash. It needs the Quince release
  index, which is not online yet.
- **Multistore.** Every setting belongs to a shop; exchange settings can be shared across
  a shop group.
- **Multilingual.** English base with a full Polish translation, on PrestaShop's standard
  translation system.

## What the module stores

The consent log keeps, for each event: the visitor key (a random identifier kept in the
module's cookie), the customer's e-mail address and id when the shop knows them, the
consent code and version, the hash of the wording, the language, the decision, where it
came from (banner, checkout, registration, account, newsletter, a form, the back office
or an external system), the **IP address**
and the **browser header**. An entry made by an employee also keeps the employee and a
note.

The IP address, the browser header and the visitor key are not returned by the Admin
API. They are visible on the log screen and in the CSV export, behind employee
permissions. Uninstalling the module deletes the log.

## What it does not do

- It does not store the wording of a clause. Clauses live on the shop's CMS pages; the
  module keeps a hash to tell when the text changed after consent was collected.
- It does not replace PrestaShop's own consent fields. The shop keeps its state, the
  module records the history beside it.
- It does not support TCF (`window.__tcfapi`), the framework for publishers selling ad
  space.
- It does not make a shop compliant on its own. Which consents to ask for, their wording
  and the retention periods are decisions for the shop and its legal advice.

## Requirements

| Requirement | Version |
|-------------|---------|
| PrestaShop  | 9.2 (tested on 9.2.0) |
| PHP         | 8.4 (tested), 8.1 or newer required |
| Quince Engine | not required |
| PHP cURL extension | required for the exchange and for updates |

Quince CMP works on its own. With Quince Engine installed, the module joins the
engine's back office navigation and the banner can use the shared Quince colours;
without it, the banner uses the colours set on its own **Banner & preferences** screen.

## Installation

The release ZIP already contains everything the module needs. Composer and the
command line are not required.

**From the back office**

1. Download the latest **`quince_cmp.zip`** from the
   [**Releases**](https://github.com/quinceproject/quince_cmp/releases) page.
2. In the back office go to **Modules → Module Manager → Upload a module** and
   choose the ZIP. PrestaShop installs it.

**By copying the files to the server**

1. Unpack the release ZIP. It contains a `quince_cmp` folder.
2. Upload that folder to the `modules/` directory of the shop, for example by FTP.
3. In **Modules → Module Manager** find Quince CMP and click **Install**.

**From Quince Engine** *(coming soon)*

The module list in Quince Engine will install the module straight from its release,
without downloading anything by hand.

**With Composer** *(coming soon)*

Installing with `composer require quinceproject/cmp` will work once the package is
published on Packagist.

> ⚠️ **Do not** use the green **Code → Download ZIP** button on this page. That
> archive contains the source only and is **missing the `vendor/` folder**, so the
> module will not run. Always install from a
> [**Release**](https://github.com/quinceproject/quince_cmp/releases).

## Configuration

Open **Quince project → Consents and cookies** in the back office menu, or click **Configure**
next to the module in the Module Manager. The sidebar holds the screens:

1. **Overview.** Whether the module works in each shop, and what still needs attention.
2. **Consent types.** The consents the shop collects, their versions, wording links
   and where they are shown.
3. **Cookies.** The cookie table by category, and the scanner.
4. **Scripts & blocking.** The services held back until consent.
5. **Banner & preferences.** Look and wording of the banner and the preferences window.
6. **Consent log.** The log with filters and CSV export.
7. **Sync.** The exchange with an external system and its queue.
8. **Settings.** Consent lifetime, retention, Consent Mode and regions.
9. **API.** The Admin API resources, scopes and clients.
10. **Support.** Diagnostics, settings transfer between shops and updates.

## Architecture

- **`ConsentLog`** writes the append-only log; **`ConsentTypes`** and
  **`ConsentVersions`** hold the consent types and their history.
- **`Blocker`** rewrites the finished page on `actionOutputHTMLBefore` so held-back
  services do not run; **`Signals`** builds the Consent Mode declarations.
- **`CoreBridge`** records consents given in PrestaShop's own forms, orders and
  customer accounts.
- **`Sync`** classes sign, queue and retry the webhook exchange; the `inbox` and `cron`
  front controllers receive changes and trigger sending.
- **Admin API resources** in `src/ApiPlatform` with the scopes `quince_cmp_consent_read`
  and `quince_cmp_consent_write`.
- **Symfony controllers and Twig** for the back office, with the module's own copy of
  the Quince panel styles, so it does not need Quince Engine.

## Development / building from source

This repository tracks **source only**. The `vendor/` directory is git-ignored, so a
`git clone` is **not** directly installable. **Start with `composer install`**: the
development environment mounts module directories straight from disk, and without
that step a fresh clone gives a broken back office.

```bash
git clone https://github.com/quinceproject/quince_cmp.git
cd quince_cmp
composer install
```

The folder must be called `quince_cmp`, the same as the module. A clone of this
repository already has that name.

To build an installable archive, zip the `quince_cmp/` folder **with its `vendor/`
directory included**, leaving out `.git/` and `.gitignore`.

## License

Code: [Open Software License 3.0 (OSL-3.0)](LICENSE). It is a reciprocal licence:
whoever modifies and distributes this code has to publish the changes under the same
licence and keep the original copyright and author notices.

Alpine.js and SortableJS ship with the module under their own MIT licences, listed in
[THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).

**The name "Quince" is not covered by the code licence.** A fork may use the code; it
may not use the name.

© 2026 Marcin Gajewski

## Supporting the project

Quince modules are free downloads and there is nothing to buy. If the module is useful
to you, you can support its development by buying me a coffee:

**[buymeacoffee.com/marcingajewski](https://buymeacoffee.com/marcingajewski)**
