<?php


namespace App\Service\Storage;

use League\Flysystem\Config;
use League\Flysystem\UrlGeneration\TemporaryUrlGenerator;

final class FakeTemporaryUrlGenerator implements TemporaryUrlGenerator
{
    public function temporaryUrl(string $path, \DateTimeInterface $expiresAt, Config $config): string
    {
        return 'https://attachments.test/'.$path;
    }
}