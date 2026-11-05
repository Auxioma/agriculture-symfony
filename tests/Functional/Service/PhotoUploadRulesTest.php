<?php

namespace App\Tests\Functional\Service;

use App\Service\Producer\PhotoUploadRules;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Les règles de toutes les photos envoyées par un producteur (exploitation et produits) : jpeg, png ou webp, 5 Mo.
 * Sans base : des fichiers du dossier de tests.
 */
final class PhotoUploadRulesTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../Fixtures/files/';

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }
    }

    // * dernier argument : mode test, le fichier n'a pas transité par une vraie requête HTTP
    private function upload(string $path, string $name, int $error = UPLOAD_ERR_OK): UploadedFile
    {
        return new UploadedFile($path, $name, null, $error, true);
    }

    public function testAnImageIsAccepted(): void
    {
        self::assertNull(PhotoUploadRules::error($this->upload(self::FIXTURES.'sample.jpg', 'sample.jpg')));
    }

    public function testNothingOrSomethingThatIsNotAFileIsRefused(): void
    {
        foreach ([null, 'sample.jpg', ['sample.jpg']] as $notAFile) {
            self::assertSame('Aucun fichier "photo" valide reçu.', PhotoUploadRules::error($notAFile));
        }
    }

    public function testAnUploadThatFailedIsRefused(): void
    {
        self::assertSame('Aucun fichier "photo" valide reçu.', PhotoUploadRules::error($this->upload(self::FIXTURES.'sample.jpg', 'sample.jpg', UPLOAD_ERR_PARTIAL)));
    }

    public function testOnlyJpegPngAndWebpAreAccepted(): void
    {
        // * le type est lu dans le contenu : un texte reste un texte, même renommé en .jpg
        self::assertSame('Format non supporté (jpeg, png ou webp uniquement).', PhotoUploadRules::error($this->upload(self::FIXTURES.'sample.txt', 'sample.txt')));
        self::assertSame('Format non supporté (jpeg, png ou webp uniquement).', PhotoUploadRules::error($this->upload(self::FIXTURES.'sample.txt', 'astuce.jpg')));
    }

    public function testAnImageOverFiveMegabytesIsRefused(): void
    {
        $big = tempnam(sys_get_temp_dir(), 'photo');
        $this->temporaryFiles[] = $big;
        // * une vraie image suivie de zéros : elle reste reconnue comme jpeg, mais pèse plus que 5 Mo
        file_put_contents($big, (string) file_get_contents(self::FIXTURES.'sample.jpg').str_repeat("\0", PhotoUploadRules::MAX_SIZE_BYTES));

        self::assertSame('Fichier trop volumineux (5 Mo maximum).', PhotoUploadRules::error($this->upload($big, 'grosse.jpg')));
    }
}
