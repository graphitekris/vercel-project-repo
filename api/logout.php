<?php
require 'config.php';
require 'auth.php';

logout();
header('Location: signin.php');
exit;