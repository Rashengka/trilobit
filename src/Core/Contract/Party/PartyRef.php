<?php

declare(strict_types=1);

namespace Trilobit\Core\Contract\Party;

/**
 * The only shape a reference to a person may take once it crosses the
 * boundary between two modules; see .ai/plans/01a-komunikace-modulu.md.
 *
 * A foreign key cannot make this trip - NoCrossModuleAssociationTest is what
 * stops one from trying - so what travels instead is a type and an identifier
 * with no constraint behind them. `type` says which module's own notion of a
 * person this is ('crm.contact', 'core.user', ...) and `id` is that module's
 * own identifier, both kept as strings because the modules that read them are
 * not the module that minted them.
 */
final readonly class PartyRef
{
    public function __construct(
        public string $type,
        public string $id,
    ) {}
}
