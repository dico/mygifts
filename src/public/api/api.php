<?php
require_once '/var/www/html/vendor/autoload.php';
require_once '/var/www/html/backend/Model/Core/Router.php';

\App\Model\Core\Router::dispatchApi();
