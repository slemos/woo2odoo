# Woo2odoo-plugin

A WooCommmerce Extension inspired by [Create Woo Extension](https://github.com/woocommerce/woocommerce/blob/trunk/packages/js/create-woo-extension/README.md).

## Getting Started

### Prerequisites

-   [NPM](https://www.npmjs.com/)
-   [Composer](https://getcomposer.org/download/)
-   [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/)

### Installation and Build

```
npm install
npm run build
wp-env start
```

### Run tests

``
npm run wp-env run -- --env-cwd=wp-content/plugins/woo2odoo wordpress ./vendor/bin/phpunit
``


Visit the added page at http://localhost:8888/wp-admin/admin.php?page=wc-admin&path=%2Fwoo2odoo.
