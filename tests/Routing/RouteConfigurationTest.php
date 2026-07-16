<?php

declare(strict_types=1);

namespace App\Tests\Routing;

use App\Controller\WalletController;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\Router;

final class RouteConfigurationTest extends KernelTestCase
{
    /**
     * @param array{class-string, string} $controller
     * @param list<string>                $methods
     * @param array<string, string>       $requirements
     */
    #[DataProvider('applicationRouteProvider')]
    public function testApplicationRouteContract(
        string $name,
        string $path,
        array $controller,
        array $methods,
        array $requirements,
    ): void {
        $route = $this->getRoute($name);

        self::assertSame($path, $route->getPath());
        self::assertSame($controller, $route->getDefault('_controller'));
        self::assertSame($methods, $route->getMethods());
        self::assertSame('json', $route->getDefault('_format'));
        self::assertSame($requirements, $route->getRequirements());
    }

    public function testApplicationHasExactlyExpectedRoutes(): void
    {
        $routeNames = array_keys(iterator_to_array($this->getRouter()->getRouteCollection()));
        $applicationRouteNames = array_values(array_filter(
            $routeNames,
            static fn (string $name): bool => str_starts_with($name, 'app_'),
        ));

        self::assertSame([
            'app_wallet_list',
            'app_wallet_create',
            'app_wallet_transfer',
            'app_wallet_deposit',
            'app_wallet_delete',
        ], $applicationRouteNames);
    }

    #[DataProvider('walletIdRouteProvider')]
    public function testWalletIdRoutesMatchOnlyDigits(string $path, string $method, string $routeName): void
    {
        $context = new RequestContext();
        $context->setMethod($method);
        $matcher = new UrlMatcher($this->getRouter()->getRouteCollection(), $context);

        self::assertSame($routeName, $matcher->match($path)['_route']);

        $this->expectException(ResourceNotFoundException::class);
        $matcher->match(str_replace('/42', '/abc', $path));
    }

    public static function applicationRouteProvider(): iterable
    {
        yield 'wallet list' => [
            'app_wallet_list',
            '/api/wallets',
            [WalletController::class, 'list'],
            ['GET'],
            [],
        ];
        yield 'wallet create' => [
            'app_wallet_create',
            '/api/wallets',
            [WalletController::class, 'create'],
            ['POST'],
            [],
        ];
        yield 'wallet transfer' => [
            'app_wallet_transfer',
            '/api/wallets/transfer',
            [WalletController::class, 'transfer'],
            ['POST'],
            [],
        ];
        yield 'wallet deposit' => [
            'app_wallet_deposit',
            '/api/wallets/{id}/deposit',
            [WalletController::class, 'deposit'],
            ['POST'],
            ['id' => '\\d+'],
        ];
        yield 'wallet delete' => [
            'app_wallet_delete',
            '/api/wallets/{id}',
            [WalletController::class, 'delete'],
            ['DELETE'],
            ['id' => '\\d+'],
        ];
    }

    public static function walletIdRouteProvider(): iterable
    {
        yield 'deposit' => ['/api/wallets/42/deposit', 'POST', 'app_wallet_deposit'];
        yield 'delete' => ['/api/wallets/42', 'DELETE', 'app_wallet_delete'];
    }

    private function getRoute(string $name): Route
    {
        $route = $this->getRouter()->getRouteCollection()->get($name);
        self::assertInstanceOf(Route::class, $route);

        return $route;
    }

    private function getRouter(): Router
    {
        self::bootKernel();

        $router = self::getContainer()->get('router');
        self::assertInstanceOf(Router::class, $router);

        return $router;
    }
}
