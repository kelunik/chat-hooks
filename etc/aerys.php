<?php

use Auryn\Injector;
use function Aerys\router;

$config = require __DIR__ . "/config/config.php";

$injector = new Injector;
$injector->share(new \Amp\Mysql\Pool(sprintf(
    "host=%s;user=%s;pass=%s;db=%s",
    $config["database"]["host"],
    $config["database"]["user"],
    $config["database"]["pass"],
    $config["database"]["name"]
)));

$injector->alias("Kelunik\\Chat\\Integration\\HookRepository", "Kelunik\\Chat\\Integration\\MysqlHookRepository");

$handler = $injector->make("Kelunik\\Chat\\Integration\\Dispatcher", [
    ":config" => $config,
]);

$router = router();
$router->post("{service}/{id:[0-9]+}", [$handler, "handle"]);

$host = (new Aerys\Host)
    ->expose("*", $config["deploy"]["port"])
    ->name($config["deploy"]["host"])
    ->use($router);