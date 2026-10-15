<?php

declare(strict_types=1);

namespace Modulento\Newsletter;

use Modulento\Core\Controller\Controller;
use Modulento\Core\Support\RateLimiter;
use Modulento\Core\Support\Session;

final class NewsletterController extends Controller
{
    public function index(array $params): void
    {
        $this->render('@newsletter/index.twig');
    }

    public function subscribe(array $params): void
    {
        $email = strtolower(trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : ''));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            Session::flash('error', $this->trans('newsletter.subscribe.invalid'));
            $this->redirect('/newsletter');
            return;
        }

        // Per address, not per visitor: asking again and again for the same
        // address is the only way this form can be abused.
        if (!(new RateLimiter($this->app->db))->hit('newsletter-subscribe', $email, 3, 3600)) {
            $token = (new Subscribers($this->app->db))->subscribe($email);
            if ($token !== null) {
                $this->app->mailer->send($email, '@newsletter/emails/confirm.txt.twig', [
                    'link' => $this->app->url('/newsletter/confirm/' . $token, absolute: true),
                ]);
            }
        }

        // Always the same message, confirmed or not, limited or not - a
        // signup form that answers differently tells a visitor which
        // addresses are already on the list.
        Session::flash('success', $this->trans('newsletter.subscribe.done'));
        $this->redirect('/newsletter');
    }

    public function confirm(array $params): void
    {
        $done = (new Subscribers($this->app->db))->confirm($params['token']);
        Session::flash($done ? 'success' : 'error', $this->trans($done ? 'newsletter.confirm.done' : 'newsletter.confirm.invalid'));
        $this->redirect('/newsletter');
    }

    public function unsubscribe(array $params): void
    {
        $done = (new Subscribers($this->app->db))->unsubscribeByToken($params['token']);
        Session::flash($done ? 'success' : 'error', $this->trans($done ? 'newsletter.unsubscribe.done' : 'newsletter.unsubscribe.invalid'));
        $this->redirect('/newsletter');
    }
}
