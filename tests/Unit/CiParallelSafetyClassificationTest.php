<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Tests\Concerns\GuardsDestructiveMigrationTestEnvironment;
use Tests\TestCase;

/** Menjaga primitive test yang berbagi schema atau proses tidak masuk lane paralel. */
class CiParallelSafetyClassificationTest extends TestCase
{
    public function test_semua_test_berisiko_memiliki_klasifikasi_lane_ci_yang_eksplisit(): void
    {
        $violations = [];

        foreach ($this->test_files() as $file) {
            $source = file_get_contents($file->getPathname());

            if ($source === false) {
                $violations[] = sprintf('Tidak dapat membaca sumber test %s.', $this->relativePath($file));

                continue;
            }

            $class = $this->classFromFile($source);

            if ($class === null) {
                $violations[] = sprintf('Tidak menemukan class test pada %s.', $this->relativePath($file));

                continue;
            }

            require_once $file->getPathname();
            $reflection = new ReflectionClass($class);
            $groups = $this->groupsFor($reflection);
            $primitives = $this->dangerousPrimitives($source);
            $hasSerialClassification = array_intersect(['serial', 'guarded-destructive'], $groups) !== [];

            if ($primitives !== [] && ! $hasSerialClassification) {
                $violations[] = sprintf(
                    '%s memakai primitive berisiko (%s), tetapi class %s belum diberi #[Group(\'serial\')] atau #[Group(\'guarded-destructive\')].',
                    $this->relativePath($file),
                    implode(', ', $primitives),
                    $class,
                );
            }

            $usesDestructiveOptIn = str_contains($source, $this->destructiveOptIn());

            if ($usesDestructiveOptIn && ! in_array('guarded-destructive', $groups, true)) {
                $violations[] = sprintf(
                    '%s memeriksa opt-in migrasi destruktif, tetapi class %s wajib memakai #[Group(\'guarded-destructive\')].',
                    $this->relativePath($file),
                    $class,
                );
            }

            if (in_array('guarded-destructive', $groups, true) && ! in_array(GuardsDestructiveMigrationTestEnvironment::class, $reflection->getTraitNames(), true)) {
                $violations[] = sprintf(
                    '%s bergrup guarded-destructive tetapi class %s wajib memakai trait %s agar opt-in %s serta APP_ENV=testing, driver pgsql, dan database simpeg_test diperiksa sebelum setiap test.',
                    $this->relativePath($file),
                    $class,
                    GuardsDestructiveMigrationTestEnvironment::class,
                    $this->destructiveOptIn(),
                );
            }
        }

        $this->assertSame([], $violations, implode(PHP_EOL, $violations));
    }

    /** @return list<SplFileInfo> */
    private function test_files(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('tests')));

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || ! str_ends_with($file->getFilename(), 'Test.php')) {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());

            if (str_contains($path, '/tests/Browser/') || str_contains($path, '/tests/Dusk/')) {
                continue;
            }

            $files[] = $file;
        }

        return $files;
    }

    /** @return list<string> */
    private function groupsFor(ReflectionClass $class): array
    {
        return array_values(array_filter(array_map(
            static fn (\ReflectionAttribute $attribute): mixed => $attribute->getArguments()[0] ?? null,
            $class->getAttributes(Group::class),
        ), 'is_string'));
    }

    /** @return list<string> */
    private function dangerousPrimitives(string $source): array
    {
        $primitives = [];
        $databaseMigrations = 'Database'.'Migrations';
        $migrateFresh = 'migrate'.':fresh';
        $process = 'Symfony'.'\\'.'Component'.'\\'.'Process'.'\\'.'Process';
        $fixtures = 'tests'.'/Fixtures/';
        $runTestsInSeparateProcesses = 'Run'.'TestsInSeparateProcesses';
        $runInSeparateProcess = 'Run'.'InSeparateProcess';
        $legacySeparateProcessesAnnotation = '@run'.'TestsInSeparateProcesses';
        $legacySeparateProcessAnnotation = '@run'.'InSeparateProcess';

        if (str_contains($source, $databaseMigrations)) {
            $primitives[] = $databaseMigrations;
        }

        if (str_contains($source, $migrateFresh)) {
            $primitives[] = $migrateFresh;
        }

        if (str_contains($source, $process)) {
            $primitives[] = $process;
        }

        if (str_contains($source, $fixtures)) {
            $primitives[] = $fixtures;
        }

        if (str_contains($source, $runTestsInSeparateProcesses)) {
            $primitives[] = $runTestsInSeparateProcesses;
        }

        if (str_contains($source, $runInSeparateProcess)) {
            $primitives[] = $runInSeparateProcess;
        }

        if (str_contains($source, $legacySeparateProcessesAnnotation)) {
            $primitives[] = $legacySeparateProcessesAnnotation;
        }

        if (str_contains($source, $legacySeparateProcessAnnotation)) {
            $primitives[] = $legacySeparateProcessAnnotation;
        }

        return $primitives;
    }

    private function destructiveOptIn(): string
    {
        return 'SIMPEG'.'_ALLOW'.'_DESTRUCTIVE'.'_MIGRATION'.'_TESTS';
    }

    private function classFromFile(string $source): ?string
    {
        preg_match('/namespace\\s+([^;]+);/', $source, $namespace);
        preg_match('/class\\s+(\\w+)\\s+extends/', $source, $class);

        if (! isset($namespace[1], $class[1])) {
            return null;
        }

        return trim($namespace[1]).'\\'.$class[1];
    }

    private function relativePath(SplFileInfo $file): string
    {
        return str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
    }
}
