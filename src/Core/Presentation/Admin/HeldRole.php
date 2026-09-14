<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Admin;

/** One role somebody holds in this business, as the page about them draws it. */
final readonly class HeldRole
{
    public function __construct(
        /** The membership, which is what taking the role away removes. */
        public int $membership,
        public string $name,
        /**
         * Why the person reading the page may not take it away, or null when
         * they may - in which case the button is drawn. The act asks again.
         */
        public ?string $refusal,
    ) {}
}
