<?php

namespace Tackle\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Tackle\Healing\TelescopeReader;

class ReadTelescopeEntry extends AbstractTool
{
    public function __construct(private TelescopeReader $reader) {}

    public function description(): string
    {
        return 'Look up a Telescope exception entry for a given job UUID. Returns the exception class, message, and truncated stack trace if Telescope is installed and has an entry for this job. Returns empty string if not available.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'job_uuid' => $schema->string()
                ->description('The UUID of the failed job to look up in Telescope.')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $uuid = $request->string('job_uuid', '');

        if ($uuid === '') {
            return 'No job_uuid provided.';
        }

        $entry = $this->reader->forJob($uuid);

        return $entry !== '' ? $entry : 'No Telescope entry found for this job UUID.';
    }
}
