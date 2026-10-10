<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting;

use App\Domain\Shared\Enums\JournalCategory;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

abstract class PostingRule
{
    /** @return Posting[] */
    abstract public function postings(Model $source): array;

    abstract public function label(Model $source): string;

    protected function code(string $key): string
    {
        return (string) config("cargo.accounting.auto_post.accounts.{$key}");
    }

    protected function posting(string $rule, CarbonInterface $date, JournalCategory $category, string $memo): Posting
    {
        return new Posting($rule, $date, $category, mb_substr($memo, 0, 250));
    }
}
