# php_app
Extracted ERP base (from zlink_proxy.php.zip) plus the role / page-permission manager.

- Set `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME` (and `GOOGLE_MAPS_API_KEY`) as environment variables; secrets were removed from the code.
- Role Manager: `role_manage.php` (list), `role_add.php`, `role_edit.php`, `role_delete.php`; page list in `menu_catalog.php` (mirrors the `header.php` menu).
- Permissions are stored per page in `permissions` (module_name = page file without `.php`), checked with `hasPermission($page, 'access|create|edit|delete')` from `auth.php`.
- Excluded from the repo: logs, the bundled PhpSpreadsheet zip.
