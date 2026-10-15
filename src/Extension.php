<?php

declare(strict_types=1);

namespace Modulento\Newsletter;

use Modulento\Core\App;
use Modulento\Core\Extension\Extension as ExtensionContract;
use Modulento\Core\Extension\Registrar;
use Modulento\Core\Support\Router;

/**
 * A double opt-in mailing list and a way for an administrator to send a
 * message to everyone on it, over the core's own mail transport - no
 * account, and no outside mailing service, is needed.
 */
final class Extension implements ExtensionContract
{
    public const PERMISSION = 'newsletter.subscribers.manage';

    public function register(Registrar $registrar): void
    {
        $registrar->permission(self::PERMISSION, 'newsletter.permission.subscribers_manage');

        $registrar->routes(function (Router $router): void {
            $router->get('/newsletter', [NewsletterController::class, 'index'], Router::PUBLIC);
            $router->post('/newsletter', [NewsletterController::class, 'subscribe'], Router::PUBLIC);
            $router->get('/newsletter/confirm/{token}', [NewsletterController::class, 'confirm'], Router::PUBLIC);
            $router->get('/newsletter/unsubscribe/{token}', [NewsletterController::class, 'unsubscribe'], Router::PUBLIC);

            $router->get('/admin/newsletter', [AdminController::class, 'index'], self::PERMISSION);
            $router->post('/admin/newsletter/send', [AdminController::class, 'send'], self::PERMISSION);
        });

        $registrar->adminMenu('newsletter.admin.menu', '/admin/newsletter', self::PERMISSION, 'content');
        $registrar->navigation('newsletter.nav', '/newsletter');

        $registrar->task('newsletter.prune-unconfirmed', 1440, function (App $app): void {
            (new Subscribers($app->db))->pruneUnconfirmed();
        });
    }
}
