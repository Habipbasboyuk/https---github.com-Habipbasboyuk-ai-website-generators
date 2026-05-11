<?php
require 'wp-load.php';
$all = wp_load_alloptions();
$keys = array_keys($all);
$bricks = array_filter($keys, function($k) { return strpos($k, 'bricks') !== false; });
print_r($bricks);
