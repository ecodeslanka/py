<?php
// Deletes a role (POST + CSRF only). Refuses while users are still assigned to it.
require_once __DIR__ . '/role_helpers.php';
roleRequire('delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: role_manage.php'); exit; }
csrfCheck();

$id = (int)($_POST['id'] ?? 0);
$stmt = mysqli_prepare($conn, "SELECT COUNT(*) c FROM users WHERE role_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$c = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['c'];
if ($c > 0) {
    header('Location: role_manage.php?err=' . urlencode("Cannot delete: $c user(s) still use this role. Reassign them first."));
    exit;
}
$stmt = mysqli_prepare($conn, "DELETE FROM roles WHERE id = ?");   // permissions rows cascade
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
header('Location: role_manage.php?msg=' . urlencode(mysqli_stmt_affected_rows($stmt) ? 'Role deleted.' : 'Role not found.'));
exit;
