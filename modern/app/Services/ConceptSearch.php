<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hybrid ranked concept search over names + synonyms (Usagi-style, lexical).
 *
 * Postgres path blends three signals, accent-insensitively (f_unaccent):
 *   1. exact concept_code match          -> score 1000 (always first)
 *   2. whole-string trigram similarity   -> good for short/typo'd terms
 *   3. tsvector token coverage (ts_rank, 'simple' config, websearch syntax)
 *      -> catches long clinical phrases where trigram similarity collapses
 * Final score = max(trigram, ts_rank * tsrank_scale); ties broken by how
 * often the team already maps to the concept (usage boost, ordering only).
 *
 * On other drivers (SQLite in tests) a LIKE fallback keeps the filter logic
 * testable. Tunables live in config('comet.search').
 */
class ConceptSearch
{
    /**
     * @param  list<string>  $vocabularies  empty = no vocabulary filter
     * @return Collection<int, object>
     */
    public function search(
        string $query,
        array $vocabularies = [],
        string $domain = 'all',
        bool $standardOnly = true,
        bool $validOnly = true,
        int $limit = 50,
    ): Collection {
        $query = trim($query);
        if ($query === '') {
            return collect();
        }

        $results = collect();

        // 1) Exact concept_code match always ranks first.
        $exact = DB::table('concepts')->where('concept_code', $query);
        $this->applyFilters($exact, $vocabularies, $domain, $standardOnly, $validOnly);
        foreach ($exact->limit(5)->get() as $row) {
            $row->score = 1000.0;
            $row->matched_on = 'code';
            $row->synonym_name = null;
            $results->put($row->concept_id, $row);
        }

        if (DB::getDriverName() === 'pgsql') {
            $this->pgSearch($results, $query, $vocabularies, $domain, $standardOnly, $validOnly, $limit);
        } else {
            $this->likeSearch($results, $query, $vocabularies, $domain, $standardOnly, $validOnly, $limit);
        }

        $ranked = $results->sortByDesc('score')->take($limit)->values();

        return $this->applyUsageOrdering($ranked);
    }

    private function applyFilters($builder, array $vocabularies, string $domain, bool $standardOnly, bool $validOnly): void
    {
        if (count($vocabularies)) {
            $builder->whereIn('vocabulary_id', $vocabularies);
        }
        if ($domain !== 'all' && $domain !== '') {
            $builder->where('domain_id', $domain);
        }
        if ($standardOnly) {
            $builder->where('standard_concept', 'S');
        }
        if ($validOnly) {
            $builder->whereNull('invalid_reason');
        }
    }

    private function pgSearch(Collection $results, string $q, array $vocabularies, string $domain, bool $standardOnly, bool $validOnly, int $limit): void
    {
        $needle = mb_strtolower($q);
        $threshold = (float) config('comet.search.trigram_threshold', 0.25);
        $tsScale = (float) config('comet.search.tsrank_scale', 4.0);
        $tsCap = (float) config('comet.search.tsrank_cap', 0.95);

        // ── Signal 2: accent-insensitive whole-string trigram ──
        $names = DB::table('concepts')
            ->selectRaw('*, similarity(lower(f_unaccent(concept_name)), lower(f_unaccent(?))) as sim', [$needle])
            ->whereRaw('similarity(lower(f_unaccent(concept_name)), lower(f_unaccent(?))) >= ?', [$needle, $threshold]);
        $this->applyFilters($names, $vocabularies, $domain, $standardOnly, $validOnly);
        foreach ($names->orderByDesc('sim')->limit($limit)->get() as $row) {
            $this->merge($results, $row, (float) $row->sim, 'name');
        }

        $syns = DB::table('concept_synonyms as s')
            ->join('concepts as c', 'c.concept_id', '=', 's.concept_id')
            ->selectRaw('c.*, s.concept_synonym_name, similarity(lower(f_unaccent(s.concept_synonym_name)), lower(f_unaccent(?))) as sim', [$needle])
            ->whereRaw('similarity(lower(f_unaccent(s.concept_synonym_name)), lower(f_unaccent(?))) >= ?', [$needle, $threshold]);
        $this->applyFilters($syns, $vocabularies, $domain, $standardOnly, $validOnly);
        foreach ($syns->orderByDesc('sim')->limit($limit)->get() as $row) {
            $this->merge($results, $row, (float) $row->sim, 'synonym', $row->concept_synonym_name);
        }

        // ── Signal 3: token coverage for long phrases (websearch syntax) ──
        $tsNames = DB::table('concepts')
            ->selectRaw("*, ts_rank(to_tsvector('simple', f_unaccent(concept_name)), websearch_to_tsquery('simple', f_unaccent(?))) as tsr", [$needle])
            ->whereRaw("to_tsvector('simple', f_unaccent(concept_name)) @@ websearch_to_tsquery('simple', f_unaccent(?))", [$needle]);
        $this->applyFilters($tsNames, $vocabularies, $domain, $standardOnly, $validOnly);
        foreach ($tsNames->orderByDesc('tsr')->limit($limit)->get() as $row) {
            $this->merge($results, $row, min($tsCap, (float) $row->tsr * $tsScale), 'tokens');
        }

        $tsSyns = DB::table('concept_synonyms as s')
            ->join('concepts as c', 'c.concept_id', '=', 's.concept_id')
            ->selectRaw("c.*, s.concept_synonym_name, ts_rank(to_tsvector('simple', f_unaccent(s.concept_synonym_name)), websearch_to_tsquery('simple', f_unaccent(?))) as tsr", [$needle])
            ->whereRaw("to_tsvector('simple', f_unaccent(s.concept_synonym_name)) @@ websearch_to_tsquery('simple', f_unaccent(?))", [$needle]);
        $this->applyFilters($tsSyns, $vocabularies, $domain, $standardOnly, $validOnly);
        foreach ($tsSyns->orderByDesc('tsr')->limit($limit)->get() as $row) {
            $this->merge($results, $row, min($tsCap, (float) $row->tsr * $tsScale), 'tokens', $row->concept_synonym_name);
        }
    }

    private function likeSearch(Collection $results, string $q, array $vocabularies, string $domain, bool $standardOnly, bool $validOnly, int $limit): void
    {
        $needle = '%'.mb_strtolower($q).'%';

        $names = DB::table('concepts')->whereRaw('lower(concept_name) like ?', [$needle]);
        $this->applyFilters($names, $vocabularies, $domain, $standardOnly, $validOnly);
        foreach ($names->limit($limit)->get() as $row) {
            $this->merge($results, $row, strlen($q) / max(1, strlen($row->concept_name)), 'name');
        }

        $syns = DB::table('concept_synonyms as s')
            ->join('concepts as c', 'c.concept_id', '=', 's.concept_id')
            ->select('c.*', 's.concept_synonym_name')
            ->whereRaw('lower(s.concept_synonym_name) like ?', [$needle]);
        $this->applyFilters($syns, $vocabularies, $domain, $standardOnly, $validOnly);
        foreach ($syns->limit($limit)->get() as $row) {
            $this->merge($results, $row, strlen($q) / max(1, strlen($row->concept_synonym_name)), 'synonym', $row->concept_synonym_name);
        }
    }

    private function merge(Collection $results, object $row, float $score, string $matchedOn, ?string $synonym = null): void
    {
        $existing = $results->get($row->concept_id);
        if ($existing && $existing->score >= $score) {
            return;
        }
        $row->score = round($score, 3);
        $row->matched_on = $matchedOn;
        $row->synonym_name = $synonym;
        unset($row->concept_synonym_name, $row->sim, $row->tsr);
        $results->put($row->concept_id, $row);
    }

    /**
     * Stable usage tie-break: within equal scores, concepts the team already
     * maps to rank first ("the team maps to this often" prior). Ordering only
     * — scores are not inflated.
     *
     * @param  Collection<int, object>  $ranked
     * @return Collection<int, object>
     */
    private function applyUsageOrdering(Collection $ranked): Collection
    {
        if ($ranked->isEmpty()) {
            return $ranked;
        }

        $uses = DB::table('maps')
            ->whereIn('target_concept_id', $ranked->pluck('concept_id')->all())
            ->selectRaw('target_concept_id, count(*) as uses')
            ->groupBy('target_concept_id')
            ->pluck('uses', 'target_concept_id');

        return $ranked
            ->map(function ($row) use ($uses) {
                $row->team_uses = (int) ($uses[$row->concept_id] ?? 0);

                return $row;
            })
            ->sortBy([['score', 'desc'], ['team_uses', 'desc']])
            ->values();
    }
}
