<?php
require __DIR__ . '/../../apps/todo_core/bootstrap.php';
logout_user();
session_start();
flash('success', 'ログアウトしました。');
redirect('login.php');
