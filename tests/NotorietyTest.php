<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../utils/notoriety.php';

mqTest('La notoriete conserve un poids initial modere puis suit les avis reels', function (): void {
    mqAssertSame(75, mq_notoriety_percent(75, 0, 0));
    mqAssertSame(90, mq_notoriety_percent(100, 0, 1));
    mqAssertSame(60, mq_notoriety_percent(50, 10, 15));
    mqAssertSame(95, mq_notoriety_percent(50, 90, 90));
    mqAssertSame(4, mq_notoriety_percent(50, 0, 100));
});

mqTest('Les seuils et estimations refusent les valeurs ambiguës', function (): void {
    foreach ([50, 75, 100] as $seed) mqAssertSame($seed, mq_notoriety_seed((string)$seed));
    foreach ([null, true, 60, 0, '75.0', []] as $bad) mqAssertThrows(RuntimeException::class, fn() => mq_notoriety_seed($bad));
    foreach ([0, 60, 90] as $min) mqAssertSame($min, mq_notoriety_minimum(['min_notoriety' => (string)$min]));
    foreach ([null, true, 50, 100, '60.0', []] as $bad) mqAssertThrows(RuntimeException::class, fn() => mq_notoriety_minimum(['min_notoriety' => $bad]));
    mqAssertSame(0, mq_notoriety_minimum([]));
    mqAssertSame(60, mq_notoriety_minimum(['min_familiarity' => 7]));
    mqAssertSame(90, mq_notoriety_minimum(['min_familiarity' => 9]));
    mqAssertSame(0, mq_notoriety_minimum(['min_familiarity' => 9, 'min_notoriety' => 0]));
});
