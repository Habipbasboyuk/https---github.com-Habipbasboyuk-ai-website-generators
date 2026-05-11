<?php
require 'wp-load.php';
$all = wp_load_alloptions();
$keys = array_keys($all);
$acss = array_filter($keys, function($k) { return strpos($k, 'acss') !== false || strpos($k, 'automatic_css') !== false; });
print_r($acss);
