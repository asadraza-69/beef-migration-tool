<?php

namespace Nudelsalat\Migrations\Events;

use Nudelsalat\Migrations\Migration;

class MigrationEvent
{
    public const PRE_MIGRATE = 'pre_migrate';
    public const POST_MIGRATE = 'post_migrate';

    public function __construct(
        public Migration $migration,
        public string $driver
    ) {}
}
