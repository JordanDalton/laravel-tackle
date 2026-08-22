<?php

namespace Tackle\Evals;

use Closure;

/**
 * One benchmark scenario for the harness: a small, self-contained bug (or
 * task) seeded into an isolated directory, a prompt handed to the agent, and a
 * grader that inspects the result. Cases are deliberately independent of a full
 * app — the grader loads the produced files directly — so the suite runs fast
 * and scores deterministically.
 */
class EvalCase
{
    /**
     * @param  string  $id  stable slug
     * @param  string  $category  'bug' | 'feature' | …
     * @param  array<string, string>  $files  relative path => seeded (buggy) contents
     * @param  string  $prompt  the task handed to the agent
     * @param  Closure(string): EvalGrade  $grader  receives the case directory, returns a grade
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $category,
        public readonly array $files,
        public readonly string $prompt,
        public readonly Closure $grader,
    ) {}

    /**
     * Grade the case directory after the agent has run. Never throws — a grader
     * that blows up (e.g. the file is now unparseable) scores as not-fixed with
     * the error captured.
     */
    public function grade(string $dir): EvalGrade
    {
        try {
            return ($this->grader)($dir);
        } catch (\Throwable $e) {
            return new EvalGrade(fixed: false, brokeHappyPath: true, note: 'grader error: '.$e->getMessage());
        }
    }
}
