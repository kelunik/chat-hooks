<?php

namespace Kelunik\Chat\Integration;

use Amp\Mysql\Pool;
use Amp\Mysql\ResultSet;
use Amp\Promise;
use function Amp\pipe;

class MysqlHookRepository implements HookRepository {
    private $mysql;

    public function __construct(Pool $mysql) {
        $this->mysql = $mysql;
    }

    public function get(int $id): Promise {
        return pipe($this->mysql->prepare("SELECT room_id, token FROM hook WHERE hook_id = ?", [$id]), function (ResultSet $stmt) {
            return $stmt->fetchObject();
        });
    }
}