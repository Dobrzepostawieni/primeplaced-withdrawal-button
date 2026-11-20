# PrimePlaced - Withdrawal Button & Refunds for WooCommerce

Withdrawal button and returns for WooCommerce: the EU right of withdrawal
form, a confirmation email with a PDF, a returns panel and refunds. Customers
withdraw without an account; the shop handles every request and refund in
WooCommerce.

This repository holds the human-readable source of the free plugin published
on WordPress.org:
https://wordpress.org/plugins/primeplaced-withdrawal-button/

Each release matches the code in the plugin directory's SVN tag of the same
version. Install the plugin from WordPress.org; this repository is for reading
and rebuilding the code.

Documentation: https://dobrzepostawieni.pl/withdrawal-button/documentation

## Building the JavaScript

The compiled file in `assets/build/` is generated from `assets/src/`
(`assets/src/front/form.ts` becomes `assets/build/ppwb-form.js`). All other
JavaScript, CSS and PHP files are shipped unminified.

Requirements: Node.js 20 or newer, npm 10 or newer.

```sh
npm ci
npm run build
```

## PHP dependencies

The PHP libraries (Freemius SDK, Dompdf and their dependencies) are shipped in
the WordPress.org package in `vendor/` and `vendor-prefixed/`. The libraries in
`vendor-prefixed/` are copies with prefixed namespaces, made with
[Strauss](https://github.com/BrianHenryIE/strauss), so they do not conflict
with other plugins. `composer.json` lists them.

## License

GPL-3.0-or-later. See `LICENSE.txt`.
