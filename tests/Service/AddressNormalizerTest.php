<?php

namespace App\Tests\Service;

use App\Service\AddressNormalizer;
use PHPUnit\Framework\TestCase;

/** Story 3.1 (AD-10) : casse, espaces, accents et ponctuation ne changent pas l'adresse comparée. */
final class AddressNormalizerTest extends TestCase
{
    public function testSameAddressWrittenDifferentlyGivesSameForm(): void
    {
        $normalizer = new AddressNormalizer();

        self::assertSame('12 rue de l eglise 33530 bassens', $normalizer->normalize("  12, Rue de l'Église\n33530   BASSENS. "));
        self::assertSame($normalizer->normalize('12 rue de l’eglise, 33530 Bassens'), $normalizer->normalize('12 RUE DE L EGLISE 33530 BASSENS'));
        self::assertSame('', $normalizer->normalize(' , - . '));
    }
}
