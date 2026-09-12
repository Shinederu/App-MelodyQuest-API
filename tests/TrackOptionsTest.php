<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../utils/track_options.php';

mqTest('Les bornes de musique acceptent zero et preservent la fin sur modification partielle', function (): void {
    mqAssertSame(['start_offset_seconds' => 0, 'end_offset_seconds' => 45], mq_track_bounds(['start_offset_seconds' => '0'], ['start_offset_seconds' => 12, 'end_offset_seconds' => 45]));
    mqAssertSame(['start_offset_seconds' => 12, 'end_offset_seconds' => null], mq_track_bounds(['end_offset_seconds' => null], ['start_offset_seconds' => 12, 'end_offset_seconds' => 45]));
});

mqTest('Les timestamps refusent les bornes inversees et les valeurs invalides', function (): void {
    foreach ([-1, 86401, '1.5', true, [], 'abc'] as $value) {
        mqAssertThrows(RuntimeException::class, fn() => mq_optional_seconds($value));
    }
    foreach ([0, 10, 9] as $end) {
        mqAssertThrows(RuntimeException::class, fn() => mq_track_bounds(['start_offset_seconds' => 10, 'end_offset_seconds' => $end]));
    }
});

mqTest('La notoriete distingue non evaluee et notes de 1 a 10', function (): void {
    mqAssertSame(null, mq_familiarity(null));
    mqAssertSame(1, mq_familiarity('1', false));
    mqAssertSame(10, mq_familiarity(10));
    foreach ([0, 11, true, '5.5', []] as $value) {
        mqAssertThrows(RuntimeException::class, fn() => mq_familiarity($value));
    }
    mqAssertThrows(RuntimeException::class, fn() => mq_familiarity(null, false));
});
