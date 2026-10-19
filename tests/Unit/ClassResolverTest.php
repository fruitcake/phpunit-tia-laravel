<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel\Tests\Unit;

use Fruitcake\PhpUnitTia\Laravel\ClassReferences;
use Fruitcake\PhpUnitTia\Laravel\LaravelResolver;
use Fruitcake\PhpUnitTia\Laravel\Tests\Support\TemporaryProject;
use JMac\Testing\PhpUnit\Tia\Contracts\EdgeAwareResolver;
use JMac\Testing\PhpUnit\Tia\Graph;
use JMac\Testing\PhpUnit\Tia\TestPaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs the resolver through core's Graph::affected() without a fallback
 * after it. Core's own directory guess then runs the tests linked to other
 * files in app/Enums: only StatusTest, which shows a path left alone.
 */
final class ClassResolverTest extends TestCase
{
    use TemporaryProject;

    private const string ENUM = 'app/Enums/ApprovalReason.php';

    private const array FALLBACK = ['tests/StatusTest.php'];

    /** @var array<string, list<string>> test file => project-relative files it is linked to */
    private array $links = [
        'tests/StatusTest.php' => ['app/Enums/Status.php'],
        'tests/SshLoginToolsTest.php' => ['app/Services/SshLoginApprover.php'],
        'tests/InvoiceTest.php' => ['app/Models/Invoice.php'],
    ];

    protected function setUp(): void
    {
        $this->setUpTemporaryProject();

        // Only cases: no executable line, so coverage never links it.
        $this->write(self::ENUM, "<?php\n\nnamespace App\\Enums;\n\nenum ApprovalReason: string\n{\n    case Manual = 'manual';\n}\n");
        $this->write('app/Enums/Status.php', "<?php\n\nnamespace App\\Enums;\n\nenum Status { case Open; public function label(): string { return 'x'; } }\n");
        $this->write('app/Models/Invoice.php', "<?php\n\nnamespace App\\Models;\n\nclass Invoice {}\n");

        foreach (array_keys($this->links) as $test) {
            $this->write($test, "<?php\n");
        }
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryProject();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function references(): iterable
    {
        yield 'use' => ["use App\\Enums\\ApprovalReason;\n\nclass SshLoginApprover { function reason() { return ApprovalReason::Manual; } }"];
        yield 'use with an alias' => ["use App\\Enums\\ApprovalReason as Reason;\n\nclass SshLoginApprover { function reason() { return Reason::Manual; } }"];
        yield 'group use' => ["use App\\Enums\\{Status, ApprovalReason};\n\nclass SshLoginApprover {}"];
        yield 'fully qualified' => ['class SshLoginApprover { function reason() { return \\App\\Enums\\ApprovalReason::Manual; } }'];
        yield 'class string' => ["class SshLoginApprover { protected \$casts = ['reason' => 'App\\\\Enums\\\\ApprovalReason']; }"];
    }

    #[Test]
    #[DataProvider('references')]
    public function a_declaration_only_enum_runs_the_tests_of_the_files_that_use_it(string $body): void
    {
        $this->write('app/Services/SshLoginApprover.php', "<?php\n\nnamespace App\\Services;\n\n{$body}\n");

        $this->assertAffected(['tests/SshLoginToolsTest.php'], self::ENUM);
    }

    #[Test]
    public function a_file_in_the_same_namespace_needs_no_use(): void
    {
        // Left alone, the fallback would run both tests linked in app/Enums.
        $this->links['tests/PolicyTest.php'] = ['app/Enums/ApprovalPolicy.php'];
        $this->write('tests/PolicyTest.php', "<?php\n");
        $this->write('app/Enums/ApprovalPolicy.php', "<?php\n\nnamespace App\\Enums;\n\nfinal class ApprovalPolicy { function default(): ApprovalReason { return ApprovalReason::Manual; } }\n");

        $this->assertAffected(['tests/PolicyTest.php'], self::ENUM);
    }

    #[Test]
    public function a_class_named_in_config_runs_every_test(): void
    {
        $this->write('config/approvals.php', "<?php\n\nreturn ['default' => App\\Enums\\ApprovalReason::class];\n");

        $this->assertAffected(['tests/InvoiceTest.php', 'tests/SshLoginToolsTest.php', 'tests/StatusTest.php'], self::ENUM);
    }

    #[Test]
    public function a_test_that_names_it_runs(): void
    {
        $this->links['tests/ReasonTest.php'] = [];
        $this->write('tests/ReasonTest.php', "<?php\n\nuse App\\Enums\\ApprovalReason;\n");

        $this->assertAffected(['tests/ReasonTest.php'], self::ENUM);
    }

    #[Test]
    public function a_class_used_through_another_unlinked_class_is_followed(): void
    {
        $this->write('app/Enums/ReasonGroup.php', "<?php\n\nnamespace App\\Enums;\n\nenum ReasonGroup: string\n{\n    case Default = ApprovalReason::Manual->value;\n}\n");
        $this->write('app/Services/SshLoginApprover.php', "<?php\n\nnamespace App\\Services;\n\nuse App\\Enums\\ReasonGroup;\n\nclass SshLoginApprover { function group() { return ReasonGroup::Default; } }\n");

        $this->assertAffected(['tests/SshLoginToolsTest.php'], self::ENUM);
    }

    #[Test]
    public function classes_that_only_name_each_other_end_the_search(): void
    {
        $this->write('app/Enums/First.php', "<?php\n\nnamespace App\\Enums;\n\ninterface First { const OTHER = Second::class; const REASON = ApprovalReason::class; }\n");
        $this->write('app/Enums/Second.php', "<?php\n\nnamespace App\\Enums;\n\ninterface Second { const OTHER = First::class; }\n");

        $this->assertAffected(self::FALLBACK, self::ENUM);
    }

    #[Test]
    public function a_class_nothing_names_is_left_to_the_fallback(): void
    {
        $this->write('app/Enums/Brand.php', "<?php\n\nnamespace App\\Enums;\n\nenum Brand { case New; }\n");

        $this->assertAffected(self::FALLBACK, 'app/Enums/Brand.php');
    }

    #[Test]
    public function a_file_without_a_class_is_left_to_the_fallback(): void
    {
        $this->write('app/Enums/helpers.php', "<?php\n\nfunction approval_reason() { return new class {}; }\n");
        $this->write('app/Services/SshLoginApprover.php', "<?php\n\nnamespace App\\Services;\n\nclass SshLoginApprover { function reason() { return approval_reason(); } }\n");

        $this->assertAffected(self::FALLBACK, 'app/Enums/helpers.php');
    }

    #[Test]
    public function similar_names_do_not_count(): void
    {
        $this->write('app/Services/SshLoginApprover.php', "<?php\n\nnamespace App\\Services;\n\nuse App\\Enums\\ApprovalReasons;\n\nclass SshLoginApprover { public \$ApprovalReason; function x() { return \$this->ApprovalReason; } }\n");

        $this->assertAffected(self::FALLBACK, self::ENUM);
    }

    #[Test]
    public function test_code_and_factories_stay_with_the_application_resolver(): void
    {
        $everything = ['tests/InvoiceTest.php', 'tests/SshLoginToolsTest.php', 'tests/StatusTest.php'];
        $this->write('tests/Concerns/ApprovesLogins.php', "<?php\n\nnamespace Tests\\Concerns;\n\ntrait ApprovesLogins {}\n");
        $this->write('database/factories/InvoiceFactory.php', "<?php\n\nnamespace Database\\Factories;\n\nclass InvoiceFactory {}\n");

        $this->assertAffected($everything, 'tests/Concerns/ApprovesLogins.php');
        $this->assertAffected($everything, 'database/factories/InvoiceFactory.php');
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function declarations(): iterable
    {
        yield 'enum' => ["<?php\nnamespace App\\Enums;\nenum Reason: string { case A = 'a'; }", 'App\\Enums\\Reason'];
        yield 'interface' => ["<?php\nnamespace App\\Contracts;\ninterface Approves {}", 'App\\Contracts\\Approves'];
        yield 'trait' => ["<?php\nnamespace App\\Concerns;\ntrait Approves {}", 'App\\Concerns\\Approves'];
        yield 'final class with attributes' => ["<?php\nnamespace App;\n#[Attr]\nfinal readonly class Thing {}", 'App\\Thing'];
        yield 'global namespace' => ["<?php\nclass Thing {}", 'Thing'];
        yield 'anonymous class' => ["<?php\nreturn new class extends Migration {};", null];
        yield 'class constant only' => ["<?php\n\$x = Foo::class;", null];
        yield 'functions' => ["<?php\nfunction helper() {}", null];
    }

    #[Test]
    #[DataProvider('declarations')]
    public function it_reads_the_class_a_file_declares(string $source, ?string $class): void
    {
        $this->assertSame($class, ClassReferences::declaredIn($source));
    }

    /**
     * @param  list<string>  $expected
     */
    private function assertAffected(array $expected, string $changed, ?EdgeAwareResolver $resolver = null): void
    {
        $graph = new Graph($this->root);
        $graph->setTestPaths(new TestPaths(directories: ['tests'], files: [], suffixes: ['Test.php']));
        $graph->markKnownTestFiles(array_keys($this->links));

        foreach ($this->links as $test => $files) {
            foreach ($files as $file) {
                $graph->link($test, $this->root.'/'.$file);
            }
        }

        $graph->setResolvers([$resolver ?? new LaravelResolver]);

        $affected = $graph->affected([$changed]);
        sort($affected);

        $this->assertSame($expected, $affected);
    }
}
