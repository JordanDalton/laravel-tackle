<?php

namespace Tackle\Support;

use Laravel\Ai\Files\Image;

/**
 * Finds image paths in a prompt — dropped into the terminal (which pastes the
 * path, quoted or with backslash-escaped spaces), typed, or @-mentioned — and
 * turns them into laravel/ai image attachments. The path is replaced with a
 * readable marker so the prompt still reads naturally.
 */
class ImageAttachments
{
    private const EXTENSIONS = 'png|jpe?g|gif|webp';

    /**
     * @return array{0: string, 1: array<int, object>} [clean prompt, attachments]
     */
    public static function extract(string $prompt, string $workspace): array
    {
        $attachments = [];

        $patterns = [
            // 'quoted path.png' or "quoted path.png"
            '/\'([^\']+\.(?:'.self::EXTENSIONS.'))\'|"([^"]+\.(?:'.self::EXTENSIONS.'))"/iu',
            // unquoted path, possibly with backslash-escaped spaces, possibly @-mentioned
            '/@?(?:[^\s\\\\]|\\\\ )+\.(?:'.self::EXTENSIONS.')/iu',
        ];

        foreach ($patterns as $pattern) {
            $prompt = (string) preg_replace_callback($pattern, function (array $matches) use (&$attachments, $workspace) {
                $raw = $matches[1] ?? null;
                $raw = ($raw !== null && $raw !== '') ? $raw : ($matches[2] ?? $matches[0]);

                $path = self::resolve($raw, $workspace);

                if ($path === null) {
                    return $matches[0]; // not a real file — leave the text alone
                }

                $attachments[] = Image::fromPath($path);

                return '[attached image: '.basename($path).']';
            }, $prompt);
        }

        return [$prompt, $attachments];
    }

    private static function resolve(string $raw, string $workspace): ?string
    {
        $path = str_replace('\\ ', ' ', ltrim(trim($raw), '@'));

        if (str_starts_with($path, '~/')) {
            $path = ($_SERVER['HOME'] ?? getenv('HOME') ?: '').substr($path, 1);
        }

        if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $path = $workspace.DIRECTORY_SEPARATOR.$path;
        }

        return (is_file($path) && is_readable($path)) ? $path : null;
    }
}
