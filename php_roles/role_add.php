<?php
require_once __DIR__ . '/role_helpers.php';
roleRequire('create');

$errors = [];
$role = ['role_name' => '', 'description' => '', 'active' => 1];
$perms = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $name = trim($_POST['role_name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $active = isset($_POST['active']) ? 1 : 0;
    $role = ['role_name' => $name, 'description' => $desc, 'active' => $active];
    if ($name === '') $errors[] = 'Role name is required.';
    if (!$errors) {
        $stmt = mysqli_prepare($conn, "INSERT INTO roles (role_name, description, active) VALUES (?,?,?)");
        mysqli_stmt_bind_param($stmt, 'ssi', $name, $desc, $active);
        if (mysqli_stmt_execute($stmt)) {
            $id = mysqli_insert_id($conn);
            saveRolePerms($id, $_POST['perm'] ?? []);
            header('Location: role_manage.php?msg=' . urlencode("Role \"$name\" created."));
            exit;
        }
        $errors[] = mysqli_errno($conn) == 1062 ? 'A role with this name already exists.' : 'Database error: ' . mysqli_error($conn);
    }
    foreach (($_POST['perm'] ?? []) as $k => $v) {   // keep the ticks on error
        $perms[$k] = ['can_access' => isset($v['access']), 'can_create' => isset($v['create']), 'can_edit' => isset($v['edit']), 'can_delete' => isset($v['delete'])];
    }
}

$form_title = 'Add Role';
$submit_label = 'Create Role';
include __DIR__ . '/header.php';
include __DIR__ . '/role_form.php';
