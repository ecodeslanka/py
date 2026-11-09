# Roles & Page Permissions — install notes

## Files
New:      rbac.php, role_form.php, access_denied.php, assets/css/rbac.css, rbac_install.sql
Replaced: role.php (role list: add / edit / duplicate / activate / delete)
Changed:  config.php (+3 lines at the end), header.php (sidebar filter + 4 lines), auth.php (hasPermission)

## Install
1. Upload the files (keep your own config.php and just add these lines before the closing ?>):
       require_once __DIR__ . '/rbac.php';
       rbac_guard();
2. Log in as your admin and open User Master → User Role. The database is updated
   automatically (or run rbac_install.sql in phpMyAdmin). On that first run:
   - every role with "admin" in its name gets Full Access
   - YOUR role gets Full Access (if you have no role, a "Super Admin" role is created and given to you)
3. Create roles, tick View / Add / Edit / Delete per page, then assign roles on the Users page.

## How it works
- The permission list is read from the sidebar in header.php: Section → Main menu → Sub menu → Page.
  Add a link to the sidebar and it appears on the role page automatically.
- Rights are saved in `permissions` (module_name = page file name without .php).
- Users only see sidebar links they can View. Opening a page by URL without View shows "Access denied".
- Requests that look like delete / add / edit (e.g. ?delete=5, action=delete_x, action=save_x,
  action=update_x) are blocked when the role lacks that right. AJAX calls get a JSON 403.
- Full Access roles skip every check. Users with no role can only open the Dashboard
  (change RBAC_NO_ROLE_FULL_ACCESS in rbac.php to true to keep the old behaviour).
- Add / edit / view pages that are not in the sidebar (edit_employee.php, add_customer.php, ...)
  are linked to their menu page in $RBAC_RELATED_PAGES at the top of rbac.php. Add more there.

## In your own pages
    <?php if (canDelete()) { ?> <button>Delete</button> <?php } ?>
    rbac_require('edit');                 // stop the request if the role cannot edit this page
    canEdit('customers')                  // check another page
    <button data-perm="delete">Delete</button>   // hidden automatically when not allowed
    if (RBAC.can.create) { ... }          // in JavaScript

## Permissions follow the sidebar only
The Role page lists only Section -> Main menu -> Sub menu -> Page from header.php. There is no
"Other Pages (not in sidebar)" section any more. Imports, reports and add/edit/delete pages are
controlled through their menu page (see $RBAC_RELATED_PAGES). To clean old rows: open
rbac_repair.php -> "Clear & keep only sidebar-menu permissions", then re-save roles on the Role page.
