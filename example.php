<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Wodzy\DisposableEmail\Blocklist;
use Wodzy\DisposableEmail\DomainSet;
use Wodzy\DisposableEmail\Exception\InvalidEmailException;

// 1. Use the bundled disposable-email list.
$blocklist = Blocklist::bundled();

$emails = [
    'john.doe@yopmail.com',
    'jane.doe@gmail.com',
    'spammer@foo.mailinator.com', // subdomains are also caught
    'not-an-email',
];

foreach ($emails as $email) {
    try {
        $blocked = $blocklist->blocks($email);
    } catch (InvalidEmailException $e) {
        printf("- %-40s INVALID (%s)\n", $email, $e->getMessage());
        continue;
    }

    printf("- %-40s %s\n", $email, $blocked ? 'BLOCKED' : 'ok');
}

// 2. Combine lists, and exempt some domains.
$custom = new Blocklist(
    DomainSet::bundled()->merge(new DomainSet(['spam.test'])),
    new DomainSet(['partner.yopmail.com']),
);
var_dump($custom->blocks('hello@spam.test'));            // true
var_dump($custom->blocks('hello@partner.yopmail.com'));  // false

// 3. DomainSet is a plain domain matcher, e.g. for an allow-only policy.
$company = new DomainSet(['company.com']);
var_dump($company->matches('jane@paris.company.com'));   // true
