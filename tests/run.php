<?php

declare(strict_types=1);

// Plain-PHP checks of the newsletter's own classes, on SQLite in memory:
//   MODULENTO_CORE=/path/to/modulento php tests/run.php
// The core is only needed for its classes (vendor/autoload.php); without
// the variable it is expected next to this repository, in ../modulento.
// Exit code 0 means every check passed.

use Modulento\Core\App;
use Modulento\Newsletter\AdminController;
use Modulento\Newsletter\Subscribers;

$here = dirname(__DIR__);
$core = getenv('MODULENTO_CORE') ?: dirname($here) . '/modulento';

if (!is_file($core . '/vendor/autoload.php')) {
    fwrite(STDERR, "No Modulento core with vendor/ found at {$core}.\n"
        . "Set MODULENTO_CORE to a working copy of the core in which \"composer install\" has run.\n");
    exit(1);
}

require $core . '/vendor/autoload.php';

date_default_timezone_set('UTC');

$failed = [];
$checks = 0;

function check(string $label, bool $condition): void
{
    global $failed, $checks;
    $checks++;
    if (!$condition) {
        $failed[] = $label;
    }
}

// --- Database ------------------------------------------------------------
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('CREATE TABLE setting (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
$pdo->exec("CREATE TABLE extension (id TEXT PRIMARY KEY, version TEXT, enabled INTEGER, enabled_at TEXT)");
$pdo->exec("INSERT INTO extension VALUES ('newsletter', '0', 1, '2026-01-01 00:00:00')");
$pdo->exec("CREATE TABLE account (id INTEGER PRIMARY KEY, email TEXT UNIQUE, display_name TEXT, password_hash TEXT, status TEXT DEFAULT 'active',
    locale TEXT DEFAULT 'de', created_at TEXT, last_login_at TEXT)");
$pdo->exec('CREATE TABLE page (id INTEGER PRIMARY KEY, status TEXT, role TEXT, in_header INTEGER, in_footer INTEGER, position INTEGER)');
$pdo->exec('CREATE TABLE page_translation (page_id INTEGER, locale TEXT, title TEXT, slug TEXT, meta_description TEXT, body TEXT)');
$pdo->exec('CREATE TABLE account_preference (account_id INTEGER, name TEXT, value TEXT, PRIMARY KEY (account_id, name))');
$pdo->exec('CREATE TABLE x_newsletter_subscriber (id INTEGER PRIMARY KEY, email TEXT UNIQUE NOT NULL, token_hash TEXT NOT NULL,
    confirmed_at TEXT, created_at TEXT NOT NULL)');
$pdo->exec('CREATE TABLE x_newsletter_send (id INTEGER PRIMARY KEY, subject TEXT NOT NULL, body TEXT NOT NULL, recipient_count INTEGER NOT NULL, sent_at TEXT NOT NULL)');

// The migration and the schema above must name the same columns.
$migration = (string) file_get_contents($here . '/migrations/001_newsletter.sql');
foreach (['x_newsletter_subscriber', 'x_newsletter_send'] as $table) {
    preg_match('/CREATE TABLE ' . $table . ' \((.*?)\n\) ENGINE/s', $migration, $body);
    preg_match_all('/^\s{4}([a-z_]+) [A-Z]/m', $body[1] ?? '', $columns);
    $inTest = array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(), 'name');
    sort($columns[1]);
    sort($inTest);
    check("schema: {$table} has the same columns in the migration and in the tests", $columns[1] !== [] && $columns[1] === $inTest);
}

$uploads = sys_get_temp_dir() . '/modulento-newsletter-test-' . bin2hex(random_bytes(4));
mkdir($uploads . '/extensions', 0777, true);
symlink($here, $uploads . '/extensions/newsletter');

$mailLog = $uploads . '/mail.log';
$app = new App([
    'app' => [
        'env' => 'dev', 'url' => 'https://newsletter.example', 'name' => 'Testseite', 'root' => $core,
        'extensions' => $uploads . '/extensions', 'uploads' => $uploads, 'secret_key' => $uploads . '/secret.key',
    ],
    'mail' => ['from' => 'noreply@newsletter.example', 'transport' => 'log', 'log_path' => $mailLog],
    'update' => ['token' => ''],
    'packages' => ['sources' => []],
], $pdo, 'de');
$app->translator->load($core . '/core/lang', 'core');
$app->extensions->loadEnabled($app);
check('extension: the core loads it', isset($app->extensions->loaded()['newsletter'])
    && $app->extensions->loaded()['newsletter']->version === json_decode((string) file_get_contents($here . '/extension.json'), true)['version']
    && $app->extensions->loaded()['newsletter']->type === 'addon');
check('extension: menu entry, navigation entry and permission are announced', $app->navigation() === [['label_key' => 'newsletter.nav', 'path' => '/newsletter']]
    && $app->adminMenu() === [['label_key' => 'newsletter.admin.menu', 'path' => '/admin/newsletter', 'permission' => 'newsletter.subscribers.manage', 'group' => 'content']]
    && ($app->permissions()['newsletter.subscribers.manage'] ?? null) === 'newsletter.permission.subscribers_manage');

$lastMail = function () use ($mailLog): array {
    $log = (string) file_get_contents($mailLog);
    $entries = array_values(array_filter(explode("--\n", $log)));
    $last = end($entries);
    preg_match('/^To: (.+)$/m', $last, $to);
    preg_match('/^Subject: (.+)$/m', $last, $subject);

    return ['to' => $to[1] ?? null, 'subject' => $subject[1] ?? null, 'body' => $last];
};

// --- Subscribing, confirming, unsubscribing -------------------------------
$subscribers = new Subscribers($pdo);

$token = $subscribers->subscribe('anna@example.test');
$row = $pdo->query("SELECT * FROM x_newsletter_subscriber WHERE email = 'anna@example.test'")->fetch();
check('subscribe: stored unconfirmed, with a token', $row !== false && $row['confirmed_at'] === null && $token !== null);
check('subscribe: the plaintext token is never stored', $row['token_hash'] !== $token && $row['token_hash'] === hash('sha256', $token));

$again = $subscribers->subscribe('anna@example.test');
check('subscribe: asking again while unconfirmed gives a fresh token, not the same one', $again !== null && $again !== $token);
check('subscribe: the old token no longer confirms', !$subscribers->confirm($token));
check('subscribe: the fresh one does', $subscribers->confirm($again));
check('subscribe: confirming twice fails the second time', !$subscribers->confirm($again));

check('subscribe: an already-confirmed address gets nothing, so a form cannot tell the two cases apart', $subscribers->subscribe('anna@example.test') === null);
check('confirmed: counted and listed', $subscribers->confirmedCount() === 1 && array_column($subscribers->confirmed(), 'email') === ['anna@example.test']);

$bToken = $subscribers->subscribe('berta@example.test');
check('confirm: an unknown token fails', !$subscribers->confirm('not-a-real-token'));
check('confirmed: only the confirmed one counts, the pending one does not', $subscribers->confirmedCount() === 1);
$subscribers->confirm($bToken);
check('confirmed: now both', $subscribers->confirmedCount() === 2);

$annaId = (int) $pdo->query("SELECT id FROM x_newsletter_subscriber WHERE email = 'anna@example.test'")->fetchColumn();
$unsubToken = $subscribers->freshUnsubscribeToken($annaId);
check('unsubscribe: an unknown token fails, nothing removed', !$subscribers->unsubscribeByToken('not-a-real-token') && $subscribers->confirmedCount() === 2);
check('unsubscribe: the fresh token removes the row outright', $subscribers->unsubscribeByToken($unsubToken) && $subscribers->confirmedCount() === 1);
check('unsubscribe: used once, it does not work again', !$subscribers->unsubscribeByToken($unsubToken));

// --- Pruning stale, unconfirmed signups ------------------------------------
$pdo->exec("INSERT INTO x_newsletter_subscriber (email, token_hash, confirmed_at, created_at) VALUES ('stale@example.test', 'x', NULL, '2026-01-01 00:00:00')");
$pdo->exec("UPDATE x_newsletter_subscriber SET created_at = '2026-01-01 00:00:00' WHERE email = 'berta@example.test'");
$subscribers->pruneUnconfirmed();
check('prune: an old, unconfirmed signup is removed', $pdo->query("SELECT COUNT(*) FROM x_newsletter_subscriber WHERE email = 'stale@example.test'")->fetchColumn() == 0);
check('prune: an old but confirmed address is kept - the age of created_at does not matter once confirmed', $pdo->query("SELECT COUNT(*) FROM x_newsletter_subscriber WHERE email = 'berta@example.test'")->fetchColumn() == 1);

// --- Mail templates --------------------------------------------------------
$app->translator->setLocale('de');
$app->mailer->send('carla@example.test', '@newsletter/emails/confirm.txt.twig', ['link' => 'https://newsletter.example/newsletter/confirm/abc']);
check('mail: sent to the right address, with the confirmation link in the body', $lastMail()['to'] === 'carla@example.test' && str_contains($lastMail()['body'], 'https://newsletter.example/newsletter/confirm/abc'));

$campaignBody = $app->view()->renderBlock('@newsletter/emails/campaign.txt.twig', 'body', [
    'subject' => 'Hallo', 'body' => "Zeile eins\nZeile zwei", 'unsubscribe_link' => 'https://newsletter.example/newsletter/unsubscribe/xyz',
]);
check('mail: the campaign body is printed as typed, with the unsubscribe link appended', str_contains($campaignBody, "Zeile eins\nZeile zwei")
    && str_contains($campaignBody, 'https://newsletter.example/newsletter/unsubscribe/xyz'));
$campaignSubject = $app->view()->renderBlock('@newsletter/emails/campaign.txt.twig', 'subject', ['subject' => 'Hallo <Welt>', 'body' => 'x', 'unsubscribe_link' => 'x']);
check('mail: the subject is the administrator\'s own text, not run through a fixed wording', trim($campaignSubject) === 'Hallo <Welt>');

// --- Sending a campaign: AdminController::send() ---------------------------
$subscribers->subscribe('dora@example.test');
$doraToken = (function () use ($pdo) {
    $stmt = $pdo->query("SELECT token_hash FROM x_newsletter_subscriber WHERE email = 'dora@example.test'");
    return $stmt->fetchColumn();
})();
// Dora stays unconfirmed on purpose: a send must never reach her.
check('setup: berta and dora are on file, only berta confirmed', $subscribers->confirmedCount() === 1);

$_POST = ['subject' => 'Neuigkeiten', 'body' => "Es gibt Neues.\nViele Grüße"];
(new AdminController($app))->send([]);
check('send: reaches only confirmed addresses', $lastMail()['to'] === 'berta@example.test' && $lastMail()['subject'] === 'Neuigkeiten');
check('send: dora, unconfirmed, received nothing', !str_contains((string) file_get_contents($mailLog), 'dora@example.test'));
check('send: a history row is written with the right recipient count', $pdo->query('SELECT subject, recipient_count FROM x_newsletter_send ORDER BY id DESC LIMIT 1')->fetch() === ['subject' => 'Neuigkeiten', 'recipient_count' => 1]);

$_POST = ['subject' => '', 'body' => 'x'];
(new AdminController($app))->send([]);
check('send: an empty subject sends nothing and writes no history row', $pdo->query('SELECT COUNT(*) FROM x_newsletter_send')->fetchColumn() == 1);

// --- Language files ----------------------------------------------------------
$de = require $here . '/lang/de.php';
$en = require $here . '/lang/en.php';
check('lang: de and en have the same keys', array_keys($de) === array_keys($en));
check('lang: every key starts with "newsletter."', array_filter(array_keys($de), fn (string $key) => !str_starts_with($key, 'newsletter.')) === []);
$used = [];
foreach ([...glob($here . '/templates/{,*/}*.twig', GLOB_BRACE), ...glob($here . '/src/*.php')] as $file) {
    preg_match_all("/'(newsletter\.[a-z0-9_.]+[a-z0-9])'/", (string) file_get_contents($file), $found);
    $used = array_merge($used, $found[1]);
}
$unknown = array_diff(array_unique($used), array_keys($de), ['newsletter.subscribers.manage']);
check('lang: every key used in templates and code exists: ' . implode(', ', $unknown), $unknown === []);

// --- Clean up ----------------------------------------------------------------
$remove = function (string $dir) use (&$remove): void {
    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
        is_dir("{$dir}/{$entry}") && !is_link("{$dir}/{$entry}") ? $remove("{$dir}/{$entry}") : unlink("{$dir}/{$entry}");
    }
    rmdir($dir);
};
$remove($uploads);

if ($failed !== []) {
    echo "FAILED:\n";
    foreach ($failed as $label) {
        echo "  - {$label}\n";
    }
    echo count($failed) . " of {$checks} checks failed\n";
    exit(1);
}

echo "OK ({$checks} checks)\n";
