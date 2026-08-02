<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Tackle\Contracts\InteractionPolicy;

class ConfirmAction extends AbstractTool
{
    public function __construct(private ?InteractionPolicy $interaction = null) {}

    public function description(): string
    {
        return 'Ask the user to confirm before taking an action. Use before destructive or irreversible operations such as deleting files, dropping tables, or running migrations on production. Returns "confirmed" or "cancelled" — stop and explain if cancelled.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Plain-English description of the action to confirm.')
                ->required(),
            'default' => $schema->boolean()
                ->description('Default answer if the user presses Enter without choosing. Defaults to true.'),
        ];
    }

    public function handle(Request $request): string
    {
        $action = (string) $request->string('action', 'Proceed?');
        $default = $request->boolean('default', true);

        return $this->interaction()->confirm($action, $default) ? 'confirmed' : 'cancelled';
    }

    private function interaction(): InteractionPolicy
    {
        return $this->interaction ??= app(InteractionPolicy::class);
    }
}
