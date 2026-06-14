<?php

namespace Tackle\Models;

use Illuminate\Database\Eloquent\Model;

class HealingLog extends Model
{
    protected $table = 'tackle_healing_log';

    protected $fillable = [
        'subject_type',
        'subject_class',
        'exception_class',
        'exception_message',
        'branch',
        'pr_url',
        'mode',
        'tests_passed',
        'outcome',
    ];

    protected $casts = [
        'tests_passed' => 'boolean',
    ];
}
