<?php

use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

if ($_SERVER['REQUEST_URI'] === '/' || $_SERVER['REQUEST_URI'] === '') {
    header('Location: /dashboard');
    exit;
}

return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
