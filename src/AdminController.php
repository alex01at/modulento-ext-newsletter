<?php

declare(strict_types=1);

namespace Modulento\Newsletter;

use Modulento\Core\Controller\Controller;
use Modulento\Core\Support\Clock;
use Modulento\Core\Support\Session;

final class AdminController extends Controller
{
    private const MAX_SUBJECT = 150;

    public function index(array $params): void
    {
        $subscribers = new Subscribers($this->app->db);

        $this->render('@newsletter/admin/index.twig', [
            'confirmed_count' => $subscribers->confirmedCount(),
            'sends' => $this->app->db->query('SELECT * FROM x_newsletter_send ORDER BY id DESC LIMIT 20')->fetchAll(),
        ]);
    }

    public function send(array $params): void
    {
        $subject = trim(mb_substr((string) ($_POST['subject'] ?? ''), 0, self::MAX_SUBJECT));
        $body = trim((string) ($_POST['body'] ?? ''));

        if ($subject === '' || $body === '') {
            Session::flash('error', $this->trans('newsletter.admin.send_empty'));
            $this->redirect('/admin/newsletter');
            return;
        }

        $subscribers = new Subscribers($this->app->db);
        $recipients = $subscribers->confirmed();

        foreach ($recipients as $recipient) {
            $this->app->mailer->send($recipient['email'], '@newsletter/emails/campaign.txt.twig', [
                'subject' => $subject,
                'body' => $body,
                'unsubscribe_link' => $this->app->url(
                    '/newsletter/unsubscribe/' . $subscribers->freshUnsubscribeToken($recipient['id']),
                    absolute: true
                ),
            ]);
        }

        $stmt = $this->app->db->prepare(
            'INSERT INTO x_newsletter_send (subject, body, recipient_count, sent_at) VALUES (:subject, :body, :count, :now)'
        );
        $stmt->execute(['subject' => $subject, 'body' => $body, 'count' => count($recipients), 'now' => Clock::now()]);

        Session::flash('success', $this->trans('newsletter.admin.send_done', ['count' => count($recipients)]));
        $this->redirect('/admin/newsletter');
    }
}
