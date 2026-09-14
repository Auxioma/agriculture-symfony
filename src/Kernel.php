<?php

/**
 * Kernel Symfony standard (MicroKernelTrait, bundles/routes chargés depuis config/bundles.php et
 * config/{packages,routes}/). getAllowedEnvs() restreint APP_ENV aux trois environnements réellement
 * supportés par le projet (prod/dev/test), pour échouer tôt sur une valeur invalide plutôt qu'un
 * comportement indéfini plus loin dans le boot.
 */

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
