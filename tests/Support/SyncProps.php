<?php

declare(strict_types=1);

namespace AvalynxOfficeEas\Tests\Support;

/**
 * Accessor for the magic dynamic properties of Z-Push's SyncObject/StateObject
 * hierarchy (untyped third-party API). Property names stay dynamic so static
 * analysis does not report undefined property access on those classes.
 */
final class SyncProps
{
    public static function set(object $target, string $name, mixed $value): void
    {
        new self()->write($target, $name, $value);
    }

    public static function get(object $target, string $name): mixed
    {
        return new self()->read($target, $name);
    }

    private function write(object $target, string $name, mixed $value): void
    {
        $writer = function () use ($name, $value): void {
            $this->{$name} = $value;
        };

        $writer->call($target);
    }

    private function read(object $target, string $name): mixed
    {
        $reader = (fn () => $this->{$name} ?? null);

        return $reader->call($target);
    }
}
