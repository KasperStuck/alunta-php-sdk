<?php

use KasperStuck\Alunta\Sdk\Uuid;

it('accepts any 8-4-4-4-12 hexadecimal uuid, of any version and case', function (string $value) {
    expect(Uuid::isValid($value))->toBeTrue();
})->with([
    'version 4' => ['550e8400-e29b-41d4-a716-446655440000'],
    'version 1' => ['123e4567-e89b-12d3-a456-426614174000'],
    'nil' => ['00000000-0000-0000-0000-000000000000'],
    'upper case' => ['550E8400-E29B-41D4-A716-446655440000'],
]);

it('refuses anything else', function (mixed $value) {
    expect(Uuid::isValid($value))->toBeFalse();
})->with([
    'a path' => ['../me'],
    'braces' => ['{550e8400-e29b-41d4-a716-446655440000}'],
    'a trailing newline' => ["550e8400-e29b-41d4-a716-446655440000\n"],
    'no dashes' => ['550e8400e29b41d4a716446655440000'],
    'a non-hex digit' => ['550e8400-e29b-41d4-a716-44665544000g'],
    'empty' => [''],
    'not a string' => [123],
    'null' => [null],
]);
