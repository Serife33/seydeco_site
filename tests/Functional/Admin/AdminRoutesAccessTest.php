<?php

namespace App\Tests\Functional\Admin;

use Symfony\Component\Routing\RouterInterface;

/**
 * Parcourt toutes les routes /admin du routeur : une route ajoutée plus tard est couverte sans modifier ce test.
 */
final class AdminRoutesAccessTest extends AdminTestCase
{
    /** Routes accessibles sans connexion complète, par construction. */
    private const array PUBLIC_ROUTES = ['admin_login', 'admin_logout'];
    /** Écran du code : réservé à une connexion à mi-chemin. */
    private const array TWO_FACTOR_ROUTES = ['2fa_login', '2fa_login_check', 'admin_2fa_resend'];

    public function testEveryAdminRouteRefusesAnonymous(): void
    {
        $routes = $this->protectedRoutes(withTwoFactorRoutes: true);
        self::assertNotEmpty($routes);

        foreach ($routes as [$name, $method, $path]) {
            $this->client->request($method, $path);
            self::assertResponseRedirects('/admin/connexion', message: "Route $name ($method $path) accessible sans connexion");
        }
    }

    public function testEveryAdminRouteRefusesHalfWayLogin(): void
    {
        $this->submitLogin();
        self::assertSelectorTextContains('h1', 'Code de connexion');

        foreach ($this->protectedRoutes(withTwoFactorRoutes: false) as [$name, $method, $path]) {
            $this->client->request($method, $path);
            self::assertResponseRedirects('/admin/code', message: "Route $name ($method $path) accessible sans code");
        }
    }

    /** @return list<array{string, string, string}> une entrée par route et par méthode HTTP */
    private function protectedRoutes(bool $withTwoFactorRoutes): array
    {
        $routes = [];
        foreach (static::getContainer()->get(RouterInterface::class)->getRouteCollection() as $name => $route) {
            if (!str_starts_with($route->getPath(), '/admin')
                || \in_array($name, self::PUBLIC_ROUTES, true)
                || (!$withTwoFactorRoutes && \in_array($name, self::TWO_FACTOR_ROUTES, true))) {
                continue;
            }
            $path = preg_replace('/\{[^}]+\}/', '1', $route->getPath());
            foreach ($route->getMethods() ?: ['GET', 'POST'] as $method) {
                $routes[] = [$name, $method, $path];
            }
        }

        return $routes;
    }
}
