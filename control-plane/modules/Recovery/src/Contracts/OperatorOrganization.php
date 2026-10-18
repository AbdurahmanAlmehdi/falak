<?php

namespace Falak\Recovery\Contracts;

/**
 * The organization that operates this install (FALAK_DR_ORGANIZATION, or the only organization of a
 * single-organization install, else none). Instance-operator features (the control plane's DR, Falak updates, the
 * control-plane machine as a server) are shown to it only.
 */
interface OperatorOrganization
{
    public function id(): ?string;
}
