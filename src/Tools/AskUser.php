<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Tackle\Contracts\InteractionPolicy;

class AskUser extends AbstractTool
{
    public function __construct(private ?InteractionPolicy $interaction = null) {}

    public function description(): string
    {
        return 'Present the user with an interactive selection prompt and return their choice. ALWAYS call this tool instead of writing a numbered list or bullet list of options in your response text. Use it any time you have identified two or more valid paths — implementation approaches, return types, architectural options, etc. — and the user needs to choose. The user sees a styled terminal select() or multiselect() prompt. Pass multiple=true to allow selecting more than one option.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'question' => $schema->string()
                ->description('The question to ask the user.')
                ->required(),
            'options' => $schema->array()
                ->description('The list of options to present.')
                ->required(),
            'multiple' => $schema->boolean()
                ->description('Allow the user to select multiple options. Defaults to false.'),
        ];
    }

    public function handle(Request $request): string
    {
        $question = (string) $this->arg($request, 'question', 'Choose an option:');
        $options = $request->array('options');
        $multiple = $request->boolean('multiple', false);

        if (empty($options)) {
            return 'No options were provided.';
        }

        return $this->interaction()->choose($question, $options, $multiple);
    }

    private function interaction(): InteractionPolicy
    {
        return $this->interaction ??= app(InteractionPolicy::class);
    }
}
