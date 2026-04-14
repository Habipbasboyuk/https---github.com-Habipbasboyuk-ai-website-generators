<?php
require_once __DIR__ . '/wp-load.php';

$global_classes = get_option('bricks_global_classes', []);
if (is_array($global_classes)) {
    $fixed = false;
    foreach ($global_classes as &$class) {
        if (!isset($class['name'])) {
            $class['name'] = $class['id'] ?? 'aisb-recovered-class-' . uniqid();
            $fixed = true;
        }
    }
    unset($class);

    if ($fixed) {
        update_option('bricks_global_classes', $global_classes);
        echo "Fixed Bricks global classes in DB!\n";
    } else {
        echo "No missing names found in global classes.\n";
    }
} else {
    echo "bricks_global_classes is not an array or does not exist.\n";
}
