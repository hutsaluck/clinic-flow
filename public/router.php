<?php
// PHP built-in development server only. Nginx/FPM always executes index.php.
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if (str_starts_with($path,'/app/') && !str_contains($path,'..') && is_file(__DIR__.$path)) return false;
$_SERVER['SCRIPT_FILENAME']=__DIR__.'/index.php';
require __DIR__.'/index.php';
