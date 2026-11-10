# Quince CMP: API

Module version: 0.3.6

The module is the only place in the shop that knows what a visitor agreed to. This
document describes how other modules, the theme and external systems should ask it.

**The ground rule:** nobody reads the `quince_cmp` cookie on their own. Its format is
internal to the module and will change without notice. Everything below is stable.

---

## 1. PHP: asking from the server

The entry point for modules that measure, send or store data from PHP: the order
confirmation, a cron task, the GA4 Measurement Protocol, a CRM integration.

```php
$cmp = Module::getInstanceByName('quince_cmp');

if ($cmp && $cmp->allowed('cookies_analytics')) {
    // measuring is allowed
}
```

### `allowed(string $code, ?int $idShop = null): bool`

Whether the visitor agreed to the given consent type. Without a shop it takes the shop
from the current context.

Returns `false` when there is no consent, when it has expired (see Settings, *Consent
lifetime*) or when the visitor never answered.

### `consentState(?int $idShop = null, ?int $idLang = null): array`

```php
[
    'codes'   => ['cookies_necessary', 'cookies_analytics'],
    'signals' => ['ad_storage' => 'denied', 'analytics_storage' => 'granted', …],
    'decided' => true,
]
```

`decided` tells "refused" apart from "not asked yet". They are not the same thing and
must not be mixed up in reporting.

### Why this exists

Server-side measurement bypasses `gtag`, and with it the Consent Mode signals. On one
deployment a purchase event sent straight from PHP won deduplication against the one
from the browser and overwrote the attribution: all the revenue ended up in the
*Unassigned* channel. A module that measures from PHP has someone to ask here before
it sends.

---

## 2. JavaScript: asking from the page

`window.QuinceCMP` is available once `front.js` has run, that is at the bottom of the
page. A script that runs earlier has to wait for the event.

### `QuinceCMP.state()`

```js
{ codes: ['cookies_necessary'], signals: { … }, decided: false }
```

### `QuinceCMP.allowed(code)`

```js
if (QuinceCMP.allowed('cookies_marketing')) { … }
```

### `QuinceCMP.whenAllowed(code, fn)`

Calls `fn` when consent is or becomes granted. **It also fires immediately if consent
is already there**, otherwise a script added after the decision would wait forever.
This is the recommended way to start anything conditional.

```js
QuinceCMP.whenAllowed('cookies_analytics', function () {
    // start measuring
});
```

### `QuinceCMP.on(fn)`

Listens to every change. `fn` gets the same shape as `state()`.

### `QuinceCMP.set(codes)`

Saves the given set of categories. Returns a `Promise`. Required categories are added
on the server side, whatever was sent.

### `QuinceCMP.open()`

Opens the preferences window.

### DOM events

| event | when | `detail` |
|---|---|---|
| `quince:consent` | at start and after every change | `{ codes, signals, decided }` |
| `quince:open` | a request to open the preferences window | none |

### dataLayer

After every change an entry goes to the tag manager:

```js
dataLayer.push({ event: 'quince_consent_update', quince_consent: { … } });
```

Trigger in GTM: custom event `quince_consent_update`.

---

## 3. Consent Mode

The module sends the signals to `gtag` itself. Another module **should not** repeat it.

Order in `<head>`, before any tag:

```js
gtag('set', 'ads_data_redaction', true);
gtag('set', 'url_passthrough', true);
gtag('consent', 'default', { …, region: ['PL','DE'], wait_for_update: 500 });
gtag('consent', 'default', { … });      // outside the regions, when scope = regions
gtag('consent', 'update',  { … });      // when the visitor has a valid choice
```

The regional declaration comes **before** the general one: gtag applies the first one
that matches.

Seven signals: `ad_storage`, `analytics_storage`, `ad_user_data`,
`ad_personalization` (required by Consent Mode v2) and `functionality_storage`,
`personalization_storage`, `security_storage` (optional).

`security_storage` is `granted` by default: refusing it breaks fraud detection and
authentication, and protects nothing.

**Google tags are not held back.** They are meant to load and limit themselves.
Blocking their script takes cookieless pings and conversion modelling away from Google.

---

## 4. Holding services back: the HTML contract

The module rewrites the finished page with the `actionOutputHTMLBefore` hook. A service
without consent looks like this:

```html
<script type="text/plain" data-qc-code="cookies_marketing" data-qc-type="text/javascript" src="…"></script>

<iframe data-qc-code="cookies_marketing" data-qc-name="Google Maps" data-qc-src="…"></iframe>
```

| attribute | meaning |
|---|---|
| `data-qc-code` | the code of the category whose consent releases this service |
| `data-qc-type` | the original `type`, restored after consent |
| `data-qc-src` | the original frame address; `src` is removed, so no request goes out |

A module that wants to hold something back until consent can use these attributes
directly: `front.js` releases them together with the rest.

---

## 5. HTTP: exchange with an external system

### 5.1 Saving the visitor's choice

```
POST /module/quince_cmp/consent
Content-Type: application/x-www-form-urlencoded

codes=cookies_analytics,cookies_marketing
```

```json
{ "ok": true, "codes": ["cookies_necessary","cookies_analytics","cookies_marketing"], "signals": { … } }
```

Records an event for **every** category, refusals included, together with a hash of the
wording that was on screen at that moment.

### 5.2 Sending consents out

The module sends a `POST` to the address from the Sync screen. Body:

```json
{
  "shop": "shop.example.com",
  "code": "cookies_marketing",
  "granted": true,
  "email": "anna.kowalska@example.com",
  "visitor_key": "9f1c4b7e2a8d40559e3b6c1af2d70e84",
  "version": "3",
  "text_hash": "b1946ac92492d2347c6235b4d2611184",
  "source": "banner",
  "origin": "shop",
  "occurred_at": "2026-08-27 11:40:07"
}
```

Headers:

```
X-Quince-Timestamp: 1774604407
X-Quince-Signature: hmac_sha256(timestamp + "." + raw_body, shared_key)
```

- The signature is computed from the **raw** body, byte for byte. Do not re-encode the
  JSON before checking it.
- A timestamp more than **300 s** off means the request is rejected.
- Answer `2xx` to confirm. Anything else goes back to the queue.
- Retries after 1, 5, 15, 60 and 240 minutes. After five attempts the item waits for a
  person.

### 5.3 Receiving changes from outside

```
POST /module/quince_cmp/inbox
```

The same body shape and the same signature, with the sender key from the Sync screen.
Withdrawal is **the same call with `granted: false`**: the state is worked out from the
newest event, so the other side does not need to know our history.

Answers:

| code | meaning |
|---|---|
| `200` | accepted |
| `401` | wrong signature or an expired timestamp |
| `409` | the shop has a newer event; the conflict is recorded, ours stays |
| `422` | a required field is missing |
| `503` | the sender key is not set |

### 5.4 Triggering the send

```
GET /module/quince_cmp/cron?token=…
```

The Sync screen shows the token. It is derived from the signing key, so changing the
key also invalidates the old cron address.

---

## 6. PrestaShop Admin API: OAuth2

The fourth way, and **the default for new integrations**. The module exposes its
resources through the PrestaShop 9 Admin API, so access is granted where it is granted
to everyone else: *Configure → Advanced Parameters → Admin API*. There is no separate
Quince key, no separate screen and no separate place to revoke access.

The module's **API** screen creates a client with our scopes already ticked and shows
who has it. The client lands on the core list like any other.

### 6.1 Scopes

| Scope | Allows |
|---|---|
| `quince_cmp_consent_read` | reading a person's consent state, the event log, the consent types and their versions |
| `quince_cmp_consent_write` | adding events |

Kept apart on purpose. A system that only reads the consent history should not be
able to add to it.

### 6.2 Token

```
POST /admin-api/access_token
Content-Type: application/x-www-form-urlencoded

grant_type=client_credentials
&client_id=…
&client_secret=…
&scope=quince_cmp_consent_read quince_cmp_consent_write
```

The token lives briefly. When it expires, ask for a new one instead of storing it.

### 6.3 Operations

```
GET  /admin-api/quince-cmp/consents/{email}         quince_cmp_consent_read
GET  /admin-api/quince-cmp/consent-events           quince_cmp_consent_read
GET  /admin-api/quince-cmp/consent-events/{id}      quince_cmp_consent_read
POST /admin-api/quince-cmp/consent-events           quince_cmp_consent_write
GET  /admin-api/quince-cmp/consent-types            quince_cmp_consent_read
GET  /admin-api/quince-cmp/consent-types/{code}     quince_cmp_consent_read
GET  /admin-api/quince-cmp/consent-versions         quince_cmp_consent_read
```

The event list is filtered with `code`, `email`, `source`, `origin`, `from`, `to`,
`granted`, `shop` and paged with `page`. The consent types can be filtered with `kind`
(`cookie`, `form`, `document`). The consent types and the version history need
`shopId`, and the version history also needs `code`.

Writing takes the same shape as the webhook in §5.2, only the fields have readable
names (`recordedAt` instead of `occurred_at`). It goes through the same checks and the
same conflict rules: the consent log means the same thing whichever door the event
came through.

### 6.4 What the API does not return

The IP address, the browser header and the visitor key. They are in the log so the
shop can prove the circumstances of a consent when someone disputes it, not so they
come out with every query. Whoever needs them has the log screen and the CSV export,
both behind employee permissions.

### 6.5 Four conditions without which the API stays silent

Checked on PrestaShop 9.2. Each of them can switch off the whole thing, and none says
so plainly:

1. **`PS_ENABLE_ADMIN_API`** has to be on. The module's API screen has a button for it,
   but asks for confirmation: it is a **shop** setting, not a module one, and once it is
   on, every module that declared resources exposes them.

2. **HTTPS is strictly required.** `SSLMiddlewareListener` rejects requests over plain
   HTTP; the only exception is in developer mode, through
   `PS_ADMIN_API_FORCE_DEBUG_SECURED`.

3. **Multistore switches the API off completely** while the `admin_api_multistore` flag
   is off. It shows as **404 on every request**, so it looks like a typo in the
   address. The core does not mention it on its API screen.

4. With multistore on, **every** request has to carry the shop context: `shopId`,
   `shopGroupId`, `shopIds` or `allShops`. Without it the answer is **400**.

### 6.6 Why not keys of our own

PrestaShop 9 has OAuth2, an API clients screen, scopes, secret rotation, access
revocation and OpenAPI documentation generated from the resource definitions. A module
adding a second mechanism on top would repeat it less well and scatter the shop's
integrations over several screens, and the merchant would lose the one place that
shows who has access to what.

The webhook from §5 stays as the way for systems that do not speak OAuth2.

---

## 7. What the API does not do

- **It does not expose the wording of consents.** Clauses live on the shop's CMS
  pages; the module keeps only a hash, to detect that the wording changed after a
  version was issued.
- **It does not replace the consent fields in PrestaShop's forms.** The shop keeps the
  state, the module records the history beside it. The native fields stay in place.
- **It does not decide for the tags.** Consent Mode tells Google what is allowed;
  blocking holds back the rest. The module does not modify other code apart from
  changing `type` and `src`.
