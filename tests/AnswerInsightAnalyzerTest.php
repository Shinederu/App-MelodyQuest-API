<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../services/AnswerInsightAnalyzer.php';

$answerAnalyzer = new AnswerInsightAnalyzer();

mqTest('Les fautes proches sont regroupées pour une même œuvre', function () use ($answerAnalyzer): void {
    $result = $answerAnalyzer->analyze([
        [
            'guess_text' => 'Genshin Impcat',
            'attempt_count' => 2,
            'wrong_count' => 2,
            'user_ids' => '1,2',
            'track_ids' => '10',
            'track_titles' => 'Main Theme',
            'family_id' => 7,
            'family_name' => 'Genshin Impact',
            'category_id' => 3,
            'category_name' => 'Jeux vidéo',
            'accepted_aliases' => [],
            'last_at' => '2026-08-10 12:00:00',
        ],
        [
            'guess_text' => 'Genshin Impakt',
            'attempt_count' => 1,
            'wrong_count' => 1,
            'user_ids' => '3',
            'track_ids' => '11',
            'track_titles' => 'Battle Theme',
            'family_id' => 7,
            'family_name' => 'Genshin Impact',
            'category_id' => 3,
            'category_name' => 'Jeux vidéo',
            'accepted_aliases' => [],
            'last_at' => '2026-08-11 12:00:00',
        ],
    ]);

    mqAssertSame(1, count($result['alias_candidates']));
    mqAssertSame(3, $result['alias_candidates'][0]['attempt_count']);
    mqAssertSame(3, $result['alias_candidates'][0]['user_count']);
    mqAssertSame(2, count($result['alias_candidates'][0]['variants']));
    mqAssertTrue($result['alias_candidates'][0]['can_add_alias']);
});

mqTest('Un alias déjà accepté ne peut pas être ajouté une seconde fois', function () use ($answerAnalyzer): void {
    $result = $answerAnalyzer->analyze([[
        'guess_text' => 'SNK',
        'attempt_count' => 2,
        'wrong_count' => 2,
        'user_ids' => '1,2',
        'track_ids' => '20',
        'track_titles' => 'Opening',
        'family_id' => 8,
        'family_name' => 'Shingeki no Kyojin',
        'category_id' => 1,
        'category_name' => 'Anime',
        'accepted_aliases' => ['SNK'],
        'last_at' => '2026-08-11 12:00:00',
    ]]);

    mqAssertTrue($result['alias_candidates'][0]['already_accepted']);
    mqAssertFalse($result['alias_candidates'][0]['can_add_alias']);
});

mqTest('Une réponse récurrente sans rapport devient une idée de contenu', function () use ($answerAnalyzer): void {
    $base = [
        'guess_text' => 'Naruto',
        'attempt_count' => 1,
        'wrong_count' => 1,
        'track_ids' => '30',
        'track_titles' => 'Theme',
        'category_id' => 1,
        'category_name' => 'Anime',
        'accepted_aliases' => [],
        'last_at' => '2026-08-11 12:00:00',
    ];

    $result = $answerAnalyzer->analyze([
        $base + ['user_ids' => '1', 'family_id' => 10, 'family_name' => 'One Piece'],
        $base + ['user_ids' => '2', 'family_id' => 11, 'family_name' => 'Bleach'],
    ]);

    mqAssertSame(1, count($result['content_ideas']));
    mqAssertSame('Naruto', $result['content_ideas'][0]['guess_text']);
    mqAssertSame(2, $result['content_ideas'][0]['user_count']);
    mqAssertSame(2, $result['content_ideas'][0]['family_count']);
    mqAssertSame(1, $result['content_ideas'][0]['recommended_category_id']);
});
