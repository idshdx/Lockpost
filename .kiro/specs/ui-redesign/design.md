# Design Document: UI Redesign

## Overview

This redesign replaces the prototype-quality UI of SYM.PGP.ONY with a production-ready interface. The scope is purely presentational and structural — no new business logic is introduced. The key changes are:

- All CDN assets replaced with locally-vendored files served through Symfony AssetMapper
- Bootstrap 5 dark theme applied globally via `data-bs-theme="dark"` on `<html>`
- A consistent nav and footer added to `base.html.twig`
- Two new static pages: About (`/about`) and Privacy (`/privacy`)
- `alert()` calls on the Submit page replaced with a Stimulus controller that renders inline Bootstrap alerts
- Copy-to-clipboard on Link and Verify pages migrated to a Stimulus controller
- Duplicate submit button on Verify page removed
- Semantic HTML, ARIA attributes, and skip-link added throughout
- Bootstrap Icons vendored locally under `public/bootstrap-icons/`

The application remains fully stateless. No database, session, or tracking changes are made.

---

## Architecture

### Asset Pipeline

Symfony AssetMapper (`symfony/asset-mapper`) handles all static assets. There is no Webpack or Vite. Assets are referenced in Twig via `{{ asset('path/to/file') }}`, which AssetMapper resolves and version-fingerprints at compile time.

**Vendored asset layout after redesign:**

```
public/
  bootstrap/
    bootstrap.min.css
    bootstrap.min.js
  bootstrap-icons/
    bootstrap-icons.css
    fonts/
      bootstrap-icons.woff
      bootstrap-icons.woff2
assets/
  vendor/
    openpgp.min.js        ← moved from CDN
  controllers/
    clipboard_controller.js   ← new
    submit_controller.js      ← new
  styles/
    app.css
```

OpenPGP.js is registered in `importmap.php` as a local path entry so it participates in the ES module graph without a separate `<script>` tag.

### JavaScript Architecture

No new JS libraries are introduced. The existing Stimulus + Turbo setup handles all interactivity.

Two new Stimulus controllers replace the inline `<script>` blocks:

| Controller | File | Responsibility |
|---|---|---|
| `clipboard` | `assets/controllers/clipboard_controller.js` | Copy text to clipboard; toggle button label/icon |
| `submit` | `assets/controllers/submit_controller.js` | Encrypt message, POST to `/message/submit`, show inline feedback, redirect on success |

The `submit` controller imports `openpgp` from the importmap, keeping the encryption logic inside the ES module graph.

### Routing

Two new routes are added to `DefaultController`:

| Route | Name | Method | Template |
|---|---|---|---|
| `/about` | `app_about` | GET | `templates/default/about.html.twig` |
| `/privacy` | `app_privacy` | GET | `templates/default/privacy.html.twig` |

Both are simple render-only actions with no form handling.

---

## Components and Interfaces

### `base.html.twig`

The base template is the single source of truth for the global shell. After redesign it provides:

**`<html>` element:**
```twig
<html lang="en" data-bs-theme="dark">
```

**`<head>` block:**
```twig
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{% block title %}SYM.PGP.ONY{% endblock %}</title>
<link rel="stylesheet" href="{{ asset('bootstrap/bootstrap.min.css') }}">
<link rel="stylesheet" href="{{ asset('bootstrap-icons/bootstrap-icons.css') }}">
{% block stylesheets %}{% endblock %}
```

No CDN `<link>` or `<script>` tags remain.

**Skip link (first focusable element in `<body>`):**
```twig
<a class="visually-hidden-focusable" href="#main-content">Skip to main content</a>
```

**`<nav>` element:**
```twig
<nav class="navbar navbar-expand-md bg-body-tertiary" role="navigation" aria-label="Main navigation">
  <div class="container">
    <a class="navbar-brand fw-bold" href="{{ path('app_home') }}">SYM.PGP.ONY</a>
    <button class="navbar-toggler" type="button"
            data-bs-toggle="collapse" data-bs-target="#navbarMenu"
            aria-controls="navbarMenu" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarMenu">
      <ul class="navbar-nav ms-auto">
        <li class="nav-item">
          <a class="nav-link {% if app.request.attributes.get('_route') == 'app_home' %}active{% endif %}"
             {% if app.request.attributes.get('_route') == 'app_home' %}aria-current="page"{% endif %}
             href="{{ path('app_home') }}">Home</a>
        </li>
        {# Verify, About, Privacy follow the same pattern #}
      </ul>
    </div>
  </div>
</nav>
```

`aria-current="page"` is set by comparing `app.request.attributes.get('_route')` to each route name. This is a pure Twig expression — no JS required.

**`<main>` wrapper:**
```twig
<main id="main-content" class="container py-4">
  {% block flash_messages %}
    {% for label, messages in app.flashes %}
      {% for message in messages %}
        <div class="alert alert-{{ label == 'error' ? 'danger' : label }} alert-dismissible fade show"
             role="alert">
          {{ message }}
          <button type="button" class="btn-close" data-bs-dismiss="alert"
                  aria-label="Dismiss alert"></button>
        </div>
      {% endfor %}
    {% endfor %}
  {% endblock %}
  {% block body %}{% endblock %}
</main>
```

Flash messages move inside `<main>` so they are within the landmark region.

**`<footer>` element:**
```twig
<footer class="py-4 mt-auto border-top" role="contentinfo">
  <div class="container d-flex flex-wrap justify-content-between align-items-center">
    <span class="text-body-secondary">
      &copy; {{ "now"|date("Y") }} SYM.PGP.ONY — Privacy-first encrypted messaging.
    </span>
    <ul class="nav">
      <li class="nav-item"><a class="nav-link text-body-secondary" href="{{ path('app_about') }}">About</a></li>
      <li class="nav-item"><a class="nav-link text-body-secondary" href="{{ path('app_privacy') }}">Privacy</a></li>
    </ul>
  </div>
</footer>
```

**Bootstrap JS (end of `<body>`):**
```twig
<script src="{{ asset('bootstrap/bootstrap.min.js') }}"></script>
{{ importmap('app') }}
{% block javascripts %}{% endblock %}
```

`importmap('app')` renders the ES module importmap and loads `assets/app.js` as the entrypoint, which bootstraps Stimulus and Turbo.

---

### `index.html.twig`

Layout: `container` → `row justify-content-center` → `col-md-8 col-lg-6`.

The "How it works" info block uses a `<section>` with an `<h2>`. The form heading is `<h1>`. The Symfony form renders via `form_start` / `form_widget` / `form_end` — no manual field markup needed since `EmailFormType` has a single email field and a submit button.

```twig
<main> {# provided by base #}
  <div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
      <h1 class="mb-4">Generate Secure Message Link</h1>
      <section aria-labelledby="how-it-works-heading" class="alert alert-info mb-4">
        <h2 id="how-it-works-heading" class="h5">How it works</h2>
        <ol class="mb-0">...</ol>
      </section>
      {{ form_start(form, {'attr': {'aria-labelledby': 'page-heading', 'novalidate': 'novalidate'}}) }}
      ...
      {{ form_end(form) }}
    </div>
  </div>
```

---

### `link.html.twig`

Layout: same `col-md-8 col-lg-6` centred column.

The copy button is wired to the `clipboard` Stimulus controller instead of the inline `copyLink()` function.

```twig
<div data-controller="clipboard"
     data-clipboard-success-label-value="Copied!"
     data-clipboard-original-label-value="Copy Link">
  <input type="text" class="form-control" id="linkInput"
         value="{{ url('app_submit', {'token': token}) }}"
         readonly aria-label="Your secure link"
         data-clipboard-target="source">
  <button class="btn btn-outline-primary" type="button"
          data-action="clipboard#copy"
          aria-label="Copy link to clipboard">
    <i class="bi bi-clipboard" aria-hidden="true"></i>
    <span data-clipboard-target="label">Copy Link</span>
  </button>
</div>
```

---

### `submit.html.twig`

The inline `<script>` block and CDN `<script src="...openpgp...">` are removed. The form is wired to the `submit` Stimulus controller.

```twig
<form id="messageForm"
      data-controller="submit"
      data-submit-recipient-value="{{ email }}"
      data-submit-public-key-value="{{ publicKey|e('html_attr') }}"
      data-submit-submit-url-value="{{ path('app_submit_message') }}"
      data-submit-home-url-value="{{ path('app_home') }}">

  <div class="mb-3">
    <label for="message" class="form-label">Your Message</label>
    <textarea class="form-control" id="message" rows="6" required
              data-submit-target="message"></textarea>
  </div>

  {# Inline feedback placeholder — hidden by default #}
  <div class="d-none" data-submit-target="feedback" role="alert"></div>

  {# Loading indicator #}
  <div class="d-none" data-submit-target="loading">
    <div class="alert alert-warning">
      <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
      Encrypting and sending…
    </div>
  </div>

  <button type="submit" class="btn btn-primary" data-submit-target="submitBtn">
    Send Encrypted Message
  </button>
</form>

<noscript>
  <div class="alert alert-danger mt-3" role="alert">
    JavaScript is required for client-side encryption. This page cannot function without it.
    Please enable JavaScript in your browser and reload the page.
  </div>
</noscript>
```

The `submit` controller handles: showing/hiding loading, showing inline success/error alerts, and redirecting after 3 seconds on success.

---

### `verify.html.twig`

Changes:
1. The duplicate submit button is removed. `{{ form_end(form) }}` is called with `render_rest: false` after the explicit single submit button, or the form type is updated to suppress the default row. The cleanest approach: render all fields manually, add one `<button type="submit">`, then call `{{ form_end(form, {'render_rest': false}) }}`.
2. The inline `<script>` copy buttons are replaced with `clipboard` Stimulus controller instances on each textarea wrapper.
3. The inline `<style>` block is removed; equivalent utility classes replace the custom CSS.
4. The `verification-guide` div uses Bootstrap's `border-start border-primary` utilities instead of custom CSS.

```twig
{# Each copy-able field follows this pattern: #}
<div class="mb-3" data-controller="clipboard">
  {{ form_label(form.message, 'Message', {'label_attr': {'class': 'form-label'}}) }}
  <div class="d-flex gap-2">
    {{ form_widget(form.message, {'attr': {'class': 'form-control', 'data-clipboard-target': 'source'}}) }}
    <button type="button" class="btn btn-outline-secondary align-self-start"
            data-action="clipboard#copy"
            aria-label="Copy message to clipboard">
      <i class="bi bi-clipboard" aria-hidden="true"></i>
    </button>
  </div>
</div>
```

The single submit button:
```twig
<div class="d-grid">
  <button type="submit" class="btn btn-primary">Verify Signature</button>
</div>
{{ form_end(form, {'render_rest': false}) }}
```

---

### `about.html.twig`

Static page. Layout: `col-md-8 col-lg-7` centred. Uses `<main>` (from base), `<h1>`, `<section>` elements with `aria-labelledby`. Content covers: what the app does, the core workflow (4 steps), zero-storage principle, no-tracking principle, CTA link to Index_Page.

---

### `privacy.html.twig`

Static page. Same layout as About. Sections cover: no server-side message storage, no cookies or tracking, email address usage (key lookup + delivery only, not retained), tokenised link encoding (encrypted, time-limited, not logged).

---

### Stimulus Controllers

#### `clipboard_controller.js`

```
Targets:  source (input/textarea), label (optional span)
Values:   successLabel (string), originalLabel (string)
Actions:  copy() → navigator.clipboard.writeText(source.value)
          → swap label text for 2 s, then restore
```

Uses `navigator.clipboard.writeText` (async, Permissions API). Falls back to `document.execCommand('copy')` for older browsers.

#### `submit_controller.js`

```
Targets:  message (textarea), feedback (div), loading (div), submitBtn (button)
Values:   recipient (string), publicKey (string), submitUrl (string), homeUrl (string)
Actions:  submit(event) — called on form submit event
```

Lifecycle of `submit()`:
1. `event.preventDefault()`
2. Hide feedback, show loading, disable submitBtn + message
3. `import openpgp` (already in importmap — static import at top of file)
4. `openpgp.readKey` + `openpgp.encrypt`
5. `fetch(submitUrlValue, { method: 'POST', body: JSON.stringify({...}) })`
6. On success: show `alert-success` in feedbackTarget, wait 3 s, `window.location.href = homeUrlValue`
7. On error: show `alert-danger` in feedbackTarget with error message
8. Finally: hide loading, re-enable controls

If `openpgp` is undefined at connect time (library failed to load), the controller shows an error in feedbackTarget immediately and disables the submit button.

---

### `importmap.php` Changes

Add OpenPGP.js as a local path entry:

```php
'openpgp' => [
    'path' => './assets/vendor/openpgp.min.js',
],
```

Remove any CDN version entry for openpgp if present.

---

### `DefaultController.php` Changes

Add two route methods:

```php
#[Route('/about', name: 'app_about')]
public function about(): Response
{
    return $this->render('default/about.html.twig');
}

#[Route('/privacy', name: 'app_privacy')]
public function privacy(): Response
{
    return $this->render('default/privacy.html.twig');
}
```

No constructor changes needed — these methods have no dependencies.

---

## Data Models

No new data models are introduced. This redesign is purely presentational.

The only data flowing into templates that is relevant to the UI layer:

| Template | Variables | Source |
|---|---|---|
| `link.html.twig` | `token` (string) | `DefaultController::index()` |
| `submit.html.twig` | `email` (string), `publicKey` (string) | `DefaultController::submit()` |
| `verify.html.twig` | `form` (FormView) | `DefaultController::verifySignaturePage()` |
| `index.html.twig` | `form` (FormView) | `DefaultController::index()` |
| `about.html.twig` | _(none)_ | static |
| `privacy.html.twig` | _(none)_ | static |

The `publicKey` variable passed to `submit.html.twig` is now rendered into a `data-submit-public-key-value` HTML attribute (escaped with `|e('html_attr')`) rather than a bare JS template literal, which eliminates the XSS risk of the current `{{ publicKey|raw }}` approach.


---

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system — essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

### Property 1: No External Asset References

*For any* page rendered by the application, the resulting HTML SHALL contain no `<link>` or `<script>` tag whose `src` or `href` attribute points to an external domain (i.e. any domain other than the application's own origin).

**Validates: Requirements 1.4**

---

### Property 2: Nav Role and Label Present on Every Page

*For any* page rendered by the application, the HTML SHALL contain a `<nav>` element with `role="navigation"` and `aria-label="Main navigation"`.

**Validates: Requirements 2.1**

---

### Property 3: Active Nav Link Marked with aria-current

*For any* route that has a corresponding nav link (Home, Verify, About, Privacy), rendering that route's page SHALL produce exactly one nav link with `aria-current="page"` — the one matching the current route — and no other nav link SHALL carry that attribute.

**Validates: Requirements 2.4**

---

### Property 4: Footer Role Present on Every Page

*For any* page rendered by the application, the HTML SHALL contain a `<footer>` element with `role="contentinfo"`.

**Validates: Requirements 3.1**

---

### Property 5: Viewport Meta Tag Present on Every Page

*For any* page rendered by the application, the `<head>` SHALL contain `<meta name="viewport" content="width=device-width, initial-scale=1">`.

**Validates: Requirements 6.1**

---

### Property 6: Main Landmark Present on Every Page

*For any* page rendered by the application, the HTML SHALL contain a `<main>` element with `id="main-content"`.

**Validates: Requirements 7.1**

---

### Property 7: Skip Link Present on Every Page

*For any* page rendered by the application, the HTML SHALL contain a skip link `<a href="#main-content">` that appears before the `<nav>` element in document order.

**Validates: Requirements 7.2**

---

### Property 8: Every Form Has an Accessible Name

*For any* page rendered by the application that contains a `<form>` element, that form SHALL have either an `aria-labelledby` attribute referencing an existing heading element or an `aria-label` attribute.

**Validates: Requirements 7.3**

---

### Property 9: Every Input and Textarea Has an Associated Label

*For any* page rendered by the application, every `<input>` and `<textarea>` element SHALL have a corresponding `<label>` element whose `for` attribute matches the input's `id`.

**Validates: Requirements 7.4**

---

### Property 10: Icon-Only Interactive Elements Have Accessible Names

*For any* page rendered by the application, every `<button>` or `<a>` element that contains only an icon (no visible text node) SHALL have an `aria-label` attribute or contain a visually-hidden `<span>` with descriptive text.

**Validates: Requirements 7.5, 12.4**

---

### Property 11: Exactly One h1 Per Page

*For any* page rendered by the application, the HTML SHALL contain exactly one `<h1>` element.

**Validates: Requirements 7.6**

---

### Property 12: Flash Messages Include role="alert"

*For any* flash message rendered by the application, the containing `<div>` SHALL include `role="alert"`.

**Validates: Requirements 7.7**

---

### Property 13: Submit Controller Shows Inline Feedback (Not alert())

*For any* outcome of the submit controller's `submit()` action (success or error), the controller SHALL update the feedback target element's class list and content to reflect the outcome using Bootstrap alert classes (`alert-success` on success, `alert-danger` on error) and SHALL NOT call `window.alert()`. On success, the controller SHALL initiate a redirect to the home URL after 3 seconds. Edge case: if `openpgp` is unavailable at connect time, the feedback target SHALL immediately show `alert-danger` and the submit button SHALL be disabled.

**Validates: Requirements 8.1, 8.2, 8.3, 8.4**

---

### Property 14: Old Application Name Absent from All Pages

*For any* page rendered by the application, the string "PGP Reply-back" SHALL NOT appear anywhere in the rendered HTML.

**Validates: Requirements 10.4**

---

### Property 15: Dark Theme Attribute on html Element

*For any* page rendered by the application, the `<html>` element SHALL carry the attribute `data-bs-theme="dark"`.

**Validates: Requirements 13.1**

---

## Error Handling

### Asset Loading Failures

**OpenPGP.js unavailable:** The `submit` Stimulus controller checks for the `openpgp` module at `connect()` time. If the import fails (e.g. the local file is missing), the ES module system will throw at import time and the controller will not connect. To handle this gracefully, the controller wraps the openpgp import in a try/catch and, on failure, shows the feedback div with an `alert-danger` message and disables the submit button. The `<noscript>` fallback covers the JS-disabled case.

**Bootstrap JS unavailable:** Bootstrap JS is required only for the navbar collapse toggle and the dismiss button on alerts. If it fails to load, the nav remains functional (all links work) but the mobile toggle won't animate. This is an acceptable degradation.

### Form Submission Errors

The `submit` controller handles three error categories:

| Scenario | Handling |
|---|---|
| Encryption failure (bad public key, openpgp error) | Catch in try/catch, show `alert-danger` with `error.message` |
| Network error (fetch throws) | Catch in try/catch, show `alert-danger` |
| Server error (non-2xx response) | Parse JSON body for `error`/`errors` fields, show `alert-danger` |

In all error cases: loading indicator is hidden, submit button and textarea are re-enabled, and the feedback div is shown.

### Route Errors

The two new routes (`/about`, `/privacy`) are static renders with no dependencies. They cannot produce application errors. Standard Symfony 404/500 error pages handle any unexpected failures.

### Template Rendering Errors

If `app.request.attributes.get('_route')` returns null (e.g. on a custom error page), the `aria-current` Twig expression evaluates to false for all nav links — no nav link is marked active. This is correct behaviour.

---

## Testing Strategy

### Dual Testing Approach

Both unit/functional tests and property-based tests are used. They are complementary:

- **Functional tests** (PHPUnit + Symfony's `WebTestCase`): verify specific rendered HTML, route responses, and structural requirements. These are the primary vehicle for this redesign since most properties are about rendered HTML.
- **Property-based tests** (PHPUnit + a PBT library): verify universal properties across generated inputs. For this redesign, the most valuable PBT targets are the Stimulus controller logic and the "no external assets on any page" property.

**Recommended PBT library:** [`eris/eris`](https://github.com/giorgiosironi/eris) — a PHP property-based testing library compatible with PHPUnit. Alternatively, since the Stimulus controllers are JavaScript, [`fast-check`](https://fast-check.dev/) can be used for JS controller unit tests.

### Functional Tests (PHPUnit WebTestCase)

Each correctness property maps to one or more test methods in `tests/Controller/DefaultControllerTest.php` or a new `tests/UI/` test class.

| Property | Test approach |
|---|---|
| P1: No external assets | Render each route, assert no `cdn.` or external domain in `<link>`/`<script>` src/href |
| P2: Nav role/label | Render any page, assert nav element attributes |
| P3: aria-current on active link | Render each of the 4 nav routes, assert exactly one `aria-current="page"` on the correct link |
| P4: Footer role | Render any page, assert footer role |
| P5: Viewport meta | Render any page, assert meta viewport |
| P6: main#main-content | Render any page, assert main element with id |
| P7: Skip link | Render any page, assert skip link before nav |
| P8: Form accessible name | Render index, verify, submit pages; assert form aria attributes |
| P9: Input/label pairing | Render form pages, assert all inputs have matching labels |
| P10: Icon aria-label | Render link, verify pages; assert icon buttons have aria-label |
| P11: One h1 per page | Render all pages, assert count of h1 == 1 |
| P12: Flash role="alert" | Trigger a flash, render page, assert role="alert" on alert div |
| P14: No old name | Render all pages, assert "PGP Reply-back" not in response content |
| P15: Dark theme | Render any page, assert html[data-bs-theme="dark"] |

**Unit tests (specific examples):**
- GET `/about` → 200, contains expected content sections
- GET `/privacy` → 200, contains expected content sections
- GET `/submit/{token}` → contains `<noscript>` element
- GET `/verify` → exactly one `<button type="submit">` in the form
- GET any page → `<title>` contains "SYM.PGP.ONY"
- GET any page → footer contains current year, About link, Privacy link

### Property-Based Tests

**Feature: ui-redesign, Property 1: No external asset references**
Using `eris/eris` or a simple loop over all application routes: for each route, render the page and assert the HTML contains no external domain references in asset tags. Run minimum 100 iterations if using randomised route/parameter generation.

**Feature: ui-redesign, Property 3: Active nav link aria-current**
For each of the four nav routes, assert that rendering that route produces `aria-current="page"` on exactly one nav link. This can be parameterised as a data provider test.

**Feature: ui-redesign, Property 11: Exactly one h1 per page**
For all application routes (parameterised), assert the rendered HTML contains exactly one `<h1>` element.

**Feature: ui-redesign, Property 13: Submit controller inline feedback**
JavaScript property tests using `fast-check` on the `submit_controller.js` logic:
- *For any* mock successful fetch response, the feedback target receives `alert-success` class and `window.alert` is never called
- *For any* mock error fetch response or thrown error, the feedback target receives `alert-danger` class and `window.alert` is never called
- Minimum 100 iterations per property

**Property Test Configuration:**
- Each property-based test MUST run a minimum of 100 iterations
- Each test MUST include a comment referencing the design property: `// Feature: ui-redesign, Property N: <property text>`
- Each correctness property MUST be implemented by a single property-based test

### Test File Layout

```
tests/
  UI/
    BaseTemplateTest.php      ← P1, P2, P4, P5, P6, P7, P11, P14, P15
    NavigationTest.php        ← P3 (aria-current per route)
    AccessibilityTest.php     ← P8, P9, P10, P12
    NewPagesTest.php          ← About + Privacy route/content examples
    VerifyPageTest.php        ← P11 (one submit button), copy buttons
    SubmitPageTest.php        ← noscript, feedback div, P13 (JS tests)
```
