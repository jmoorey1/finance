<?php

function payee_pattern_metrics(string $pattern): array
{
    $wildcardCount = substr_count($pattern, '%') + substr_count($pattern, '_');
    $literal = str_replace(['%', '_'], '', $pattern);

    $startsAnchored = $pattern !== '' && $pattern[0] !== '%' && $pattern[0] !== '_';
    $endsAnchored = $pattern !== '' && substr($pattern, -1) !== '%' && substr($pattern, -1) !== '_';

    return [
        'exact_rank' => $wildcardCount === 0 ? 1 : 0,
        'anchor_rank' => ($startsAnchored ? 1 : 0) + ($endsAnchored ? 1 : 0),
        'literal_length' => strlen($literal),
        'wildcard_count' => $wildcardCount,
        'pattern_length' => strlen($pattern),
    ];
}

function resolve_best_payee_match(PDO $conn, string $description): ?array
{
    $description = trim($description);
    if ($description === '') {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT
            pp.id,
            pp.payee_id,
            pp.match_pattern,
            pp.priority,
            p.name AS payee_name
        FROM payee_patterns pp
        JOIN payees p ON p.id = pp.payee_id
        WHERE ? LIKE pp.match_pattern
    ");
    $stmt->execute([$description]);
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($matches)) {
        return null;
    }

    foreach ($matches as &$match) {
        $metrics = payee_pattern_metrics((string)$match['match_pattern']);
        $match['_exact_rank'] = $metrics['exact_rank'];
        $match['_anchor_rank'] = $metrics['anchor_rank'];
        $match['_literal_length'] = $metrics['literal_length'];
        $match['_wildcard_count'] = $metrics['wildcard_count'];
        $match['_pattern_length'] = $metrics['pattern_length'];
        $match['_priority'] = (int)($match['priority'] ?? 0);
        $match['_id'] = (int)($match['id'] ?? 0);
    }
    unset($match);

    usort($matches, function (array $a, array $b): int {
        return
            ($b['_priority'] <=> $a['_priority'])
            ?: ($b['_exact_rank'] <=> $a['_exact_rank'])
            ?: ($b['_anchor_rank'] <=> $a['_anchor_rank'])
            ?: ($b['_literal_length'] <=> $a['_literal_length'])
            ?: ($a['_wildcard_count'] <=> $b['_wildcard_count'])
            ?: ($b['_pattern_length'] <=> $a['_pattern_length'])
            ?: ($a['_id'] <=> $b['_id']);
    });

    $best = $matches[0];
    unset(
        $best['_exact_rank'],
        $best['_anchor_rank'],
        $best['_literal_length'],
        $best['_wildcard_count'],
        $best['_pattern_length'],
        $best['_priority'],
        $best['_id']
    );

    return $best;
}

function resolve_payee_id_for_description(PDO $conn, string $description): ?int
{
    $match = resolve_best_payee_match($conn, $description);
    return $match ? (int)$match['payee_id'] : null;
}
