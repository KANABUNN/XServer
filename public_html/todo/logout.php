<?php
require __DIR__ . '/../../app/bootstrap.php';
logout_user();
session_start();
flash('success', 'ログアウトしました。');
redirect('login.php');
