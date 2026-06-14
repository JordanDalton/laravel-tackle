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
        return 'Read Telescope exception entries. Pass job_uuid to look up a specific failed job, or omit it to retrieve the most recent exceptions. Returns an empty result if Telescope is not installed.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'job_uuid' => $schema->string()
                ->description('UUID of a specific failed job to look up. Omit to return recent exceptions.'),
            'limit' => $schema->integer()
                ->description('Number of recent exceptions to return when no job_uuid is given. Defaults to 10.'),
        ];
    }

    public function handle(Request $request): string
    {
        $uuid = $request->string('job_uuid', '');

        if ($uuid !== '') {
            $entry = $this->reader->forJob($uuid);
            return $entry !== '' ? $entry : 'No Telescope entry found for this job UUID.';
        }

        $limit  = max(1, min(50, (int) $request->integer('limit', 10)));
        $result = $this->reader->recent($limit);

        return $result !== '' ? $result : 'No Telescope exception entries found.';
    }
}
