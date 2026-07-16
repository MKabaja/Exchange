<?php

declare(strict_types=1);

use App\Controller\WalletController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->add('app_wallet_list', '/api/wallets')
        ->controller([WalletController::class, 'list'])
        ->methods(['GET'])
        ->format('json');

    $routes->add('app_wallet_create', '/api/wallets')
        ->controller([WalletController::class, 'create'])
        ->methods(['POST'])
        ->format('json');

    $routes->add('app_wallet_transfer', '/api/wallets/transfer')
        ->controller([WalletController::class, 'transfer'])
        ->methods(['POST'])
        ->format('json');

    $routes->add('app_wallet_deposit', '/api/wallets/{id}/deposit')
        ->controller([WalletController::class, 'deposit'])
        ->methods(['POST'])
        ->format('json')
        ->requirements(['id' => '\\d+']);

    $routes->add('app_wallet_delete', '/api/wallets/{id}')
        ->controller([WalletController::class, 'delete'])
        ->methods(['DELETE'])
        ->format('json')
        ->requirements(['id' => '\\d+']);
};
