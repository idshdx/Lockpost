# Implementation Plan: UI Redesign

## Overview

Migrate SYM.PGP.ONY from a prototype UI to a production-ready interface. All work is purely presentational — no business logic changes. Tasks are ordered so each step builds on the previous: vendor assets first, then the base shell, then page templates, then JS controllers, then tests.

## Tasks

- [x] 1. Vendor Bootstrap Icons locally
  - Download Bootstrap Icons release (CSS + `fonts/` directory) into `public/bootstrap-icons/`
  - Required files: `bootstrap-icons.css`, `fonts/bootstrap-icons.woff`, `fonts/bootstrap-icons.woff2`
  - No Twig changes yet — just the files on disk so `asset()` can resolve them
  - _Requirements: 12.1_

- [x] 2. Vendor OpenPGP.js locally and update importmap
  - Copy `openpgp.min.js` into `assets/vendor/openpgp.min.js`
  - Add entry to `importmap.php`: `'openpgp' => ['path' => './assets/vendor/openpgp.min.js']`
  - Remove any existing CDN openpgp entry from `importmap.php` if present
  - _Requirements: 1.3_

- [x] 3. Rewrite `base.html.twig`
  - [x] 3.1 Update `<html>` and `<head>`
    - Set `<html lang="en" data-bs-theme="dark">`
    - Add `<meta name="viewport" content="width=device-width, initial-scale=1">`
    - Set default `<title>` to "SYM.PGP.ONY"
    - Replace CDN Bootstrap CSS `<link>` with `{{ asset('bootstrap/bootstrap.min.css') }}`
    - Add Bootstrap Icons CSS: `{{ asset('bootstrap-icons/bootstrap-icons.css') }}`
    - Remove all CDN `<link>` tags
    - _Requirements: 1.1, 1.4, 1.5, 6.1, 10.1, 13.1_

  - [x] 3.2 Add skip link, nav, main wrapper, footer
    - Add skip link `<a class="visually-hidden-focusable" href="#main-content">Skip to main content</a>` as first focusable element in `<body>`
    - Replace existing `<nav>` with full Bootstrap navbar: brand "SYM.PGP.ONY", links to Home/Verify/About/Privacy, responsive toggler with `aria-expanded`/`aria-controls`, `aria-current="page"` via Twig route comparison
    - Wrap body content in `<main id="main-content" class="container py-4">` with flash messages block inside it (using `role="alert"` on each flash div)
    - Add `<footer role="contentinfo">` with tagline, copyright year via `{{ "now"|date("Y") }}`, About and Privacy links
    - Replace CDN Bootstrap JS `<script>` with `{{ asset('bootstrap/bootstrap.min.js') }}`; keep `{{ importmap('app') }}`
    - Remove old `<div class="container mt-4">` flash block and `<div class="container mt-2">` body wrapper
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 3.1, 3.2, 3.3, 3.4, 7.1, 7.2, 7.7, 10.1, 10.2, 10.3, 10.4, 13.3_

- [x] 4. Add `/about` and `/privacy` routes to `DefaultController`
  - Add `#[Route('/about', name: 'app_about')]` method `about()` returning `render('default/about.html.twig')`
  - Add `#[Route('/privacy', name: 'app_privacy')]` method `privacy()` returning `render('default/privacy.html.twig')`
  - No constructor changes needed
  - _Requirements: 4.1, 5.1_

- [x] 5. Create `templates/default/about.html.twig`
  - Extend `base.html.twig`; set `{% block title %}About — SYM.PGP.ONY{% endblock %}`
  - Layout: `col-md-8 col-lg-7` centred column
  - Single `<h1>About SYM.PGP.ONY</h1>`
  - `<section aria-labelledby>` blocks covering: what the app does, the 4-step workflow, zero-storage principle, no-tracking principle
  - CTA link to `path('app_home')` inviting the user to generate their first secure link
  - _Requirements: 4.2, 4.3, 4.4, 4.5, 7.6, 11.5_

- [x] 6. Create `templates/default/privacy.html.twig`
  - Extend `base.html.twig`; set `{% block title %}Privacy — SYM.PGP.ONY{% endblock %}`
  - Same layout as About
  - Single `<h1>Privacy Policy</h1>`
  - Sections covering: no server-side message storage, no cookies or tracking, email address usage (key lookup + delivery only, not retained), tokenised link encoding (encrypted, time-limited, not logged)
  - _Requirements: 5.2, 5.3, 5.4, 5.5, 5.6, 7.6, 11.5_

- [x] 7. Update `templates/default/index.html.twig`
  - Remove outer `<div class="container">` (base now provides it) and `<div class="card">` wrapper
  - Layout: `row justify-content-center` → `col-md-8 col-lg-6`
  - Change `<h2>` to `<h1 class="mb-4">Generate Secure Message Link</h1>`
  - Wrap "How it works" in `<section aria-labelledby="how-it-works-heading">` with `<h2 id="how-it-works-heading">` inside
  - Add `aria-labelledby="page-heading"` (or equivalent) and `novalidate` to `form_start`
  - Update copy per Requirements 11.1
  - _Requirements: 6.2, 7.3, 7.6, 11.1_

- [x] 8. Update `templates/default/link.html.twig`
  - Remove outer `<div class="container">` and `<div class="card">` wrapper
  - Layout: `row justify-content-center` → `col-md-8 col-lg-6`
  - Change `<h2>` to `<h1>`
  - Replace `onclick="copyLink()"` button and inline `<script>` with `clipboard` Stimulus controller wiring:
    - Wrap input + button in `<div data-controller="clipboard" data-clipboard-success-label-value="Copied!" data-clipboard-original-label-value="Copy Link">`
    - Add `data-clipboard-target="source"` to the input
    - Add `data-action="clipboard#copy"` and `aria-label="Copy link to clipboard"` to the button; replace button text with `<i class="bi bi-clipboard" aria-hidden="true"></i> <span data-clipboard-target="label">Copy Link</span>`
  - Remove `{% block javascripts %}` inline script block
  - Update copy per Requirements 11.2
  - _Requirements: 6.2, 7.5, 10.4, 11.2, 12.3, 12.4_

- [x] 9. Update `templates/default/submit.html.twig`
  - Remove outer `<div class="container">` and `<div class="card">` wrapper; remove inline `<style>` block
  - Layout: `row justify-content-center` → `col-md-8 col-lg-6`
  - Change `<h2>` to `<h1>`
  - Replace `<form onsubmit="...">` with `<form data-controller="submit" data-submit-recipient-value="{{ email }}" data-submit-public-key-value="{{ publicKey|e('html_attr') }}" data-submit-submit-url-value="{{ path('app_submit_message') }}" data-submit-home-url-value="{{ path('app_home') }}">`
  - Add `data-submit-target="message"` to textarea, `data-submit-target="submitBtn"` to submit button
  - Add hidden feedback div: `<div class="d-none" data-submit-target="feedback" role="alert"></div>`
  - Add hidden loading div: `<div class="d-none" data-submit-target="loading">` with spinner markup
  - Add `<noscript>` block with `alert-danger` message explaining JS is required
  - Remove both CDN `<script>` tags and the entire `{% block javascripts %}` inline script
  - Update copy per Requirements 11.3
  - _Requirements: 6.2, 7.3, 7.6, 8.1, 8.2, 8.3, 8.4, 8.5, 11.3, 14.2_

- [x] 10. Update `templates/default/verify.html.twig`
  - Remove inline `<style>` block; replace custom CSS with Bootstrap utilities (`border-start border-primary ps-3` etc.)
  - Remove outer `<div class="container">` if present; layout: `col-md-8`
  - Change page heading to `<h1>`
  - Wire each copy button to `clipboard` Stimulus controller: wrap each field's label+widget+button in `<div class="mb-3" data-controller="clipboard">`, add `data-clipboard-target="source"` to each `form_widget`, replace copy buttons with `<button type="button" data-action="clipboard#copy" aria-label="Copy [field] to clipboard"><i class="bi bi-clipboard" aria-hidden="true"></i></button>`
  - Fix duplicate submit button: render fields manually, add exactly one `<button type="submit" class="btn btn-primary">Verify Signature</button>`, then call `{{ form_end(form, {'render_rest': false}) }}`
  - Remove `{% block javascripts %}` inline script block
  - _Requirements: 7.3, 7.5, 7.6, 9.1, 9.2, 11.4, 12.3, 12.4_

- [x] 11. Create `assets/controllers/clipboard_controller.js`
  - Targets: `source` (input/textarea), `label` (optional span)
  - Values: `successLabel` (string), `originalLabel` (string)
  - `copy()` action: call `navigator.clipboard.writeText(this.sourceTarget.value)`; on success swap label text for 2 s then restore; fall back to `document.execCommand('copy')` for older browsers
  - Export as default Stimulus controller class
  - _Requirements: 12.3_

- [x] 12. Create `assets/controllers/submit_controller.js`
  - Targets: `message` (textarea), `feedback` (div), `loading` (div), `submitBtn` (button)
  - Values: `recipient` (string), `publicKey` (string), `submitUrl` (string), `homeUrl` (string)
  - `connect()`: import openpgp (static import at top of file); if unavailable show `alert-danger` in feedbackTarget and disable submitBtn
  - `submit(event)` action:
    1. `event.preventDefault()`
    2. Hide feedback, show loading, disable submitBtn + message
    3. `openpgp.readKey` + `openpgp.encrypt` using `publicKeyValue` and `recipientValue`
    4. `fetch(submitUrlValue, { method: 'POST', body: JSON.stringify({encrypted, recipient}) })`
    5. On 2xx: show `alert-success` in feedbackTarget, wait 3 s, redirect to `homeUrlValue`
    6. On non-2xx: parse JSON body for `error`/`errors`, show `alert-danger`
    7. On thrown error: show `alert-danger` with `error.message`
    8. Finally: hide loading, re-enable controls
  - SHALL NOT call `window.alert()` under any code path
  - _Requirements: 8.1, 8.2, 8.3, 8.4, 8.5_

- [x] 13. Clean up `public/styles.css` and `assets/styles/app.css`
  - Remove `background-color: skyblue` from `assets/styles/app.css`
  - Add minimal dark-theme tweaks to `public/styles.css` only if Bootstrap variables are insufficient (e.g. body min-height for sticky footer via `d-flex flex-column` on `<body>`)
  - Do not add overrides that duplicate Bootstrap dark theme defaults
  - _Requirements: 13.2, 13.4_

- [x] 14. Checkpoint — wire Stimulus controllers into `assets/controllers.json` if needed
  - Verify `clipboard_controller.js` and `submit_controller.js` are auto-discovered by `@symfony/stimulus-bundle` (they should be, given the `assets/controllers/` convention)
  - If not auto-discovered, add entries to `assets/controllers.json`
  - Ensure `assets/app.js` imports and starts Stimulus (already done via `assets/bootstrap.js`)
  - Ensure all tests pass, ask the user if questions arise.

- [x] 15. Write PHPUnit functional tests — `tests/UI/BaseTemplateTest.php`
  - Extend `WebTestCase`; boot client and request each route (/, /about, /privacy, /verify, /submit/{valid-token} where feasible)
  - [x] 15.1 Write property test for P1: No external asset references
    - **Property 1: No External Asset References**
    - For each rendered page assert no `<link href>` or `<script src>` contains an external domain
    - **Validates: Requirements 1.4**
  - [x] 15.2 Write property test for P2: Nav role and label present on every page
    - **Property 2: Nav Role and Label Present on Every Page**
    - Assert `<nav role="navigation" aria-label="Main navigation">` present in every response
    - **Validates: Requirements 2.1**
  - [x] 15.3 Write property test for P4: Footer role present on every page
    - **Property 4: Footer Role Present on Every Page**
    - Assert `<footer` with `role="contentinfo"` present in every response
    - **Validates: Requirements 3.1**
  - [x] 15.4 Write property test for P5: Viewport meta tag present on every page
    - **Property 5: Viewport Meta Tag Present on Every Page**
    - Assert `<meta name="viewport" content="width=device-width, initial-scale=1">` in every `<head>`
    - **Validates: Requirements 6.1**
  - [x] 15.5 Write property test for P6: Main landmark present on every page
    - **Property 6: Main Landmark Present on Every Page**
    - Assert `<main id="main-content"` present in every response
    - **Validates: Requirements 7.1**
  - [x] 15.6 Write property test for P7: Skip link present on every page
    - **Property 7: Skip Link Present on Every Page**
    - Assert `<a` with `href="#main-content"` appears before `<nav` in document order
    - **Validates: Requirements 7.2**
  - [x] 15.7 Write property test for P11: Exactly one h1 per page
    - **Property 11: Exactly One h1 Per Page**
    - Assert `substr_count($html, '<h1') === 1` for every rendered page
    - **Validates: Requirements 7.6**
  - [x] 15.8 Write property test for P14: Old application name absent from all pages
    - **Property 14: Old Application Name Absent from All Pages**
    - Assert "PGP Reply-back" does not appear in any rendered page
    - **Validates: Requirements 10.4**
  - [x] 15.9 Write property test for P15: Dark theme attribute on html element
    - **Property 15: Dark Theme Attribute on html Element**
    - Assert `<html` contains `data-bs-theme="dark"` in every response
    - **Validates: Requirements 13.1**

- [x] 16. Write PHPUnit functional tests — `tests/UI/NavigationTest.php`
  - [x] 16.1 Write property test for P3: Active nav link marked with aria-current
    - **Property 3: Active Nav Link Marked with aria-current**
    - For each of the 4 nav routes (app_home, app_verify, app_about, app_privacy): render the page, assert exactly one `aria-current="page"` attribute exists and it is on the link matching the current route
    - Use a PHPUnit data provider to parameterise over the 4 routes
    - **Validates: Requirements 2.4**

- [x] 17. Write PHPUnit functional tests — `tests/UI/AccessibilityTest.php`
  - [x] 17.1 Write property test for P8: Every form has an accessible name
    - **Property 8: Every Form Has an Accessible Name**
    - For index, verify, submit pages: assert every `<form` element has `aria-labelledby` or `aria-label`
    - **Validates: Requirements 7.3**
  - [x] 17.2 Write property test for P9: Every input and textarea has an associated label
    - **Property 9: Every Input and Textarea Has an Associated Label**
    - For all form pages: for each `<input id="X">` and `<textarea id="X">` assert a `<label for="X">` exists
    - **Validates: Requirements 7.4**
  - [x] 17.3 Write property test for P10: Icon-only interactive elements have accessible names
    - **Property 10: Icon-Only Interactive Elements Have Accessible Names**
    - For link and verify pages: assert every `<button>` or `<a>` containing only an `<i>` (no text node) has an `aria-label`
    - **Validates: Requirements 7.5, 12.4**
  - [x] 17.4 Write property test for P12: Flash messages include role="alert"
    - **Property 12: Flash Messages Include role="alert"**
    - Trigger a flash message (e.g. submit invalid form), follow redirect, assert the flash `<div>` has `role="alert"`
    - **Validates: Requirements 7.7**

- [x] 18. Write PHPUnit functional tests — `tests/UI/NewPagesTest.php`
  - Test GET `/about` returns 200 and contains expected `<h1>`, section headings, and CTA link to `/`
  - Test GET `/privacy` returns 200 and contains expected `<h1>` and all four privacy statement sections
  - Test that both pages extend base (contain nav, footer, skip link)
  - _Requirements: 4.1–4.5, 5.1–5.6_

- [x] 19. Write PHPUnit functional tests — `tests/UI/VerifyPageTest.php`
  - Test GET `/verify` returns exactly one `<button type="submit">` inside the form
  - Test that copy buttons have `aria-label` attributes
  - Test that the page contains `<h1>` (not `<h2>`) as primary heading
  - _Requirements: 9.1, 9.2, 7.5, 7.6_

- [x] 20. Write PHPUnit functional tests — `tests/UI/SubmitPageTest.php`
  - Test GET `/submit/{token}` (using a valid token from `TokenLinkService`) contains `<noscript>` element
  - Test that the feedback `<div>` placeholder exists and is hidden by default (`d-none` class)
  - Test that the form has `data-controller="submit"` attribute
  - Test that `publicKey` is rendered into `data-submit-public-key-value` (not a bare JS variable)
  - _Requirements: 8.5, 14.2_

- [x] 21. Final checkpoint — Ensure all tests pass
  - Run `docker exec php php bin/phpunit --no-coverage`
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Properties P13 (submit controller inline feedback) is a JS-layer property; the PHPUnit tests cover the server-rendered preconditions (feedback div present, no `window.alert` in template source); full JS property testing with fast-check is out of scope for the PHPUnit suite
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation
