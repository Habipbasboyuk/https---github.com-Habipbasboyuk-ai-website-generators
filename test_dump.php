<?php
require 'wp-load.php';
$v = get_option('bricks_global_variables', []);
$c = get_option('bricks_color_palette', []);
echo "Variables:\n";
print_r(array_slice($v, 0, 15));
echo "Palette:\n";
print_r(array_slice($c, 0, 15));
