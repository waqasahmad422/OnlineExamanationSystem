<?php
require_once './app/config.php';
require_once './app/helpers.php';
require_once './app/auth.php';

logout_user();
set_message('success', 'You have been logged out successfully.');
redirect('./index.php');
?>
