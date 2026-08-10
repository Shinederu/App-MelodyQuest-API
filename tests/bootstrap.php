<?php

declare(strict_types=1);

$GLOBALS['mq_test_count'] = 0;
$GLOBALS['mq_test_failures'] = 0;

function mqTest(string $name, callable $test): void
{
    $GLOBALS['mq_test_count']++;

    try {
        $test();
        echo "[OK] {$name}" . PHP_EOL;
    } catch (Throwable $e) {
        $GLOBALS['mq_test_failures']++;
        fwrite(STDERR, "[ECHEC] {$name}: {$e->getMessage()}" . PHP_EOL);
    }
}

function mqAssertTrue(bool $condition, string $message = 'La condition attendue est fausse'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function mqAssertFalse(bool $condition, string $message = 'La condition attendue est vraie'): void
{
    mqAssertTrue(!$condition, $message);
}

function mqAssertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $detail = sprintf(
            'Attendu %s, reçu %s',
            var_export($expected, true),
            var_export($actual, true)
        );
        throw new RuntimeException($message === '' ? $detail : $message . ' - ' . $detail);
    }
}

function mqAssertThrows(string $exceptionClass, callable $callback): void
{
    try {
        $callback();
    } catch (Throwable $e) {
        mqAssertTrue(
            $e instanceof $exceptionClass,
            sprintf('Exception %s attendue, %s reçue', $exceptionClass, $e::class)
        );
        return;
    }

    throw new RuntimeException("L'exception {$exceptionClass} était attendue");
}

function mqInvokePrivate(object $object, string $method, array $arguments = []): mixed
{
    $reflection = new ReflectionMethod($object, $method);
    return $reflection->invokeArgs($object, $arguments);
}

function mqFinishTests(): never
{
    $count = (int)$GLOBALS['mq_test_count'];
    $failures = (int)$GLOBALS['mq_test_failures'];

    echo PHP_EOL . sprintf('%d test(s), %d échec(s).', $count, $failures) . PHP_EOL;
    exit($failures === 0 ? 0 : 1);
}
