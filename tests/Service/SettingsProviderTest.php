<?php

namespace App\Tests\Service;

use App\Entity\Setting;
use App\Service\SettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class SettingsProviderTest extends TestCase
{
    #[TestWith(['06 12 34 56 78', '33612345678'])]
    #[TestWith(['+33 6 12 34 56 78', '33612345678'])]
    #[TestWith(['0033 6 12 34 56 78', '33612345678'])]
    #[TestWith(['', ''])]
    public function testWhatsappNumberIsInternational(string $stored, string $expected): void
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findAll')->willReturn([new Setting(SettingsProvider::WHATSAPP_PHONE, $stored)]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        self::assertSame($expected, (new SettingsProvider($em))->whatsappNumber());
    }

    /** Désactivé par défaut (migration : « 0 ») ; seule la valeur « 1 » ouvre le devis en ligne. */
    #[TestWith([null, false])]
    #[TestWith(['0', false])]
    #[TestWith(['', false])]
    #[TestWith(['1', true])]
    public function testQuoteOnline(?string $stored, bool $expected): void
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findAll')->willReturn(null === $stored ? [] : [new Setting(SettingsProvider::QUOTE_ONLINE, $stored)]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        self::assertSame($expected, (new SettingsProvider($em))->quoteOnline());
    }

    public function testPhoneFallsBackToWhatsappAndSearchEnginesClosedByDefault(): void
    {
        $provider = $this->provider([SettingsProvider::WHATSAPP_PHONE => '06 12 34 56 78']);
        self::assertSame(['06 12 34 56 78', '33612345678', false], [$provider->phoneDisplay(), $provider->phoneNumber(), $provider->searchEnginesOpen()]);

        $provider = $this->provider([SettingsProvider::WHATSAPP_PHONE => '06 12 34 56 78', SettingsProvider::PHONE => '05 56 00 00 00', SettingsProvider::SEARCH_ENGINES_OPEN => '1']);
        self::assertSame(['05 56 00 00 00', '33556000000', true], [$provider->phoneDisplay(), $provider->phoneNumber(), $provider->searchEnginesOpen()]);
    }

    public function testSocialLinksKeepFilledOnesInFooterOrder(): void
    {
        $provider = $this->provider(['pinterest_url' => 'https://pin.it/x', 'youtube_url' => ' ', 'instagram_url' => 'https://instagram.com/x']);

        self::assertSame(['Instagram' => 'https://instagram.com/x', 'Pinterest' => 'https://pin.it/x'], $provider->socialLinks());
    }

    /** @param array<string, string> $values */
    private function provider(array $values): SettingsProvider
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findAll')->willReturn(array_map(fn (string $name) => new Setting($name, $values[$name]), array_keys($values)));
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        return new SettingsProvider($em);
    }
}
