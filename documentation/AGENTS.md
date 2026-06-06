# AGENTS.md - Tec Fattura24 Connector

Practical, no-fluff briefing for AI coding agents working on this module.
Read this before writing code. It captures the module-specific constraints that
are not obvious from the file list alone.

---

## 1. Identity

| Field | Value |
|---|---|
| Module name | `tecfattura24` |
| Main class | `Tecfattura24` in `tecfattura24.php`, extends `Module` |
| Current version | `1.0.0`, kept in `$this->version`, `@version` and `config_it.xml` |
| Vendor | Tecnoacquisti.com® / Arte e Informatica di Loris Modena e C. s.a.s. |
| PS support | PrestaShop 1.7.8.11 - 9.x (`min => 1.7.8.11`, `max => _PS_VERSION_`) |
| Purpose | Send PrestaShop orders to Fattura24 when configured document rules match an order status |
| Architecture | Legacy module, currently monofile plus Smarty templates |

---

## 2. Repository Layout

```
tecfattura24/
  tecfattura24.php                  # Main module class, install/uninstall, configuration, hooks, API client, XML builder.
  README.md                         # Public module overview and operational notes.
  CHANGELOG.md                      # Release notes.
  config_it.xml                     # PrestaShop generated module metadata for Italian marketplace/admin contexts.
  controllers/
    admin/index.php                 # Sentinel only.
    front/index.php                 # Sentinel only.
  documentation/
    AGENTS.md                       # This file.
    index.php                       # Sentinel only.
  sql/index.php                     # Sentinel only. Schema is created directly by installDb().
  upgrade/index.php                 # Sentinel only. Add versioned install-x.y.z.php scripts here when needed.
  views/
    img/logo-tecnoacquisti.svg      # Brand asset.
    templates/
      admin/configure.tpl           # Intro panel above HelperForm.
      admin/copyright.tpl           # Module copyright block below configuration.
      admin/document_rules.tpl      # Per-document status/rule configuration form.
      hook/admin_order.tpl          # Back-office order status/retry panel.
  translations/index.php            # Sentinel only.
  dist/                             # Release ZIPs go here. Not committed.
```

There is no `src/` layer and no Composer setup in the current module. Do not add
framework structure unless the change really needs it.

---

## 3. Runtime Flow

Registered hooks:

- `actionOrderStatusUpdate` - automatic send when one or more enabled document rules match the reached status.
- `displayAdminOrder` - legacy order detail panel.
- `displayAdminOrderSideBottom` - modern order detail side panel.

Automatic send flow:

1. `hookActionOrderStatusUpdate()` receives the order status change.
2. `getDocumentRulesForState()` returns every enabled rule whose `id_order_state` matches the new status.
3. Zero-total orders are rejected per rule unless that rule has `allow_zero=1`.
4. `sendOrderToFattura24()` builds the current `idRequest` for the rule document type.
5. A MySQL named lock is acquired for order ID, shop ID and document type.
6. Existing `sent` rows are not sent again unless a manual retry forces the send.
7. Existing `error` rows require manual retry.
8. A pending row is inserted or reset in `tecfattura24_document`.
9. The module builds XML with `DOMDocument`.
10. The module posts to Fattura24 `SaveDocument`.
11. A response with `docId` marks the row as `sent`; errors mark it as `error`.

Manual retry:

- Rendered by `views/templates/hook/admin_order.tpl`.
- Triggered by `submitTecfattura24Retry`.
- Requires `tecfattura24_token`, generated with `Tools::hash($this->name . '_retry_' . employee id)`.
- Carries `tecfattura24_document_type`.
- Loads the matching configured rule and calls `sendOrderToFattura24(..., true, $rule)`, therefore bypassing the automatic error guard only for that document type.

---

## 4. Database

Owned table: `<prefix>tecfattura24_document`.

Created in `Tecfattura24::installDb()` and dropped in `uninstallDb()`.

| Column | Type | Notes |
|---|---|---|
| `id_tecfattura24_document` | INT UNSIGNED AI | Primary key. |
| `id_order` | INT UNSIGNED | PrestaShop order ID. |
| `id_shop` | INT UNSIGNED | Shop ID. |
| `id_order_state` | INT UNSIGNED | Triggering or retry state. |
| `document_type` | VARCHAR(16) | Fattura24 document type. |
| `id_request` | VARCHAR(64) | Fattura24 idempotency key. |
| `doc_id` | VARCHAR(64) NULL | Fattura24 document ID. |
| `status` | VARCHAR(16) | `pending`, `sent`, `error`. |
| `api_response` | MEDIUMTEXT NULL | Raw successful API response, and failed API response bodies when Fattura24 does not return `docId`. |
| `error_message` | TEXT NULL | Last error. |
| `attempts` | INT UNSIGNED | Attempt counter. |
| `date_add` | DATETIME | Creation date. |
| `date_upd` | DATETIME | Last update date. |

Unique key: `id_order`, `document_type`, `id_shop`.

The table is intentionally separate from PrestaShop invoice/order tables so the
module can track Fattura24 attempts without modifying core order state.

---

## 5. Configuration Keys

All configuration values are global.

| Key | Default | Purpose |
|---|---:|---|
| `TECFATTURA24_API_KEY` | empty | Fattura24 API key. Rendered as masked text in BO. |
| `TECFATTURA24_DOCUMENT_RULES` | empty | JSON map keyed by document type. Each rule stores enabled, status, numerator, template, shop code, custom number format, email, paid and zero-total flags. |
| `TECFATTURA24_TRIGGER_STATE` | `0` | Legacy fallback status used only when no document rules are saved. |
| `TECFATTURA24_DOCUMENT_TYPE` | `FE` | Legacy fallback type used only when no document rules are saved. |
| `TECFATTURA24_SEND_EMAIL` | `0` | Legacy fallback email flag used only when no document rules are saved. |
| `TECFATTURA24_PAID_STATUS` | `0` | Legacy fallback paid flag used only when no document rules are saved. |
| `TECFATTURA24_ALLOW_ZERO` | `0` | Legacy fallback zero-total flag used only when no document rules are saved. |
| `TECFATTURA24_ID_NUMERATOR` | empty | Legacy fallback numerator ID used only when no document rules are saved. |
| `TECFATTURA24_ID_TEMPLATE` | empty | Legacy fallback template ID used only when no document rules are saved. |
| `TECFATTURA24_TIMEOUT` | `60` | cURL timeout. Normalized to 60 when outside 5-120. |
| `TECFATTURA24_DEBUG` | `0` | Enables `PrestaShopLogger` debug messages. |
| `TECFATTURA24_TEST_KEY` | empty | Stores last API key test timestamp and HTTP status. |

Secret handling:

- The API key field is `text`, not `password`.
- `maskSecret()` keeps only the last 4 characters visible.
- `postProcess()` preserves the stored value when the submitted value is empty
  or still masked.
- Never expose the real API key through JavaScript, Smarty, logs, URLs,
  diagnostics or release metadata.

Configuration page shape:

- `Fattura24 API settings` contains only API key, HTTP timeout and debug log.
- `Fattura24 document rules` is a separate Smarty form rendered by `views/templates/admin/document_rules.tpl`.
- Each supported document type can be enabled independently and mapped to its own PrestaShop order status.
- Multiple document types may share the same PrestaShop status. In that case the hook sends one document per enabled matching rule.
- Customer orders (`C`) use Fattura24 `Number` with a default format of `{order_id}-{shop_code}-{year}`. When `shop_code` is empty, `buildDocumentNumber()` falls back to `SHOP<id_shop>`.
- `DOCUMENT_NUMBER_MAX_LENGTH` is 20. Fattura24 does not publish a `Number`
  field limit in the public SaveDocument page, so the module fails locally when
  the generated number is longer instead of sending a risky API request.
- `SHOP_CODE_MAX_LENGTH` is 8 and accepts only `A-Z`, `0-9`, `_`, `-` after
  normalization. `NUMERIC_CONFIG_MAX_LENGTH` is 16 for numerator/template IDs.
- Number formats accept only `A-Z`, `0-9`, `_`, `-`, `/`, `.`, braces and the
  supported token names.
- Fiscal documents should normally use `IdNumerator`; `custom_number` for fiscal documents is an advanced override and must stay disabled by default.
- When `TECFATTURA24_DOCUMENT_RULES` is empty, `getDocumentRules()` exposes the legacy single-document configuration as a backward-compatible rule. Once the rules form is saved, the JSON rules become the source of truth.

---

## 6. Fattura24 API

Base URL: `https://www.app.fattura24.com/api/v0.3/`

Endpoints used:

| Endpoint | Method | Purpose |
|---|---|---|
| `TestKey` | POST form data | Validate the configured or submitted API key. |
| `SaveDocument` | POST form data | Create the Fattura24 document. |

`SaveDocument` payload:

- `apiKey`
- `source` as `TecF24-Pre <module version>`
- `idRequest`, built as `<documentType><id_order>_<id_shop>`
- `xml`, built by `buildDocumentXml()`

The code expects HTTP 200 and a response containing `docId`. A missing `docId`
is treated as an error unless Fattura24 reports that the document already exists;
in that case the local row is marked as `sent` because the remote document is
already present.

---

## 7. XML Mapping Notes

`buildDocumentXml()` reads from the invoice address, customer and order.

Important nodes:

- `FeCustomerPec` from `address.pec` when the column/property exists.
- `FeDestinationCode` from `address.sdi` for Italian addresses; fallback
  `0000000` for Italy and `XXXXXXX` outside Italy.
- `CustomerFiscalCode` from `Address::dni`.
- `CustomerVatCode` from `Address::vat_number`, with country prefix stripped
  when it matches the invoice country ISO code.
- `PaymentMethodName` and `PaymentMethodDescription` from `Order::payment`.
- `FePaymentCode` guessed from the payment label:
  - bank/wire/bonifico -> `MP05`
  - check/assegno -> `MP02`
  - cash/contrassegno -> `MP01`
  - fallback -> `MP08`
- Product rows use tax-excluded unit prices and product tax rates.
- Discounts are emitted as negative rows with VAT code `0`.
- Shipping is emitted only when `total_shipping_tax_excl > 0`.
- Customer orders use `DocumentType=C` and add `Number` from the configured
  number format. `FePaymentCode`, `Payments` and `IdNumerator` are not sent for `C`.
- Supported number tokens are `{year}`, `{shop_id}`, `{shop_code}`, `{order_id}`,
  `{order_reference}` and `{document_type}`.

ArteInvoice integration is passive. This module does not require ArteInvoice,
but it can read `sdi` and `pec` from the `address` table when those fields exist.

---

## 8. Compatibility Rules

- Keep compatibility with PS 1.7.8.11, 8.x and 9.x.
- The main module file is legacy code; do not add `declare(strict_types=1)` or
  PHP 8-only syntax there unless the whole compatibility policy changes.
- If a future modern path is added, isolate it behind `_PS_VERSION_` checks and
  keep PS 1.7-safe code out of that path.
- Use Smarty templates for HTML. Do not add HTML markup inside PHP strings.
- Keep all source-code-facing text in English.
- Keep PHP, Smarty, JS, CSS, SQL and shell comments ASCII-only.
- Prefer existing PrestaShop APIs and legacy helpers already used by the module.

---

## 9. Validation And Security

- Always validate merchant-provided values both in the back-office form and on
  the PHP server path that persists or uses them.
- Front-end constraints such as `pattern`, `maxlength`, `inputmode`, switches
  and selects are useful for ergonomics, but they are never the source of truth.
- Server-side validation must reject unsupported characters, unsupported tokens,
  oversized values and invalid IDs before saving configuration or calling
  Fattura24.
- Escape all values rendered in Smarty templates with the appropriate modifier.
- Sanitize or cast all values used in SQL, XML nodes, URLs, logs and API
  payloads. Use `pSQL()`, integer casts, `DOMDocument` text/CDATA nodes and
  existing helper methods instead of string concatenation for untrusted data.
- Be especially careful with fields that can influence document numbers,
  templates, numerators, API requests, XML payloads and log messages. Prevent
  HTML, JavaScript, SQL, XML and command injection by default.
- Never trust data because it came from the PrestaShop back office. Treat all
  submitted configuration, order data and address extension fields as untrusted
  until validated for the exact context where they are used.

---

## 10. Packaging And Release Notes

Current export rules are in `.gitattributes`.

Release ZIPs belong in `dist/` and must not be committed. `config.xml`, logs,
`.gitignore`, `.gitattributes` and `dist/` are excluded from release archives.

When releasing:

1. Update `@version` in `tecfattura24.php`.
2. Update `$this->version` in `tecfattura24.php`.
3. Update `config_it.xml`.
4. Update `CHANGELOG.md`.
5. Keep generated/runtime files out of the archive.
6. Build the ZIP from the release tag, not the working tree.

Use the workspace release workflow document before committing, tagging or
publishing.

---

## 11. Common Gotchas

- `Configuration::get()` has no default-value second argument. The second
  argument is `id_lang`; do not use it for fallbacks.
- Automatic sends stop after an `error` row. This is intentional; retry from the
  order panel.
- The idempotency key includes document type and shop ID. Enabling multiple
  document types creates separate tracked document rows for the same order.
- `displayAdminOrder` and `displayAdminOrderSideBottom` may both fire. The
  module uses a static rendered-order guard to avoid duplicate panels.
- MySQL named locks use timeout `0`. If another send is in progress, the module
  fails fast instead of waiting.
- The API key test stores only timestamp and HTTP status in
  `TECFATTURA24_TEST_KEY`; it does not store the API response body.
- Debug mode logs only high-level send messages. Do not add logs that include
  API keys or full XML unless there is a deliberate, reviewed diagnostic mode.
