<?php

declare(strict_types=1);

namespace App\Controller;

use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;

#[Controller(prefix: "")]
class HealthController
{
    #[GetMapping(path: "ping")]
    public function ping(): array
    {
        return [
            'status' => 'ok',
            'message' => 'pong',
        ];
    }
}
