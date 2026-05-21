<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Wodzy\MailSentry\Exception\InvalidEmailException;
use Wodzy\MailSentry\Validator;

// 1. Use the bundled disposable-email blacklist.
$validator = Validator::withDefaultBlacklist();

$emails = [
    'john.doe@yopmail.com',
    'jane.doe@gmail.com',
    'spammer@foo.mailinator.com', // subdomains are also caught
    'not-an-email',
];

foreach ($emails as $email) {
    try {
        $blocked = $validator->isBlacklisted($email);
    } catch (InvalidEmailException $e) {
        printf("- %-40s INVALID (%s)\n", $email, $e->getMessage());
        continue;
    }

    printf("- %-40s %s\n", $email, $blocked ? 'BLACKLISTED' : 'ok');
}

// 2. Or build from your own file / in-memory list.
$custom = new Validator(['example.com', 'spam.test']);
var_dump($custom->isBlacklisted('hello@example.com')); // true
