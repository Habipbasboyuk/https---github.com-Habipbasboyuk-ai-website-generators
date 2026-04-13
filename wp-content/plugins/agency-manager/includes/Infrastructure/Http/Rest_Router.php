<?php
namespace AM\Infrastructure\Http;

use AM\Infrastructure\Http\Controllers\Projects_Controller;
use AM\Infrastructure\Http\Controllers\Tasks_Controller;
use AM\Infrastructure\Http\Controllers\Dependencies_Controller;
use AM\Infrastructure\Http\Controllers\Activity_Controller;
use AM\Infrastructure\Http\Controllers\Settings_Controller;

if (!defined('ABSPATH')) { exit; }

final class Rest_Router {
    public static function register_routes(): void {
        (new Projects_Controller())->register();
        (new Tasks_Controller())->register();
        (new Dependencies_Controller())->register();
        (new Activity_Controller())->register();
        (new Settings_Controller())->register();
    }
}
