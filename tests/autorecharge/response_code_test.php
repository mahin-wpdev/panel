<?php
declare(strict_types=1);
require __DIR__.'/../../autorecharge/autorecharge.php';

$cases = [
    [['status'=>'success'], 200],
    [['status'=>'error','retryable'=>true], 503],
    [['status'=>'error','manual_review'=>true], 202],
    [['status'=>'error','retryable'=>false], 422],
];
foreach ($cases as [$result, $expected]) {
    if (autorecharge_response_code($result) !== $expected) {
        throw new RuntimeException('Unexpected HTTP response code for webhook result.');
    }
}
if (autorecharge_exception_response_code(new RuntimeException('Invalid Nagad SMS format')) !== 400 ||
    autorecharge_exception_response_code(new RuntimeException('Database connection lost')) !== 500) {
    throw new RuntimeException('Malformed payload and server failures must have distinct response codes.');
}
echo "PASS: webhook success, retry, review, and failure response codes\n";
