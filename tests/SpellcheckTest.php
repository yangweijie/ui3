<?php
declare(strict_types=1);

use Yangweijie\Ui3\Spellcheck;

test('engine loads a system dictionary', function () {
    $sc = new Spellcheck();
    expect($sc->isKnown('the'))->toBeTrue();
    expect($sc->isKnown('THE'))->toBeTrue();
    expect($sc->isMisspelled('teh'))->toBeTrue();
});

test('engine falls back to bundled words when no dictionary is readable', function () {
    $sc = new Spellcheck(dictionaryPath: '/nonexistent/dict');
    expect($sc->isKnown('the'))->toBeTrue();
    expect($sc->isKnown('brown'))->toBeTrue();
    expect($sc->isMisspelled('teh'))->toBeTrue();
});

test('empty and whitespace input is always known', function () {
    $sc = new Spellcheck(dictionaryPath: '/nonexistent/dict');
    expect($sc->isKnown(''))->toBeTrue();
    expect($sc->isMisspelled(''))->toBeFalse();
    expect($sc->errors(''))->toBe([]);
    expect($sc->errors('   '))->toBe([]);
});

test('errors reports misspelled words as char-offset ranges', function () {
    $sc = new Spellcheck();
    $errors = $sc->errors('The quick brwn fox. Teh end.');
    expect($errors)->toHaveCount(2);
    expect($errors[0])->toMatchArray(['start' => 10, 'end' => 14, 'word' => 'brwn']);
    expect($errors[1])->toMatchArray(['start' => 20, 'end' => 23, 'word' => 'teh']);
});

test('errors skips known words case-insensitively and ignores punctuation', function () {
    $sc = new Spellcheck(dictionaryPath: '/nonexistent/dict');
    $errors = $sc->errors('Hello, WORLD! this is fine.');
    expect($errors)->toBe([]);
});

test('errors returns empty when every token is known', function () {
    $sc = new Spellcheck(dictionaryPath: '/nonexistent/dict');
    expect($sc->errors('The quick brown dog'))->toBe([]);
});

test('errors flags non-Latin tokens and converts byte offsets correctly', function () {
    $sc = new Spellcheck(dictionaryPath: '/nonexistent/dict');
    $errors = $sc->errors('你好 teh');
    expect($errors)->toHaveCount(2);
    expect($errors[0])->toMatchArray(['start' => 0, 'end' => 2, 'word' => '你好']);
    expect($errors[1])->toMatchArray(['start' => 3, 'end' => 6, 'word' => 'teh']);
});

test('suggest returns correction candidates for a misspelled word', function () {
    $sc = new Spellcheck();
    expect($sc->suggest('teh'))->toContain('the');
    expect($sc->suggest('brwn'))->toContain('brown');
    expect($sc->suggest('recieve'))->toContain('receive');
});

test('suggest respects the limit and returns empty for known words', function () {
    $sc = new Spellcheck();
    expect($sc->suggest('the'))->toBe([]);
    expect(count($sc->suggest('teh', 2)))->toBeLessThanOrEqual(2);
});

test('addToDictionary makes a word known for the session', function () {
    $sc = new Spellcheck(dictionaryPath: '/nonexistent/dict');
    expect($sc->isMisspelled('brwn'))->toBeTrue();
    $sc->addToDictionary('brwn');
    expect($sc->isKnown('brwn'))->toBeTrue();
    expect($sc->isMisspelled('brwn'))->toBeFalse();
    expect($sc->errors('brwn dog'))->toBe([]);
});

test('ignore suppresses a word for the session', function () {
    $sc = new Spellcheck(dictionaryPath: '/nonexistent/dict');
    expect($sc->isMisspelled('xyzzy'))->toBeTrue();
    $sc->ignore('xyzzy');
    expect($sc->isKnown('xyzzy'))->toBeTrue();
    expect($sc->errors('xyzzy'))->toBe([]);
});
