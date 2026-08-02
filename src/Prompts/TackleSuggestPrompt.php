<?php

namespace Tackle\Prompts;

use Closure;
use Illuminate\Support\Collection;
use Laravel\Prompts\Key;
use Laravel\Prompts\SuggestPrompt;
use Laravel\Prompts\Themes\Default\SuggestPromptRenderer;

class TackleSuggestPrompt extends SuggestPrompt
{
    public function __construct(
        string $label,
        array|Collection|Closure $options,
        string $placeholder = '',
        string $default = '',
        int $scroll = 5,
        bool|string $required = false,
        mixed $validate = null,
        string $hint = '',
        ?Closure $transform = null,
        string|Closure $info = '',
    ) {
        parent::__construct(
            label: $label,
            options: $options,
            placeholder: $placeholder,
            default: $default,
            scroll: $scroll,
            required: $required,
            validate: $validate,
            hint: $hint,
            transform: $transform,
            info: $info,
        );

        // The parent registers key handlers via on('key', ...) twice (once for
        // navigation, once inside trackTypedValue). Clear them and re-register
        // so Tab accepts the highlighted suggestion instead of moving to the next.
        $this->listeners['key'] = [];

        $this->on('key', fn ($key) => match ($key) {
            Key::UP, Key::UP_ARROW, Key::SHIFT_TAB, Key::CTRL_P => $this->highlightPrevious(count($this->matches()), true),
            Key::DOWN, Key::DOWN_ARROW, Key::CTRL_N => $this->highlightNext(count($this->matches()), true),
            Key::TAB => $this->acceptHighlighted(),
            Key::ESCAPE => $this->clearInput(),
            Key::oneOf([Key::HOME, Key::CTRL_A], $key) => $this->highlighted !== null ? $this->highlight(0) : null,
            Key::oneOf([Key::END, Key::CTRL_E], $key) => $this->highlighted !== null ? $this->highlight(count($this->matches()) - 1) : null,
            Key::ENTER => $this->selectHighlighted(),
            Key::oneOf([Key::LEFT, Key::LEFT_ARROW, Key::RIGHT, Key::RIGHT_ARROW, Key::CTRL_B, Key::CTRL_F], $key) => $this->highlighted = null,
            default => (function () {
                $this->highlighted = null;
                $this->matches = null;
                $this->firstVisible = 0;
            })(),
        });

        $this->trackTypedValue(
            $default,
            ignore: fn ($key) => Key::oneOf([Key::HOME, Key::END, Key::CTRL_A, Key::CTRL_E], $key) && $this->highlighted !== null,
        );
    }

    protected function clearInput(): void
    {
        $this->typedValue = '';
        $this->cursorPosition = 0;
        $this->highlighted = null;
        $this->matches = null;
        $this->firstVisible = 0;
    }

    // Fill in the highlighted suggestion and clear the dropdown — does NOT submit.
    protected function acceptHighlighted(): void
    {
        if ($this->highlighted === null) {
            return;
        }

        $this->typedValue = $this->matches()[$this->highlighted].' ';
        $this->cursorPosition = mb_strlen($this->typedValue);
        $this->highlighted = null;
        $this->matches = null;
        $this->firstVisible = 0;
    }

    // The theme system looks up renderers by exact class name, so point our
    // subclass at the same renderer the parent uses.
    protected function getRenderer(): callable
    {
        return new SuggestPromptRenderer($this);
    }
}
