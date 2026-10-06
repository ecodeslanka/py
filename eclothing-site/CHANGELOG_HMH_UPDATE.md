# HMH Trading — Theme & KOKO Payment Update

## 1. New brand theme
- `assets/css/style.css` — CSS variables recolored to match the HMH Trading logo
  (deep red `#C51718` + yellow `#F0E335`). Because the theme already used CSS
  variables consistently, this recolors the header, nav, buttons, prices, and
  badges across the whole storefront without changing any layout.
- Admin panel styling (`assets/css/admin.css`) was intentionally left untouched.

## 2. Mobile "app-like" experience
- Added a fixed bottom app tab bar (Home / Categories / Cart / Search /
  Account) on screens ≤900px, with safe-area padding for notched phones.
- Condensed sticky mobile header (smaller logo, utility bar hidden, larger
  tap targets throughout).
- Added `theme-color`, `apple-mobile-web-app-*` and touch-icon meta tags so
  the site behaves more like an installed app when added to a phone's home
  screen.
- Files touched: `includes/header.php`, `includes/footer.php`,
  `assets/css/style.css`.

## 3. KOKO (Buy Now, Pay Later) payment gateway — real integration
Built from the actual request/response contract used by KOKO's official
"Paykoko" WooCommerce plugin (the `2_0_11_6_.zip` you provided), so credentials
issued for that plugin work here unchanged.

**New/changed files:**
- `includes/functions.php` — new `koko_*` functions: config, RSA-SHA256
  signing (`koko_sign`), verification (`koko_verify`), building the
  auto-submitting redirect form (`koko_build_redirect_form`), and applying
  the payment callback (`koko_apply_callback`). New `site_settings` columns
  for merchant ID / API key / private key / public key / callback secret,
  auto-added on first page load — no manual SQL needed.
- `koko_callback.php` — **new file**. This is the single URL KOKO redirects
  the customer's browser back to after payment. It verifies KOKO's RSA
  signature and marks the order paid/failed.
- `checkout.php` — KOKO is now a real online payment option: selecting it
  redirects the customer to KOKO's hosted payment page instead of just
  recording "pay via KOKO" as a label.
- `admin/payment_settings.php` — new "KOKO — Buy Now, Pay Later" panel:
  enable toggle, sandbox/production switch, Merchant ID, API Key, Private
  Key / Public Key (PEM textareas), optional callback secret, and a
  **local-only signature self-test** (no network call — signs and verifies a
  sample string with your saved keys so you can catch a bad/mismatched key
  pair before a real customer hits checkout).
- `admin/site_configuration.php` — the existing KOKO badge toggle now links
  to Payment Settings for full setup.

## What you still need to do on your real server
1. Deploy this folder and load any page once — the new `koko_*` and
   confirmed `genie_*` columns are added to `site_settings` automatically.
2. Go to **Admin → Payment Settings → KOKO** and enter your real Merchant ID,
   API Key, and the Private/Public RSA key pair KOKO issued you.
3. Click **Test KOKO Signature** to confirm the key pair is valid (this runs
   locally on your server — no live order is created).
4. Give KOKO / your Daraz Digital Payments contact the **callback URL**
   shown on that page (this is your `_returnUrl` / `_responseUrl` /
   `_cancelUrl`).
5. Place a real sandbox order choosing "KOKO" at checkout to confirm the
   full round trip (redirect to KOKO → pay → redirect back → order marked
   paid).

This could not be tested against KOKO's live servers or a live database in
the environment this update was built in (no network route to paykoko.com,
no MySQL instance) — the RSA signing/verification logic itself **was**
unit-tested and confirmed correct, but the end-to-end flow should be
verified once on your real hosting before going live with production keys.

## 4. Fixes (follow-up)
- **KOKO "signature mismatch" false error, fixed.** The self-test previously
  assumed your Private Key and KOKO's Public Key were a matching pair and
  tried to verify one against the other — they never are (they're two
  separate, unrelated keys by design: yours vs. KOKO's), so the test always
  failed even with completely correct values. It now validates each key
  independently (structurally valid RSA PEM + that signing works with the
  Private Key) and no longer cross-checks them. Button renamed **"Test KOKO
  Keys"**.
- **Cart error diagnostics improved.** `cart.php` and `cart_add()` themselves
  were not touched by this update (they sit far from any of the edited
  code), so this could not be reproduced in this sandbox (no live DB/server
  here). The cart JS in `includes/footer.php` now shows the *actual* server
  response in the browser console instead of a generic message when
  `cart.php` doesn't return valid JSON — open DevTools → Console (or Network
  tab → the `cart.php` request → Response) next time it happens and it will
  show the real PHP/server error.

## 5. New pages — Privacy Policy, Terms & Conditions, Refund Policy
- `privacy-policy.php`, `terms-conditions.php`, `refund-policy.php` — new,
  editable boilerplate pages matching the new theme. Company name, contact
  email/phone, and address are pulled live from **Admin → Site
  Configuration**.
- Linked from the footer's new "Policies" column and the footer bottom bar.
- Reachable at `/privacy-policy`, `/terms-conditions`, `/refund-policy`
  automatically via the existing `.htaccess` rule (no `.htaccess` changes
  needed).
- These are plain PHP files (not admin-managed content) — edit the text
  directly in the file if you want to adjust the wording.
