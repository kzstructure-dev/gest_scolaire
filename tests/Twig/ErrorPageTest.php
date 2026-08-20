<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class ErrorPageTest extends KernelTestCase
{
    public function testForbiddenPageIsRenderedInFrench(): void
    {
        self::bootKernel();

        /** @var Environment $twig */
        $twig = static::getContainer()->get(Environment::class);
        $html = $twig->render('bundles/TwigBundle/Exception/error403.html.twig');

        self::assertStringContainsString('Acces refuse', $html);
        self::assertStringContainsString('/logout', $html);
    }
}
