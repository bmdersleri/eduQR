<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit;

use EduQR\Container;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * The composition root — T-1128, NFR-80.
 *
 * These tests never resolve a repository: repositories take the shared MySQL
 * connection from EduQR\Support\Database, and the suite runs without a live
 * server. The two LLM-backed services are config-only, so they carry the
 * behavioural assertions (laziness, memoization, reset), and reflection carries
 * the structural ones.
 */
final class ContainerTest extends TestCase
{
    protected function setUp(): void
    {
        Container::reset();
    }

    protected function tearDown(): void
    {
        Container::reset();
    }

    // ── Structure ─────────────────────────────────────────────────────────────

    /**
     * Every service and every repository in the tree is reachable by name.
     * A collaborator with no accessor is a collaborator someone will build inline.
     *
     * @requirement NFR-80
     */
    public function testEveryServiceAndRepositoryHasAnAccessor(): void
    {
        foreach ($this->collaborators() as $shortName => $fqcn) {
            $accessor = lcfirst($shortName);

            $this->assertTrue(
                method_exists(Container::class, $accessor),
                "Container is missing an accessor for {$fqcn}"
            );
        }
    }

    /**
     * Each accessor declares a type the concrete class actually satisfies, so a
     * call site can keep the type it already had.
     *
     * @requirement NFR-80
     */
    public function testAccessorReturnTypesMatchTheConcreteClasses(): void
    {
        foreach ($this->collaborators() as $shortName => $fqcn) {
            $method = new ReflectionMethod(Container::class, lcfirst($shortName));
            $type = $method->getReturnType();

            $this->assertInstanceOf(
                ReflectionNamedType::class,
                $type,
                "Accessor for {$fqcn} must declare a single named return type"
            );

            /** @var ReflectionNamedType $type */
            $this->assertTrue(
                is_a($fqcn, $type->getName(), true),
                "{$fqcn} does not satisfy the declared return type {$type->getName()}"
            );
        }
    }

    /**
     * Named accessors only. A get(string $id) — or a set() that lets a caller
     * swap an instance — would make this a service locator instead of a
     * composition root (SYSTEM_ARCHITECTURE.md §2.2).
     *
     * @requirement NFR-80
     */
    public function testContainerExposesNoServiceLocatorSurface(): void
    {
        foreach (['get', 'set', 'has', 'make', 'bind', 'resolve', 'instance'] as $forbidden) {
            $this->assertFalse(
                method_exists(Container::class, $forbidden),
                "Container must not expose {$forbidden}()"
            );
        }
    }

    /**
     * Every accessor is callable as Container::name() with no arguments, so no
     * call site has to know how the graph is assembled.
     *
     * @requirement NFR-80
     */
    public function testEveryPublicMethodIsStaticAndArgumentFree(): void
    {
        foreach ((new ReflectionClass(Container::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $this->assertTrue($method->isStatic(), "{$method->getName()}() must be static");
            $this->assertSame(
                0,
                $method->getNumberOfRequiredParameters(),
                "{$method->getName()}() must be callable with no arguments"
            );
        }
    }

    // ── Behaviour ─────────────────────────────────────────────────────────────

    /**
     * Nothing is built until it is asked for, and asking for one collaborator
     * does not drag the rest of the graph in with it — a request that never
     * touches the database must never open a connection.
     *
     * @requirement NFR-80
     */
    public function testResolvingOneCollaboratorBuildsOnlyThatCollaborator(): void
    {
        $this->assertSame([], $this->memo(), 'reset() must leave the memo empty');

        Container::questionGenerationService();

        $this->assertSame(['questionGenerationService'], array_keys($this->memo()));
    }

    /**
     * One request builds one object graph.
     *
     * @requirement NFR-80
     */
    public function testAccessorsReturnTheSameInstanceOnEveryCall(): void
    {
        $this->assertSame(Container::questionGenerationService(), Container::questionGenerationService());
        $this->assertSame(
            Container::openTextThemeExtractionService(),
            Container::openTextThemeExtractionService()
        );
    }

    /**
     * reset() exists for tests: it drops the memo so the next call rebuilds.
     *
     * @requirement NFR-80
     */
    public function testResetDropsMemoizedInstances(): void
    {
        $before = Container::questionGenerationService();
        Container::reset();

        $this->assertSame([], $this->memo());
        $this->assertNotSame($before, Container::questionGenerationService());
    }

    /**
     * The generator seam that QuestionBankController exposes for tests: passing a
     * collaborator yields a one-off graph, and the shared instance is untouched.
     *
     * @requirement NFR-80
     */
    public function testQuestionBankServiceOverrideIsNotMemoized(): void
    {
        $method = new ReflectionMethod(Container::class, 'questionBankService');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame('generator', $params[0]->getName());
        $this->assertTrue($params[0]->isOptional());

        $type = $params[0]->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        /** @var ReflectionNamedType $type */
        $this->assertSame(\EduQR\Contracts\QuestionGenerationServiceInterface::class, $type->getName());
        $this->assertTrue($type->allowsNull());

        $generator = new class () implements \EduQR\Contracts\QuestionGenerationServiceInterface {
            public function generateFromNotes(
                string $courseTitle,
                ?string $topicName,
                string $lectureNotes,
                string $language
            ): array {
                return [];
            }
        };

        $overridden = null;

        try {
            $overridden = Container::questionBankService($generator);
        } catch (\Throwable) {
            // Building the graph reaches the repositories, and the unit suite has
            // no database. The memo assertion below is the leak worth guarding and
            // holds either way.
        }

        $this->assertArrayNotHasKey('questionBankService', $this->memo());

        if ($overridden !== null) {
            $this->assertNotSame($overridden, Container::questionBankService($generator));
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Short class name => FQCN for every service and repository in the tree.
     *
     * @return array<string,string>
     */
    private function collaborators(): array
    {
        $root = dirname(__DIR__, 2) . '/src';
        $found = [];

        foreach (['Repositories', 'Services'] as $folder) {
            foreach (glob($root . '/' . $folder . '/*.php') ?: [] as $file) {
                $shortName = basename($file, '.php');
                $found[$shortName] = 'EduQR\\' . $folder . '\\' . $shortName;
            }
        }

        $this->assertNotEmpty($found, 'No services or repositories found to check');

        return $found;
    }

    /**
     * @return array<string,object>
     */
    private function memo(): array
    {
        $property = (new ReflectionClass(Container::class))->getProperty('instances');

        /** @var array<string,object> */
        return $property->getValue();
    }
}
