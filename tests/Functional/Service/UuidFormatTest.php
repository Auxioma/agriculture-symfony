<?php

namespace App\Tests\Unit\Service;

use App\Service\Validation\UuidFormat;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Test unitaire (sans base ni kernel) : UuidFormat::isValid() protège les routes publiques contre les
 * identifiants que PostgreSQL refuserait (erreur de conversion, donc HTTP 500).
 */
final class UuidFormatTest extends TestCase
{
    public function testAcceptsCanonicalUuids(): void
    {
        self::assertTrue(UuidFormat::isValid(Uuid::v4()->toRfc4122()));
        self::assertTrue(UuidFormat::isValid(Uuid::v7()->toRfc4122()));
        self::assertTrue(UuidFormat::isValid('123E4567-E89B-12D3-A456-426614174000'), 'les majuscules sont valides');
    }

    public function testRejectsMalformedStrings(): void
    {
        foreach ([
            '',
            'abc',
            '123e4567e89b12d3a456426614174000',           // sans tirets
            '{123e4567-e89b-12d3-a456-426614174000}',     // avec accolades
            '123e4567-e89b-12d3-a456-42661417400',        // un caractère de moins
            '123e4567-e89b-12d3-a456-4266141740000',      // un caractère de trop
            '123e4567-e89b-12d3-a456-42661417400g',       // caractère non hexadécimal
            ' 123e4567-e89b-12d3-a456-426614174000',      // espace au début
            '123e4567-e89b-12d3-a456-426614174000 ',      // espace à la fin
            "123e4567-e89b-12d3-a456-426614174000\n",     // retour à la ligne final
        ] as $value) {
            self::assertFalse(UuidFormat::isValid($value), sprintf('"%s" ne doit pas être accepté', addcslashes($value, "\n")));
        }
    }

    public function testRejectsNonStringValues(): void
    {
        self::assertFalse(UuidFormat::isValid(null));
        self::assertFalse(UuidFormat::isValid(123));
        self::assertFalse(UuidFormat::isValid(['123e4567-e89b-12d3-a456-426614174000']));
    }
}