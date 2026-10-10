<?php
require_once __DIR__ . '/role_helpers.php';
roleRequire('edit');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = mysqli_prepare($conn, "SELECT * FROM roles WHERE id = ?");
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$role = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
if (!$role) { header('Location: role_manage.php?err=' . urlencode('Role not found.')); exit; }

$errors = [];
$perms = loadRolePerms($id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $name = trim($_POST['role_name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $active = isset($_POST['active']) ? 1 : 0;
    $role = ['id' => $id, 'role_name' => $name, 'description' => $desc, 'active' => $active];
    if ($name === '') $errors[] = 'Role name is required.';
    if (!$errors) {
        $stmt = mysqli_prepare($conn, "UPDATE roles SET role_name=?, description=?, active=? WHERE id=?");
        mysqli_stmt_bind_param($stmt, 'ssii', $name, $desc, $active, $id);
        if (mysqli_stmt_execute($stmt)) {
            saveRolePerms($id, $_POST['perm'] ?? []);
            header('Location: role_manage.php?msg=' . urlencode("Role \"$name\" updated."));
            exit;
        }
        $errors[] = mysqli_errno($conn) == 1062 ? 'A role with this name already exists.' : 'Database error: ' . mysqli_error($conn);
    }
    $perms = [];
    foreach (($_POST['perm'] ?? []) as $k => $v) {
        $perms[$k] = ['can_access' => isset($v['access']), 'can_create' => isset($v['create']), 'can_edit' => isset($v['edit']), 'can_delete' => isset($v['delete'])];
    }
}

$form_title = 'Edit Role: ' . $role['role_name'];
$submit_label = 'Save Changes';
include __DIR__ . '/header.php';
include __DIR__ . '/role_form.php';
