// =====================================================
// SIDEBAR ICON-ONLY COLLAPSE (with localStorage)
// =====================================================
var SIDEBAR_STORAGE_KEY = 'yms_sidebar_icon_only';

function applySidebarState() {
    var sidebar = document.getElementById('sidebar');
    var btn     = document.getElementById('sidebarCollapseBtn');
    if (!sidebar) return;

    var isIconOnly = localStorage.getItem(SIDEBAR_STORAGE_KEY) === 'true';

    if (isIconOnly) {
        sidebar.classList.add('icon-only');
        if (btn) {
            btn.querySelector('i').className   = 'fa-solid fa-angles-right';
            btn.querySelector('.collapse-label').textContent = 'Show Menu';
        }
    } else {
        sidebar.classList.remove('icon-only');
        if (btn) {
            btn.querySelector('i').className   = 'fa-solid fa-angles-left';
            btn.querySelector('.collapse-label').textContent = 'Hide Menu';
        }
    }
}

function toggleSidebarIconOnly() {
    var current = localStorage.getItem(SIDEBAR_STORAGE_KEY) === 'true';
    localStorage.setItem(SIDEBAR_STORAGE_KEY, (!current).toString());
    applySidebarState();
}

// =====================================================
// MOBILE SIDEBAR TOGGLE
// =====================================================
function toggleSidebar() {
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('overlay');

    if (!sidebar) return;
    sidebar.classList.toggle('collapsed');
    if (overlay) overlay.classList.toggle('active');
}

// =====================================================
// USER DROPDOWN
// =====================================================
function toggleUserDropdown() {
    var dropdown = document.getElementById('userDropdown');
    if (!dropdown) return;
    dropdown.classList.toggle('active');
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    var userProfile = document.querySelector('.user-profile');
    var dropdown    = document.getElementById('userDropdown');
    if (dropdown && userProfile && !userProfile.contains(event.target)) {
        dropdown.classList.remove('active');
    }
});

// =====================================================
// SUBMENU TOGGLE
// =====================================================
function toggleSubmenu(element) {
    var sidebar = document.getElementById('sidebar');
    // Don't open submenus in icon-only mode
    if (sidebar && sidebar.classList.contains('icon-only')) return;

    var submenu = element.nextElementSibling;
    var toggle  = element.querySelector('.submenu-toggle');

    if (submenu) submenu.classList.toggle('open');
    if (toggle)  toggle.classList.toggle('open');
}

// =====================================================
// DOMContentLoaded INIT
// =====================================================
document.addEventListener('DOMContentLoaded', function() {

    // Apply saved sidebar state on every page load
    applySidebarState();

    // Auto-open submenu if an active item is inside
    var activeSubmenuItem = document.querySelector('.submenu-item.active');
    if (activeSubmenuItem) {
        var submenu  = activeSubmenuItem.closest('.submenu');
        var menuItem = submenu ? submenu.previousElementSibling : null;
        var toggle   = menuItem ? menuItem.querySelector('.submenu-toggle') : null;

        if (submenu)  submenu.classList.add('open');
        if (toggle)   toggle.classList.add('open');
    }

    // Close mobile sidebar when a plain menu item (non-submenu) is clicked
    document.querySelectorAll('.menu-item:not(.has-submenu), .submenu-item').forEach(function(item) {
        item.addEventListener('click', function() {
            if (window.innerWidth <= 768) {
                var sidebar = document.getElementById('sidebar');
                var overlay = document.getElementById('overlay');
                if (sidebar && !sidebar.classList.contains('collapsed')) {
                    sidebar.classList.add('collapsed');
                    if (overlay) overlay.classList.remove('active');
                }
            }
        });
    });
});