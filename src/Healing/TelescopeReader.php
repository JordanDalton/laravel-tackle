<?php

namespace Tackle\Healing;

use Illuminate\Support\Facades\DB;
use Throwable;

class TelescopeReader
{
    /**
     * Return any Telescope exception entry that matches the job UUID.
     * Returns an empty string if Telescope is not installed or the entry
     * is not found — callers must tolerate a blank result.
     */
    public function recent(int $limit = 10): string
    {
        if (! class_exists('Laravel\Telescope\Telescope')) {
            return '';
        }

        try {
            $rows = DB::table('telescope_entries')
                ->where('type', 'exception')
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();

            if ($rows->isEmpty()) {
                return 'No recent Telescope exception entries found.';
            }

            return $rows->map(function ($row) {
                $content = json_decode($row->content ?? '{}', true) ?? [];
                $class   = $content['class']   ?? '';
                $message = $content['message'] ?? '';
                $when    = $row->created_at    ?? '';
                return "[{$when}] {$class}: {$message}";
            })->implode("\n");
        } catch (Throwable) {
            return '';
        }
    }

    public function forJob(string $jobUuid): string
    {
        if (!config('ai-code.healing.telescope', true)) {
            return '';
        }

        if (!class_exists('Laravel\Telescope\Telescope')) {
            return '';
        }

        try {
            $row = DB::table('telescope_entries')
                ->where('type', 'exception')
                ->where('content', 'like', '%' . $jobUuid . '%')
                ->orderByDesc('created_at')
                ->first();

            if (!$row) {
                return '';
            }

            $content = json_decode($row->content ?? '{}', true) ?? [];
            $class   = $content['class'] ?? '';
            $message = $content['message'] ?? '';
            $trace   = collect($content['trace'] ?? [])
                ->take(10)
                ->map(fn ($f) => ($f['file'] ?? '?') . ':' . ($f['line'] ?? '?') . ' ' . ($f['function'] ?? ''))
                ->implode("\n");

            return trim("Telescope exception entry:\nClass: {$class}\nMessage: {$message}\nTrace:\n{$trace}");
        } catch (Throwable) {
            return '';
        }
    }
}
