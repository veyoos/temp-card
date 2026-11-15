<?php
// Change these settings for your MySQL server before installation.
const DB_HOST = 'localhost';
const DB_NAME = 'temp_card';
const DB_USER = 'root';
const DB_PASS = '';
date_default_timezone_set('Asia/Tehran');
session_set_cookie_params(['httponly'=>true, 'samesite'=>'Lax', 'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
session_start();
