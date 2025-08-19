<?php

namespace Corbital\Settings\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SettingUpdated
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $group,
        public string $key,
        public mixed $value,
        public mixed $oldValue,
        public ?string $tenantId = null
    ) {}
}
