<?php

namespace Keepsuit\LaravelOpenTelemetry\Tests\Support;

use Livewire\Component;

class LivewireTestComponent extends Component
{
    public function render(): string
    {
        return <<<'HTML'
            <div>
                <h1>Livewire Test Component</h1>
            </div>
        HTML;
    }
}
