<?php

declare(strict_types=1);

namespace Yangweijie\Ui3;

/**
 * Pure-PHP spellcheck engine. Loads a system word list (e.g. /usr/share/dict/words
 * on macOS/Linux) with a bundled fallback dictionary, and exposes fast
 * membership tests plus lazy Levenshtein suggestions.
 *
 * Misspelled-word detection is O(token) — a dictionary is a hash set, so a
 * field can be re-scanned on every keystroke with no perceptible cost.
 * Suggestions are expensive (Levenshtein over a filtered candidate set) and
 * are therefore computed on demand, typically when a context menu opens.
 */
final class Spellcheck
{
    /** @var array<string,true> lowercase dictionary words */
    private array $dict = [];

    /** @var array<string,true> words the user explicitly added */
    private array $custom = [];

    /** @var array<string,true> words ignored for this session */
    private array $ignored = [];

    /** @var array<int,list<string>> dictionary indexed by byte length */
    private array $byLen = [];

    /** @var array<string,true> high-frequency words (ties broken toward these) */
    private array $common = [];

    private bool $indexed = false;

    /** @var string|null which shared dictionary this instance uses ('bundled' when none) */
    private ?string $dictKey = 'bundled';

    /** @var array<string,array<string,true>> loaded dictionaries, shared per source path */
    private static array $sharedDicts = [];

    /** @var array<string,array<int,list<string>>> length indexes, shared per source path */
    private static array $sharedIndexes = [];

    /** @param list<string> $bundledWords fallback dictionary (used when no system dict is readable) */
    public function __construct(
        ?string $dictionaryPath = null,
        array $bundledWords = self::DEFAULT_WORDS,
    ) {
        foreach ($bundledWords as $w) {
            $this->common[$w] = true;
        }
        $path = $dictionaryPath ?? self::defaultDictionary();
        if ($path !== null && is_file($path) && is_readable($path)) {
            self::$sharedDicts[$path] ??= self::loadFile($path);
            $this->dict = self::$sharedDicts[$path];
            $this->dictKey = $path;
        }
        if ($this->dict === []) {
            $this->dict = $this->common;
        }
    }

    /** First readable system dictionary path, or null. */
    public static function defaultDictionary(): ?string
    {
        foreach (['/usr/share/dict/words', '/usr/share/dict/american-english', '/usr/share/dict/british-english'] as $p) {
            if (is_file($p) && is_readable($p)) {
                return $p;
            }
        }
        return null;
    }

    /** @return array<string,true> */
    private static function loadFile(string $path): array
    {
        $out = [];
        $fh = fopen($path, 'r');
        if ($fh === false) {
            return $out;
        }
        while (($line = fgets($fh)) !== false) {
            $w = strtolower(trim($line));
            if ($w !== '' && !preg_match('/[\x80-\xff\s\']/', $w)) {
                $out[$w] = true;
            }
        }
        fclose($fh);
        return $out;
    }

    public function addToDictionary(string $word): void
    {
        $w = strtolower(trim($word));
        if ($w !== '') {
            $this->custom[$w] = true;
        }
    }

    public function ignore(string $word): void
    {
        $w = strtolower(trim($word));
        if ($w !== '') {
            $this->ignored[$w] = true;
        }
    }

    public function isKnown(string $word): bool
    {
        $w = strtolower(trim($word));
        if ($w === '') {
            return true;
        }
        return isset($this->dict[$w]) || isset($this->custom[$w]) || isset($this->ignored[$w]);
    }

    public function isMisspelled(string $word): bool
    {
        return !$this->isKnown($word);
    }

    /**
     * Tokenize $text and return every misspelled word as a char-offset range.
     * Offsets are character (not byte) offsets into $text, matching the
     * mb_substr math the backends use for text geometry.
     *
     * @return list<array{start:int,end:int,word:string}>
     */
    public function errors(string $text): array
    {
        if ($text === '') {
            return [];
        }
        if (!preg_match_all('/[\p{L}\p{N}]+(?:[\x27][\p{L}\p{N}]+)*/u', $text, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $out = [];
        foreach ($m[0] as [$raw, $byteOff]) {
            $word = strtolower($raw);
            $start = mb_strlen(substr($text, 0, $byteOff));
            $end = $start + mb_strlen($raw);
            if ($this->isKnown($word)) {
                continue;
            }
            $out[] = ['start' => $start, 'end' => $end, 'word' => $word];
        }
        return $out;
    }

    /**
     * Correction candidates for a word, cheapest first, capped at $limit.
     * Candidates are dictionary words sharing the first letter and within one
     * character of the length, scored by optimal-string-alignment (Damerau)
     * distance so transposition typos ("teh" → "the") rank correctly. Empty
     * when the word is already known.
     *
     * @return list<string>
     */
    public function suggest(string $word, int $limit = 5): array
    {
        $w = strtolower(trim($word));
        if ($w === '' || $this->isKnown($w)) {
            return [];
        }
        $this->ensureIndex();
        $len = strlen($w);
        $first = $w[0];
        $cands = [];
        foreach ([$len - 1, $len, $len + 1] as $l) {
            if ($l < 1 || !isset($this->byLen[$l])) {
                continue;
            }
            foreach ($this->byLen[$l] as $cand) {
                if ($cand[0] !== $first) {
                    continue;
                }
                $d = self::osaDistance($w, $cand);
                if ($d <= 2 || ($len >= 6 && $d <= 3)) {
                    // Common words win ties against obscure dictionary entries.
                    $score = isset($this->common[$cand]) ? max(0, $d - 1) : $d;
                    $cands[] = [$score, $cand];
                }
            }
        }
        usort($cands, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: strcmp($a[1], $b[1]));
        $out = [];
        foreach ($cands as [, $cand]) {
            $out[] = $cand;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** Optimal string alignment distance (Damerau with no substring edits). */
    private static function osaDistance(string $a, string $b): int
    {
        $m = strlen($a);
        $n = strlen($b);
        if ($m === 0) {
            return $n;
        }
        if ($n === 0) {
            return $m;
        }
        $prev = range(0, $n);
        $cur = array_fill(0, $n + 1, 0);
        for ($i = 1; $i <= $m; $i++) {
            $cur[0] = $i;
            for ($j = 1; $j <= $n; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $cur[$j] = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + $cost);
                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $cur[$j] = min($cur[$j], $prev2[$j - 2] + 1);
                }
            }
            $prev2 = $prev;
            $prev = $cur;
        }
        return $prev[$n];
    }

    private function ensureIndex(): void
    {
        if ($this->indexed) {
            return;
        }
        $key = $this->dictKey ?? 'bundled';
        if (!isset(self::$sharedIndexes[$key])) {
            $buckets = [];
            foreach (array_keys($this->dict) as $w) {
                $l = strlen($w);
                $buckets[$l][] = $w;
            }
            self::$sharedIndexes[$key] = $buckets;
        }
        $this->byLen = self::$sharedIndexes[$key];
        $this->indexed = true;
    }

    /** Compact fallback dictionary covering common English vocabulary. */
    public const DEFAULT_WORDS = [
        'a', 'about', 'above', 'accept', 'account', 'across', 'act', 'action', 'add', 'after',
        'again', 'against', 'age', 'ago', 'agree', 'air', 'all', 'allow', 'almost', 'alone',
        'along', 'already', 'also', 'although', 'always', 'am', 'among', 'amount', 'an', 'and',
        'animal', 'another', 'answer', 'any', 'anyone', 'anything', 'app', 'appear', 'apple', 'are',
        'area', 'arm', 'around', 'arrive', 'art', 'as', 'ask', 'at', 'attack', 'attention',
        'author', 'autumn', 'available', 'away', 'back', 'bad', 'bag', 'ball', 'band', 'bank',
        'bar', 'base', 'basic', 'be', 'beach', 'bear', 'beautiful', 'because', 'become', 'bed',
        'been', 'before', 'begin', 'behind', 'believe', 'below', 'best', 'better', 'between', 'big',
        'bird', 'black', 'block', 'blood', 'blue', 'board', 'boat', 'body', 'book', 'born',
        'both', 'bottom', 'box', 'boy', 'brain', 'branch', 'break', 'bridge', 'bright', 'bring',
        'brother', 'brown', 'build', 'building', 'business', 'busy', 'but', 'buy', 'by', 'call',
        'came', 'camera', 'camp', 'can', 'capital', 'car', 'card', 'care', 'careful', 'carry',
        'case', 'cat', 'catch', 'cause', 'center', 'certain', 'chair', 'chance', 'change', 'charge',
        'check', 'child', 'children', 'choose', 'city', 'class', 'clean', 'clear', 'clearly', 'click',
        'close', 'cloud', 'code', 'cold', 'color', 'come', 'common', 'company', 'compare', 'complete',
        'computer', 'condition', 'consider', 'contain', 'continue', 'control', 'cool', 'copy', 'corn', 'corner',
        'cost', 'could', 'count', 'country', 'course', 'court', 'cover', 'create', 'cross', 'crowd',
        'cry', 'culture', 'cup', 'current', 'cut', 'dad', 'dance', 'danger', 'dark', 'data',
        'date', 'daughter', 'day', 'dead', 'deal', 'dear', 'death', 'decide', 'deep', 'defeat',
        'degree', 'design', 'desk', 'develop', 'die', 'different', 'difficult', 'digital', 'dinner', 'direction',
        'dirty', 'discover', 'discuss', 'disease', 'distance', 'divide', 'do', 'doctor', 'dog', 'door',
        'down', 'draw', 'dream', 'dress', 'drink', 'drive', 'drop', 'dry', 'during', 'each',
        'early', 'earth', 'east', 'easy', 'eat', 'edge', 'education', 'effect', 'effort', 'egg',
        'eight', 'either', 'electric', 'else', 'end', 'enemy', 'energy', 'engine', 'enough', 'enter',
        'entire', 'environment', 'equal', 'error', 'especially', 'even', 'evening', 'event', 'ever', 'every',
        'everyone', 'everything', 'exact', 'example', 'excellent', 'except', 'exchange', 'exist', 'expect', 'experience',
        'explain', 'express', 'eye', 'face', 'fact', 'fail', 'fall', 'family', 'far', 'farm',
        'fast', 'father', 'fear', 'feed', 'feel', 'feeling', 'field', 'fight', 'figure', 'fill',
        'film', 'final', 'finally', 'find', 'fine', 'finger', 'finish', 'fire', 'first', 'fish',
        'five', 'floor', 'fly', 'focus', 'follow', 'food', 'foot', 'for', 'force', 'forest',
        'forget', 'form', 'forward', 'found', 'four', 'free', 'freedom', 'fresh', 'friend', 'from',
        'front', 'fruit', 'full', 'fun', 'function', 'future', 'game', 'garden', 'gas', 'gate',
        'gather', 'general', 'gentle', 'get', 'girl', 'give', 'glass', 'go', 'goal', 'god',
        'gold', 'gone', 'good', 'government', 'great', 'green', 'ground', 'group', 'grow', 'guess',
        'gun', 'guy', 'hair', 'half', 'hall', 'hand', 'handle', 'hang', 'happen', 'happy',
        'hard', 'has', 'hat', 'have', 'he', 'head', 'health', 'hear', 'heart', 'heat',
        'heavy', 'height', 'hello', 'help', 'her', 'here', 'high', 'hill', 'him', 'his',
        'history', 'hit', 'hold', 'home', 'hope', 'horse', 'hospital', 'hot', 'hotel', 'hour',
        'house', 'how', 'however', 'huge', 'human', 'hundred', 'hunt', 'hurry', 'hurt', 'husband',
        'i', 'ice', 'idea', 'if', 'image', 'imagine', 'important', 'in', 'include', 'increase',
        'indeed', 'information', 'inside', 'instead', 'interest', 'into', 'involve', 'is', 'island', 'issue',
        'it', 'item', 'job', 'join', 'jump', 'just', 'keep', 'key', 'kid', 'kill',
        'kind', 'king', 'kitchen', 'know', 'knowledge', 'land', 'language', 'large', 'last', 'late',
        'later', 'laugh', 'law', 'lay', 'lead', 'learn', 'least', 'leave', 'left', 'leg',
        'length', 'less', 'let', 'letter', 'level', 'life', 'light', 'like', 'line', 'list',
        'listen', 'little', 'live', 'local', 'long', 'look', 'lose', 'loss', 'lot', 'love',
        'low', 'luck', 'machine', 'main', 'major', 'make', 'man', 'many', 'map', 'mark',
        'market', 'marry', 'match', 'matter', 'may', 'maybe', 'me', 'mean', 'measure', 'meet',
        'meeting', 'member', 'men', 'method', 'middle', 'might', 'mile', 'milk', 'million', 'mind',
        'minute', 'miss', 'mission', 'model', 'moment', 'money', 'month', 'moon', 'more', 'morning',
        'most', 'mother', 'mountain', 'mouth', 'move', 'movie', 'much', 'music', 'must', 'my',
        'name', 'nation', 'nature', 'near', 'need', 'never', 'new', 'news', 'next', 'nice',
        'night', 'no', 'noise', 'north', 'not', 'note', 'nothing', 'notice', 'now', 'number',
        'object', 'occur', 'ocean', 'of', 'off', 'offer', 'office', 'often', 'oil', 'ok',
        'old', 'on', 'once', 'one', 'only', 'open', 'operate', 'opinion', 'or', 'order',
        'other', 'our', 'out', 'outside', 'over', 'own', 'page', 'pain', 'paint', 'paper',
        'parent', 'park', 'part', 'party', 'pass', 'past', 'path', 'pay', 'peace', 'people',
        'perhaps', 'period', 'person', 'phone', 'photo', 'pick', 'picture', 'piece', 'place', 'plan',
        'plant', 'play', 'player', 'please', 'point', 'police', 'poor', 'popular', 'position', 'possible',
        'power', 'practice', 'present', 'president', 'press', 'pretty', 'price', 'probably', 'problem', 'process',
        'produce', 'product', 'program', 'project', 'protect', 'prove', 'provide', 'public', 'pull', 'purpose',
        'push', 'put', 'quality', 'question', 'quick', 'quickly', 'quiet', 'quite', 'race', 'radio',
        'rain', 'raise', 'range', 'rate', 'rather', 'reach', 'read', 'ready', 'real', 'really',
        'reason', 'receive', 'recent', 'record', 'red', 'remember', 'remove', 'report', 'represent', 'require',
        'research', 'rest', 'result', 'return', 'rich', 'right', 'ring', 'rise', 'river', 'road',
        'rock', 'role', 'room', 'root', 'rose', 'round', 'row', 'rule', 'run', 'safe',
        'same', 'save', 'say', 'school', 'science', 'sea', 'season', 'seat', 'second', 'section',
        'see', 'seek', 'seem', 'sell', 'send', 'sense', 'series', 'serve', 'set', 'seven',
        'several', 'shall', 'shape', 'share', 'she', 'ship', 'short', 'should', 'shoulder', 'show',
        'side', 'sign', 'silent', 'simple', 'simply', 'since', 'sing', 'single', 'sister', 'sit',
        'site', 'six', 'size', 'skin', 'sky', 'sleep', 'slow', 'small', 'smile', 'snow',
        'so', 'social', 'soft', 'soil', 'soldier', 'solution', 'some', 'someone', 'something', 'sometimes',
        'son', 'song', 'soon', 'sound', 'source', 'south', 'space', 'speak', 'special', 'specific',
        'speed', 'spend', 'spirit', 'sport', 'spring', 'square', 'staff', 'stage', 'stand', 'star',
        'start', 'state', 'station', 'stay', 'step', 'still', 'stock', 'stone', 'stop', 'store',
        'story', 'straight', 'strange', 'street', 'strength', 'strong', 'student', 'study', 'subject', 'success',
        'such', 'sudden', 'suggest', 'summer', 'sun', 'support', 'sure', 'surface', 'system', 'table',
        'take', 'talk', 'tall', 'team', 'technology', 'tell', 'ten', 'test', 'than', 'thank',
        'that', 'the', 'their', 'them', 'then', 'there', 'these', 'they', 'thing', 'think',
        'third', 'this', 'those', 'though', 'thought', 'three', 'through', 'throw', 'thus', 'time',
        'tiny', 'to', 'today', 'together', 'tonight', 'too', 'tool', 'top', 'total', 'touch',
        'toward', 'town', 'trade', 'train', 'travel', 'tree', 'trip', 'trouble', 'true', 'trust',
        'truth', 'try', 'turn', 'two', 'type', 'understand', 'unit', 'until', 'up', 'upon',
        'us', 'use', 'usually', 'value', 'very', 'view', 'visit', 'voice', 'wait', 'walk',
        'wall', 'want', 'war', 'warm', 'watch', 'water', 'way', 'we', 'weak', 'wear',
        'weather', 'week', 'weight', 'well', 'west', 'what', 'when', 'where', 'whether', 'which',
        'while', 'white', 'who', 'whole', 'whom', 'why', 'wide', 'wife', 'wild', 'will',
        'win', 'wind', 'window', 'wish', 'with', 'within', 'without', 'woman', 'wonder', 'word',
        'work', 'worker', 'world', 'worry', 'would', 'write', 'writer', 'wrong', 'yard', 'year',
        'yes', 'yet', 'you', 'young', 'your', 'yourself',
    ];
}
