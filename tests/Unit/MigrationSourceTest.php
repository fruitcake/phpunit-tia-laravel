<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel\Tests\Unit;

use Fruitcake\PhpUnitTia\Laravel\MigrationSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MigrationSourceTest extends TestCase
{
    #[Test]
    public function it_reads_a_full_migration(): void
    {
        $source = MigrationSource::parse(<<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\DB;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            /**
             * Run the migrations. Schema::table($this->table) in a comment does not count.
             */
            public function up(): void
            {
                Schema::table('invoices', function (Blueprint $table) {
                    $table->foreignId('user_id')->constrained('users');
                });
                Schema::rename('old_posts', 'posts');
                DB::statement('ALTER TABLE orders ADD COLUMN total INT');
                DB::table('settings')->insert(['key' => 'x', 'created_at' => DB::raw('CURRENT_TIMESTAMP')]);
            }

            public function down(): void
            {
                Schema::dropIfExists('invoices');
            }
        };
        PHP);

        $this->assertTrue($source->complete);
        $this->assertSame(['invoices', 'old_posts', 'orders', 'posts', 'settings', 'users'], $source->tables);
        $this->assertSame(['posts'], $source->created);
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function completeMigrations(): iterable
    {
        yield 'create' => ["Schema::create('users', fn () => null);", ['users'], ['users']];
        yield 'fully qualified facade' => ["\\Illuminate\\Support\\Facades\\Schema::table('users', fn () => null);", ['users'], []];
        yield 'double quotes' => ['Schema::drop("sessions");', ['sessions'], []];
        yield 'schema qualified table' => ["DB::table('public.audits')->delete();", ['audits'], []];
        yield 'raw create' => ["DB::statement('CREATE TABLE IF NOT EXISTS invoices (id INT)');", ['invoices'], ['invoices']];
        yield 'unprepared nowdoc' => ["DB::unprepared(<<<'SQL'\n    DROP TRIGGER t;\n    ALTER TABLE users ADD age INT;\n    SQL);", ['users'], []];
        yield 'data update' => ["DB::update('update users set active = 1');", ['users'], []];
        yield 'has table guard' => ["if (! Schema::hasTable('jobs')) { Schema::create('jobs', fn () => null); }", ['jobs'], ['jobs']];
        yield 'foreign key constraints toggled' => ["Schema::disableForeignKeyConstraints();\nSchema::drop('posts');\nSchema::enableForeignKeyConstraints();", ['posts'], []];
        yield 'driver check' => ["if (DB::connection()->getDriverName() === 'sqlite') { return; }\nSchema::table('users', fn () => null);", ['users'], []];
        yield 'schema driver check' => ["if (Schema::getConnection()->getDriverName() === 'sqlite') { return; }\nSchema::table('users', fn () => null);", ['users'], []];
        yield 'transaction' => ["DB::transaction(function () { DB::table('users')->update(['a' => 1]); });", ['users'], []];
        yield 'safe helpers' => ["DB::table('users')->insert(['token' => Str::random(), 'at' => Carbon::now()]);", ['users'], []];
        yield 'class constant is not a call' => ["Schema::table('users', fn () => User::class);", ['users'], []];
        yield 'named table argument' => ["Schema::create(table: 'users', callback: fn () => null);", ['users'], ['users']];
        yield 'safe instance' => ["Schema::table('users', fn (\$t) => \$t->timestamp('at')->default(new Expression('CURRENT_TIMESTAMP')));", ['users'], []];
        yield 'text that is not sql' => ["Schema::table('users', fn (\$t) => \$t->string('x')->comment('copied from legacy'));", ['users'], []];
    }

    /**
     * @param  list<string>  $tables
     * @param  list<string>  $created
     */
    #[Test]
    #[DataProvider('completeMigrations')]
    public function it_reads_every_table_of_a_migration_it_understands(string $php, array $tables, array $created): void
    {
        $source = MigrationSource::parse($php);

        $this->assertTrue($source->complete, 'expected a complete read');
        $this->assertSame($tables, $source->tables);
        $this->assertSame($created, $source->created);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function partialMigrations(): iterable
    {
        yield 'other connection' => ["Schema::create('audits', fn () => null);\nSchema::connection('x')->table('users', fn () => null);", ['audits', 'users']];
        yield 'table from a property' => ["Schema::create('audits', fn () => null);\nSchema::table(\$this->table, fn () => null);", ['audits']];
        yield 'table from a variable' => ["foreach (['a', 'b'] as \$name) { Schema::drop(\$name); }", []];
        yield 'concatenated sql' => ["DB::statement('ALTER TABLE '.\$table.' ADD age INT');", []];
        yield 'sql without a table' => ["Schema::table('users', fn () => null);\nDB::statement('PRAGMA foreign_keys = OFF');", ['users']];
        yield 'db on another connection' => ["DB::connection('legacy')->table('users')->delete();", ['users']];
        yield 'model' => ["Schema::table('users', fn () => null);\nInvoice::query()->update(['paid' => true]);", ['users']];
        yield 'artisan' => ["Artisan::call('app:backfill');", []];
        yield 'unknown schema method' => ["Schema::table('users', fn () => null);\nSchema::someMacro('posts');", ['users']];
        yield 'nothing at all' => ['return 1;', []];
        yield 'only neutral calls' => ['Schema::disableForeignKeyConstraints();', []];
        yield 'new model' => ["Schema::table('users', fn () => null);\n\$invoice = new Invoice;\n\$invoice->save();", ['users']];
        yield 'fully qualified new model' => ["Schema::table('users', fn () => null);\n(new \\App\\Models\\Invoice)->save();", ['users']];
        yield 'container db' => ["Schema::table('users', fn () => null);\napp('db')->table('invoices')->delete();", ['users']];
        yield 'resolved schema' => ["Schema::table('users', fn () => null);\nresolve('db.schema')->drop('invoices');", ['users']];
        yield 'injected schema' => ["Schema::table('users', fn () => null);\n\$this->schema->drop('invoices');", ['users']];
        yield 'model foreign key' => ["Schema::table('invoices', function (\$table) { \$table->foreignIdFor(User::class)->constrained(); });", ['invoices']];
        yield 'constrained to another column' => ["Schema::table('invoices', function (\$table) { \$table->foreignId('user_id')->constrained(column: 'uuid'); });", ['invoices']];
        yield 'foreign key on a property' => ["Schema::table('invoices', function (\$table) { \$table->foreign('user_id')->references('id')->on(\$this->users); });", ['invoices']];
    }

    /**
     * @param  list<string>  $tables
     */
    #[Test]
    #[DataProvider('partialMigrations')]
    public function it_marks_a_migration_it_cannot_follow_as_incomplete(string $php, array $tables): void
    {
        $source = MigrationSource::parse($php);

        $this->assertFalse($source->complete, 'expected an incomplete read');
        $this->assertSame($tables, $source->tables, 'what it did read is still reported');
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function foreignKeys(): iterable
    {
        yield 'constrained with a table' => ["\$table->foreignId('owner_id')->constrained('users');", ['users']];
        yield 'constrained with a named table' => ["\$table->foreignId('owner_id')->constrained(table: 'users', column: 'id');", ['users']];
        yield 'implicit constrained' => ["\$table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();", ['users']];
        yield 'implicit constrained irregular plural' => ["\$table->foreignId('category_id')->constrained();", ['categories']];
        yield 'implicit constrained uuid' => ["\$table->foreignUuid('team_id')->constrained();", ['teams']];
        yield 'references on' => ["\$table->foreign('user_id')->references('id')->on('users');", ['users']];
        yield 'constrained with only a column' => ["\$table->foreignId('user_id')->constrained(column: 'id');", ['users']];
        yield 'several' => ["\$table->foreignId('user_id')->constrained();\n\$table->foreignId('post_id')->constrained();", ['posts', 'users']];
    }

    /**
     * @param  list<string>  $referenced
     */
    #[Test]
    #[DataProvider('foreignKeys')]
    public function it_counts_the_table_a_foreign_key_references(string $column, array $referenced): void
    {
        $source = MigrationSource::parse("Schema::table('invoices', function (Blueprint \$table) {\n    {$column}\n});");

        $this->assertTrue($source->complete);
        $this->assertSame(self::sorted(['invoices', ...$referenced]), $source->tables);
    }

    /**
     * @param  list<string>  $tables
     * @return list<string>
     */
    private static function sorted(array $tables): array
    {
        sort($tables);

        return $tables;
    }
}
