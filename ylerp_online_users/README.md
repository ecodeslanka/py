# YLERP – Live Online Users panel

Shows who is logged in and working right now: user, role, **IP address**, **device (PC / Mobile / Tablet)**,
OS, browser, current page, login time, time online and last action. Updates live (every 5 s).

## Files (copy into the ERP root, same folder as `config.php`)
| File | Type | Purpose |
|---|---|---|
| `online_presence.php` | new | Presence table (`user_presence`, auto-created), touch/list/remove helpers |
| `online_users_api.php` | new | JSON endpoint: `?action=ping` (heartbeat) and `?action=list` |
| `online_users.php` | new | Full live page (cards, filters, table) |
| `header.php` | **replace** | Adds the "users online" button + dropdown next to the notification bell, and the heartbeat |
| `logout.php` | **replace** | Removes the user from the live list on logout |

`header.php` / `logout.php` are your originals with small additions only (diff them to see). No DB migration needed.

## How it works
* Every page load (via `header.php`) and a 30 s background ping record a heartbeat in `user_presence`.
* **Online** = heartbeat in the last 90 s. **Working** = real activity (mouse/keys/touch/page load) in the last 2 min, otherwise **Idle**.
* Closing the browser drops the user from the list after ~90 s; logout removes them immediately.
* All timing uses the database clock, so PHP/MySQL timezone differences don't matter.

## Access
Only full-access roles (`roles.full_access = 1`) see the panel and page, because it exposes IP addresses.
To show it to every logged-in user set `PRESENCE_VIEW_ALL` to `true` in `online_presence.php`.
Add a menu link to `online_users.php` in the sidebar if you want one (pages outside the RBAC menu are not role-guarded, the page checks access itself).

## Notes
* IP comes from `REMOTE_ADDR`; proxy headers (`CF-Connecting-IP`, `X-Real-IP`, `X-Forwarded-For`) are only trusted when the direct peer is a private/loopback address, so users can't spoof their IP.
* Tested end-to-end on MariaDB with PHP 8 (two users, PC + iPhone, idle/offline transitions, 401/403 handling, logout removal). The header UI itself was not rendered in a browser.
