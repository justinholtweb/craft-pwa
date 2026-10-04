<?php

namespace justinholtweb\pwa\web;

use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Makes `{{ pwa }}` a template global, alongside `craft.pwa`.
 *
 * The short form is the one the documentation, the settings screen and the preflight remediation
 * all tell people to type — `{{ pwa.head() }}` — so it has to exist on the front end and in the
 * control panel both, not only as a property of `craft`.
 */
class Extension extends AbstractExtension implements GlobalsInterface
{
    public function getGlobals(): array
    {
        return ['pwa' => new Variable()];
    }
}
