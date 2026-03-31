# Requirements Document

## Introduction

SYM.PGP.ONY is a stateless, privacy-first web application that lets users receive PGP-encrypted messages via shareable links. The current UI was built to validate functionality and is now being overhauled for public release. This redesign covers: replacing CDN-loaded assets with locally-served ones, adding a proper navigation header and footer, introducing About and Privacy pages, improving accessibility and semantic HTML across all pages, making the layout responsive, replacing browser-native `alert()` calls with inline UI feedback, fixing known UI bugs (duplicate button on verify page), and reviewing all user-facing copy. No new functional features are introduced.

## Glossary

- **App**: The SYM.PGP.ONY Symfony 7.1 web application.
- **AssetMapper**: Symfony's asset pipeline (`symfony/asset-mapper`) used to serve and version static files without a bundler.
- **Base_Template**: `templates/base.html.twig` — the Twig layout inherited by all page templates.
- **Bootstrap**: The Bootstrap 5 CSS/JS library vendored at `public/bootstrap/`.
- **Bootstrap_Icons**: The Bootstrap Icons SVG icon font vendored at `public/bootstrap-icons/` — no CDN.
- **CDN_Asset**: Any CSS or JavaScript file currently loaded from an external URL (e.g. `cdn.jsdelivr.net`).
- **Dark_Theme**: Bootstrap 5 dark color mode applied via `data-bs-theme="dark"` on the `<html>` element.
- **Flash_Message**: A one-time Symfony session message rendered in the Base_Template.
- **Index_Page**: The home page (`/`) — "Generate Secure Message Link" form.
- **Link_Page**: The page shown after link generation (`/`) — "Your Secure Link is Ready".
- **Submit_Page**: The tokenised message submission page (`/submit/{token}`).
- **Verify_Page**: The PGP signature verification page (`/verify`).
- **About_Page**: A new static informational page (`/about`).
- **Privacy_Page**: A new static privacy policy page (`/privacy`).
- **Nav**: The top navigation bar rendered in the Base_Template.
- **Footer**: The bottom site footer rendered in the Base_Template.
- **Stimulus_Controller**: A Stimulus JS controller in `assets/controllers/`.
- **Inline_Feedback**: A visible DOM element (e.g. Bootstrap alert or status text) used instead of `window.alert()`.
- **OpenPGP_JS**: The OpenPGP.js library used for client-side encryption on the Submit_Page.
- **Importmap**: The `importmap.php` file that declares JavaScript modules for AssetMapper.

---

## Requirements

### Requirement 1: Vendor All Third-Party Assets Locally

**User Story:** As a privacy-conscious user, I want the browser to load all assets from the same origin, so that no third-party servers can track my visit.

#### Acceptance Criteria

1. THE App SHALL serve Bootstrap CSS from `public/bootstrap/bootstrap.min.css` via AssetMapper, replacing the `cdn.jsdelivr.net` Bootstrap CSS `<link>` tag in the Base_Template.
2. THE App SHALL serve Bootstrap JS from `public/bootstrap/bootstrap.min.js` via AssetMapper, replacing the `cdn.jsdelivr.net` Bootstrap JS `<script>` tag in the Base_Template.
3. THE App SHALL serve OpenPGP.js from a locally vendored file under `public/` or `assets/vendor/`, replacing the `cdn.jsdelivr.net` OpenPGP.js `<script>` tag in the Submit_Page template.
4. WHEN the Base_Template is rendered, THE App SHALL include no `<link>` or `<script>` tags whose `src` or `href` attribute points to an external domain.
5. THE App SHALL reference Bootstrap CSS and Bootstrap JS using the Twig `asset()` function so AssetMapper applies cache-busting versioning.

---

### Requirement 2: Navigation Header

**User Story:** As a user, I want a consistent navigation bar on every page, so that I can move between sections of the site without using the browser back button.

#### Acceptance Criteria

1. THE Base_Template SHALL render a `<nav>` element with `role="navigation"` and `aria-label="Main navigation"` at the top of every page.
2. THE Nav SHALL display the application name "SYM.PGP.ONY" as a home link pointing to the Index_Page.
3. THE Nav SHALL contain links to: Index_Page (label "Home"), Verify_Page (label "Verify"), About_Page (label "About"), and Privacy_Page (label "Privacy").
4. WHEN the current page matches a Nav link's route, THE Nav SHALL mark that link with `aria-current="page"`.
5. THE Nav SHALL include a responsive collapse toggle (Bootstrap navbar toggler) so the menu is accessible on small viewports.
6. THE Nav SHALL use a `<button>` element with `aria-expanded` and `aria-controls` attributes for the mobile toggle.

---

### Requirement 3: Footer

**User Story:** As a user, I want a footer on every page, so that I can find secondary links and understand the site's purpose at a glance.

#### Acceptance Criteria

1. THE Base_Template SHALL render a `<footer>` element with `role="contentinfo"` at the bottom of every page.
2. THE Footer SHALL display the application name "SYM.PGP.ONY" and a short tagline describing the service.
3. THE Footer SHALL contain links to the About_Page and Privacy_Page.
4. THE Footer SHALL display a copyright notice with the current year rendered via Twig (`{{ "now"|date("Y") }}`).

---

### Requirement 4: About Page

**User Story:** As a new visitor, I want an About page, so that I can understand what SYM.PGP.ONY does and how it protects my privacy.

#### Acceptance Criteria

1. THE App SHALL expose a route `/about` with name `app_about` that renders the About_Page.
2. THE About_Page SHALL use semantic HTML (`<main>`, `<section>`, `<h1>`) and extend the Base_Template.
3. THE About_Page SHALL explain the core workflow: generating a link, sharing it, client-side encryption, server signing, and email delivery.
4. THE About_Page SHALL state the zero-storage and no-tracking design principles.
5. THE About_Page SHALL include a link to the Index_Page inviting the user to generate their first secure link.

---

### Requirement 5: Privacy Page

**User Story:** As a user, I want a Privacy page, so that I can understand what data the application collects and how it is handled.

#### Acceptance Criteria

1. THE App SHALL expose a route `/privacy` with name `app_privacy` that renders the Privacy_Page.
2. THE Privacy_Page SHALL use semantic HTML and extend the Base_Template.
3. THE Privacy_Page SHALL state that no messages are stored server-side.
4. THE Privacy_Page SHALL state that no cookies or tracking technologies are used.
5. THE Privacy_Page SHALL state that the email address entered on the Index_Page is used only to look up a public PGP key and to deliver the encrypted message, and is not retained.
6. THE Privacy_Page SHALL state that the tokenised link encodes the recipient email in an encrypted, time-limited token and is not logged.

---

### Requirement 6: Responsive Layout

**User Story:** As a user on a mobile device, I want the layout to adapt to my screen size, so that I can use the application comfortably without horizontal scrolling.

#### Acceptance Criteria

1. THE Base_Template SHALL include `<meta name="viewport" content="width=device-width, initial-scale=1">`.
2. THE App SHALL use Bootstrap's responsive grid (`container`, `row`, `col-*`) on all page templates so content reflows correctly on viewports from 320 px wide upward.
3. WHEN the viewport width is below the Bootstrap `md` breakpoint (768 px), THE Nav SHALL collapse into a hamburger menu.
4. WHEN the viewport width is below the Bootstrap `md` breakpoint, THE Submit_Page form and Verify_Page form SHALL each occupy the full column width.

---

### Requirement 7: Accessibility — Semantic HTML and ARIA

**User Story:** As a user relying on assistive technology, I want the pages to use correct semantic HTML and ARIA attributes, so that screen readers and keyboard navigation work properly.

#### Acceptance Criteria

1. THE Base_Template SHALL wrap page content in a `<main>` element with `id="main-content"`.
2. THE Base_Template SHALL include a visually-hidden skip link `<a href="#main-content">Skip to main content</a>` as the first focusable element in `<body>`.
3. EVERY `<form>` element in the App SHALL have an accessible name via `aria-labelledby` referencing the form's heading, or via `aria-label`.
4. EVERY `<input>` and `<textarea>` element in the App SHALL have an associated `<label>` element linked by a matching `for`/`id` pair.
5. EVERY icon-only or text-free interactive element (e.g. close button on alerts, copy button) SHALL have an `aria-label` describing its action.
6. THE App SHALL use `<h1>` exactly once per page as the primary page heading, with subsequent headings following a logical hierarchy (`h2`, `h3`).
7. Flash_Message alert `<div>` elements SHALL include `role="alert"` so screen readers announce them immediately.
8. THE Verify_Page collapse toggle SHALL set `aria-expanded="true"` when the collapsible section is open and `aria-expanded="false"` when closed.

---

### Requirement 8: Replace `alert()` with Inline Feedback

**User Story:** As a user submitting a message, I want success and error states shown within the page, so that I am not interrupted by browser-native dialog boxes.

#### Acceptance Criteria

1. WHEN the Submit_Page message is sent successfully, THE App SHALL display an Inline_Feedback success message within the page DOM and redirect to the Index_Page after 3 seconds, instead of calling `window.alert()`.
2. WHEN an error occurs during encryption or submission on the Submit_Page, THE App SHALL display an Inline_Feedback error message within the page DOM instead of calling `window.alert()`.
3. WHEN the OpenPGP.js library fails to load on the Submit_Page, THE App SHALL display an Inline_Feedback error message within the page DOM instead of calling `window.alert()`.
4. THE Inline_Feedback elements SHALL use Bootstrap alert classes (`alert-success`, `alert-danger`) and include `role="alert"` for screen reader announcement.
5. THE Submit_Page SHALL contain a dedicated `<div>` placeholder for Inline_Feedback that is hidden by default and shown by the Stimulus_Controller when needed.

---

### Requirement 9: Fix Duplicate "Verify Signature" Button

**User Story:** As a user on the Verify page, I want only one submit button, so that the interface is not confusing.

#### Acceptance Criteria

1. THE Verify_Page SHALL render exactly one "Verify Signature" submit button within the form.
2. THE Verify_Page form SHALL use `{{ form_end(form) }}` without a second manually added submit button, or SHALL suppress the default form row and render the button once explicitly.

---

### Requirement 10: Application Name Update

**User Story:** As a user, I want the application to be consistently identified as "SYM.PGP.ONY", so that the branding is clear and uniform.

#### Acceptance Criteria

1. THE Base_Template `<title>` block default value SHALL be "SYM.PGP.ONY".
2. THE Nav brand link SHALL display "SYM.PGP.ONY".
3. THE Footer SHALL display "SYM.PGP.ONY".
4. THE App SHALL not display the old name "PGP Reply-back" anywhere in rendered HTML.

---

### Requirement 11: Review and Update User-Facing Copy

**User Story:** As a user, I want clear, accurate instructions on each page, so that I understand how to use the application correctly.

#### Acceptance Criteria

1. THE Index_Page "How it works" section SHALL describe all four steps of the workflow accurately: entering a PGP-associated email, receiving a shareable link, the recipient encrypting a message client-side, and the user receiving the encrypted email.
2. THE Link_Page SHALL clearly state that the link expires in 30 days, that the message is encrypted client-side before sending, and that no data is stored on the server.
3. THE Submit_Page SHALL inform the user that JavaScript and a modern browser are required for client-side encryption, and that the message is encrypted before it leaves the browser.
4. THE Verify_Page instructions SHALL accurately describe the four verification steps: paste the message, paste the PGP signature, paste the sender's public key, and click Verify.
5. THE About_Page and Privacy_Page SHALL be written in plain English, free of technical jargon where possible, and accurately reflect the application's behaviour.

---

### Requirement 12: Bootstrap Icons — Vendored Locally

**User Story:** As a developer, I want icon assets served from the same origin, so that no external requests are made and icons are available offline.

#### Acceptance Criteria

1. THE App SHALL vendor Bootstrap Icons CSS and font files under `public/bootstrap-icons/`, replacing any CDN reference.
2. THE Base_Template SHALL load Bootstrap Icons CSS via the Twig `asset()` function.
3. Icons used in the Nav, buttons, and copy actions SHALL be rendered using Bootstrap Icons CSS classes (e.g. `<i class="bi bi-clipboard">`).
4. EVERY icon used without adjacent visible text SHALL have an `aria-label` or be paired with a visually-hidden `<span>` for screen reader users.

---

### Requirement 13: Dark Color Theme

**User Story:** As a user, I want a dark-themed interface, so that the visual design reflects the secure and privacy-focused nature of the application.

#### Acceptance Criteria

1. THE `<html>` element in the Base_Template SHALL carry `data-bs-theme="dark"` to activate Bootstrap 5's built-in dark color mode.
2. THE App SHALL not override Bootstrap's dark theme variables unless a specific contrast or branding adjustment is required.
3. THE Nav and Footer SHALL use Bootstrap dark-theme-compatible classes so they blend with the overall dark background.
4. Form controls, alerts, and cards SHALL render correctly under the dark theme without custom CSS overrides beyond minor spacing or branding tweaks.

---

### Requirement 14: Progressive Enhancement — No-JavaScript Fallback

**User Story:** As a user with JavaScript disabled, I want pages that do not require JavaScript to still render usefully, so that I am not left with a broken experience.

#### Acceptance Criteria

1. THE Index_Page, Link_Page, Verify_Page, About_Page, and Privacy_Page SHALL render fully functional content when JavaScript is disabled.
2. WHEN JavaScript is disabled, THE Submit_Page SHALL display a visible `<noscript>` message explaining that JavaScript is required for client-side encryption and that the page cannot function without it.
3. THE App SHALL not rely on JavaScript for navigation, layout, or any page other than the Submit_Page.
