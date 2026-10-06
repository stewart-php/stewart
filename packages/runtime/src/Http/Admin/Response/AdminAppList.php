<?php

declare(strict_types=1);

namespace Stewart\Runtime\Http\Admin\Response;

use Stewart\Contracts\Wire\ListOf;

final readonly class AdminAppList
{
    /** @param list<AdminAppView> $apps */
    public function __construct(
        #[ListOf(AdminAppView::class)]
        public array $apps,
    ) {}
}
