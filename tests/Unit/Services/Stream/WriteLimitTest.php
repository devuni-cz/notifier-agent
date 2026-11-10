<?php

declare(strict_types=1);

use Devuni\Notifier\Services\Stream\WriteLimit;

beforeEach(function () {
    $this->stream = fopen('php://temp', 'w+b');
});

afterEach(function () {
    fclose($this->stream);
});

it('passes writes through up to exactly the limit', function () {
    $limit = WriteLimit::attachTo($this->stream, 10);

    expect(fwrite($this->stream, '12345'))->toBe(5)
        ->and(fwrite($this->stream, '67890'))->toBe(5)
        ->and($limit->exceeded)->toBeFalse()
        ->and(stream_get_contents($this->stream, -1, 0))->toBe('1234567890');
});

it('reports a write over the limit as zero bytes written and stores none of it', function () {
    // Zero, not false: HTTP clients hand this value to curl, and only a short
    // write makes curl end the transfer.
    $limit = WriteLimit::attachTo($this->stream, 10);

    expect(fwrite($this->stream, '123456789'))->toBe(9)
        ->and(fwrite($this->stream, 'ab'))->toBe(0)
        ->and($limit->exceeded)->toBeTrue()
        ->and(stream_get_contents($this->stream, -1, 0))->toBe('123456789');
});

it('refuses every later write, even one that would fit', function () {
    WriteLimit::attachTo($this->stream, 10);

    fwrite($this->stream, '12345678');
    fwrite($this->stream, 'abcde');

    expect(fwrite($this->stream, 'x'))->toBe(0)
        ->and(stream_get_contents($this->stream, -1, 0))->toBe('12345678');
});

it('does not limit reads', function () {
    fwrite($this->stream, str_repeat('a', 50));
    rewind($this->stream);

    WriteLimit::attachTo($this->stream, 10);

    expect(stream_get_contents($this->stream))->toBe(str_repeat('a', 50));
});

it('tracks each stream separately', function () {
    $other = fopen('php://temp', 'w+b');

    $first = WriteLimit::attachTo($this->stream, 5);
    $second = WriteLimit::attachTo($other, 5);

    fwrite($this->stream, '123456');

    expect($first->exceeded)->toBeTrue()
        ->and($second->exceeded)->toBeFalse()
        ->and(fwrite($other, '12345'))->toBe(5);

    fclose($other);
});
