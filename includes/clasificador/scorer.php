<?php
/**
 * Ranking contextual del vocabulario semántico.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_classifier_longest_concept_run')) {
    function seo_classifier_longest_concept_run(array $term, array $haystack) {
        $best = 0;
        $term_count = count($term);
        $hay_count = count($haystack);
        for ($i = 0; $i < $term_count; $i++) {
            for ($j = 0; $j < $hay_count; $j++) {
                $k = 0;
                while (($i + $k) < $term_count && ($j + $k) < $hay_count && $term[$i + $k] === $haystack[$j + $k]) $k++;
                if ($k > $best) $best = $k;
            }
        }
        return $best;
    }
}

if (!function_exists('seo_classifier_vocab_stats')) {
    function seo_classifier_vocab_stats($group) {
        static $cache = [];
        $group = sanitize_key((string) $group);
        if (isset($cache[$group])) return $cache[$group];

        $rows = (array) (seo_classifier_vocabulary_index()[$group] ?? []);
        $df = [];
        foreach ($rows as $row) {
            $seen = [];
            foreach (array_unique((array) ($row['concepts'] ?? [])) as $token) $seen[$token] = true;
            foreach (array_keys($seen) as $token) $df[$token] = (int) ($df[$token] ?? 0) + 1;
        }
        $n = max(1, count($rows));
        $idf = [];
        foreach ($df as $token => $count) $idf[$token] = log(($n + 1) / ($count + 1)) + 1.0;
        return $cache[$group] = ['n'=>$n, 'idf'=>$idf];
    }
}

if (!function_exists('seo_classifier_label_metric')) {
    function seo_classifier_label_metric(array $term, array $context, $group) {
        $term_seq = (array) ($term['concepts'] ?? seo_classifier_concept_sequence((string) ($term['label'] ?? '')));
        if (!$term_seq) return ['score'=>0.0,'matched'=>0,'title_matched'=>0,'run'=>0,'reasons'=>[]];

        $title_seq = seo_classifier_concept_sequence((string) ($context['title'] ?? ''));
        $full_seq = seo_classifier_concept_sequence((string) ($context['full'] ?? ''));
        $category_seq = seo_classifier_concept_sequence((string) ($context['categories'] ?? ''));
        $tag_seq = seo_classifier_concept_sequence((string) ($context['tags'] ?? ''));

        $title = array_fill_keys($title_seq, true);
        $full = array_fill_keys($full_seq, true);
        $categories = array_fill_keys($category_seq, true);
        $tags = array_fill_keys($tag_seq, true);
        $stats = seo_classifier_vocab_stats($group);
        $idf = (array) ($stats['idf'] ?? []);

        $denom = 0.0;
        $title_weight = 0.0;
        $full_weight = 0.0;
        $category_weight = 0.0;
        $tag_weight = 0.0;
        $matched = 0;
        $title_matched = 0;
        $matched_labels = [];

        foreach (array_values(array_unique($term_seq)) as $token) {
            $weight = (float) ($idf[$token] ?? 1.0);
            $denom += $weight;
            if (isset($title[$token])) {
                $title_weight += $weight;
                $matched++;
                $title_matched++;
                $matched_labels[] = $token;
            } elseif (isset($full[$token])) {
                $full_weight += $weight;
                $matched++;
                $matched_labels[] = $token;
            }
            if (isset($categories[$token])) $category_weight += $weight;
            if (isset($tags[$token])) $tag_weight += $weight;
        }
        if ($matched < 1 || $denom <= 0) return ['score'=>0.0,'matched'=>0,'title_matched'=>0,'run'=>0,'reasons'=>[]];

        $coverage = ($title_weight + (0.42 * $full_weight)) / $denom;
        $title_coverage = $title_weight / $denom;
        $category_coverage = $category_weight / $denom;
        $tag_coverage = $tag_weight / $denom;
        $run = seo_classifier_longest_concept_run($term_seq, $title_seq);
        $bonus = 0.0;
        $reasons = [];

        $term_norm = seo_classifier_normalize((string) ($term['label'] ?? ''));
        $title_norm = seo_classifier_normalize((string) ($context['title'] ?? ''));
        $full_norm = seo_classifier_normalize((string) ($context['full'] ?? ''));
        if ($term_norm !== '' && strpos(' ' . $title_norm . ' ', ' ' . $term_norm . ' ') !== false) {
            $bonus += 0.16;
            $reasons[] = 'frase exacta en título';
        } elseif ($term_norm !== '' && strpos(' ' . $full_norm . ' ', ' ' . $term_norm . ' ') !== false) {
            $bonus += 0.09;
            $reasons[] = 'frase exacta en contenido';
        }
        if ($run >= 3) {
            $bonus += 0.13;
            $reasons[] = 'secuencia de ' . $run . ' conceptos';
        } elseif ($run === 2) {
            $bonus += 0.09;
            $reasons[] = 'secuencia de 2 conceptos';
        }
        if ($title_matched >= 3) $bonus += 0.07;
        elseif ($title_matched === 2) $bonus += 0.04;
        if ($category_coverage >= 0.50) $reasons[] = 'apoyo de categoría';
        if ($tag_coverage >= 0.50) $reasons[] = 'apoyo de product_tag';
        if ($matched_labels) $reasons[] = 'coincide: ' . implode(', ', array_slice(array_unique($matched_labels), 0, 4));

        $score = 0.07
            + (0.61 * $coverage)
            + (0.17 * $title_coverage)
            + (0.13 * $category_coverage)
            + (0.08 * $tag_coverage)
            + $bonus;

        $metric = [
            'score'=>round(max(0.0, min(1.0, $score)), 4),
            'matched'=>$matched,
            'title_matched'=>$title_matched,
            'run'=>$run,
            'reasons'=>$reasons,
        ];
        return seo_classifier_adjust_label_metric($group, $term, $context, $metric);
    }
}

if (!function_exists('seo_classifier_rank_label_group')) {
    function seo_classifier_rank_label_group($group, array $context, $limit = 5) {
        $index = seo_classifier_vocabulary_index();
        $ranked = [];
        foreach ((array) ($index[$group] ?? []) as $term) {
            $metric = seo_classifier_label_metric($term, $context, $group);
            if ((float) ($metric['score'] ?? 0) <= 0) continue;
            $ranked[] = ['term'=>$term] + $metric;
        }
        usort($ranked, static function($a, $b) {
            $as = (float) ($a['score'] ?? 0);
            $bs = (float) ($b['score'] ?? 0);
            if ($as !== $bs) return $as > $bs ? -1 : 1;
            $am = (int) ($a['matched'] ?? 0);
            $bm = (int) ($b['matched'] ?? 0);
            if ($am !== $bm) return $am > $bm ? -1 : 1;
            return strcmp((string) ($a['term']['label'] ?? ''), (string) ($b['term']['label'] ?? ''));
        });
        return array_slice($ranked, 0, max(1, (int) $limit));
    }
}

if (!function_exists('seo_classifier_candidate_state')) {
    function seo_classifier_candidate_state($group, array $ranked) {
        if (!$ranked) return ['state'=>'unresolved','candidate'=>null,'margin'=>0.0];
        $top = $ranked[0];
        $score = (float) ($top['score'] ?? 0);
        $matched = (int) ($top['matched'] ?? 0);
        $second = (float) ($ranked[1]['score'] ?? 0);
        $margin = max(0.0, $score - $second);
        $t = seo_classifier_group_thresholds($group);

        $safe = $matched >= (int) $t['min_matched']
            && $score >= (float) $t['safe']
            && ($margin >= (float) $t['margin'] || $score >= 0.91);
        $review = $matched >= (int) $t['min_matched'] && $score >= (float) $t['review'];

        return [
            'state'=>$safe ? 'safe' : ($review ? 'review' : 'unresolved'),
            'candidate'=>$top,
            'margin'=>round($margin, 4),
        ];
    }
}
