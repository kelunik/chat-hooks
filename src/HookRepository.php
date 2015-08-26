<?php

namespace Kelunik\Chat\Integration;

use Amp\Promise;

interface HookRepository {
    public function get(int $id): Promise;
}