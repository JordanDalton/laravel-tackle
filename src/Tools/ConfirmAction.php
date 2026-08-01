<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

use function Laravel\Prompts\confirm;

class ConfirmAction extends AbstractTool
{
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
        $action = $request->string('action', 'Proceed?');
        $default = $request->boolean('default', true);

        echo PHP_EOL;

        return confirm(label: $action, default: $default) ? 'confirmed' : 'cancelled';
    }
}
