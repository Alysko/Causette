<?php

declare(strict_types=1);

return [
    'smtp_test_subject' => 'Causette mail test',
    'smtp_test_body' => 'This is a test email from your Causette installation. If you received this, mail is configured correctly.',
    'registration_subject' => 'New user registered',
    'registration_intro' => 'A new user has registered on Causette.',
    'registration_username' => 'Username:',
    'registration_email' => 'Email:',
    'registration_ip' => 'IP address:',
    'registration_referrer' => 'Referred by:',
    'registration_no_referrer' => 'None',
    'password_reset_subject' => 'Causette Password Reset',
    'password_reset_body' => 'Reset your password: {link}',
    'account_verification_subject' => 'Verify your Causette account',
    'account_verification_body' => 'Your verification code is {code} (valid {minutes} minutes).',
    'email_change_subject' => 'Confirm your new Causette email',
    'email_change_body' => 'Your email change verification code is {code} (valid {minutes} minutes).',
    'login_code_subject' => 'Your Causette 2FA Code',
    'login_code_body' => 'Your login code is {code} (valid {minutes} minutes).',
];
