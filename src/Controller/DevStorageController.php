<?php

/**
 * Sert producer_media.storage en environnement dev (adaptateur local, config/packages/flysystem.yaml
 * when@dev) -- http://localhost:8000/dev-storage ne menait nulle part avant ça, cf.
 * docs/runbooks/deploiement.md "Pourquoi --env=prod". En prod le vrai bucket S3/MinIO repond a sa
 * propre public_url, cette route n'a donc jamais rien a servir en dehors de dev.
 */

namespace App\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class DevStorageController
{
    #[Route('/dev-storage/{path}', requirements: ['path' => '.+'], methods: ['GET'])]
    public function __invoke(
        string $path,
        #[Autowire(param: 'kernel.environment')] string $environment,
        #[Autowire(param: 'kernel.project_dir')] string $projectDir,
    ): BinaryFileResponse {
        if ('dev' !== $environment) {
            throw new NotFoundHttpException();
        }

        $storageDir = realpath($projectDir.'/var/storage/dev');
        $file = $storageDir !== false ? realpath($storageDir.'/'.$path) : false;
        if (false === $file || !str_starts_with($file, $storageDir.\DIRECTORY_SEPARATOR)) {
            throw new NotFoundHttpException();
        }

        return new BinaryFileResponse($file);
    }
}
