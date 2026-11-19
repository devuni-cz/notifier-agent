<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| AI agents in client projects follow these files, so every command, option
| and setting they name has to exist, and Boost has to be able to read them.
|--------------------------------------------------------------------------
*/

function boostFile(string $path): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/resources/boost/'.$path);
}

it('ships one Markdown guideline that Boost copies without rendering', function () {
    // Older Boost renders a .blade.php guideline and would turn the component
    // example into real markup. Boost keys guidelines by package, so one file.
    expect(array_map('basename', glob(dirname(__DIR__, 2).'/resources/boost/guidelines/*')))->toBe(['core.md']);

    $guideline = boostFile('guidelines/core.md');

    expect($guideline)->toStartWith("# Devuni Notifier Agent\n")
        ->and($guideline)->not->toContain('{{')
        ->and($guideline)->not->toContain('{!!')
        ->and(preg_match('/^\s*@/m', $guideline))->toBe(0);
});

it('ships a skill with the frontmatter Boost requires', function () {
    $skill = boostFile('skills/notifier-restore/SKILL.md');

    expect(preg_match('/\A---\n(.*?)\n---\n/s', $skill, $matches))->toBe(1)
        ->and($matches[1])->toMatch('/^name: notifier-restore$/m')
        ->and($matches[1])->toMatch('/^description: >\n {2}\S/m');
});

it('names only commands, options and settings that exist', function (string $path) {
    $text = boostFile($path);
    $commands = collect(Artisan::all())->filter(fn ($command, string $name): bool => str_starts_with($name, 'notifier:'));
    $options = $commands->flatMap(fn ($command): array => array_keys($command->getDefinition()->getOptions()))->unique();
    $config = (string) file_get_contents(dirname(__DIR__, 2).'/config/notifier.php');

    preg_match_all('/\bnotifier:[a-z-]+/', $text, $named);
    preg_match_all('/(?<![\w-])--([a-z][a-z-]*)/', $text, $flags);
    preg_match_all('/\bNOTIFIER_[A-Z_]+\b/', $text, $keys);

    expect($named[0])->not->toBeEmpty()
        ->and(array_diff(array_unique($named[0]), $commands->keys()->all()))->toBe([])
        ->and(array_diff(array_unique($flags[1]), $options->all()))->toBe([])
        ->and(array_filter(array_unique($keys[0]), fn (string $key): bool => ! str_contains($config, "'{$key}'")))->toBe([]);
})->with([
    'guideline' => ['guidelines/core.md'],
    'skill' => ['skills/notifier-restore/SKILL.md'],
]);

it('keeps the Boost files in the Composer archive', function () {
    $attributes = (string) file_get_contents(dirname(__DIR__, 2).'/.gitattributes');

    expect(preg_match('#^/resources\b.*export-ignore#m', $attributes))->toBe(0);
});
